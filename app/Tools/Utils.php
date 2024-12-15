<?php declare(strict_types=1);

namespace App\Tools;

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
}