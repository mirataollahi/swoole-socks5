<?php declare(strict_types=1);

namespace App\Core\Command;

use FilesystemIterator;
use ReflectionClass;
use ReflectionException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Command manager for registering and executing CLI commands
 */
class CommandManager
{
    /**
     * @var array Registered commands
     */
    private array $commands = [];

    /**
     * Register a command
     *
     * @param BaseCommand $command Command instance
     * @return void
     */
    public function register(BaseCommand $command): void
    {
        $this->commands[$command->getCommandName()] = $command;
    }

    /**
     * Check if command exists
     *
     * @param string $name Command name
     * @return bool True if command exists
     */
    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /**
     * Get command by name
     *
     * @param string $name Command name
     * @return BaseCommand Command instance
     * @throws RuntimeException When command not found
     */
    public function get(string $name): BaseCommand
    {
        if (!$this->has($name)) {
            throw new RuntimeException("Command '$name' not found");
        }
        return $this->commands[$name];
    }

    /**
     * Get all registered commands
     *
     * @return array All commands
     */
    public function getAll(): array
    {
        return $this->commands;
    }

    /**
     * Run a command
     *
     * @param string $name Command name
     * @param array $arguments Command arguments
     * @param array $options Command options
     * @return int Exit code
     */
    public function run(string $name, array $arguments = [], array $options = []): int
    {
        $command = $this->get($name);
        $command->setArguments($arguments);
        $command->setOptions($options);
        return $command->execute();
    }

    /**
     * Display help for all commands
     *
     * @return void
     */
    public function showHelp(): void
    {
        echo "Available Commands:\n";
        echo "===================\n";
        foreach ($this->commands as $name => $command) {
            echo str_pad($name, 20) . " - " . $command->getDescription() . "\n";
        }
    }

    /**
     * Discover and register every concrete BaseCommand subclass found under
     * a directory, so individual commands never need to be registered by
     * hand. Skips BaseCommand itself (abstract), any other abstract class,
     * and any file that doesn't declare an instantiable BaseCommand
     * subclass. A command whose constructor requires arguments it can't
     * be given automatically is skipped rather than failing the whole scan.
     *
     * @param string|null $directory Directory to scan (defaults to this class's own directory)
     * @param string $namespace Namespace command classes live under (defaults to this class's own namespace)
     * @return string[] Command names that were registered
     * @throws RuntimeException When the directory doesn't exist
     */
    public function autoRegister(?string $directory, string $namespace): array
    {
        var_dump($namespace);
        $directory = rtrim($directory ?? COMMANDS_PATH, DIRECTORY_SEPARATOR);

        if (!is_dir($directory)) {
            throw new RuntimeException("Commands directory not found: $directory");
        }

        $registered = [];

        foreach ($this->findPhpFiles($directory) as $file) {
            $className = $this->resolveClassName($file, $directory, $namespace);

            if ($className === null || !$this->isRegistrableCommand($className)) {
                continue;
            }

            $command = $this->instantiateCommand($className);
            if ($command === null) {
                continue;
            }

            $this->register($command);
            $registered[] = $command->getCommandName();
        }

        return $registered;
    }

    /**
     * Recursively find every .php file under a directory
     *
     * @param string $directory Directory to scan
     * @return string[] Absolute file paths, sorted
     */
    private function findPhpFiles(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Resolve the fully qualified class name a file is expected to declare,
     * based on its path relative to the scanned directory, loading the file
     * if the class isn't already known (e.g. no Composer autoload entry)
     *
     * @param string $file Absolute file path
     * @param string $baseDirectory Directory that was scanned (maps to $namespace)
     * @param string $namespace Base namespace for classes under $baseDirectory
     * @return string|null Fully qualified class name, or null if it can't be resolved
     */
    private function resolveClassName(string $file, string $baseDirectory, string $namespace): ?string
    {
        $relativePath = ltrim(substr($file, strlen($baseDirectory)), DIRECTORY_SEPARATOR);
        $relativePath = substr($relativePath, 0, -4); // strip ".php"
        $classSuffix = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        $className = rtrim($namespace, '\\') . '\\' . $classSuffix;

        if (!class_exists($className)) {
            require_once $file;
        }

        return class_exists($className) ? $className : null;
    }

    /**
     * Whether a class name is a concrete (instantiable) BaseCommand subclass
     *
     * @param string $className Fully qualified class name
     * @return bool
     */
    private function isRegistrableCommand(string $className): bool
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            return false;
        }

        return $reflection->isSubclassOf(BaseCommand::class) && $reflection->isInstantiable();
    }

    /**
     * Instantiate a command class with no constructor arguments, returning
     * null instead of throwing if that isn't possible (e.g. it declares
     * required constructor parameters with no defaults)
     *
     * @param string $className Fully qualified class name
     * @return BaseCommand|null
     */
    private function instantiateCommand(string $className): ?BaseCommand
    {
        try {
            $command = new $className();
        } catch (Throwable $e) {
            return null;
        }

        return $command instanceof BaseCommand ? $command : null;
    }
}