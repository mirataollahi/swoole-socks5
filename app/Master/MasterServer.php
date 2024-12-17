<?php
/**
 * User: Mirataollahi ( @Mirataollahi124 )
 * Date: 12/17/24  Time: 5:14 PM
 */

namespace App\Master;

use App\BaseServer;
use App\Tools\Logger;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;

class MasterServer
{
    private Logger $logger;

    public function __construct()
    {
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
        $metrics = [];
        $metrics['total'] = [];


        foreach (BaseServer::$metricManager::METRIC_KEYS as $metricKey) {
            for ($workerId = 0; $workerId < BaseServer::$workerCount; $workerId++) {
                $metricValue = BaseServer::$metricManager->get($metricKey);
                $metrics["worker_$workerId"][$metricKey] = $metricValue;
                if (array_key_exists($metricKey, $metrics['total'])) {
                    $metrics['total'][$metricKey] += $metricValue;
                } else {
                    $metrics['total'][$metricKey] = $metricValue;
                }
            }
        }

        $metrics = $this->normalizeTotalMetrics($metrics);

        $response->setHeader('Content-Type', 'application-json');
        $jsonMetrics = json_encode($metrics);
        $response->end($jsonMetrics);
    }

    public function normalizeTotalMetrics(array $metrics): array
    {
        for ($workerId = 0; $workerId < BaseServer::$workerCount; $workerId++) {
            $workerMetrics = $metrics["worker_$workerId"];
            if (array_key_exists('receive_data_size', $workerMetrics)) {
                $metrics["worker_$workerId"]['receive_data_size'] = $this->toHumanReadableSize($metrics["worker_$workerId"]['receive_data_size']);
            }

            if (array_key_exists('sent_data_size', $workerMetrics)) {
                $metrics["worker_$workerId"]['sent_data_size'] = $this->toHumanReadableSize($metrics["worker_$workerId"]['sent_data_size']);
            }
        }
        return $metrics;
    }


    public function handleNotFoundRequest(Response $response): void
    {
        $response->setHeader('Content-Type', 'text-html');
        $response->end("Page not found (404)");
    }

    public function toHumanReadableSize(float|int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format(round($bytes, $precision)) . ' ' . $units[$index];
    }

}