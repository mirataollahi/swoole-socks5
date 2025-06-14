<?php declare(strict_types=1);

namespace App\Tools\Config;

use App\Tools\Helpers\Utils;
use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

class Config
{
    public static bool $socks5_enabled;
    public static string $socks5_host;
    public static int $socks5_port;
    public static bool $socks5_auth_enable;
    public static string $socks5_username;
    public static string $socks5_password;
    public static bool $is_debug;
    public static string $admin_host;
    public static int $admin_port;
    public static string $prometheus_host;
    public static int $prometheus_port;

    /** Http Proxy Server */
    public static bool $http_proxy_enabled;
    public static string $http_proxy_host;
    public static int $http_proxy_port;

    protected static bool $isInitialized = false;
    private static array $userEnvs;
    protected static bool $debugMode = false;

    /** Configs exists in the .env but the property do not defined in Config class */
    public static array $dotEnvExtraItems = [];

    /** Import or change custom app configs */
    public static function setCustomConfigs(): void
    {
        /** Set default date timezone */
        date_default_timezone_set('Asia/Tehran');
    }

    /** Initialize application configs in app startup
     */
    public static function init(): void
    {
        if (self::$isInitialized) {
            return;
        }
        self::$isInitialized = true;

        /** Read .env file if exists in app base path */
        $dotEnvPath = Utils::path('.env');
        if (file_exists($dotEnvPath)) {
            $dotEnvs = self::getDotEnvContents($dotEnvPath);
        } else $dotEnvs = [];

        /**
         * Importing app config
         * - Highest priority for values belongs to system environments
         * - if config item do not exist in system envs , find the config in .env file
         */
        $reflection = new ReflectionClass(self::class);
        /** Get application configs items array */
        $staticProps = $reflection->getProperties(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_PUBLIC);
        foreach ($staticProps as $prop) {
            $configName = trim($prop->getName());
            $systemEnvValue = getenv(strtoupper($configName));
            if ($systemEnvValue !== false) {
                $prop->setValue(self::parsConfigValue($systemEnvValue,$prop->getType()->getName(),$prop->getType()->allowsNull()));
                self::debugLog("Import config `$configName` with value `$systemEnvValue` from system environments");

                /** Unset the env config from local .env list of defined */
                if (array_key_exists($configName, $dotEnvs)) {
                    unset($dotEnvs[$configName]);
                }
                continue;
            }

            /** Config do not exist in system envs , so find it from .env values */
            if (array_key_exists($configName, $dotEnvs)) {
                $dotEnvConfigValue = $dotEnvs[$configName];
                $prop->setValue($dotEnvConfigValue);
                /** Remove imported config from local .env items array  */
                unset($dotEnvs[$configName]);
                self::debugLog("Import config `$configName` with value `$dotEnvConfigValue` from defined .env file");
            }
        }


        /** There is more .env configs items and they are not imported as configs properties */
        if (count($dotEnvs) > 0) {
            foreach ($dotEnvs as $envName => $envValue) {
                self::$dotEnvExtraItems[$envName] = $envValue;
                self::debugLog(
                    "Import config `$envName` with value `$envValue` from .env (property $envName do not defined in class Config)" ,
                    LogLevel::WARNING
                );
                unset($dotEnvs[$envName]);
            }
        }

        /** Import custom user defined configs */
        self::setCustomConfigs();
    }

    /** Log a message in config class if debug mode is enabled */
    protected static function debugLog(string $message,LogLevel $level = LogLevel::INFO): void
    {
        if (self::$debugMode) {
            Logger::echo($message, $level);
        }
    }

    /** Read user .env file if exists */
    protected static function getDotEnvContents(string $filePath, bool $overwrite = true): array
    {
        /** Check envs already is loaded */
        if (isset(self::$userEnvs))
            return self::$userEnvs;

        try {
            if (!file_exists($filePath)) {
                Logger::echo("Failed to read app environments because .env file do not exists in app base directory");
            }
            if (!is_readable($filePath)) {
                Logger::echo("Failed to read app environments because .env file is not readable");
                return [];
            }
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $envs = [];
            foreach ($lines as $line) {
                $line = trim($line);
                /** Skip comments */
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                    continue;
                }
                /** Optional "export " prefix */
                if (str_starts_with($line, 'export ')) {
                    $line = substr($line, 7);
                }

                /** Match key=value (allows optional quotes around the value) */
                if (!preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/i', $line, $matches)) {
                    continue; // skip malformed lines
                }
                [$_, $key, $value] = $matches;

                /** Remove surrounding quotes if present */
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                if (!$overwrite && array_key_exists($key, $envs)) {
                    continue;
                }

                $configKey = trim(strtolower($key));
                $envs[$configKey] = trim($value);
            }
            self::$userEnvs = $envs;
            return self::$userEnvs;
        } catch (Throwable $exception) {
            Logger::echo("Error in reading .env file : {$exception->getMessage()}");
            return [];
        }
    }

    /** Get application running mode is debug or not */
    public static function isDebug(): bool
    {
        if (!isset(self::$is_debug)){
            self::$is_debug = getenv('is_debug');
        }
        return self::$is_debug;
    }

    public static function parsConfigValue(mixed $envValue,string $type,bool $nullable = false): mixed
    {
        /** Pars bool env value */
        if ($type === 'bool'){
            if (empty($envValue)) {
                return false;
            }
            if (in_array($envValue, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($envValue, ['0', 'false', 'no', 'off', '', null], true)) {
                return false;
            }
            if (is_numeric($envValue)){
                $envValue = intval($envValue);
                return $envValue > 0;
            }
            return strtolower($envValue) === 'true';
        }

        /** pars integer property */
        if ($type === 'int' || $type === 'float'){
            if (is_numeric($envValue)){
                return $type === 'float' ? floatval($envValue) : intval($envValue);
            }
            return $nullable ? null : ($type === 'float' ? 0.0 : 0);
        }

        /** Pars String property */
        /** Parse array property */
        if ($type === 'array') {
            if (is_array($envValue)) {
                return $envValue;
            }
            $decoded = Utils::safeJsonDecode($envValue);
            if ($decoded === false){
                Logger::echo("Error in casting json to array config value $envValue");
                return [];
            }
            if (is_array($decoded)) {
                return $decoded;
            }
            // Fallback: split by commas
            if (is_string($envValue) && str_contains($envValue, ',')) {
                return array_map('trim', explode(',', $envValue));
            }
            return $nullable ? null : [];
        }
        return $nullable ? null : $envValue;
    }
}