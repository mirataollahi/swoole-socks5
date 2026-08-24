<?php

namespace App\Types;

use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use Swoole\Server;
use Throwable;

/** Proxy client connection instance */
abstract class ProxyClient
{
    /** Client connection logger service */
    public Logger $logger;

    /** Client connection is in closing and free up resources status */
    public bool $isClosing = false;

    /** Initialize proxy tcp client connection  */
    abstract public function initialize(): void;

    /** Free up client using resources like timers or coroutines */
    abstract public function free(int $flags = 0);

    /** handle received tcp packet from the client connection */
    abstract public function onPacket(string $packet): void;

    /** Create a new client connection on this tcp proxy server */
    public function __construct(
        public int    $fd,
        public int    $workerId,
        public int    $pid,
        public string $proxyServerName,
        public Server $server,
        public array  $clientInfo
    )
    {
        $proxyNameSignature = strtoupper($this->proxyServerName);
        $this->logger = new Logger("{$proxyNameSignature}_CLIENT_$this->fd");
    }

    /** Close proxy client connection and free up using resources */
    public function close(int $flags = 0): void
    {
        if ($this->isClosing)
            return;
        $this->isClosing = true;
        Logger::echo("closing client connection $this->fd from proxy server $this->proxyServerName");
        Logger::echo("Free up client #$this->fd resources before destruct on proxy server $this->proxyServerName");
        try {
            $this->free($flags);
            Logger::echo("Client connection $this->fd closed successfully on proxy server $this->proxyServerName", LogLevel::SUCCESS);
        } catch (Throwable $exception) {
            Logger::echo("Error in free up client resources before close [fd:$this->fd] [proxy:$this->proxyServerName] : {$exception->getMessage()}", LogLevel::ERROR);
        }

    }
}