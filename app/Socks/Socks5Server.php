<?php

namespace App\Socks;

use App\Tools\Config\Config;
use App\Types\AbstractProxyServer;
use App\Types\ProxyClient;
use Swoole\Server;

class Socks5Server extends AbstractProxyServer
{
    /** Socks5 proxy server name */
    public string $serverName = 'socks5';

    /** Initialize socks5 proxy tcp server configs and events */
    public function initialize(): void
    {
        $this->host = Config::$socks5_host;
        $this->port = Config::$socks5_port;
    }

    /** Create socks5 proxy client connection instance */
    public function createProxyClient(int $fd,Server $server): Socks5Client
    {
        return new Socks5Client(
            fd: $fd,
            workerId: $server->getWorkerId(),
            pid: $server->getWorkerPid(),
            proxyServerName: $this->serverName,
            server: $server,
            clientInfo: $server->getClientInfo($fd),
        );
    }

    /** Handle received packet in the socks5 proxy server from client connection */
    public function onPacket(ProxyClient $client,string $packet): void
    {
        $client->onPacket($packet);
    }

    /** Handle socks5 server started event */
    public function onProxyStart(): void
    {
        $this->logger->success("Socks5 proxy server started at $this->host:$this->port");
    }

}
