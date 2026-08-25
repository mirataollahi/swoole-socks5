<?php declare(strict_types=1);

namespace App\Core\Command;

use ReflectionProperty;
use Throwable;

/**
 * Base command class for all CLI commands
 */
abstract class BaseCommand
{
    /**
     * @var string Command name used to invoke the command
     */
    protected string $commandName = '';

    /**
     * @var string Command description shown in help
     */
    protected string $description = '';

    /**
     * @var array Command arguments passed from CLI
     */
    protected array $arguments = [];

    /**
     * @var array Command options passed from CLI
     */
    protected array $options = [];

    /**
     * @var string ANSI color/style codes available to all commands
     */
    protected const COLOR_RESET = "\033[0m";
    protected const COLOR_BOLD = "\033[1m";
    protected const COLOR_DIM = "\033[2m";
    protected const COLOR_RED = "\033[31m";
    protected const COLOR_GREEN = "\033[32m";
    protected const COLOR_YELLOW = "\033[33m";
    protected const COLOR_BLUE = "\033[34m";
    protected const COLOR_MAGENTA = "\033[35m";
    protected const COLOR_CYAN = "\033[36m";
    protected const COLOR_WHITE = "\033[37m";
    protected const COLOR_BG_BLUE = "\033[44m";
    protected const COLOR_BG_GREEN = "\033[42m";
    protected const COLOR_BG_YELLOW = "\033[43m";
    protected const COLOR_BG_RED = "\033[41m";

    /**
     * Get command name
     *
     * @return string Command name
     */
    public function getCommandName(): string
    {
        return $this->commandName;
    }

    /**
     * Get command description
     *
     * @return string Command description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set command arguments
     *
     * @param array $arguments Command arguments
     * @return void
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    /**
     * Set command options
     *
     * @param array $options Command options
     * @return void
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * Execute the command
     *
     * @return int Exit code (0 = success, non-zero = error)
     */
    abstract public function execute(): int;

    /**
     * Display command help
     *
     * @return void
     */
    public function help(): void
    {
        echo "Command: {$this->commandName}\n";
        echo "Description: {$this->description}\n";
        echo "Usage: php console {$this->commandName}\n";
    }

    /**
     * Print a top-level banner: a set of pre-formatted, already-bordered
     * lines (as produced by printSectionHeader's box style, or built by
     * hand) wrapped in a solid background color. Used for the "app-level"
     * headers/footers that bracket a command's output.
     *
     * @param array $lines Pre-formatted lines (top border, content, bottom border)
     * @param string $bgColor Background ANSI color code
     * @param bool $trailingBlankLine Print an extra blank line after the banner
     * @return void
     */
    protected function printBanner(array $lines, string $bgColor = self::COLOR_BG_BLUE, bool $trailingBlankLine = false): void
    {
        echo $bgColor . self::COLOR_WHITE . self::COLOR_BOLD;
        echo implode("\n", $lines);
        echo self::COLOR_RESET . "\n";

        if ($trailingBlankLine) {
            echo "\n";
        }
    }

    /**
     * Print a section header inside a colored double-line box
     *
     * @param string $title Section title (may include an emoji prefix)
     * @param string $color ANSI color code for the border/title
     * @param int $width Interior width of the box
     * @return void
     */
    protected function printSectionHeader(string $title, string $color, int $width = 70): void
    {
        $padding = str_repeat('═', $width);
        $titleLength = strlen($title);
        $leftPadding = intval(($width - $titleLength - 2) / 2);
        $rightPadding = $width - $titleLength - 2 - $leftPadding;

        echo "\n" . $color . self::COLOR_BOLD;
        echo "╔" . $padding . "╗\n";
        echo "║" . str_repeat(' ', $leftPadding) . $title . str_repeat(' ', $rightPadding) . "║\n";
        echo "╚" . $padding . "╝";
        echo self::COLOR_RESET . "\n";
    }

    /**
     * Print a single "key : value [SET/NOT SET]" row
     *
     * @param string $key Row label
     * @param mixed $value Row value
     * @param bool $isSet Whether the underlying value is actually set
     * @param string $keyColor ANSI color for the key
     * @param string $valueColor ANSI color for the value
     * @return void
     */
    protected function printConfigItem(string $key, mixed $value, bool $isSet, string $keyColor = self::COLOR_WHITE, string $valueColor = self::COLOR_GREEN): void
    {
        $keyPadding = str_pad($key, 30, ' ', STR_PAD_RIGHT);
        echo "  " . $keyColor . $keyPadding . self::COLOR_RESET . " : ";

        if (!$isSet) {
            echo self::COLOR_RED . self::COLOR_BOLD . str_pad('(not set)', 20, ' ', STR_PAD_RIGHT) . self::COLOR_RESET;
        } elseif (is_bool($value)) {
            $boolColor = $value ? self::COLOR_GREEN : self::COLOR_RED;
            $boolText = $value ? '✓ ENABLED' : '✗ DISABLED';
            echo $boolColor . self::COLOR_BOLD . str_pad($boolText, 20, ' ', STR_PAD_RIGHT) . self::COLOR_RESET;
        } elseif ($value === null) {
            echo self::COLOR_DIM . str_pad('(null)', 20, ' ', STR_PAD_RIGHT) . self::COLOR_RESET;
        } elseif ($value === '') {
            echo self::COLOR_DIM . str_pad('(empty)', 20, ' ', STR_PAD_RIGHT) . self::COLOR_RESET;
        } elseif (is_numeric($value)) {
            echo self::COLOR_YELLOW . self::COLOR_BOLD . str_pad((string)$value, 20, ' ', STR_PAD_RIGHT) . self::COLOR_RESET;
        } else {
            echo $valueColor . str_pad($value, 20, ' ', STR_PAD_RIGHT) . self::COLOR_RESET;
        }

        echo " [";
        if ($isSet) {
            echo self::COLOR_GREEN . "✓ SET" . self::COLOR_RESET;
        } else {
            echo self::COLOR_RED . "✗ NOT SET" . self::COLOR_RESET;
        }
        echo "]\n";
    }

    /**
     * Safely read a (possibly typed/uninitialized) static property's value
     * via reflection, without triggering an "uninitialized property" error.
     *
     * @param string $class Fully qualified class name
     * @param string $propertyName Static property name
     * @return array{value: mixed, isSet: bool}
     */
    protected function getStaticPropertyValue(string $class, string $propertyName): array
    {
        try {
            $reflection = new ReflectionProperty($class, $propertyName);
            if ($reflection->isInitialized()) {
                return ['value' => $reflection->getValue(), 'isSet' => true];
            }
        } catch (Throwable $e) {
            // Property or class doesn't exist
        }

        return ['value' => null, 'isSet' => false];
    }

    /**
     * Print a batch of config rows, each read from a static property on
     * $configClass via getStaticPropertyValue().
     *
     * Each entry in $items accepts:
     *   'property'  => string    Static property name on $configClass (required)
     *   'label'     => string    Display label (required)
     *   'keyColor'  => string    Optional ANSI color for the key
     *   'valueColor'=> string    Optional ANSI color for the value
     *   'transform' => callable  Optional value formatter, e.g. to mask secrets
     *
     * @param string $configClass Fully qualified class name holding the static properties
     * @param array $items Items to print, see above
     * @return void
     */
    protected function printConfigItems(string $configClass, array $items): void
    {
        foreach ($items as $item) {
            $config = $this->getStaticPropertyValue($configClass, $item['property']);
            $value = $config['value'];

            if (isset($item['transform']) && is_callable($item['transform'])) {
                $value = ($item['transform'])($value);
            }

            $this->printConfigItem(
                $item['label'],
                $value,
                $config['isSet'],
                $item['keyColor'] ?? self::COLOR_WHITE,
                $item['valueColor'] ?? self::COLOR_GREEN
            );
        }
    }

    /**
     * Print a simple "key : value" row, without the SET/NOT SET badge used
     * by printConfigItem(). Useful for status output (process info, etc.)
     * where every value is already known and there's nothing to flag as
     * missing.
     *
     * @param string $key Row label
     * @param string $value Row value
     * @param string $keyColor ANSI color for the key
     * @param string $valueColor ANSI color for the value
     * @return void
     */
    protected function printKeyValue(string $key, string $value, string $keyColor = self::COLOR_WHITE, string $valueColor = self::COLOR_GREEN): void
    {
        $keyPadding = str_pad($key, 30, ' ', STR_PAD_RIGHT);
        echo "  " . $keyColor . $keyPadding . self::COLOR_RESET . " : " . $valueColor . $value . self::COLOR_RESET . "\n";
    }

}