<?php

namespace App\HttpProxy;

use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use Swoole\Coroutine\Socket;
use Throwable;

class ProxyRemoteSocket
{
    /** Remote host tcp socket connection */
    private ?Socket $remoteSocket = null;

    /** Remote host address or ip */
    protected string $host;

    /** Remote host port number */
    protected int $port;

    /** Remote host connection timeout */
    protected float $timeout = 3;

    /** Remote client connection is established or not */
    public bool $isConnected = false;

    /** Show more logs in debug mode running  */
    public static bool $isDebug = true;

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;


    }

    /** Initialize remote socket connection */
    public function connect(): bool
    {
        try {
            self::debugLog("Connecting to remote proxy socket $this->host:$this->port ...",LogLevel::INFO);
            $this->remoteSocket = new Socket(AF_INET, SOCK_STREAM, 0);
            $this->isConnected = $this->remoteSocket->connect($this->host, $this->port, $this->timeout);
            if (!$this->isConnected)
                self::debugLog("Remote connection failed on $this->host:$this->port",LogLevel::WARNING);
            if ($this->isConnected)
                self::debugLog("Remote socket client connection established successfully on $this->host:$this->port",LogLevel::INFO);
            return $this->isConnected;
        } catch (Throwable $exception) {
            self::debugLog("Error in connecting to remote socket host $this->host:$this->port : {$exception->getMessage()}",LogLevel::ERROR);
            return false;
        }
    }

    /** Client connection still alive and connected */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public static function debugLog(string $message,LogLevel $level): void
    {
        if (self::$isDebug)
            Logger::echo($message, $level);
    }

    /** Close remote socket client connection */
    public function close(): void
    {
        try {
            if (isset($this->remoteSocket)){
                $this->remoteSocket->close();
                unset($this->remoteSocket);
            }
        } catch (Throwable $exception) {
            Logger::echo("Error in closing proxy remote socket : {$exception->getMessage()}");
        }
    }
}