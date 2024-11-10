<?php

namespace App;

use App\Socks\SocksServer;
use App\Tools\Logger;

class BaseServer
{
    public Logger $logger;
    public SocksServer $socksServer;
    public function __construct()
    {
        $this->logger = new Logger('BASE_SERVER');
        $this->logger->info("Starting application .... ");
        $this->socksServer = new SocksServer();
        $this->socksServer->server->start();
    }
}
