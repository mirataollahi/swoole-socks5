<?php declare(strict_types=1);

namespace App\Tools\Process;

/**
 * Immutable snapshot of a single running server process, as reported by `ps`.
 */
final class ServerProcessInfo
{
    /**
     * @param int $pid Process ID
     * @param int $ppid Parent process ID
     * @param float $cpuPercent CPU usage percentage (ps %CPU)
     * @param float $memPercent Memory usage percentage (ps %MEM)
     * @param int $vszKb Virtual memory size, in KB
     * @param int $rssKb Resident memory (actual RAM in use), in KB
     * @param string $tty Controlling terminal, or "?" if none
     * @param string $stat Process state code (e.g. S, R, Z)
     * @param string $startedAt Process start time, as reported by ps
     * @param string $cpuTime Accumulated CPU time, as reported by ps
     * @param string $command Full command line
     * @param bool $isMaster Whether this is the root of the process tree (Swoole master)
     */
    public function __construct(
        public readonly int    $pid,
        public readonly int    $ppid,
        public readonly float  $cpuPercent,
        public readonly float  $memPercent,
        public readonly int    $vszKb,
        public readonly int    $rssKb,
        public readonly string $tty,
        public readonly string $stat,
        public readonly string $startedAt,
        public readonly string $cpuTime,
        public readonly string $command,
        public readonly bool   $isMaster,
    )
    {
    }

    /**
     * Resident memory (actual RAM in use), in megabytes
     *
     * @return float
     */
    public function rssMb(): float
    {
        return round($this->rssKb / 1024, 1);
    }

    /**
     * Virtual memory size, in megabytes
     *
     * @return float
     */
    public function vszMb(): float
    {
        return round($this->vszKb / 1024, 1);
    }

    /**
     * Human friendly role of this process within the Swoole process tree
     *
     * @return string
     */
    public function role(): string
    {
        return $this->isMaster ? 'master' : 'worker';
    }
}