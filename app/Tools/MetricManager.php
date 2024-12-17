<?php declare(strict_types=1);

namespace App\Tools;

use Swoole\Table;
use Swoole\Timer;
use Swoole\Coroutine;

class MetricManager
{
    private const METRIC_KEYS = [
        self::COROUTINE_NUM,
        self::SOCKS5_REQUEST_COUNT,
        self::SOCKS5_RESPONSE_COUNT,
        self::RAM_USAGE,
        self::CPU_USAGE,
    ];

    public const COROUTINE_NUM = 'coroutine_num';
    public const SOCKS5_REQUEST_COUNT = 'socks5_request_count';
    public const SOCKS5_RESPONSE_COUNT = 'socks5_response_count';
    public const RAM_USAGE = 'ram_usage';
    public const CPU_USAGE = 'cpu_usage';

    private int $workerId;
    private static ?Table $metricsTable = null;
    private int $delaySeconds = 3;
    private Logger $logger;
    private int $workerCount = 1;

    public function __construct(int $workerCount = 1)
    {
        $this->logger = new Logger('METRIC_MANAGER');
        $this->workerCount = $workerCount;
        // Create persists table to store metrics
        self::$metricsTable = new Table(1024 * $this->workerCount); // Adjust size based on the number of workers
        self::$metricsTable->column(self::COROUTINE_NUM, Table::TYPE_INT);
        self::$metricsTable->column(self::SOCKS5_REQUEST_COUNT, Table::TYPE_INT);
        self::$metricsTable->column(self::SOCKS5_RESPONSE_COUNT, Table::TYPE_INT);
        self::$metricsTable->column(self::RAM_USAGE, Table::TYPE_FLOAT);
        self::$metricsTable->column(self::CPU_USAGE, Table::TYPE_FLOAT);
        self::$metricsTable->create();
    }


    /**
     * The method run in worker layer and after worker started
     * Starts a timer to periodically update metrics for the current worker.
     */
    public function initialize(int $workerId): void
    {
        $this->logger->info("Initializing Metrics manager for worker $workerId");
        $this->workerId = $workerId;

        // Clean all old metrics values with zero
        self::$metricsTable->set((string)$this->workerId, array_fill_keys(self::METRIC_KEYS, 0));


        Timer::tick($this->delaySeconds * 1000, function () {
            $this->updateMetrics();
        });
    }

    /**
     * Updates the metrics for the current worker.
     */
    private function updateMetrics(): void
    {
        $metrics = [
            self::COROUTINE_NUM => Coroutine::stats()['coroutine_num'] ?? 0,
            self::RAM_USAGE => memory_get_usage(true) / 1024 / 1024, // Convert to MB
            self::CPU_USAGE => sys_getloadavg()[0], // 1-minute CPU load average
        ];

        self::$metricsTable->set("$this->workerId", $metrics);
    }

    /**
     * Increments the request count for this worker.
     */
    public function incrementRequestCount(): void
    {
        $this->incrementMetric(self::SOCKS5_REQUEST_COUNT);
    }

    /**
     * Increments the response count for this worker.
     */
    public function incrementResponseCount(): void
    {
        $this->incrementMetric(self::SOCKS5_RESPONSE_COUNT);
    }

    /**
     * Increments a specific metric for the current worker.
     *
     * @param string $key
     */
    private function incrementMetric(string $key): void
    {
        $currentValue = self::$metricsTable->get("$this->workerId", $key) ?? 0;
        self::$metricsTable->set("$this->workerId", [$key => $currentValue + 1]);
    }

    /**
     * Retrieves total metrics across all workers.
     *
     * @return array
     */
    public static function getTotalMetrics(): array
    {
        $totalMetrics = array_fill_keys(self::METRIC_KEYS, 0);

        foreach (self::$metricsTable as $workerId => $metrics) {
            foreach ($metrics as $key => $value) {
                $totalMetrics[$key] += $value;
            }
        }

        return $totalMetrics;
    }

    /**
     * Retrieves metrics for a specific worker.
     *
     * @param int $workerId
     * @return array|null
     */
    public static function getWorkerMetrics(int $workerId): ?array
    {
        return self::$metricsTable->get("$workerId") ?: null;
    }
}