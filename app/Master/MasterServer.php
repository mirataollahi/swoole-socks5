<?php

namespace App\Master;

use App\BaseServer;
use App\Metrics\Metric;
use App\Tools\EnvManager;
use App\Tools\Logger;
use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

class MasterServer
{
    private Logger $logger;
    public string $host;
    public int $port;
    public Server $server;
    public BaseServer $appContext;

    public function __construct(BaseServer $appContext)
    {
        $this->appContext = $appContext;
        $this->host = EnvManager::getEnv('ADMIN_HOST');
        $this->port = intval(EnvManager::getEnv('ADMIN_PORT'));
        $this->logger = new Logger('HTTP_PANEL');
        $this->server = new Server($this->host,$this->port);
        $this->server->set([
            'worker_num' => 4,
            'hook_flags' => SWOOLE_HOOK_ALL,
            'enable_coroutine' => true,
            'tcp_fastopen' => true,
            'max_request' => 6000,
            'log_level' => SWOOLE_LOG_WARNING,
        ]);
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('request', [$this, 'on_request']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('WorkerStop', [$this, 'onWorkerStop']);
        $this->server->on('AfterReload', [$this, 'onAfterReload']);
        $this->server->on('BeforeReload', [$this, 'onBeforeReload']);
        $this->server->on('ManagerStart', [$this, 'onManagerStart']);
        $this->server->on('ManagerStop', [$this, 'onManagerStop']);
        $this->server->on('WorkerExit', [$this, 'onWorkerExit']);
        $this->server->on('WorkerError', [$this, 'onWorkerError']);
    }
    public function on_request(Request $request, Response $response): void
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

    public function handleGetMetricsRequest(Request $request, Response $response): void
    {
        $this->logger->info("Handling http web panel get metrics request ...");

        $metrics = [
            'total' => []
        ];

        // Fetch global stats once
        $serverStats = BaseServer::$masterServer->server->stats();
        $metrics['total']['download_size'] = $serverStats['total_recv_bytes'] ?? 0;
        $metrics['total']['upload_size'] = $serverStats['total_send_bytes'] ?? 0;

        $workerCount = BaseServer::$workerCount;
        $metricKeys = Metric::cases();

        foreach ($metricKeys as $metricCase) {
            // Ensure total for this metric is initialized to zero
            if (!isset($metrics['total'][$metricCase->value])) {
                $metrics['total'][$metricCase->value] = 0;
            }

            for ($workerId = 0; $workerId < $workerCount; $workerId++) {
                // Retrieve the metric for the specific worker
                $metricValue = BaseServer::$metricManager->get($metricCase->value, $workerId) ?? 0;

                // Store the worker's metric
                $metrics["worker_$workerId"][$metricCase->value] = $metricValue;

                // Accumulate the total
                $metrics['total'][$metricCase->value] += $metricValue;
            }
        }

        // Perform any normalization or post-processing on the totals
        $metrics = $this->normalizeTotalMetrics($metrics);

        // Set the response headers and body
        $response->setHeader('Content-Type', 'application/json');
        $response->end(json_encode($metrics));
    }

    public function onStart(Server $server): void
    {
        $socksHost = BaseServer::$socksServer->server->host;
        $socksPort = BaseServer::$socksServer->server->port;
        $this->logger->success("Socks5 server started at $socksHost:$socksPort");
        $this->logger->success("Master server started at $server->host:$server->port");
    }
    public function normalizeTotalMetrics(array $metrics): array
    {
        $metrics['total']['download_data_size'] = $this->toHumanReadableSize($metrics['total']['download_size']);
        $metrics['total']['upload_data_size'] = $this->toHumanReadableSize($metrics['total']['upload_size']);
        return $metrics;
    }


    public function handleNotFoundRequest(Response $response): void
    {
        $response->setHeader('Content-Type', 'text-html');
        $response->end("Page not found (404)");
    }

    public function toHumanReadableSize(float|int|null $bytes, int $precision = 2): string
    {
        if (empty($bytes))
            return 0;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format(round($bytes, $precision)) . ' ' . $units[$index];
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->logger->info("Server worker #$workerId started with pid {$server->getWorkerPid()}");
        $this->appContext->initWorkerLayer($workerId);
    }

    public function onWorkerStop(Server $server, int $workerId): void
    {
        $this->logger->warning("Server worker #$workerId stopped with pid {$server->getWorkerId()}");
    }

    public function onAfterReload(Server $server): void
    {
        $this->logger->warning("Reloading finished in worker {$server->getWorkerId()} and pid {$server->getWorkerPid()}");
    }

    public function onBeforeReload(Server $server): void
    {
        $this->logger->success("Reloading worker {$server->getWorkerId()} and pid {$server->getWorkerPid()}");
    }

    public function onManagerStart(Server $server): void
    {
        $this->logger->info("Manager process started with pid {$server->getManagerPid()}");
    }

    public function onManagerStop(Server $server): void
    {
        $this->logger->warning("Manager process stopped with pid {$server->getManagerPid()}");
    }

    public function onWorkerExit(Server $server, int $workerId): void
    {
        $this->logger->warning("[wid:{$server->getWorkerId()}]Worker $workerId with pid {$server->getWorkerPid()} exited");
    }

    public function onWorkerError(Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void
    {
        $this->logger->error("Worker Error : [worker_id:$workerId] [worker_pid:$workerPid] [exit_code:$exitCode] [signal:$signal] [server_port:$server->port]");
    }

}