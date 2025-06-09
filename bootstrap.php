<?php

use App\Tools\Config\Config;

/** Require composer classes autoloader php file */
// require_once __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function ($class) {
    // Namespace prefixes and their base directories
    $prefixes = [
        'App\\'  => __DIR__ . '/app/',
        'Test\\' => __DIR__ . '/tests/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        // Does the class use this namespace prefix?
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        // Get the relative class name (without namespace prefix)
        $relativeClass = substr($class, $len);

        // Build the file path
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        // Require the file if it exists
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});


/** Set application base root path directory */
const BASE_PATH = __DIR__;


/** Require php define configs if exists */
if (file_exists(BASE_PATH.'/config.php')){
    require_once BASE_PATH.'/config.php';
}


/** Initialize app config manager and import user env values */
Config::init();

