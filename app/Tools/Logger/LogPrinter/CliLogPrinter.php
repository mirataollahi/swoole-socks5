<?php declare(strict_types=1);

namespace App\Tools\Logger\LogPrinter;

class CliLogPrinter implements LogPrinterInterface
{
    /**
     * Print the log message to the command line (CLI).
     *
     * @param string $message The log message to print
     * @return void
     */
    public function print(string $message): void
    {
        echo $message . PHP_EOL;
    }
}