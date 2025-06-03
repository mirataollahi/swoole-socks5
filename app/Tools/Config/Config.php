<?php

namespace App\Tools\Config;

use App\Tools\Helpers\Utils;
use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

class Config
{
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

    protected static bool $isInitialized = false;
    private static array $userEnvs;
    protected static bool $debugMode = true;

    /** Initialize application configs in app startup */
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
        $configs = [];
        foreach ($staticProps as $prop) {
            $configName = trim($prop->getName());
            $systemEnvValue = getenv(strtoupper($configName));
            if ($systemEnvValue !== false) {
                $prop->setValue($systemEnvValue);
                self::debugLog("Import config `$configName` with value `$systemEnvValue` from system environments");
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
                Config::$$envName = $envValue;
            }
        }

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
}