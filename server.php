<?php

use App\BaseServer;

require_once __DIR__.'/vendor/autoload.php';

Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

date_default_timezone_set('Asia/Tehran');


const BASE_PATH = __DIR__;


/** Starting TCP socks server */
new BaseServer();
