<?php

namespace App\Types;

use App\BaseServer;
use App\Tools\Config\Config;
use App\Tools\Logger\Logger;
use Swoole\Server;
use Swoole\Server\Port;

abstract class AbstractProxyServer
{
    public Port $server;
    public Logger $logger;
    public string $host;
    public int $port;
    public string $serverName = 'proxy_server';

    /** Online client connection on this worker process */
    public array $clients = [];

    /** Handle received packet to the tcp proxy server */
    abstract public function onPacket(ProxyClient $client , string $packet): void;

    /** Create a proxy client connection handle instance */
    abstract public function createProxyClient(int $fd,Server $server): ProxyClient;

    /** Proxy server started event handler */
    abstract public function onProxyStart();

    /** Create an instance of proxy server */
    public function __construct(public BaseServer $appContext)
    {
        $this->logger = new Logger(strtoupper($this->serverName));
        $this->initialize();
        $this->initializeServer();
    }

    /** Config proxy server events and configs */
    public function initializeServer(): void
    {
        $this->server = BaseServer::$masterServer->server->addlistener($this->host,$this->port,SWOOLE_SOCK_TCP);
        $this->server->set($this->getServerConfigs());
        $this->server->on('Connect', [$this, 'onConnect']);
        $this->server->on('Receive', [$this, 'onReceive']);
        $this->server->on('close', [$this, 'onClose']);
    }

    /** Get tcp server configs  */
    public function getServerConfigs(): array
    {
        return [
            'hook_flags' => SWOOLE_HOOK_ALL,
            'enable_coroutine' => true,
            'tcp_fastopen' => true,
        ];
    }

    /** Initialize proxy server configs and events before starting server */
    abstract public function initialize(): void;

    /** Handle tcp client connection closed in the proxy server */
    public function onConnect(Server $server, int $fd , int $reactorId): void
    {
        if (!array_key_exists($fd,$this->clients)){
            $this->clients[$fd] = $this->createProxyClient($fd,$server);
        }
        $this->logger->info("TCP Client #$fd connected at reactor id $reactorId and worker {$server->getWorkerId()}");
    }

    /** Handle received data from proxy client connection */
    public function onReceive(Server $server, int $fd , int $reactorId,string $data): void
    {
        if ($this->isOnline($fd)){
            $this->onPacket($this->clients[$fd],$data);
            $packetSize = strlen($data);
            $this->logger->info("Packet received [fd:$fd] [wid:$reactorId] [pid:{$server->getWorkerPid()}] [size:$packetSize]" );
        }
    }

    /** Check a tcp client connection is still alive and not closed */
    public function isOnline(int $fd): bool
    {
        return array_key_exists($fd,$this->clients);
    }

    /** Get proxy server running host or ip address */
    public function getHost(): string
    {
        return $this->host;
    }

    /** Get proxy server running port number */
    public function getPort(): int
    {
        return $this->port;
    }

    /** Client connection closed in proxy server */
    public function onClose(Server $server, int $fd): void
    {
        if (array_key_exists($fd , $this->clients)){
            $this->clients[$fd]?->close();
            unset($this->clients[$fd]);
        }
        $this->logger->info("Client #$fd closed in worker {$server->getWorkerId()}");
    }
}