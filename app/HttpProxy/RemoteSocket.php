<?php

namespace App\HttpProxy;

use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use App\Types\ProxyClient;
use Swoole\Coroutine\Socket;
use Throwable;

class RemoteSocket extends Socket
{
    /** Remote host address or ip */
    protected string $host;

    /** Remote host port number */
    protected int $port;

    /** Remote host connection timeout */
    protected float $timeout = 3;

    /** Remote client connection is established or not */
    public bool $isConnected = false;

    /** Show more logs in debug mode running  */
    public bool $isDebug = true;

    public Logger $logger;
    public bool $isClosing = false;
    public ProxyClient $proxyClient;

    public function __construct(string $host, int $port,HttpProxyClient $proxyClient)
    {
        $this->proxyClient = $proxyClient;
        $this->logger = $proxyClient->logger;
        $this->host = $host;
        $this->port = $port;
        parent::__construct(AF_INET, SOCK_STREAM, 0);
    }

    /** Initialize remote socket connection */
    public function safeConnect(): bool
    {
        try {
            $this->log("Connecting to remote proxy socket {$this->host}:{$this->port}...", LogLevel::INFO);
            $this->isConnected = $this->connect($this->host, $this->port, $this->timeout);

            if (!$this->isConnected) {
                $this->log("Remote connection failed on {$this->host}:{$this->port}", LogLevel::WARNING);
                return false;
            }

            $this->log("Remote socket client connection established successfully on {$this->host}:{$this->port}", LogLevel::INFO);
            return true;
        } catch (Throwable $exception) {
            $this->log("Error in connecting to remote socket host {$this->host}:{$this->port}: {$exception->getMessage()}", LogLevel::ERROR);
            return false;
        }
    }

    /** Client connection still alive and connected */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public function log(string $message, LogLevel $level): void
    {
        if ($this->isDebug) {
            match ($level) {
                LogLevel::SUCCESS => $this->logger->success($message),
                LogLevel::ERROR => $this->logger->error($message),
                LogLevel::WARNING => $this->logger->warning($message),
                default => $this->logger->info($message),
            };
        }
    }

    /** Close remote socket client connection */
    public function safeClose(): void
    {
        if ($this->isClosing) {
            return;
        }
        $this->isClosing = true;
        try {
            if (isset($this->remoteSocket)) {
                $this->remoteSocket->close();
                unset($this->remoteSocket);
            }
            $this->log("Remote socket connection closed successfully.", LogLevel::INFO);
        } catch (Throwable $exception) {
            $this->log("Error in closing remote socket: {$exception->getMessage()}", LogLevel::ERROR);
        }
    }

    public function __destruct()
    {
        $this->safeClose();
    }
}