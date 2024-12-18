<?php

use App\BaseServer;

require_once __DIR__ . '/composer/vendor/autoload.php';

Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
date_default_timezone_set('Asia/Tehran');
require_once __DIR__.'/config.php';
BaseServer::run();
