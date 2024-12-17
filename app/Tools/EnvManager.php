<?php declare(strict_types=1);

namespace App\Tools;

class EnvManager
{
    /**
     * Add app environment variable if already do not exists
     *
     * @param string $env Environment unique key
     * @param mixed $value Environment value
     */
    public static function putEnv(string $env, mixed $value = null): void
    {
        if (getenv($env) === false) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            if (is_bool($value)){
                $value = $value ? 'true' : 'false';
            }
            putenv("$env=$value");
        }
    }

    public static function getEnv(string|int $envKey,mixed $default = null): mixed
    {
        if (array_key_exists($envKey, $_ENV)) {
            $env = $_ENV[$envKey];
        } else {
            $env = getenv($envKey);
        }

        // If the variable is not defined, return the default value
        if ($env === false) {
            return $default;
        }

        // If the value is a boolean string, convert it to a native boolean
        if (is_string($env)) {
            $loweredEnv = strtolower($env);
            if ($loweredEnv === 'true' || $loweredEnv === 'false') {
                return filter_var($env, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }

        // Return the value as a string, even if it is null or empty
        return $env;
    }
}