<?php

namespace App\Socks;

use App\Tools\Logger;
use Swoole\Coroutine\Socket;
use Swoole\Server;

class SocksClient
{
    public Logger $logger;
    public int $fd;
    public int $workerId;
    public int $reactorId;
    public array $userInfo = [];
    private ?Socket $targetSocket = null;
    public bool $isConnected = false;
    public Server $server;

    public function __construct(Server $server , int $fd, int $reactorId, int $workerId, array $userInfo = [])
    {
        $this->server = $server;
        $this->logger = new Logger("CLIENT_$fd");
        $this->fd = $fd;
        $this->reactorId = $reactorId;
        $this->workerId = $workerId;
        $this->userInfo = $userInfo;
        $this->logger->success("Client with fd $fd connected in worker $workerId");
    }

    public function onReceive(string $data): void
    {
        $this->logger->info("Client $this->fd received packet with length " . strlen($data));

        if ($this->targetSocket === null) {
            // Handle the initial SOCKS5 handshake and connection request
            $this->handleHandshake($data);
        } else {
            // If the handshake is done, forward the data to the target
            if ($this->isConnected) {
                $this->logger->info("Forwarding data to target for client $this->fd");
                $this->targetSocket->send($data);
            } else {
                $this->logger->error("Target socket not connected for client $this->fd");
            }
        }
    }

    private function handleHandshake(string $data): void
    {
        if (strlen($data) < 3) {
            $this->logger->error("Invalid SOCKS5 handshake from client $this->fd");
            return;
        }

        // Step 1: Handle SOCKS5 handshake
        if (ord($data[0]) === 0x05) {
            // No authentication required (0x00)
            $this->logger->info("Handling SOCKS5 handshake for client $this->fd");
            $response = "\x05\x00"; // Version 5, No authentication required
            $this->sendToClient($response);
        } else {
            $this->logger->error("Unsupported SOCKS version from client $this->fd");
            $this->close();
            return;
        }

        // Step 2: Handle client request
        $data = substr($data, 2);
        if (strlen($data) < 4) {
            $this->logger->error("Invalid SOCKS5 request format from client $this->fd");
            return;
        }

        // Command (CONNECT only in this implementation)
        $command = ord($data[1]);
        if ($command !== 0x01) { // Only handle CONNECT commands
            $this->logger->error("Unsupported SOCKS5 command from client $this->fd");
            $this->sendToClient("\x05\x07\x00\x01" . "\x00\x00\x00\x00" . pack('n', 0));
            $this->close();
            return;
        }

        // Address type
        $addressType = ord($data[3]);
        $destAddr = '';
        $destPort = 0;

        if ($addressType === 0x01) { // IPv4
            if (strlen($data) < 10) {
                $this->logger->error("Invalid IPv4 address in request from client $this->fd");
                return;
            }
            $destAddr = inet_ntop(substr($data, 4, 4));
            $destPort = unpack('n', substr($data, 8, 2))[1];
        } elseif ($addressType === 0x03) { // Domain name
            $domainLen = ord($data[4]);
            if (strlen($data) < 5 + $domainLen + 2) {
                $this->logger->error("Invalid domain name in request from client $this->fd");
                return;
            }
            $destAddr = substr($data, 5, $domainLen);
            $destPort = unpack('n', substr($data, 5 + $domainLen, 2))[1];
        } else {
            $this->logger->error("Unsupported address type from client $this->fd");
            return;
        }

        $this->logger->info("Client $this->fd requests connection to $destAddr:$destPort");

        // Step 3: Connect to the target address
        go(function () use ($destAddr, $destPort) {
            $this->targetSocket = new Socket(AF_INET, SOCK_STREAM, 0);
            $this->isConnected = $this->targetSocket->connect($destAddr, $destPort, 3);
            if (!$this->isConnected) {
                $this->logger->error("Failed to connect to $destAddr:$destPort for client $this->fd");
                $this->sendToClient("\x05\x05\x00\x01" . "\x00\x00\x00\x00" . pack('n', 0)); // Connection refused
                $this->close();
                return;
            }

            $this->logger->success("Connected to $destAddr:$destPort for client $this->fd");

            // Step 4: Send successful connection response to client
            $this->sendToClient("\x05\x00\x00\x01" . "\x00\x00\x00\x00" . pack('n', 0));

            // Start reading from target and forward to the client
            go(function () {
                while (true) {
                    $data = $this->targetSocket->recv();
                    if ($data === '' || $data === false) {
                        $this->logger->warning("Target connection closed for client $this->fd");
                        $this->close();
                        break;
                    }
                    $this->sendToClient($data);
                }
            });
        });
    }

    private function sendToClient(string $data): void
    {
        // Assuming there's a server instance to send data back to the client (Server::send)
        if ($this->server->exist($this->fd)) {
            $this->server->send($this->fd, $data);
        } else {
            $this->logger->warning("Failed to send data to client $this->fd: client not found");
        }
    }

    public function close(): void
    {
        $this->logger->warning("Closing client $this->fd");

        // Close target socket if exists
        if ($this->targetSocket !== null && $this->isConnected) {
            $this->targetSocket->close();
        }

        // Assuming there's a server instance to close the client connection
        if ($this->server->exist($this->fd)) {
            $this->server->close($this->fd);
        }
    }
}
