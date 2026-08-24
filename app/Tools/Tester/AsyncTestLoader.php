<?php declare(strict_types=1);

namespace App\Tools\Tester;

use App\Tools\Logger\Logger;

/**
 * PHP swoole async tests bootloader and coroutine manager
 */
class AsyncTestLoader
{
    public array $arguments = [];
    public Logger $logger;

    public function __construct(array $arguments)
    {
        $this->arguments = $arguments;
        $this->logger = new Logger();
    }

    public static function run($arguments): void
    {
        new self($arguments);
    }

    public function executeTest(): void
    {

    }


}