<?php declare(strict_types=1);

namespace App\Commands;

use App\Core\Command\BaseCommand;
use App\Tools\Config\Config;

/**
 * Command to display all configuration settings
 */
class ShowConfigsCommand extends BaseCommand
{
    protected string $commandName = 'config:show';
    protected string $description = 'Display all configuration settings';

    /**
     * @var array List of config properties to display
     */
    private const CONFIG_NAMES = [
        'is_debug',
        'socks5_enabled',
        'socks5_host',
        'socks5_port',
        'socks5_auth_enable',
        'socks5_username',
        'socks5_password',
        'http_proxy_enabled',
        'http_proxy_host',
        'http_proxy_port',
        'admin_host',
        'admin_port',
        'metrics_host',
        'metrics_port'
    ];

    /**
     * Execute the command
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        Config::init();

        $this->printMainHeader();
        $this->printApplicationSettings();
        $this->printSocks5Settings();
        $this->printHttpProxySettings();
        $this->printAdminSettings();
        $this->printMetricsSettings();
        $this->printExtraItems();
        $this->printSummary();

        return 0;
    }

    /**
     * Print main header
     *
     * @return void
     */
    private function printMainHeader(): void
    {
        $this->printBanner([
            "╔══════════════════════════════════════════════════════════════════╗",
            "║                CONFIGURATION SETTINGS OVERVIEW                    ║",
            "╚══════════════════════════════════════════════════════════════════╝",
        ]);
    }

    /**
     * Print application settings
     *
     * @return void
     */
    private function printApplicationSettings(): void
    {
        $this->printSectionHeader("📱 APPLICATION SETTINGS", self::COLOR_CYAN);
        $this->printConfigItems(Config::class, [
            ['property' => 'is_debug', 'label' => 'APP_DEBUG'],
        ]);
        echo "\n";
    }

    /**
     * Print SOCKS5 proxy settings
     *
     * @return void
     */
    private function printSocks5Settings(): void
    {
        $this->printSectionHeader("🔒 SOCKS5 PROXY SERVER", self::COLOR_MAGENTA);
        $this->printConfigItems(Config::class, [
            ['property' => 'socks5_enabled', 'label' => 'SOCKS5_ENABLED'],
            ['property' => 'socks5_host', 'label' => 'SOCKS5_HOST'],
            ['property' => 'socks5_port', 'label' => 'SOCKS5_PORT'],
            ['property' => 'socks5_auth_enable', 'label' => 'SOCKS5_AUTH_ENABLED'],
            ['property' => 'socks5_username', 'label' => 'SOCKS5_USERNAME'],
            [
                'property' => 'socks5_password',
                'label' => 'SOCKS5_PASSWORD',
                'transform' => fn($value) => $value ? '***hidden***' : '',
            ],
        ]);
        echo "\n";
    }

    /**
     * Print HTTP proxy settings
     *
     * @return void
     */
    private function printHttpProxySettings(): void
    {
        $this->printSectionHeader("🌐 HTTP PROXY SERVER", self::COLOR_BLUE);
        $this->printConfigItems(Config::class, [
            ['property' => 'http_proxy_enabled', 'label' => 'HTTP_PROXY_ENABLED'],
            ['property' => 'http_proxy_host', 'label' => 'HTTP_PROXY_HOST'],
            ['property' => 'http_proxy_port', 'label' => 'HTTP_PROXY_PORT'],
        ]);
        echo "\n";
    }

    /**
     * Print admin dashboard settings
     *
     * @return void
     */
    private function printAdminSettings(): void
    {
        $this->printSectionHeader("🖥️  ADMIN DASHBOARD", self::COLOR_YELLOW);
        $this->printConfigItems(Config::class, [
            ['property' => 'admin_host', 'label' => 'ADMIN_HOST'],
            ['property' => 'admin_port', 'label' => 'ADMIN_PORT'],
        ]);
        echo "\n";
    }

    /**
     * Print metrics server settings
     *
     * @return void
     */
    private function printMetricsSettings(): void
    {
        $this->printSectionHeader("📊 METRICS SERVER", self::COLOR_GREEN);
        $this->printConfigItems(Config::class, [
            ['property' => 'metrics_host', 'label' => 'METRICS_HOST'],
            ['property' => 'metrics_port', 'label' => 'METRICS_PORT'],
        ]);
        echo "\n";
    }

    /**
     * Print extra .env items
     *
     * @return void
     */
    private function printExtraItems(): void
    {
        if (!empty(Config::$dotEnvExtraItems)) {
            $this->printSectionHeader("📦 EXTRA ENVIRONMENT ITEMS", self::COLOR_RED);
            echo "  " . self::COLOR_BG_YELLOW . self::COLOR_WHITE . self::COLOR_BOLD . " Found " . count(Config::$dotEnvExtraItems) . " extra item(s) " . self::COLOR_RESET . "\n\n";
            foreach (Config::$dotEnvExtraItems as $key => $value) {
                $this->printConfigItem(strtoupper($key), $value, true, self::COLOR_YELLOW, self::COLOR_WHITE);
            }
            echo "\n";
        }
    }

    /**
     * Print summary footer and statistics
     *
     * @return void
     */
    private function printSummary(): void
    {
        $this->printBanner([
            "╔══════════════════════════════════════════════════════════════════╗",
            "║                      CONFIGURATION LOADED                         ║",
            "╚══════════════════════════════════════════════════════════════════╝",
        ], self::COLOR_BG_BLUE, trailingBlankLine: true);

        $totalConfigs = count(self::CONFIG_NAMES);
        $setConfigs = 0;

        foreach (self::CONFIG_NAMES as $configName) {
            $config = $this->getStaticPropertyValue(Config::class, $configName);
            if ($config['isSet']) {
                $setConfigs++;
            }
        }

        $notSetConfigs = $totalConfigs - $setConfigs;

        echo self::COLOR_DIM . "Summary: " . self::COLOR_RESET;
        echo self::COLOR_BOLD . $totalConfigs . self::COLOR_RESET . " configs total | ";
        echo self::COLOR_GREEN . self::COLOR_BOLD . $setConfigs . self::COLOR_RESET . " set | ";
        if ($notSetConfigs > 0) {
            echo self::COLOR_RED . self::COLOR_BOLD . $notSetConfigs . self::COLOR_RESET . " not set | ";
        }

        $debugConfig = $this->getStaticPropertyValue(Config::class, 'is_debug');
        $isDebug = $debugConfig['isSet'] && $debugConfig['value'];
        echo "Debug mode: " . ($isDebug ? self::COLOR_GREEN . "ON" : self::COLOR_RED . "OFF") . self::COLOR_RESET . "\n\n";
    }
}