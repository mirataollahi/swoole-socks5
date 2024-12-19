<?php declare(strict_types=1);

namespace App\Metrics;

use App\BaseServer;
use App\Tools\Logger;
use Swoole\Table;
use Swoole\Timer;

class MetricManager
{
    private int $workerId;
    private static ?Table $metricsTable = null;
    private int $delaySeconds = 3;
    private Logger $logger;
    private int $workerCount;

    public function __construct(int $workerCount)
    {
        $this->logger = new Logger('METRIC_MANAGER');
        $this->workerCount = $workerCount;
        self::$metricsTable = new Table($this->workerCount * 2);
        foreach (Metric::cases() as $metricCase) {
            self::$metricsTable->column($metricCase->value, Table::TYPE_FLOAT);
        }
        self::$metricsTable->create();
    }


    /**
     * The method run in worker layer and after worker started
     * Starts a timer to periodically update metrics for the current worker.
     */
    public function initialize(int $workerId): void
    {
        $this->workerId = $workerId;
        $this->logger->info("Initializing Metrics manager for worker $workerId");

        // Initialize all metrics for this worker to 0
        $resetData = array_fill_keys(Metric::values(), 0);
        self::$metricsTable->set((string)$this->workerId, $resetData);


        // Set up a recurring timer to update metrics
        Timer::tick($this->delaySeconds * 1000, function () {
            $this->updateMetrics();
        });
    }

    /**
     * Updates the metrics for the current worker.
     */
    private function updateMetrics(): void
    {
        $stats = BaseServer::$socksServer->server->stats();
        $metrics = [
            Metric::COROUTINE_NUM->value       => $stats['coroutine_num'] ?? 0,
            Metric::RAM_USAGE->value           => (float)(memory_get_usage(true)),
            Metric::CPU_USAGE->value           => (float)(sys_getloadavg()[0]),
            Metric::CONNECTION_COUNT->value    => $stats['connection_num'] ?? 0,
            Metric::REQUEST_COUNT->value       => $stats['worker_request_count'] ?? 0,
            Metric::RESPONSE_COUNT->value      => $stats['worker_response_count'] ?? 0,
            Metric::ACCEPT_CLIENT_COUNT->value => $stats['accept_count'] ?? 0,
            Metric::ABORT_CLIENT_COUNT->value  => $stats['abort_count'] ?? 0,
            Metric::CLOSE_CLIENT_COUNT->value  => $stats['close_count'] ?? 0,
        ];
        self::$metricsTable->set("$this->workerId", $metrics);
    }

    /**
     * Increments a specific metric for the current worker.
     *
     * @param string $key
     */
    public function increment(string $key): void
    {
        $currentValue = self::$metricsTable->get("$this->workerId", $key) ?? 0;
        self::$metricsTable->set("$this->workerId", [$key => $currentValue + 1]);
    }


    /**
     * Retrieves a specific metric for a given worker (or the current worker if not specified).
     */
    public function get(string $metric, ?int $workerId = null): mixed
    {
        $id = $workerId ?? $this->workerId;
        return self::$metricsTable->get((string)$id, $metric) ?? null;
    }
}