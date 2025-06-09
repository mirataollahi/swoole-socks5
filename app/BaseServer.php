<?php declare(strict_types=1);

namespace App;

use App\Master\MasterServer;
use App\Metrics\MetricManager;
use App\Socks\SocksServer;
use App\Tools\Config\Config;
use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use Throwable;

class BaseServer
{
    /** Application instance logger service */
    public Logger $logger;

    /** Single master tcp server and network layer */
    public static SocksServer $socksServer;

    /** Proxy server host address */
    public static string $socksHost;

    /** Proxy server port number */
    public static int $socksPort;

    /**
     * Socks5 proxy server authentication username
     * Set false to disable username authentication method in server
     */
    public static string|false $socksUsername = false;

    /**
     * SOCKS% proxy server authentication password
     * username and password must be set and not null to enable authentication method
     */
    public static string|false $socksPassword = false;

    /** Server worker process count */
    public static  int $workerCount = 4;

    /** Manager and report worker processes metrics and report sum of them */
    public static MetricManager $metricManager;

    /** Master tcp server and network layer */
    public static MasterServer $masterServer;

    /** Run application and services and then start server */
    public function __construct()
    {
        $this->logger = new Logger('BASE_SERVER');
        $this->logger->info("Starting application .... ");
        self::$metricManager = new MetricManager(self::$workerCount);
        self::$masterServer= new MasterServer($this);
        self::$socksServer = new SocksServer($this);
        self::$masterServer->server->start();
    }

    /** Run proxy application statically */
    public static function run(): void
    {
        try {
            BaseServer::$socksHost = Config::$socks5_host;
            BaseServer::$socksPort = Config::$socks5_port;
            BaseServer::$socksUsername = Config::$socks5_username;
            BaseServer::$socksPassword = Config::$socks5_password;
            new self();
        } catch (Throwable $exception) {
            Logger::echo("Startup error : $exception", LogLevel::ERROR);
        }
    }

    /** Get current worker id in worker layer */
    public static function getWorkerId(): int|false
    {
        if(isset(self::$masterServer) && isset(self::$socksServer->server)) {
            return self::$masterServer->server->getWorkerId();
        }
        return false;
    }

    /** The method run in worker layer and run after worker started */
    public function initWorkerLayer(int $workerId): void
    {
        self::$metricManager->initialize($workerId);
    }

    /** Worker process is exiting . Try to stop running codes in this worker process */
    public static function closeWorkerLayer(int $workerId): void
    {
        Logger::echo("Start cleanup worker process $workerId before exist and stop ... ");
        self::$metricManager->close();
    }
}
