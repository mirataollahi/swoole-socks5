<?php declare(strict_types=1);

namespace App\Commands;

use App\Core\Command\BaseCommand;
use App\Tools\Config\Config;
use App\Tools\Process\ServerProcessInfo;
use App\Tools\Process\ServerProcessInspector;

/**
 * Command to check whether the proxy server is running and, if so, show
 * details about its running process(es).
 */
class ServerStatusCommand extends BaseCommand
{
    protected string $commandName = 'server:status';
    protected string $description = 'Show whether the server is running and details about its process(es)';

    /**
     * Constructor
     *
     * @param ServerProcessInspector $inspector Process inspector (defaults to the standard server.php locator)
     */
    public function __construct(
        private readonly ServerProcessInspector $inspector = new ServerProcessInspector()
    )
    {
    }

    /**
     * Execute the command
     *
     * @return int Exit code (0 = running, 1 = not running)
     */
    public function execute(): int
    {
        Config::init();

        $processes = $this->inspector->findProcesses();

        $this->printStatusBanner($processes !== []);
        $this->printConfiguredEndpoints();

        if ($processes === []) {
            echo self::COLOR_DIM . "No matching process was found. Start it with: " . self::COLOR_RESET;
            echo self::COLOR_BOLD . "php console server:start" . self::COLOR_RESET . "\n\n";

            return 1;
        }

        $this->printProcesses($processes);
        $this->printSummary($processes);

        return 0;
    }

    /**
     * Print the RUNNING / NOT RUNNING banner
     *
     * @param bool $isRunning
     * @return void
     */
    private function printStatusBanner(bool $isRunning): void
    {
        $label = $isRunning ? ' ● SERVER IS RUNNING ' : ' ○ SERVER IS NOT RUNNING ';
        $bgColor = $isRunning ? self::COLOR_BG_GREEN : self::COLOR_BG_RED;

        echo "\n" . $bgColor . self::COLOR_WHITE . self::COLOR_BOLD . $label . self::COLOR_RESET . "\n";
    }

    /**
     * Print the host:port endpoints the server is configured to bind to
     *
     * @return void
     */
    private function printConfiguredEndpoints(): void
    {
        $this->printSectionHeader("⚙️  CONFIGURED ENDPOINTS", self::COLOR_CYAN);
        $this->printKeyValue('SOCKS5', $this->endpoint('socks5_host', 'socks5_port', 'socks5_enabled'));
        $this->printKeyValue('HTTP PROXY', $this->endpoint('http_proxy_host', 'http_proxy_port', 'http_proxy_enabled'));
        $this->printKeyValue('ADMIN', $this->endpoint('admin_host', 'admin_port'));
        $this->printKeyValue('METRICS', $this->endpoint('metrics_host', 'metrics_port'));
        echo "\n";
    }

    /**
     * Build a "host:port" string for a configured service, or "disabled" /
     * "(not set)" when appropriate
     *
     * @param string $hostProperty Config property holding the host
     * @param string $portProperty Config property holding the port
     * @param string|null $enabledProperty Optional config property gating the service
     * @return string
     */
    private function endpoint(string $hostProperty, string $portProperty, ?string $enabledProperty = null): string
    {
        if ($enabledProperty !== null) {
            $enabled = $this->getStaticPropertyValue(Config::class, $enabledProperty);
            if ($enabled['isSet'] && $enabled['value'] === false) {
                return 'disabled';
            }
        }

        $host = $this->getStaticPropertyValue(Config::class, $hostProperty);
        $port = $this->getStaticPropertyValue(Config::class, $portProperty);

        if (!$host['isSet'] || !$port['isSet']) {
            return '(not set)';
        }

        return $host['value'] . ':' . $port['value'];
    }

    /**
     * Print details for every matched process
     *
     * @param ServerProcessInfo[] $processes
     * @return void
     */
    private function printProcesses(array $processes): void
    {
        $this->printSectionHeader("🧩 PROCESSES (" . count($processes) . ")", self::COLOR_MAGENTA);

        foreach ($processes as $process) {
            $roleLabel = $process->isMaster ? '★ MASTER' : '  WORKER';
            $roleColor = $process->isMaster ? self::COLOR_YELLOW : self::COLOR_WHITE;

            echo "\n" . self::COLOR_BOLD . $roleColor . $roleLabel . self::COLOR_RESET
                . self::COLOR_DIM . "  (PID {$process->pid})" . self::COLOR_RESET . "\n";

            $this->printKeyValue('PID', (string)$process->pid);
            $this->printKeyValue('Parent PID', (string)$process->ppid);
            $this->printKeyValue('CPU', $process->cpuPercent . '%');
            $this->printKeyValue('Memory (RSS)', $process->rssMb() . ' MB');
            $this->printKeyValue('Virtual Memory', $process->vszMb() . ' MB');
            $this->printKeyValue('TTY', $process->tty);
            $this->printKeyValue('State', $process->stat);
            $this->printKeyValue('Started At', $process->startedAt);
            $this->printKeyValue('CPU Time', $process->cpuTime);
            $this->printKeyValue('Command', $process->command);
        }

        echo "\n";
    }

    /**
     * Print a one-line totals summary across all matched processes
     *
     * @param ServerProcessInfo[] $processes
     * @return void
     */
    private function printSummary(array $processes): void
    {
        $totalRssMb = round(array_sum(array_map(fn(ServerProcessInfo $p): int => $p->rssKb, $processes)) / 1024, 1);

        echo self::COLOR_DIM . "Total processes: " . self::COLOR_RESET . self::COLOR_BOLD . count($processes) . self::COLOR_RESET;
        echo " | " . self::COLOR_DIM . "Total memory: " . self::COLOR_RESET . self::COLOR_BOLD . $totalRssMb . " MB" . self::COLOR_RESET . "\n\n";
    }
}