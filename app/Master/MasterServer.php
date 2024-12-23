<?php

namespace App\Master;

use App\BaseServer;
use App\Metrics\Metric;
use App\Tools\Helper;
use App\Tools\Logger;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

class MasterServer
{
    private Logger $logger;
    public string $host;
    public int $port;

    public function __construct()
    {
        $this->host = Helper::getEnv('ADMIN_HOST');
        $this->port = intval(Helper::getEnv('ADMIN_PORT'));
        $this->logger = new Logger('HTTP_PANEL');
        $httpServer = BaseServer::$socksServer->server->addlistener(BaseServer::$socksHost, BaseServer::$socksPort + 1, SWOOLE_SOCK_TCP);
        if ($httpServer === false) {
            throw new RuntimeException("Couldn't run http server for web panel");
        }
        $httpServer->set([
            'open_http_protocol' => true,
        ]);
        $httpServer->on('request', [$this, 'on_request']);
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
        $serverStats = BaseServer::$socksServer->server->stats();
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

}