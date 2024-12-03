<?php declare(strict_types=1);

namespace App\Tools;

use Throwable;

class Utils
{
    /**
     * Check the $value is same as the $hex value in hexadecimal format or not
     */
    public static function hexCompare(string|int|null $value , string $hex): bool
    {
      if (empty($value))
          return false;
        try {
            $valueInHex = bin2hex($value);
            if (!$valueInHex)
                return false;
            $valueInHex = intval($valueInHex);
            return $valueInHex === hexdec($hex);
        }
        catch (Throwable $exception){
            echo "Check hex error : {$exception->getMessage()} \n";
            return false;
        }
    }


    public static function bin2hex(string|int|null $value , bool $asString = false): string|int|false
    {
        return self::safeCode(function () use ($value,$asString){
            if (empty($value))
                return false;
           return $asString ? bin2hex($value) : intval(bin2hex($value));
        });
    }


    public static function safeCode(callable $function,mixed $failedValue = false): mixed
    {
        try {
            return call_user_func($function);
        }
        catch (Throwable $exception){
            echo "Sade code error : {$exception->getMessage()} \n";
            return $failedValue;
        }
    }
}