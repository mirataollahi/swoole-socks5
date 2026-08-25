<?php declare(strict_types=1);


/**
 * Application Base Constants
 *
 * Core paths and namespaces used throughout the application.
 */

use App\Tools\Config\Config;

const BASE_PATH = __DIR__;
const APP_BASE_DIR_NAME = 'app';
const APP_BASE_NAMESPACE = 'App\\';
const TESTS_BASE_NAMESPACE = 'Test\\';
const APP_BASE_PATH = BASE_PATH . '/' . APP_BASE_DIR_NAME . '/';
const TESTS_BASE_PATH = BASE_PATH . '/tests/';
const COMMANDS_PATH = BASE_PATH . '/' . APP_BASE_DIR_NAME . '/Commands';
const COMMANDS_NAMESPACE = APP_BASE_NAMESPACE . 'Commands\\';


/**
 * Autoloader Registration
 *
 * PSR-4 style autoloader for application and test namespaces.
 */
spl_autoload_register(function ($class) {
    $prefixes = [
        APP_BASE_NAMESPACE => APP_BASE_PATH,
        TESTS_BASE_NAMESPACE => TESTS_BASE_PATH,
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

/**
 * Application Configuration
 * Initialize config manager and load environment settings.
 */
Config::init();

