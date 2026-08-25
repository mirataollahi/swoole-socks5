<?php declare(strict_types=1);

namespace App\Commands;

use App\Core\Command\BaseCommand;
use App\Tools\Config\Config;
use App\Tools\Process\ServerProcessInfo;
use App\Tools\Process\ServerProcessInspector;

/**
 * Command to start the proxy server - equivalent to running
 * `php runner/server.php` by hand, but with a pre-flight check that
 * refuses to start a second instance on top of one already running.
 */
class ServerStartCommand extends BaseCommand
{
    protected string $commandName = 'server:start';
    protected string $description = 'Start the proxy server';

    /**
     * Constructor
     *
     * @param ServerProcessInspector $inspector Process inspector (defaults to the standard server.php locator)
     */
    public function __construct(
        private readonly ServerProcessInspector $inspector = new ServerProcessInspector()
    )
    {
        $this->description = 'Start the proxy server (php ' . ServerProcessInspector::DEFAULT_SCRIPT_PATH . ')';
    }

    /**
     * Execute the command
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        Config::init();

        $existing = $this->inspector->findMaster();
        if ($existing !== null && !$this->forced()) {
            $this->printAlreadyRunning($existing);

            return 1;
        }

        $scriptPath = $this->resolveScriptPath();
        if (!is_file($scriptPath)) {
            echo self::COLOR_RED . self::COLOR_BOLD . "Server entrypoint not found: " . self::COLOR_RESET . $scriptPath . "\n";

            return 1;
        }

        $this->printStarting($scriptPath);

        return $this->runServer($scriptPath);
    }

    /**
     * Whether the --force flag was passed, to start anyway even if a
     * server process is already running
     *
     * @return bool
     */
    private function forced(): bool
    {
        return in_array('--force', $this->arguments, true) || !empty($this->options['force']);
    }

    /**
     * Resolve the absolute path to the server entrypoint script, relative
     * to the current working directory (the project root, when invoked
     * as `php console server:start`)
     *
     * @return string
     */
    private function resolveScriptPath(): string
    {
        $cwd = getcwd();
        $cwd = $cwd !== false ? rtrim($cwd, '/') : '.';

        return $cwd . '/' . ServerProcessInspector::DEFAULT_SCRIPT_PATH;
    }

    /**
     * Print a warning that the server already appears to be running
     *
     * @param ServerProcessInfo $existing
     * @return void
     */
    private function printAlreadyRunning(ServerProcessInfo $existing): void
    {
        echo "\n" . self::COLOR_BG_YELLOW . self::COLOR_WHITE . self::COLOR_BOLD . " SERVER ALREADY RUNNING " . self::COLOR_RESET . "\n\n";

        $this->printKeyValue('Master PID', (string)$existing->pid);
        $this->printKeyValue('Started At', $existing->startedAt);
        $this->printKeyValue('Command', $existing->command);

        echo "\n" . self::COLOR_DIM . "Run " . self::COLOR_RESET . self::COLOR_BOLD . "php console server:status" . self::COLOR_RESET
            . self::COLOR_DIM . " for full details, or pass " . self::COLOR_RESET . self::COLOR_BOLD . "--force" . self::COLOR_RESET
            . self::COLOR_DIM . " to start another instance anyway." . self::COLOR_RESET . "\n\n";
    }

    /**
     * Print the "starting..." banner before handing off to the server
     *
     * @param string $scriptPath
     * @return void
     */
    private function printStarting(string $scriptPath): void
    {
        echo "\n" . self::COLOR_BG_GREEN . self::COLOR_WHITE . self::COLOR_BOLD . " STARTING SERVER " . self::COLOR_RESET . "\n\n";
        $this->printKeyValue('Script', $scriptPath);
        echo "\n" . self::COLOR_DIM . "Press Ctrl+C to stop." . self::COLOR_RESET . "\n\n";
    }

    /**
     * Run the server entrypoint in the foreground, streaming its output to
     * this process's stdout, and mirror its exit code.
     *
     * @param string $scriptPath
     * @return int Exit code
     */
    private function runServer(string $scriptPath): int
    {
        $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';

        // Prefer pcntl_exec so this process is fully replaced by the server
        // (no lingering wrapper process, signals go straight to the server).
        if (function_exists('pcntl_exec')) {
            pcntl_exec($phpBinary, [$scriptPath]);
            // pcntl_exec only returns on failure
            echo self::COLOR_RED . "Failed to exec server process.\n" . self::COLOR_RESET;

            return 1;
        }

        // Fallback: run as a child process and stream its output live.
        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);
        passthru($command, $exitCode);

        return $exitCode;
    }
}