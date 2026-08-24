<?php declare(strict_types=1);

namespace App;

use App\HttpProxy\HttpProxyServer;
use App\Master\MasterServer;
use App\Metrics\MetricManager;
use App\Tools\Config\Config;
use App\Tools\Logger\Logger;
use App\Types\ProxyServer;

class Application
{
    /** Single application context instance in running context */
    private static Application $appContext;

    /** Application instance logger service */
    public Logger $logger;

    /** Enabled proxy servers */
    public array $proxies = [];

    /** Server worker process count */
    public int $workerCount = 4;

    /** Manager and report worker processes metrics and report sum of them */
    public MetricManager $metrics;

    /** Master tcp server and network layer */
    public MasterServer $masterServer;

    /** Run application and services and then start server */
    private function __construct()
    {
        $this->logger = new Logger('BASE_SERVER');
        $this->logger->info("Starting application .... ");
        $this->masterServer = new MasterServer();
        $this->metrics = new MetricManager($this->workerCount);

        /** Initialize proxy servers */
        $this->initProxyServers();
    }

    public static function getContext(): Application
    {
        if (!isset(self::$appContext)) {
            self::$appContext = new Application();
        }
        return self::$appContext;
    }

    /** Initialize proxy servers base on defined configs */
    public function initProxyServers(): void
    {
        // if (Config::$socks5_enabled)
            // $this->proxies[ProxyServer::SOCKS5->name] = new Socks5Server($this);


        if (Config::$http_proxy_enabled)
            $this->proxies[ProxyServer::HTTP->name] = new HttpProxyServer($this);
    }

    /** Start master server and worker processes */
    public function start(): void
    {
        $this->logger->info("Starting proxy server ...");
        $this->masterServer->start();
    }

    /** The method run in worker layer and run after worker started */
    public function initWorkerLayer(int $workerId): void
    {
        $this->metrics->initialize($workerId);
    }

    /** Worker process is exiting . Try to stop running codes in this worker process */
    public function closeWorkerLayer(int $workerId): void
    {
        Logger::echo("Start cleanup worker process $workerId before exist and stop ... ");
        $this->metrics->close();
    }
}
