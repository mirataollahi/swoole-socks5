<?php declare(strict_types=1);

namespace App\Tools\Logger\LogPrinter;

/**
 * Interface for classes responsible for printing log messages
 */
interface LogPrinterInterface
{
    /**
     * Print the log message.
     *
     * @param string $message The log message to print
     * @return void
     */
    public function print(string $message): void;
}