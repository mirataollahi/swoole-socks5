<?php

namespace App\Socks;

use App\BaseServer;
use App\Tools\Helper;
use App\Tools\Logger;
use Swoole\Server;

class SocksServer
{
    public Server $server;
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
        $this->host = Helper::getEnv('SOCKET_HOST', BaseServer::$socksHost);
        $this->port = Helper::getEnv('SOCKET_PORT', BaseServer::$socksPort);
        $this->server = new Server($this->host, $this->port);

        $this->server->set([
            'worker_num' => 4,
            'hook_flags' => SWOOLE_HOOK_ALL,
            'enable_coroutine' => true,
            'tcp_fastopen' => true,
            'max_request' => 6000,
        ]);
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('Connect', [$this, 'onConnect']);
        $this->server->on('Receive', [$this, 'onReceive']);
        $this->server->on('close', [$this, 'onClose']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('WorkerStop', [$this, 'onWorkerStop']);
        $this->server->on('AfterReload', [$this, 'onAfterReload']);
        $this->server->on('BeforeReload', [$this, 'onBeforeReload']);
        $this->server->on('ManagerStart', [$this, 'onManagerStart']);
        $this->server->on('ManagerStop', [$this, 'onManagerStop']);
        $this->server->on('WorkerExit', [$this, 'onWorkerExit']);
        $this->server->on('WorkerError', [$this, 'onWorkerError']);
    }

    public function onStart(Server $server): void
    {
        $this->logger->info("TCP server started at $server->host:$server->port");
    }

    public function onConnect(Server $server, int $fd , int $reactorId): void
    {
        $this->logger->info("TCP Client #$fd connected at reactor id $reactorId and worker {$server->getWorkerId()}");
        if (!array_key_exists($fd,$this->clients)){
            $this->clients[$fd] = new Socks5Client($this->server , $fd,$reactorId,$server->getWorkerId(),$server->getClientInfo($fd));
        }
    }

    public function onReceive(Server $server, int $fd , int $reactorId,string $data): void
    {
        $this->logger->info("📥 New TCP Packet receive from fd $fd and reactor_id $reactorId and wid {$server->getWorkerId()}");
        // Check client still connected
        if (array_key_exists($fd,$this->clients)){
            /** @var Socks5Client $client */
            $client = $this->clients[$fd];
            $client->onReceive($data);
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        $this->logger->info("Client #$fd closed in worker {$server->getWorkerId()}");
        if (array_key_exists($fd , $this->clients)){
            $this->clients[$fd]?->close();
            unset($this->clients[$fd]);
        }
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->logger->info("Server worker #$workerId started with pid {$server->getWorkerPid()}");
        $this->appContext->initWorkerLayer($workerId);
    }

    public function onWorkerStop(Server $server, int $workerId): void
    {
        $this->logger->warning("Server worker #$workerId stopped with pid {$server->getWorkerId()}");
    }

    public function onAfterReload(Server $server): void
    {
        $this->logger->warning("Reloading finished in worker {$server->getWorkerId()} and pid {$server->getWorkerPid()}");
    }

    public function onBeforeReload(Server $server): void
    {
        $this->logger->success("Reloading worker {$server->getWorkerId()} and pid {$server->getWorkerPid()}");
    }

    public function onManagerStart(Server $server): void
    {
        $this->logger->info("Manager process started with pid {$server->getManagerPid()}");
    }

    public function onManagerStop(Server $server): void
    {
        $this->logger->warning("Manager process stopped with pid {$server->getManagerPid()}");
    }

    public function onWorkerExit(Server $server, int $workerId): void
    {
        $this->logger->warning("[wid:{$server->getWorkerId()}]Worker $workerId with pid {$server->getWorkerPid()} exited");
    }

    public function onWorkerError(Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void
    {
        $this->logger->error("Worker Error : [worker_id:$workerId] [worker_pid:$workerPid] [exit_code:$exitCode] [signal:$signal] [server_port:$server->port]");
    }
}
