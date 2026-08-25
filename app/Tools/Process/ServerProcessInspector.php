<?php declare(strict_types=1);

namespace App\Tools\Process;

/**
 * Finds and describes running "php runner/server.php" (Swoole) processes
 * by reading the system process table via `ps`.
 *
 * Swoole starts a master process, which forks a manager process, which in
 * turn forks the worker processes - all of them share the exact same
 * command line unless renamed with swoole_set_process_name(). We tell them
 * apart by walking the PPID chain: the "master" is whichever matched
 * process's parent is NOT itself one of the matched processes (i.e. the
 * root of the tree, whose real parent is the shell/console that launched it).
 */
final class ServerProcessInspector
{
    /**
     * @var string Default path fragment used to recognize the server process
     */
    public const DEFAULT_SCRIPT_PATH = 'runner/server.php';

    /**
     * @param string $scriptPath Path fragment used to recognize the server process
     */
    public function __construct(
        private readonly string $scriptPath = self::DEFAULT_SCRIPT_PATH
    )
    {
    }

    /**
     * Whether at least one matching process is currently running
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->findProcesses() !== [];
    }

    /**
     * Get the master process, if the server is running
     *
     * @return ServerProcessInfo|null
     */
    public function findMaster(): ?ServerProcessInfo
    {
        foreach ($this->findProcesses() as $process) {
            if ($process->isMaster) {
                return $process;
            }
        }

        return null;
    }

    /**
     * Get every process (master + manager/workers) belonging to the server,
     * master first, then workers ordered by PID
     *
     * @return ServerProcessInfo[]
     */
    public function findProcesses(): array
    {
        $rows = $this->readProcessTable();

        $matches = array_values(array_filter(
            $rows,
            fn(array $row): bool => str_contains($row['command'], $this->scriptPath)
        ));

        $matchedPids = array_column($matches, 'pid');

        $processes = array_map(
            fn(array $row): ServerProcessInfo => new ServerProcessInfo(
                pid: $row['pid'],
                ppid: $row['ppid'],
                cpuPercent: $row['cpu'],
                memPercent: $row['mem'],
                vszKb: $row['vsz'],
                rssKb: $row['rss'],
                tty: $row['tty'],
                stat: $row['stat'],
                startedAt: $row['start'],
                cpuTime: $row['time'],
                command: $row['command'],
                isMaster: !in_array($row['ppid'], $matchedPids, true),
            ),
            $matches
        );

        usort($processes, function (ServerProcessInfo $a, ServerProcessInfo $b): int {
            if ($a->isMaster !== $b->isMaster) {
                return $a->isMaster ? -1 : 1;
            }

            return $a->pid <=> $b->pid;
        });

        return $processes;
    }

    /**
     * Read and parse the full system process table
     *
     * @return array<int, array{pid: int, ppid: int, cpu: float, mem: float, vsz: int, rss: int, tty: string, stat: string, start: string, time: string, command: string}>
     */
    private function readProcessTable(): array
    {
        $output = $this->runPs();
        if ($output === null || trim($output) === '') {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($output)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // pid ppid %cpu %mem vsz rss tty stat start time <command...>
            $parts = preg_split('/\s+/', $line, 11);
            if ($parts === false || count($parts) < 11) {
                continue;
            }

            [$pid, $ppid, $cpu, $mem, $vsz, $rss, $tty, $stat, $start, $time, $command] = $parts;

            $rows[] = [
                'pid' => (int)$pid,
                'ppid' => (int)$ppid,
                'cpu' => (float)$cpu,
                'mem' => (float)$mem,
                'vsz' => (int)$vsz,
                'rss' => (int)$rss,
                'tty' => $tty,
                'stat' => $stat,
                'start' => $start,
                'time' => $time,
                'command' => $command,
            ];
        }

        return $rows;
    }

    /**
     * Run `ps` and return its raw output, or null if it could not be executed
     * (e.g. shell_exec disabled)
     *
     * @return string|null
     */
    private function runPs(): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $output = @shell_exec(
            'ps -eo pid=,ppid=,pcpu=,pmem=,vsz=,rss=,tty=,stat=,start=,time=,args= 2>/dev/null'
        );

        return is_string($output) ? $output : null;
    }
}