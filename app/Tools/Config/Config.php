<?php declare(strict_types=1);

namespace App\Tools\Config;

use App\Tools\Helpers\Utils;
use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use BadMethodCallException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Central application configuration, loaded once from system environment variables and the
 * project's .env file (file: /C:/projects/swoole-socks5/.env).
 *
 * Each known config item is still a real `public static` property below — exactly like before —
 * so existing code that reads e.g. Config::$socks5_host keeps working unchanged.
 *
 * On top of that, {@see Config::get()} / {@see Config::has()} / {@see Config::set()} give you a
 * generic, key-based accessor that works for these same properties AND for any .env key that
 * isn't declared as a property at all. That's the piece that lets you add a brand-new item to
 * .env and read it (via get()) with zero changes to this class; declare a property for it too
 * only if you also want the Config::$new_key style access and IDE type-checking on it.
 *
 * Usage:
 *   Config::init();                     // call once, e.g. in bootstrap.php
 *   Config::$socks5_host;                // direct property access, unchanged from before
 *   Config::get('socks5_host');          // same value, generic accessor
 *   Config::get('some_brand_new_key');   // works even if no property was ever declared for it
 *   Config::socks5Host();                // optional camelCase magic accessor sugar
 */
class Config
{
    /**
     * @var bool Enable debug mode to show more logs
     */
    public static bool $is_debug;

    /**
     * @var bool Enable SOCKS5 proxy server
     */
    public static bool $socks5_enabled;

    /**
     * @var string SOCKS5 proxy server host address
     */
    public static string $socks5_host;

    /**
     * @var int SOCKS5 proxy server port number
     */
    public static int $socks5_port;

    /**
     * @var bool Enable authentication for SOCKS5 proxy
     */
    public static bool $socks5_auth_enable;

    /**
     * @var string SOCKS5 proxy authentication username
     */
    public static string $socks5_username;

    /**
     * @var string SOCKS5 proxy authentication password
     */
    public static string $socks5_password;

    /**
     * @var string Admin panel server host address
     */
    public static string $admin_host;

    /**
     * @var int Admin panel server port number
     */
    public static int $admin_port;

    /**
     * @var string Metrics http server host address
     */
    public static string $metrics_host;

    /**
     * @var int Metrics http server port number
     */
    public static int $metrics_port;

    /**
     * @var bool Enable HTTP proxy server
     */
    public static bool $http_proxy_enabled;

    /**
     * @var string HTTP proxy server host address
     */
    public static string $http_proxy_host;

    /**
     * @var int HTTP proxy server port number
     */
    public static int $http_proxy_port;

    /**
     * @var array Additional config items found in .env but not defined as class properties
     */
    public static array $dotEnvExtraItems = [];

    /**
     * @var bool Track initialization state to prevent duplicate config loading
     */
    protected static bool $isInitialized = false;

    /**
     * @var array Cached environment variables from .env file
     */
    private static array $userEnvs;

    /**
     * @var bool Enable debug logging for config operations
     */
    protected static bool $enable_debug_log = false;

    /**
     * @var string[] Names of the public static config properties above, cached once at init()
     *               time so get()/has()/set() don't need to re-run Reflection on every call.
     */
    private static array $publicConfigKeys = [];

    /**
     * Set custom application configurations
     *
     * @return void
     */
    public static function setCustomConfigs(): void
    {
        date_default_timezone_set('Asia/Tehran');
    }

    /**
     * Initialize application configurations from system env and .env file
     *
     * @return void
     * @throws RuntimeException When .env file not found
     */
    public static function init(): void
    {
        if (self::$isInitialized) {
            return;
        }
        self::$isInitialized = true;

        $dotEnvPath = self::resolveDotEnvPath();
        $dotEnvs = self::getDotEnvContents($dotEnvPath);

        self::importConfigsFromSystemEnv($dotEnvs);
        self::importExtraDotEnvItems($dotEnvs);

        self::setCustomConfigs();
    }

    /**
     * Get a configuration value by key. Works for any declared property above (e.g.
     * 'socks5_host') as well as any extra key that only exists in .env with no matching
     * property. This is what makes newly added .env items readable without touching this class.
     *
     * @param string $key Config key, e.g. 'socks5_host'. Case-insensitive.
     * @param mixed $default Returned when the key has no resolved value.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::init();
        $key = strtolower(trim($key));

        if (in_array($key, self::$publicConfigKeys, true) && isset(self::$$key)) {
            return self::$$key;
        }

        return self::$dotEnvExtraItems[$key] ?? $default;
    }

    /**
     * Check whether a configuration key currently has a resolved value.
     */
    public static function has(string $key): bool
    {
        self::init();
        $key = strtolower(trim($key));

        return (in_array($key, self::$publicConfigKeys, true) && isset(self::$$key))
            || array_key_exists($key, self::$dotEnvExtraItems);
    }

    /**
     * Get every resolved configuration value at once, e.g. for debugging or an admin panel.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::init();

        $values = self::$dotEnvExtraItems;
        foreach (self::$publicConfigKeys as $key) {
            if (isset(self::$$key)) {
                $values[$key] = self::$$key;
            }
        }

        return $values;
    }

    /**
     * Override a configuration value at runtime (e.g. in tests). Does not touch the .env file.
     * Works for declared properties as well as extra/undeclared keys.
     */
    public static function set(string $key, mixed $value): void
    {
        $key = strtolower(trim($key));

        if (in_array($key, self::$publicConfigKeys, true)) {
            self::$$key = $value;
            return;
        }

        self::$dotEnvExtraItems[$key] = $value;
    }

    /**
     * Optional magic sugar: turns calls like Config::socks5Host() into Config::get('socks5_host').
     *
     * @param array<int, mixed> $arguments Optional single argument used as the default value.
     * @throws BadMethodCallException When the resolved key has no known/resolved config item.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        $key = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        if (!self::has($key)) {
            throw new BadMethodCallException(sprintf('Undefined configuration accessor "%s::%s()"', static::class, $name));
        }

        return self::get($key, $arguments[0] ?? null);
    }

    /**
     * Get application debug mode status
     *
     * @return bool True if debug mode is enabled
     */
    public static function isDebug(): bool
    {
        if (!isset(self::$is_debug)) {
            self::$is_debug = (bool)getenv('is_debug');
        }
        return self::$is_debug;
    }

    /**
     * Parse and cast environment value to specified type
     *
     * @param mixed $envValue The raw environment value
     * @param string $type Target type for casting
     * @param bool $nullable Whether null is allowed
     * @return mixed Casted value
     */
    public static function parsConfigValue(mixed $envValue, string $type, bool $nullable = false): mixed
    {
        return match ($type) {
            'bool' => self::parseBoolValue($envValue),
            'int', 'float' => self::parseNumericValue($envValue, $type, $nullable),
            'array' => self::parseArrayValue($envValue, $nullable),
            default => $nullable ? null : $envValue,
        };
    }

    /**
     * Log a message if debug mode is enabled
     *
     * @param string $message The log message to display in CLI stdout
     * @param LogLevel $level The log level
     * @return void
     */
    protected static function debugLog(string $message, LogLevel $level = LogLevel::INFO): void
    {
        if (self::$enable_debug_log) {
            Logger::echo($message, $level);
        }
    }

    /**
     * Read and parse .env file contents
     *
     * @param string $filePath Path to the .env file
     * @param bool $overwrite Whether to overwrite existing values
     * @return array Parsed environment variables as key-value pairs
     */
    protected static function getDotEnvContents(string $filePath, bool $overwrite = true): array
    {
        if (isset(self::$userEnvs)) {
            return self::$userEnvs;
        }

        try {
            if (!self::validateDotEnvFile($filePath)) {
                return [];
            }

            $envs = self::parseDotEnvFile($filePath, $overwrite);
            self::$userEnvs = $envs;
            return self::$userEnvs;
        } catch (Throwable $exception) {
            Logger::echo("Error in reading .env file : {$exception->getMessage()}");
            return [];
        }
    }

    /**
     * Resolve the .env file path from app base directory
     *
     * @return string Resolved .env file path
     * @throws RuntimeException When neither .env nor .env.example exists
     */
    private static function resolveDotEnvPath(): string
    {
        $dotEnvPath = Utils::path('.env');
        if (!file_exists($dotEnvPath)) {
            $dotEnvPath = Utils::path('.env.example');
            if (!file_exists($dotEnvPath)) {
                throw new RuntimeException("dotenv file not found");
            }
        }
        return $dotEnvPath;
    }

    /**
     * Import configuration values from system environment variables (falling back to .env),
     * and cache the list of public static property names for get()/has()/set()/all().
     *
     * @param array $dotEnvs Reference to dot env array for tracking remaining items
     * @return void
     */
    private static function importConfigsFromSystemEnv(array &$dotEnvs): void
    {
        $reflection = new ReflectionClass(self::class);
        $staticProps = $reflection->getProperties(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_PUBLIC);

        foreach ($staticProps as $prop) {
            $configName = trim($prop->getName());
            self::$publicConfigKeys[] = $configName;

            $systemEnvValue = getenv(strtoupper($configName));

            if ($systemEnvValue !== false) {
                $prop->setValue(self::parsConfigValue(
                    $systemEnvValue,
                    $prop->getType()->getName(),
                    $prop->getType()->allowsNull()
                ));
                self::debugLog("Import config `$configName` with value `$systemEnvValue` from system environments");

                if (array_key_exists($configName, $dotEnvs)) {
                    unset($dotEnvs[$configName]);
                }
                continue;
            }

            if (array_key_exists($configName, $dotEnvs)) {
                $dotEnvConfigValue = $dotEnvs[$configName];
                $parsedValue = self::parsConfigValue(
                    $dotEnvConfigValue,
                    $prop->getType()->getName(),
                    $prop->getType()->allowsNull()
                );
                $prop->setValue($parsedValue);
                unset($dotEnvs[$configName]);
                self::debugLog("Import config `$configName` with value `$dotEnvConfigValue` from defined .env file");
            }
        }
    }

    /**
     * Import extra .env items not defined as class properties
     *
     * @param array $dotEnvs Reference to remaining dot env items
     * @return void
     */
    private static function importExtraDotEnvItems(array &$dotEnvs): void
    {
        if (count($dotEnvs) > 0) {
            foreach ($dotEnvs as $envName => $envValue) {
                self::$dotEnvExtraItems[$envName] = $envValue;
                self::debugLog(
                    "Import config `$envName` with value `$envValue` from .env (property $envName do not defined in class Config)",
                    LogLevel::WARNING
                );
                unset($dotEnvs[$envName]);
            }
        }
    }

    /**
     * Validate .env file existence and readability
     *
     * @param string $filePath Path to validate
     * @return bool True if file exists and is readable
     */
    private static function validateDotEnvFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            Logger::echo("Failed to read app environments because .env file do not exists in app base directory");
            return false;
        }

        if (!is_readable($filePath)) {
            Logger::echo("Failed to read app environments because .env file is not readable");
            return false;
        }

        return true;
    }

    /**
     * Parse .env file and extract environment variables
     *
     * @param string $filePath Path to .env file
     * @param bool $overwrite Whether to overwrite existing values
     * @return array Parsed environment variables
     */
    private static function parseDotEnvFile(string $filePath, bool $overwrite): array
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $envs = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (self::skipDotEnvLine($line)) {
                continue;
            }

            $line = self::removeExportPrefix($line);

            if (!preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $matches)) {
                continue;
            }

            [, $key, $value] = $matches;
            $value = self::removeQuotes($value);

            if (!$overwrite && array_key_exists($key, $envs)) {
                continue;
            }

            $configKey = trim(strtolower($key));
            $envs[$configKey] = trim($value);
        }

        return $envs;
    }

    /**
     * Check if .env line should be skipped
     *
     * @param string $line Line to check
     * @return bool True if line should be skipped
     */
    private static function skipDotEnvLine(string $line): bool
    {
        return $line === '' ||
            str_starts_with($line, '#') ||
            str_starts_with($line, ';');
    }

    /**
     * Remove optional "export " prefix from .env line
     *
     * @param string $line Line to process
     * @return string Line without export prefix
     */
    private static function removeExportPrefix(string $line): string
    {
        if (str_starts_with($line, 'export ')) {
            return substr($line, 7);
        }
        return $line;
    }

    /**
     * Remove surrounding quotes from value if present
     *
     * @param string $value Value to process
     * @return string Value without surrounding quotes
     */
    private static function removeQuotes(string $value): string
    {
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    /**
     * Parse boolean value from environment variable
     *
     * @param mixed $envValue Raw environment value
     * @return bool Parsed boolean value
     */
    private static function parseBoolValue(mixed $envValue): bool
    {
        if (empty($envValue)) {
            return false;
        }

        if (in_array($envValue, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($envValue, ['0', 'false', 'no', 'off', '', null], true)) {
            return false;
        }

        if (is_numeric($envValue)) {
            return intval($envValue) > 0;
        }

        return strtolower($envValue) === 'true';
    }

    /**
     * Parse numeric value from environment variable
     *
     * @param mixed $envValue Raw environment value
     * @param string $type Target type (int or float)
     * @param bool $nullable Whether null is allowed
     * @return int|float|null Parsed numeric value
     */
    private static function parseNumericValue(mixed $envValue, string $type, bool $nullable): int|float|null
    {
        if (is_numeric($envValue)) {
            return $type === 'float' ? floatval($envValue) : intval($envValue);
        }

        return $nullable ? null : ($type === 'float' ? 0.0 : 0);
    }

    /**
     * Parse array value from environment variable
     *
     * @param mixed $envValue Raw environment value
     * @param bool $nullable Whether null is allowed
     * @return array|null Parsed array value
     */
    private static function parseArrayValue(mixed $envValue, bool $nullable): ?array
    {
        if (is_array($envValue)) {
            return $envValue;
        }

        $decoded = Utils::safeJsonDecode($envValue);
        if ($decoded !== false && is_array($decoded)) {
            return $decoded;
        }

        if (is_string($envValue) && str_contains($envValue, ',')) {
            return array_map('trim', explode(',', $envValue));
        }

        return $nullable ? null : [];
    }
}