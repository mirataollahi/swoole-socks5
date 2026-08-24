<?php

use App\Application;
use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;

require_once __DIR__ . "/../bootstrap.php";

try {
    /** Run application and master proxy server */
    $app = Application::getContext();
    $app->start();
} catch (Throwable $exception) {
    Logger::echo(
        "Startup error : {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}}",
        LogLevel::ERROR
    );
}
