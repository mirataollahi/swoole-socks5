<?php

namespace App\Tools\Logger;

use App\Tools\Helpers\Utils;
use App\Tools\Logger\LogPrinter\CliLogPrinter;
use App\Tools\Logger\LogPrinter\LogPrinterInterface;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Throwable;
use Stringable;

class Logger implements LoggerInterface
{
    /**
     * Logger service instance active levels (Binary logs levels)
     */
    public int $activeLevels;

    /**
     * Async and non-blocking std-out cli printer
     */
    public bool $concurrentPrintInCli = false;

    /**
     * Logger service tag . Using in better filter and find log item
     */
    public ?string $tag = null;

    /**
     * Logger service running production or debug mode
     */
    public static bool $isProduction = false;

    /** Active log printer drivers classes */
    public array $printersClasses = [
        CliLogPrinter::class,
        // Other log message printer classes
    ];

    /**
     * Log text printer driver instance
     *
     * @var LogPrinterInterface[]
     */
    public array $printerDrivers = [];

    /** Logger service instance using in single echo log messages  */
    public static Logger $loggerInstance;

    /**
     * Create a new instance of logger service ane
     * Set logger tag base on using service name
     * Set log level base on application running mode
     */
    public function __construct(?string $tag = null)
    {
        $this->setTag($tag);

        // Create log printer driver instances
        foreach ($this->printersClasses as $printersClass) {
            $this->printerDrivers[$printersClass] = new $printersClass();
        }

        if (self::$isProduction)
            $this->activeLevels = Utils::or(
                LogLevel::SUCCESS->value,
                LogLevel::ERROR->value,
                LogLevel::WARNING->value,
                LogLevel::EMERGENCY->value,
                LogLevel::CRITICAL->value,
            );
        else
            $this->activeLevels = LogLevel::getAllLevels();
    }

    /**
     * Add datetime to output string message
     */
    public function addDateTime(Stringable|string &$text): void
    {
        $dateTime = LogColor::colorize(LogColor::UNDERLINE,date('Y-m-d H:i:s'));
        $dateTime = LogColor::colorize(LogColor::DIM,$dateTime);
        $text = sprintf("[%s] $text", $dateTime);
    }

    public function addTag(LogLevel $level , Stringable|string &$text): void
    {
        if ($this->tag){
            $tag = "[$this->tag]";
            $tag = LogColor::colorize(LogColor::BOLD,$tag);
            $tag = LogColor::colorize(LogColor::ITALIC,$tag);
            $tag = LogColor::colorize(LogColor::UNDERLINE,$tag);
            $color = $this->getLevelBgColor($level);
            $tag = LogColor::colorize($color,$tag);
            $text = "$tag $text";
        }
    }

    public function addLevelText(LogLevel $level, Stringable|string &$text): void
    {
        $levelText = "[{$level->text()}]";
        $color = $this->getLevelBgColor($level);
        $levelText = LogColor::colorize($color, $levelText);
        $text = "{$levelText} {$text}";
    }

    public function addLogContext(Stringable|string &$text,array $context = [],LogLevel $level = LogLevel::INFO): void
    {
        if (count($context) >= 1){
            $contextText = '';
            foreach ($context as $contextKey => $contextValue) {
                $levelColor = $this->getLevelColor($level);
                $contextItemText = LogColor::colorize($levelColor,
                    is_numeric($contextKey) ? "$contextValue" : "$contextKey:$contextValue"
                );
                $contextItemText = LogColor::colorize(LogColor::UNDERLINE,$contextItemText);
                $contextItemText = "[$contextItemText]";
                $contextText .= $contextItemText;
            }
            $text = "$contextText $text";
        }
    }

    /**
     * Colorize the output string in command line
     */
    private function makeOutput(LogLevel $level, Stringable|string &$text,array $context = []): void
    {
        $textColor = $this->getLevelColor($level);
        $text = LogColor::colorize($textColor,$text);
        $this->addLogContext($text,$context,$level);
        $this->addTag($level , $text);
        $this->addLevelText($level, $text);
        $this->addDateTime($text);
    }

    private function checkLogMsg(Stringable|string|null $message): ?string
    {
        return preg_replace('/[^\x{0000}-\x{FFFF}]/u', '?', $message);  // Replace with '?'
    }

    public function passLines(int $count = 1): void
    {
        echo str_repeat(PHP_EOL, $count);
    }

    public function exception(Throwable $exception, array $context = []): void
    {
        /** Exception log title */
        $exceptionName = ucfirst(get_class($exception));

        $logTitle  = "$exceptionName thrown:";
        $logTitle = LogColor::colorize(LogColor::RED,$logTitle);
        $logTitle = LogColor::colorize(LogColor::BOLD,$logTitle);
        $logTitle = LogColor::colorize(LogColor::UNDERLINE,$logTitle);

        /** Exception error message title */
        $messageTitle = LogColor::colorize(LogColor::RED,"Message:");
        $messageTitle = LogColor::colorize(LogColor::UNDERLINE,$messageTitle);
        /** Exception error message  body */
        $messageText = $exception->getMessage();
        $messageText = LogColor::colorize(LogColor::RED,$messageText);

        /** Exception error file title */
        $fileTitle = LogColor::colorize(LogColor::UNDERLINE,"File:");
        $fileTitle = LogColor::colorize(LogColor::RED,$fileTitle);
        /** Exception error file  body */
        $fileText = $exception->getFile();
        $fileText = LogColor::colorize(LogColor::RED,$fileText);


        $finalResult = "\n$logTitle\n$messageTitle$messageText\n$fileTitle$fileText\n";
        $this->print($finalResult);
    }

    /**
     * Checking a log level is enabled in current service or not
     */
    public function isLevelEnable(LogLevel $level): bool
    {
        return LogLevel::isEnabled($this->activeLevels, $level);
    }

    /**
     * Show a log message in non-service . statically show a log message with level
     */
    public static function echo(string $message, LogLevel $level = LogLevel::INFO, array $context = []): void
    {
        if (!isset(static::$loggerInstance)) {
            static::$loggerInstance = new static('GENERAL');
        }
        static::$loggerInstance->pushLog($level, $message, $context);
    }

    /**
     * Set logger service tag name
     */
    public function setTag(?string $tag = null): void
    {
        $this->tag = $tag;
    }

    /**
     * Push a log message and some level
     */
    public function pushLog(LogLevel $level, Stringable|string $message, array $context = []): void
    {
        if ($this->isLevelEnable($level)) {
            $this->makeOutput($level, $message,$context);
            $this->print($message);
        }
    }

    /**
     * Show an info log item message
     */
    public function info(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::INFO, $message, $context);
    }

    /**
     * Show a log item message with emergency level
     */
    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Show a log item message with alert level
     */
    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::ALERT, $message, $context);

    }

    /**
     * Show a log item message with critical level
     */
    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Show a log item message with notice level
     */
    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Show a log item message with debug level
     */
    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Show a log item message warning level
     */
    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::WARNING, $message, $context);
    }

    /**
     * Show a log item message error level
     */
    public function error(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::ERROR, $message, $context);
    }

    /**
     * Show a log item message success level
     */
    public function success(Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::SUCCESS, $message, $context);
    }

    /**
     * Show a log item message with info (default) level
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->pushLog(LogLevel::INFO, $message, $context);
    }

    /**
     * Print a log message with current active log printer drivers
     */
    public function print(Stringable|string $message): void
    {
        foreach ($this->printerDrivers as $printerDriver) {
            $printerDriver->print($message);
        }
    }

    public function getLevelBgColor(LogLevel $level): LogColor
    {
        return match ($level) {
            LogLevel::SUCCESS => LogColor::BG_BRIGHT_GREEN,
            LogLevel::INFO => LogColor::BG_BRIGHT_BLUE,
            LogLevel::ERROR => LogColor::BG_BRIGHT_RED,
            LogLevel::WARNING => LogColor::BG_BRIGHT_YELLOW,
            LogLevel::EMERGENCY => LogColor::BG_RED,
            LogLevel::NOTICE => LogColor::BG_CYAN,
            LogLevel::CRITICAL => LogColor::BG_BRIGHT_MAGENTA,
            default => LogColor::BG_BRIGHT_BLACK
        };
    }

    public function getLevelColor(LogLevel $level): LogColor
    {
        return match ($level) {
            LogLevel::SUCCESS => LogColor::BRIGHT_GREEN,
            LogLevel::INFO => LogColor::BRIGHT_BLUE,
            LogLevel::ERROR => LogColor::BRIGHT_RED,
            LogLevel::WARNING => LogColor::BRIGHT_YELLOW,
            LogLevel::EMERGENCY => LogColor::RED,
            LogLevel::NOTICE => LogColor::CYAN,
            LogLevel::CRITICAL => LogColor::BRIGHT_MAGENTA,
            default => LogColor::BRIGHT_BLACK
        };
    }
}
