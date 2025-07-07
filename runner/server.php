<?php

use App\BaseServer;
use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;


try {
    require_once __DIR__ . "/../bootstrap.php";
    /** Run application and master proxy server */
    BaseServer::run();

} catch (Throwable $exception) {
    Logger::echo(
        "Startup error : {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}}",
        LogLevel::ERROR
    );
}
