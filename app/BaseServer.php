<?php declare(strict_types=1);

namespace App;

use App\Master\MasterServer;
use App\Metrics\MetricManager;
use App\Socks\SocksServer;
use App\Tools\EnvManager;
use App\Tools\Logger;
use Throwable;

class BaseServer
{
    public Logger $logger;
    public static SocksServer $socksServer;
    public static string $socksHost;
    public static int $socksPort;
    public static string|false $socksUsername = false;
    public static string|false $socksPassword = false;
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

    public static function run(): void
    {
        try {
            BaseServer::$socksHost = EnvManager::getEnv('SOCKS5_HOST');
            BaseServer::$socksPort = EnvManager::getEnv('SOCKS5_PORT');
            BaseServer::$socksUsername = EnvManager::getEnv('SOCKS5_USERNAME',false);
            BaseServer::$socksPassword = EnvManager::getEnv('SOCKS5_PASSWORD',false);
            new self();
        } catch (Throwable $exception) {
            Logger::echo("Startup error : $exception", Logger::ERROR);
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
