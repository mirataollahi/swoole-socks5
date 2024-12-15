<?php

use App\BaseServer;

require_once __DIR__ . '/vendor/autoload.php';

Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);

date_default_timezone_set('Asia/Tehran');

/**
 * Sock5 Proxy server Network
 */
$host = '0.0.0.0'; // Socks5 host address
$port = 8700; // Socket5 port address


/**
 * Auth info
 */
$username = null; // Socks5 username (optional)
$password = null; // Socks5 password (optional)


BaseServer::run($host,$port,$username,$password);
