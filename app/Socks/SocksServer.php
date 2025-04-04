<?php

namespace App\Socks;

use App\BaseServer;
use App\Tools\Config\EnvManager;
use App\Tools\Logger\Logger;
use Swoole\Server;
use Swoole\Server\Port;

class SocksServer
{
    public Port $server;
    public string $host;
    public int $port;
    public Logger $logger;
    public array $clients = [];
    public BaseServer $appContext;


    public function __construct(BaseServer $appContext)
    {
        $this->appContext = $appContext;
        $this->logger = new Logger('SOCKS_SERVER');
        $this->initServer();
        $this->logger->info("Starting socks server at $this->host:$this->port");
    }


    public function initServer(): void
    {
        $this->host = EnvManager::getEnv('SOCKET_HOST', BaseServer::$socksHost);
        $this->port = EnvManager::getEnv('SOCKET_PORT', BaseServer::$socksPort);
        $this->server = BaseServer::$masterServer->server->addlistener($this->host,$this->port,SWOOLE_SOCK_TCP);

        $this->server->set([
            'hook_flags' => SWOOLE_HOOK_ALL,
            'enable_coroutine' => true,
            'tcp_fastopen' => true,
        ]);
        $this->server->on('Connect', [$this, 'onConnect']);
        $this->server->on('Receive', [$this, 'onReceive']);
        $this->server->on('close', [$this, 'onClose']);

    }

    public function onConnect(Server $server, int $fd , int $reactorId): void
    {
        if (!array_key_exists($fd,$this->clients)){
            $this->clients[$fd] = new Socks5Client($server , $fd,$reactorId,$server->getWorkerId(),$server->getClientInfo($fd));
        }
        $this->logger->info("TCP Client #$fd connected at reactor id $reactorId and worker {$server->getWorkerId()}");
    }

    public function onReceive(Server $server, int $fd , int $reactorId,string $data): void
    {
        if (array_key_exists($fd,$this->clients)){
            /** @var Socks5Client $client */
            $client = $this->clients[$fd];
            $client->onReceive($data);
        }
        $this->logger->info("📥 New TCP Packet receive from fd $fd and reactor_id $reactorId and wid {$server->getWorkerId()}");
    }

    public function onClose(Server $server, int $fd): void
    {
        if (array_key_exists($fd , $this->clients)){
            $this->clients[$fd]?->close();
            unset($this->clients[$fd]);
        }
        $this->logger->info("Client #$fd closed in worker {$server->getWorkerId()}");
    }
}
