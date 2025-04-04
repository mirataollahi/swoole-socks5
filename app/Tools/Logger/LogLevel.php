<?php declare(strict_types=1);

namespace App\Tools\Logger;

/**
 * Binary log level using hexadecimal notation
 */
enum LogLevel: int
{
    case EMERGENCY   = 0x1;      // 1
    case ALERT       = 0x2;      // 2
    case CRITICAL    = 0x4;      // 4
    case ERROR       = 0x8;      // 8
    case WARNING     = 0x10;     // 16
    case NOTICE      = 0x20;     // 32
    case INFO        = 0x40;     // 64
    case DEBUG       = 0x80;     // 128
    case SUCCESS     = 0x100;    // 256
    case TRACE       = 0x200;    // 512
    case VERBOSE     = 0x400;    // 1024


    /**
     * Get all log levels combined as an integer.
     */
    public static function getAllLevels(): int
    {
        return array_reduce(
            self::cases(),
            fn(int $carry, self $level) => $carry | $level->value,
            0
        );
    }

    /**
     * Get log level text
     */
    public function text(): string
    {
        return strtolower($this->name);
    }

    /**
     * Check if a specific log level is enabled in the current active log levels.
     */
    public static function isEnabled(int $activeLevels, LogLevel $level): bool
    {
        return ($activeLevels & $level->value) === $level->value;
    }
}
