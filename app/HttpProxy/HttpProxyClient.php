<?php

namespace App\HttpProxy;

use App\Types\ProxyClient;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Throwable;

class HttpProxyClient extends ProxyClient
{
    /** Target host socket connection */
    private ?Socket $remoteSocket = null;

    public array $remoteSockets = [];
    private bool $isTunnelEstablished = false;

    /** Initialize http proxy server  */

    public function initialize(): void
    {
        $this->logger->success("HTTP proxy client connected [wid:$this->workerId] [pid:$this->pid]");
    }

    /** HTTP proxy server packet entry point */
    public function onPacket(string $packet): void
    {
        $packetSize = strlen($packet);
        $this->logger->info("Packet received [size:$packetSize]");
        if ($this->isTunnelEstablished) {
            $this->forwardTunnelData($packet);
            return;
        }

        if (preg_match('/^CONNECT\s+([^\s:]+):(\d+)/i', $packet, $matches)) {
            $this->handleHttpsTunnel($matches[1], (int)$matches[2]);
        } elseif (preg_match('/^(GET|POST|HEAD|PUT|DELETE|OPTIONS|PATCH)\s+(\S+)\s+HTTP/i', $packet, $matches)) {
            $this->handleHttpRequest($packet);
        } else {
            $this->sendPacket("HTTP/1.1 400 Bad Request\r\n\r\n");
            $this->safeClose();
        }
    }

    /** handle client https connect tunnel packet command */
    private function handleHttpsTunnel(string $host, int $port): void
    {
        $this->logger->info("Establishing HTTPS tunnel to $host:$port");

        $remote = new Socket(AF_INET, SOCK_STREAM, 0);
        if (!$remote->connect($host, $port, 5)) {
            $this->sendPacket("HTTP/1.1 502 Bad Gateway\r\n\r\n");
            $this->safeClose();
            return;
        }

        $this->remoteSocket = $remote;
        $this->isTunnelEstablished = true;

        $this->sendPacket("HTTP/1.1 200 Connection Established\r\n\r\n");

        // Pipe remote -> client
        Coroutine::create(function () {
            while (true) {
                $data = $this->remoteSocket?->recv();
                if ($data === '' || $data === false) break;
                if ($this->server->send($this->fd, $data) === false) break;
            }
            $this->safeClose();
        });
    }

    private function forwardTunnelData(string $data): void
    {
        if ($this->remoteSocket?->send($data) === false) {
            $this->logger->error("Failed to forward tunnel data to remote.");
            $this->safeClose();
        }
    }

    private function handleHttpRequest(string $rawRequest): void
    {
        $this->logger->info("Processing plain HTTP request...");

        $lines = explode("\r\n", $rawRequest);
        $requestLine = array_shift($lines);
        if (!$requestLine || !preg_match('/^(GET|POST|HEAD|PUT|DELETE|OPTIONS|PATCH)\s+(\S+)\s+HTTP\/1\.\d$/i', $requestLine, $matches)) {
            $this->sendPacket("HTTP/1.1 400 Bad Request\r\n\r\n");
            $this->safeClose();
            return;
        }

        $method = $matches[1];
        $url = $matches[2];
        $parsedUrl = parse_url($url);

        if (!isset($parsedUrl['host'])) {
            $this->sendPacket("HTTP/1.1 400 Bad Request\r\n\r\n");
            $this->safeClose();
            return;
        }

        $host = $parsedUrl['host'];
        $port = $parsedUrl['port'] ?? 80;

        $path = ($parsedUrl['path'] ?? '/') . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '');

        // Rewrite request line
        $lines = array_merge(["$method $path HTTP/1.1"], $lines);
        $rewrittenRequest = implode("\r\n", $lines) . "\r\n\r\n";

        $remote = new Socket(AF_INET, SOCK_STREAM, 0);
        if (!$remote->connect($host, $port, 5)) {
            $this->sendPacket("HTTP/1.1 502 Bad Gateway\r\n\r\n");
            $this->safeClose();
            return;
        }

        $this->remoteSocket = $remote;

        // Send request
        if (!$remote->send($rewrittenRequest)) {
            $this->sendPacket("HTTP/1.1 502 Bad Gateway\r\n\r\n");
            $this->safeClose();
            return;
        }

        // Pipe response back
        Coroutine::create(function () use ($remote) {
            while (true) {
                $data = $remote->recv();
                if ($data === '' || $data === false) break;
                if ($this->server->send($this->fd, $data) === false) break;
            }
            $this->safeClose();
        });
    }

    public function sendPacket(string $packet): void
    {
        try {
            $this->server->send($this->fd, $packet);
        } catch (Throwable $e) {
            $this->logger->error("Failed to send packet [fd:$this->fd] [err:{$e->getMessage()}]");
        }
    }

    public function safeClose(): void
    {
        $this->remoteSocket?->close();
        $this->server->close($this->fd);
    }

    /** Get remote socket connection in self connections storage */
    public function getRemoteSocketConnection(string $host,int $port): ProxyRemoteSocket
    {
        $signature = trim($host).trim($port);
        $signature = strtolower($signature);
        $exists = array_key_exists($signature,$this->remoteSockets);
        if (!$exists){
            $this->remoteSockets[$signature] = [
                'socket' => new ProxyRemoteSocket($host, $port),
                'last_used' => time()
            ];
        } else {
            /** @var ProxyRemoteSocket $remoteSocket */
            $remoteSocket = $this->remoteSockets[$signature];
            /** Check client was broken , re-create it */
            if (!$remoteSocket->isConnected) {
                $remoteSocket->close();
                unset($remoteSocket);
                $this->remoteSockets[$signature]['socket'] = new ProxyRemoteSocket($host, $port);
            }
            $this->remoteSockets[$signature]['last_used'] = time();
        }
        return $this->remoteSockets[$signature]['socket'];
    }

    public function free(int $flags = 0): void
    {
        $this->logger->info("Start free up http client before close with flags $flags");
    }
}