<?php declare(strict_types=1);

namespace App\Master;

use App\BaseServer;
use App\Tools\Config\Config;
use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use App\Types\AbstractProxyServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Throwable;

class MasterServer
{
    /** Master server logger service instance */
    private Logger $logger;

    /** Master proxy tcp server host address */
    public string $host;

    /** Master proxy server port number */
    public int $port;

    /** Master tcp server  */
    public Server $server;

    /** Application container instance */
    public BaseServer $appContext;

    /**
     * Create master server single instance in application
     */
    public function __construct(BaseServer $appContext)
    {
        $this->appContext = $appContext;
        $this->host = Config::$admin_host;
        $this->port = Config::$admin_port;
        $this->logger = new Logger('SERVER');
        $this->server = new Server($this->host, $this->port);
        $this->server->set([
            'worker_num' => 4,
            'hook_flags' => SWOOLE_HOOK_ALL,
            'enable_coroutine' => true,
            'tcp_fastopen' => true,
            'max_request' => 6000,
            'log_level' => SWOOLE_LOG_WARNING,
        ]);
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('request', [$this, 'onRequest']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('WorkerStop', [$this, 'onWorkerStop']);
        $this->server->on('AfterReload', [$this, 'onAfterReload']);
        $this->server->on('BeforeReload', [$this, 'onBeforeReload']);
        $this->server->on('ManagerStart', [$this, 'onManagerStart']);
        $this->server->on('ManagerStop', [$this, 'onManagerStop']);
        $this->server->on('WorkerExit', [$this, 'onWorkerExit']);
        $this->server->on('WorkerError', [$this, 'onWorkerError']);
    }

    /**
     * Handle received http request from client
     */
    public function onRequest(Request $request, Response $response): void
    {
        try {
            $response->header('Content-Type', 'text/html');
            $path = trim($request->server['request_uri']);
            match ($path) {
                '/' => $this->handleGetMetricsRequest($request, $response),
                default => $this->handleNotFoundRequest($response),
            };

        } catch (Throwable $throwable) {
            $response->end("Handle request error : {$throwable->getMessage()}");
        }
    }

    /**
     * Handle get server and worker processes metrics
     */
    public function handleGetMetricsRequest(Request $request, Response $response): void
    {
        /** Handle chrome double request issue */
        if ($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            $response->end();
            return;
        }
        $path = trim($request->server['request_uri'], '/');
        $this->logger->success("HTTP Request received with path $path");

        $metrics = BaseServer::$metricManager->getTotalMetrics();
        // Set the response headers and body
        $response->setHeader('Content-Type', 'application/json');
        $response->end(json_encode($metrics,JSON_PRETTY_PRINT));
    }

    /**
     * Handle master proxy tcp server started event handler
     */
    public function onStart(Server $server): void
    {
        foreach (BaseServer::$proxyServers as $proxyServerName => $proxyServer) {
            /** @var AbstractProxyServer $proxyServer */
            $proxyServer->onProxyStart();
        }
        Logger::echo('All proxy server started',LogLevel::SUCCESS);
    }

    /**
     * Normalize worker process metrics to human-readable metrics
     */
    public function normalizeTotalMetrics(array $metrics): array
    {
        $metrics['total']['download_data_size'] = $this->toHumanReadableSize($metrics['total']['download_size']);
        $metrics['total']['upload_data_size'] = $this->toHumanReadableSize($metrics['total']['upload_size']);
        return $metrics;
    }

    /**
     * Handle not defined route request and create not found response
     */
    public function handleNotFoundRequest(Response $response): void
    {
        $response->setHeader('Content-Type', 'text-html');
        $response->end("Page not found (404)");
    }

    /**
     * Convert bytes format to human-readable size format
     */
    public function toHumanReadableSize(float|int|null $bytes, int $precision = 2): string
    {
        if (empty($bytes))
            return '0';
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format(round($bytes, $precision)) . ' ' . $units[$index];
    }

    /**
     * Master server worker process started event handler
     */
    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->logger->info("Server worker #$workerId started with pid {$server->getWorkerPid()}");
        $this->appContext->initWorkerLayer($workerId);
    }

    /**
     * Master server worker process stopped event handler
     */
    public function onWorkerStop(Server $server, int $workerId): void
    {
        $this->logger->warning("Server worker #$workerId stopped with pid {$server->getWorkerId()}");
    }

    /**
     * Worker process reloaded event handler
     */
    public function onAfterReload(Server $server): void
    {
        $this->logger->warning("Reloading finished in worker {$server->getWorkerId()} and pid {$server->getWorkerPid()}");
    }

    /**
     * Worker process starting reloading event handler (Before reload)
     */
    public function onBeforeReload(Server $server): void
    {
        $this->logger->success("Reloading worker {$server->getWorkerId()} and pid {$server->getWorkerPid()}");
    }

    /**
     * Master server manager worker process started event handler
     */
    public function onManagerStart(Server $server): void
    {
        $this->logger->info("Manager process started with pid {$server->getManagerPid()}");
    }

    /**
     * Master server manager worker process stopped event handler
     */
    public function onManagerStop(Server $server): void
    {
        $this->logger->warning("Manager process stopped with pid {$server->getManagerPid()}");
    }

    /**
     * Master server worker process exited event handler
     */
    public function onWorkerExit(Server $server, int $workerId): void
    {
        $this->logger->warning("[wid:{$server->getWorkerId()}]Worker $workerId with pid {$server->getWorkerPid()} exited");
        BaseServer::closeWorkerLayer($workerId);
    }

    /**
     * Master server worker process error happen event handler
     */
    public function onWorkerError(Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void
    {
        $this->logger->error("Worker Error : [worker_id:$workerId] [worker_pid:$workerPid] [exit_code:$exitCode] [signal:$signal] [server_port:$server->port]");
    }
}