<?php

use App\Tools\Config\Config;

/** Require composer classes autoloader php file */
require_once __DIR__ . '/vendor/autoload.php';


/** Set application base root path directory */
const BASE_PATH = __DIR__;


/** Require php define configs if exists */
if (file_exists(BASE_PATH.'/config.php')){
    require_once BASE_PATH.'/config.php';
}


/** Initialize app config manager and import user env values */
Config::init();

