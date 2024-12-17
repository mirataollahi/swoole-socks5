<?php declare(strict_types=1);

namespace App;

use App\Socks\SocksServer;
use App\Tools\Logger;
use App\Tools\MetricManager;
use Throwable;

class BaseServer
{
    public Logger $logger;
    public SocksServer $socksServer;
    public static string $socksHost;
    public static string $socksPort;
    public static ?string $socksUsername = null;
    public static ?string $socksPassword = null;
    public static MetricManager $metricManager;

    public function __construct()
    {
        $this->logger = new Logger('BASE_SERVER');
        $this->logger->info("Starting application .... ");
        self::$metricManager = new MetricManager();
        $this->socksServer = new SocksServer($this);
        $this->socksServer->server->start();
    }

    public static function run(string $host,string $port,?string $username , ?string $password): void
    {
        try {
            BaseServer::$socksHost = $host;
            BaseServer::$socksPort = $port;
            BaseServer::$socksUsername = $username;
            BaseServer::$socksPassword = $password;
            new self();
        } catch (Throwable $exception) {
            Logger::echo("Startup error : {$exception->getMessage()}} \n", Logger::ERROR);
        }
    }

    /**
     * The method run in worker layer and run after worker started
     */
    public function initWorkerLayer(int $workerId): void
    {
        self::$metricManager->initialize($workerId);
    }
}
