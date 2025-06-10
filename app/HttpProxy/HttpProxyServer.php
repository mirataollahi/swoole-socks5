<?php

namespace App\HttpProxy;

use App\Tools\Config\Config;
use App\Types\AbstractProxyServer;
use App\Types\ProxyClient;
use Swoole\Server;

class HttpProxyServer extends AbstractProxyServer
{
    /** HTTP Proxy server name */
    public string $serverName = 'http';
    /** Init HTTP proxy server configs */
    public function initialize(): void
    {
        $this->host = Config::$http_proxy_host;
        $this->port = Config::$http_proxy_port;
    }

    /** Create socks5 proxy client connection instance */
    public function createProxyClient(int $fd,Server $server): HttpProxyClient
    {
        return new HttpProxyClient(
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
        $this->logger->success("HTTP proxy server started at $this->host:$this->port");
    }

}
