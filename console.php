<?php declare(strict_types=1);

use App\Commands\ShowConfigsCommand;
use App\Core\Command\CommandManager;

require_once __DIR__ . "/bootstrap.php";

// Create command manager
$manager = new CommandManager();

// Register commands
$manager->register(new ShowConfigsCommand());
$manager->autoRegister(COMMANDS_PATH,COMMANDS_NAMESPACE);

// Parse command line arguments
$args = $_SERVER['argv'];
array_shift($args); // Remove script name

$commandName = $args[0] ?? 'help';
$commandArgs = array_slice($args, 1);

// Execute command
try {
    if ($commandName === 'help' || $commandName === '--help' || $commandName === '-h') {
        $manager->showHelp();
        exit(0);
    }

    if (!$manager->has($commandName)) {
        echo "Error: Command '$commandName' not found\n\n";
        $manager->showHelp();
        exit(1);
    }

    $exitCode = $manager->run($commandName, $commandArgs);
    exit($exitCode);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}