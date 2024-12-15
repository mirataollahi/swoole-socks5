<?php declare(strict_types=1);

namespace App\Tools;

class Helper
{
    public static function setEnv(string $key, string|int|null $value): void
    {
        putenv("$key=$value");
    }

    public static function getEnv(string $key, mixed $default = false): string|int|null|false
    {
        $envValue = getenv($key);
        if (empty($envValue)) {
            return $default;
        }

        if (is_numeric($envValue)) {
            return intval($envValue);
        }

        return $envValue;
    }
}
