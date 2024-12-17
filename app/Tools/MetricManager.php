<?php declare(strict_types=1);

namespace App\Tools;

use App\BaseServer;
use Swoole\Table;
use Swoole\Timer;

class MetricManager
{
    public const METRIC_KEYS = [
        self::COROUTINE_NUM,
        self::CONNECTION_COUNT,
        self::REQUEST_COUNT,
        self::RESPONSE_COUNT,
        self::RAM_USAGE,
        self::CPU_USAGE,
        self::RECEIVE_DATE_SIZE,
        self::SENT_DATE_SIZE,
        self::ACCEPT_CLIENT_COUNT,
        self::ABORT_CLIENT_COUNT,
        self::CLOSE_CLIENT_COUNT,
    ];

    /** Define Metrics keys */
    public const COROUTINE_NUM = 'coroutine_num';
    public const CONNECTION_COUNT = 'connection_count';
    public const REQUEST_COUNT = 'request_count';
    public const RESPONSE_COUNT = 'response_count';
    public const RAM_USAGE = 'ram_usage';
    public const CPU_USAGE = 'cpu_usage';
    public const RECEIVE_DATE_SIZE = 'receive_data_size';
    public const SENT_DATE_SIZE = 'sent_data_size';
    public const ACCEPT_CLIENT_COUNT = 'accept_client_count';
    public const ABORT_CLIENT_COUNT = 'abort_client_count';
    public const CLOSE_CLIENT_COUNT = 'close_client_count';


    private int $workerId;
    private static ?Table $metricsTable = null;
    private int $delaySeconds = 3;
    private Logger $logger;
    private int $workerCount;

    public function __construct(int $workerCount)
    {
        $this->logger = new Logger('METRIC_MANAGER');
        $this->workerCount = $workerCount;
        self::$metricsTable = new Table(2048 * $this->workerCount);
        foreach (self::METRIC_KEYS as $metricKey){
            self::$metricsTable->column($metricKey, Table::TYPE_FLOAT);
        }
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
        $stats = BaseServer::$socksServer->server->stats();
        $metrics = [
            self::COROUTINE_NUM => $stats['coroutine_num'] ?? 0,
            self::RAM_USAGE => memory_get_usage(true), // Ram usage in bytes
            self::CPU_USAGE => sys_getloadavg()[0], // 1-minute CPU load average
            self::CONNECTION_COUNT => $stats['connection_num'] ?? 0, // 1-minute CPU load average
            self::REQUEST_COUNT => $stats['worker_request_count'] ?? 0,
            self::RESPONSE_COUNT => $stats['worker_response_count'] ?? 0,
            self::RECEIVE_DATE_SIZE => $stats['total_recv_bytes'] ?? 0,
            self::SENT_DATE_SIZE => $stats['total_send_bytes'] ?? 0,
            self::ACCEPT_CLIENT_COUNT => $stats['accept_count'] ?? 0,
            self::ABORT_CLIENT_COUNT => $stats['abort_count'] ?? 0,
            self::CLOSE_CLIENT_COUNT => $stats['close_count'] ?? 0,
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
     * Retrieves total metrics across all workers.
     *
     * @return array
     */
    public function getTotal(): array
    {
        $totalMetrics = array_fill_keys(self::METRIC_KEYS, 0);

        foreach (self::$metricsTable as $metrics) {
            foreach ($metrics as $key => $value) {
                $totalMetrics[$key] += $value;
            }
        }

        return $totalMetrics;
    }

    /**
     * Retrieves metrics for a specific worker.
     *
     * @param string $metric
     * @param int|null $workerId
     * @return mixed
     */
    public function get(string $metric , ?int $workerId = null): mixed
    {
        $workerId = $workerId ?: $this->workerId;
        return self::$metricsTable->get("$workerId" , $metric) ?: null;
    }
}