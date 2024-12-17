<?php declare(strict_types=1);

namespace App;

use App\Master\MasterServer;
use App\Socks\SocksServer;
use App\Tools\Logger;
use App\Tools\MetricManager;
use Throwable;

class BaseServer
{
    public Logger $logger;
    public static SocksServer $socksServer;
    public static string $socksHost;
    public static int $socksPort;
    public static ?string $socksUsername = null;
    public static ?string $socksPassword = null;
    public static  int $workerCount = 4;
    public static MetricManager $metricManager;
    public static MasterServer $masterServer;
    public function __construct()
    {
        $this->logger = new Logger('BASE_SERVER');
        $this->logger->info("Starting application .... ");
        self::$metricManager = new MetricManager(self::$workerCount);
        self::$socksServer = new SocksServer($this);
        self::$masterServer= new MasterServer();
        self::$socksServer->server->start();
    }

    public static function run(string $host,int $port,?string $username , ?string $password): void
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
