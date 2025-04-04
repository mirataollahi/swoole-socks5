<?php declare(strict_types=1);

namespace App\Tools\Helpers;

use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use Swoole\Coroutine;
use Throwable;

class Utils
{
    /**
     * Check the $value is same as the $hex value in hexadecimal format or not
     */
    public static function hexCompare(string|int|null $value, string $hex): bool
    {
        if (empty($value))
            return false;
        try {
            $valueInHex = bin2hex($value);
            if (!$valueInHex)
                return false;
            $valueInHex = intval($valueInHex);
            return $valueInHex === hexdec($hex);
        } catch (Throwable $exception) {
            echo "Check hex error : {$exception->getMessage()} \n";
            return false;
        }
    }

    /**
     * Calculate or for some binary integer
     */
    public static function or(...$numbers): int
    {
        $result = 0;
        foreach ($numbers as $number) {
            $result |= $number; // Directly apply the bitwise OR
        }
        return $result;
    }

    /**
     * Calculate AND for some binary integers (log levels).
     */
    public static function and(...$numbers): int
    {
        $result = array_shift($numbers);
        foreach ($numbers as $number) {
            $result &= $number;
        }
        return $result;
    }

    public static function printf(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace("%$key", $value, $template);
        }
        return $template;
    }

    /**
     * Convert a bytes integer value to human-readable size
     */
    public static function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

        if ($bytes < 1) {
            return '0 B';
        }

        $exponent = (int)floor(log($bytes, 1024));
        $exponent = min($exponent, count($units) - 1);

        $size = $bytes / (1024 ** $exponent);

        return round($size, $precision) . ' ' . $units[$exponent];
    }

    /**
     * The method run a callable operation without any thrown exception
     */
    public static function safeCode(callable $callback): mixed
    {
        try {
            return call_user_func($callback);
        } catch (Throwable $exception) {
            Logger::echo(
                "Failed executing callback in safe code runner : {$exception}",
                LogLevel::WARNING);
            return false;
        }
    }

    /**
     * Check the code is running on swoole coroutine context or not
     */
    public static function isCoroutine(): bool
    {
        return Coroutine::exists(Coroutine::getCid());
    }
}