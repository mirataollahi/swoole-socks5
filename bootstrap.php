<?php

use App\Tools\Config\Config;

/** Require composer classes autoloader php file */
require_once __DIR__ . '/composer/vendor/autoload.php';

/** Set application base root path directory */
const BASE_PATH = __DIR__;

require_once __DIR__.'/config.php';



/** Initialize app config manager and import user env values */
Config::init();

/** Set default date timezone */
date_default_timezone_set('Asia/Tehran');


/**
 * Set proxy server configs base :
 * Defined system environments
 * Set in config.php file
 * Pass as cli arguments
 */
