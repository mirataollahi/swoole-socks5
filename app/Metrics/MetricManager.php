<?php declare(strict_types=1);

namespace App\Metrics;

use App\BaseServer;
use App\Tools\Helpers\Utils;
use App\Tools\Logger\Logger;
use App\Types\SafeDestructInterface;
use Swoole\Table;
use Swoole\Timer;

class MetricManager implements SafeDestructInterface
{
    /**
     * Current server context worker process id
     */
    private int $workerId;

    /**
     * Server workers process metrics table . Metrics may have different value in each worker .
     * So the metrics table have the columns : worker_id , metric_name , metric_value
     */
    private ?Table $metricsTable = null;

    /**
     * Logger instance in metrics manager
     */
    private Logger $logger;

    /**
     * Master server worker process count
     */
    private int $workerCount;

    /**
     * Return all workers metrics beside total metrics
     */
    public bool $showWorkersMetrics = false;

    /**
     * Total workers metrics store in this table in will update in a certain duration
     */
    public Table $cacheTable;

    /** Metrics cache table last update unix timestamp */
    public int $lastCacheUpdateAt = 0;

    /** Metric sync or updater interval timer id */
    public int $metricSyncerTimerId;

    /**
     * Metrics key store in the cache with the prefix text
     */
    public string $cachePrefix = 'server_';

    /** Collect and sync and updating worker process metrics interval timer delay in seconds format */
    public int $syncWorkerMetricsDelaySeconds = 5;


    public function __construct(int $workerCount)
    {
        $this->logger = new Logger('METRIC_MANAGER');
        $this->workerCount = $workerCount;

        // Create main metrics table
        $this->metricsTable = new Table(2048 * $this->workerCount);
        foreach (Metric::cases() as $metricCase) {
            $this->metricsTable->column($metricCase->value, Table::TYPE_FLOAT);
        }
        $this->metricsTable->create();


        // Create metrics cache table
        $this->cacheTable = new Table(1024 * $this->workerCount);
        $this->cacheTable->column('name', Table::TYPE_STRING,128);
        $this->cacheTable->column('value', Table::TYPE_FLOAT);
        $this->cacheTable->column('updated_at', Table::TYPE_INT);
        $this->cacheTable->create();
    }


    /**
     * The method run in worker layer and after worker started
     * Starts a timer to periodically update metrics for the current worker.
     */
    public function initialize(int $workerId): void
    {
        $this->workerId = $workerId;
        $this->logger->info("Initializing Metric manager for worker $workerId");

        // Initialize all metrics for this worker to 0
        $resetData = array_fill_keys(Metric::values(), 0);
        $this->metricsTable->set((string)$this->workerId, $resetData);


        // Create a timer for store worker process metrics in table
        $this->metricSyncerTimerId = Timer::tick($this->syncWorkerMetricsDelaySeconds * 1000, [$this, 'updateWorkerMetrics']);
    }

    /**
     * Store current worker process metrics in metrics table own row
     */
    private function updateWorkerMetrics(): void
    {
        Utils::safeCode(function () {
            $stats = BaseServer::$masterServer->server->stats();
            $this->set(Metric::COROUTINE_NUM , $stats['coroutine_num'] ?? 0);
            $this->set(Metric::RAM_USAGE , (float)(memory_get_usage(true)));
            $this->set(Metric::CPU_USAGE , (float)(sys_getloadavg()[0]));
            $this->set(Metric::CONNECTION_COUNT , $stats['connection_num'] ?? 0);
            $this->set(Metric::REQUEST_COUNT , $stats['worker_request_count'] ?? 0);
            $this->set(Metric::RESPONSE_COUNT , $stats['worker_response_count'] ?? 0);
            $this->set(Metric::ACCEPT_CLIENT_COUNT , $stats['accept_count'] ?? 0);
            $this->set(Metric::ABORT_CLIENT_COUNT , $stats['abort_count'] ?? 0);
            $this->set(Metric::CLOSE_CLIENT_COUNT , $stats['close_count'] ?? 0);
        });
    }

    /**
     * Sync and update metrics cache table
     */
    public function updateCacheTable(array $metrics): void
    {
        $this->lastCacheUpdateAt = time();
        foreach ($metrics as $metricName => $metricValue) {
            $this->cacheTable->set($metricName, [
                'name' => $metricName,
                'value' => $metricValue,
                'updated_at' => time(),
            ]);
        }
    }

    /**
     * Get all workers metrics summery from table and calculate total workers metrics list
     */
    public function collectWorkerMetrics(): array
    {
        $serverMetrics = [];
        $workersMetrics = [];
        foreach (Metric::cases() as $metricItem) {
            for ($workerId = 0; $workerId < $this->workerCount; $workerId++) {
                // Set server metrics
                if (!array_key_exists($metricItem->value, $serverMetrics)) {
                    $serverMetrics[$metricItem->value] = 0;
                } else {
                    $serverMetrics[$metricItem->value] += $this->get($metricItem,$workerId);
                }

                // set worker metrics
                $workersMetrics["{$metricItem->value}_$workerId"] = $this->get($metricItem,$workerId);

            }
        }
        $serverMetrics['worker_count'] = $this->workerCount;
        $serverMetrics[Metric::RAM_USAGE->value . '_mb'] = Utils::formatBytes($serverMetrics[Metric::RAM_USAGE->value]);

        $this->updateCacheTable($serverMetrics);
        return $serverMetrics;
    }

    /**
     * Increments a specific metric for the current worker.
     */
    public function increment(Metric $metric, ?int $workerId = null): void
    {
        $workerId = $workerId ?: $this->workerId;
        $this->metricsTable->incr("$workerId", $metric->value);
    }

    /**
     * Decrement current metrics value in a worker id
     */
    public function decrement(Metric $metric, ?int $workerId = null): void
    {
        $workerId = $workerId ?: $this->workerId;
        $this->metricsTable->decr("$workerId", $metric->value);
    }

    /**
     * Set a metrics value in metrics table in a worker id
     */
    public function set(Metric $metric, int|float $number, ?int $workerId = null): void
    {
        $workerId = $workerId ?: $this->workerId;
        $number = floatval($number);
        $this->metricsTable->set("$workerId", [$metric->value => $number]);
    }

    /**
     * Retrieves a specific metric for a given worker (or the current worker if not specified).
     */
    public function get(Metric $metric, ?int $workerId = null): mixed
    {
        $workerId = $workerId ?: $this->workerId;
        return $this->metricsTable->get("$workerId", $metric->value) ?? false;
    }

    /**
     * Get total workers metrics from cache . if metrics cache expired ,
     * Collect workers metrics again and store them in cache  .
     */
    public function getTotalMetrics(): array
    {
        $secondsSinceLastUpdate = time() - $this->lastCacheUpdateAt;
        if ($secondsSinceLastUpdate > 5) {
            return $this->collectWorkerMetrics();
        } else {
            // Get metrics from cache
            $metrics = [];
            foreach ($this->cacheTable as $key => $cacheData) {
                $metrics[$key] = $cacheData['value'];
            }
            return $metrics;
        }
    }

    /**
     * Clean running metric updater timer and intervals
     */
    public function close(): void
    {
        Utils::safeCode(function () {
            // Clear running updater interval timer id
            if (isset($this->metricSyncerTimerId)) {
                if (Timer::exists($this->metricSyncerTimerId)) {
                    Timer::clear($this->metricSyncerTimerId);
                    unset($this->metricSyncerTimerId);
                }
            }
        });
    }
}