<?php declare(strict_types=1);

namespace App\Tools\Logger\LogPrinter;

class FileLogPrinter implements LogPrinterInterface
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Print the log message to a file.
     *
     * @param string $message The log message to print
     * @return void
     */
    public function print(string $message): void
    {
        try {
            file_put_contents($this->filePath, $message . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $exception){
            echo "Error in file log printer : {$exception->getMessage()}". PHP_EOL;
        }
    }
}