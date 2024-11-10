<?php

namespace App\Tools;

use Throwable;
use Swoole\Coroutine;

class Logger
{
    const INFO = 2;
    const SUCCESS = 4;
    const WARNING = 8;
    const ERROR = 16;
    const DEBUG = 32;

    public static bool $enableRemoteLogger = false;
    public static bool $enableRpcLogger = true;
    public static bool $enableGrpcLogger = true;


    const COLORS = [
        'RESET'   => '0',
        'BLACK'   => '30',
        'RED'     => '31',
        'GREEN'   => '32',
        'YELLOW'  => '33',
        'BLUE'    => '34',
        'MAGENTA' => '35',
        'CYAN'    => '36',
        'WHITE'   => '37',
    ];

    const BRIGHT_COLORS = [
        'brightRed'     => "\033[1;31m",
        'brightGreen'   => "\033[1;32m",
        'brightYellow'  => "\033[1;33m",
        'brightBlue'    => "\033[1;34m",
        'brightMagenta' => "\033[1;35m",
        'brightCyan'    => "\033[1;36m",
        'brightWhite'   => "\033[1;37m",
    ];


    public static int $logLevel = self::INFO | self::SUCCESS | self::WARNING | self::ERROR;

    public bool $concurrentPrintInCli = false;

    public bool $isCliLoggerEnable = true;

    public ?string $tag = null;

    public function __construct(?string $tag = null)
    {
        $this->tag = $tag;
    }

    /**
     * Colorize the output string in command line
     *
     * @param string $string
     * @param string $color
     * @param string $type
     * @param int $level
     * @param bool $dontStore
     * @return string
     */
    private function colorize(string $string, string $color, string $type = ' INFO  ', int $level = self::INFO, bool $dontStore = false): string
    {
        $dateTime = date('Y-m-d H:i:s');
        $tagText = !is_null($this->tag) ? " [$this->tag] " : '';
        return "\033[{$color}m [$type] [$dateTime] {$tagText}➡ $string\033[0m";
    }

    public function info(string $message, bool $dontStore = false): void
    {
        if ($this->isLevelEnable(self::INFO)) {
            $this->print(
                $this->colorize($message, self::COLORS['BLUE'], 'INFO  ', self::INFO, $dontStore) . PHP_EOL
            );
        }
    }

    private function checkLogMsg(string|null $message): ?string
    {
        return preg_replace('/[^\x{0000}-\x{FFFF}]/u', '?', $message);  // Replace with '?'
    }


    public function success(string $message, bool $dontStore = false): void
    {
        if ($this->isLevelEnable(self::SUCCESS)) {
            $this->print(
                $this->colorize($message, self::COLORS['GREEN'], 'SUCCESS', self::SUCCESS, $dontStore) . PHP_EOL
            );
        }
    }

    public function warning(string $message, bool $dontStore = false): void
    {
        if ($this->isLevelEnable(self::WARNING)) {
            $this->print(
                $this->colorize($message, self::COLORS['YELLOW'], 'WARNING', self::WARNING, $dontStore) . PHP_EOL
            );
        }
    }

    public function error(string $message, bool $dontStore = false): void
    {
        if ($this->isLevelEnable(self::ERROR)) {
            $this->print(
                $this->colorize($message, self::COLORS['RED'], ' ERROR ', self::ERROR, $dontStore) . PHP_EOL
            );
        }
    }

    public function passLines(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
    }

    public function exception(Throwable $exception, bool $trace = false): void
    {
        $this->error("Exception happened :");
        $this->error("\tError Message : {$exception->getMessage()}");
        $this->error("\tError File : {$exception->getFile()}");
        $this->error("\tError Line : {$exception->getLine()}");

        if ($trace) {
            $errorTrace = $exception->getTrace();
            $errorTrace = json_encode($errorTrace, JSON_PRETTY_PRINT);
            $this->error("\tError Trace :\n {$errorTrace}");
        }
    }

    /**
     * Checking a log level is enabled or not
     *
     * @param int $logLevel
     * @return bool
     */
    public function isLevelEnable(int $logLevel): bool
    {
        return self::$logLevel & $logLevel;
    }

    public function endLines(int $lineNumbers = 1): void
    {
        $this->print(
            str_repeat(PHP_EOL, $lineNumbers)
        );
    }

    public function print(string|null $message = null): void
    {
        if ($this->concurrentPrintInCli)
            Coroutine::create(function () use ($message) {
                echo $message;
            });
        echo $message;
    }


    /**
     * Using only in out of context coroutine
     */
    public static function echo(string $message, int $level = self::INFO, array $options = []): void
    {
        $tag = array_key_exists('tag', $options) ? $options['tag'] : null;
        $tagText = !is_null($tag) ? " [$tag] " : '';
        $dateTime = date('Y-m-d H:i:s');
        $levelText = match ($level) {
            self::INFO => '[ INFO  ]',
            self::WARNING => '[WARNING]',
            self::SUCCESS => '[SUCCESS]',
            self::ERROR => '[ ERROR ]',
            self::DEBUG => '[ DEBUG ]',
        };
        $logColor = match ($level) {
            self::INFO => self::COLORS['BLUE'],
            self::WARNING => self::COLORS['YELLOW'],
            self::SUCCESS => self::COLORS['GREEN'],
            self::ERROR => self::COLORS['RED'],
            self::DEBUG => self::COLORS['CYAN'],
        };
        echo "\033[{$logColor}m $levelText [$dateTime] $tagText ➡ $message\033[0m\n";
    }


    public function setTag(?string $tag = null): void
    {
        $this->tag = $tag;
    }
}
