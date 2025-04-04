<?php

use App\BaseServer;

/**
 * Require composer classes autoloader php file
 */
require_once __DIR__ . '/composer/vendor/autoload.php';

/**
 * Set default date timezone
 */
date_default_timezone_set('Asia/Tehran');


/**
 * Set proxy server configs base :
 * Defined system environments
 * Set in config.php file
 * Pass as cli arguments
 */
require_once __DIR__.'/config.php';


/**
 * Run application and master proxy server
 */
BaseServer::run();
