<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Tools\Logger\Logger;
use App\Tools\Logger\LogLevel;
use Swoole\Atomic;
use Swoole\Constant;
use Swoole\Coroutine\Http\Client;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server as HttpServer;
use function Swoole\Coroutine\run;

class ProxyTest
{
    public HttpServer $httpServer;
    public string $host = '0.0.0.0';
    public int $port = 8422;
    public Client $httpClient;
    public Logger $logger;
    public Atomic $requestCounter;
    public string $proxyType = 'http';
    public string $proxyHost = 'localhost';
    public int $proxyPort = 8800;
    public ?string $proxyUsername = null;
    public ?string $proxyPassword = null;

    public function __construct()
    {
        $this->logger = new Logger();
        $this->requestCounter = new Atomic(0);
        $this->initHttpServer();
        $this->addClientProcess();
        $this->httpServer->start();
    }

    public function initHttpServer(): void
    {
        $this->httpServer = new HttpServer($this->host, $this->port, SWOOLE_PROCESS);
        $this->httpServer->set([
            Constant::OPTION_ENABLE_COROUTINE => true,
            Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
            Constant::OPTION_WORKER_NUM => 1,
            Constant::OPTION_OPEN_HTTP_PROTOCOL => true,
        ]);
        $this->httpServer->on(Constant::EVENT_START, function ($server) {
            $this->logger->success("Http server running on $this->host:$this->port");
        });

        $this->httpServer->on(Constant::EVENT_REQUEST, function (Request $request, Response $response) {
            $requestId = $this->requestCounter->add();
            $uri = strtolower(trim($request->server['request_uri']));
            $this->logger->info("Request #$requestId received with uri $uri");
            $response->end($requestId);
        });
    }

    public function addClientProcess(): void
    {
        $this->logger->info("Adding client process ...");
        $this->httpServer->addProcess(new Swoole\Process(function () {
            run(function () {
                if (!isset($this->logger)) {
                    $this->logger = new Logger();
                }
                $this->logger->info("Start running http client request tester process ... ");

                \Swoole\Timer::after(5000, function () {
                    try {
                        $this->logger->info("Sending request to http server ...");
                        $client = new Swoole\Coroutine\Http\Client($this->host, $this->port);
                        $client->setMethod('GET');
                        $client->setHeaders([
                            'content-type' => 'application/json',
                        ]);
                        $configs = $this->getClientConfigBaseProxy();
                        $client->set($configs);
                        $client->execute('/');
                        $statusCode = $client->getStatusCode();
                        $response = $client->getBody();

                        \Swoole\Coroutine::sleep(2);
                        if ($statusCode == 200) {
                            $this->logger->success("Successfully sent http request to server with status code $statusCode");
                            $this->logger->success("Response body is $response");
                        } else {
                            $this->logger->error("Client request failed with status code $statusCode and data $response");
                        }
                    } catch (Throwable $exception) {
                        $this->logger->error("Failed to send request to server : {$exception->getMessage()}");
                    }
                });
            });
        }));
    }


    public function getClientConfigBaseProxy(): array
    {
        $configs = [];
        if ($this->proxyType === 'http'){
            $configs['http_proxy_host'] = $this->proxyHost;
            $configs['http_proxy_port'] = $this->proxyPort;
        }

        if($this->proxyType === 'socks5'){
            $configs['socks5_host'] = $this->proxyHost;
            $configs['socks5_port'] = $this->proxyPort;
            if (!empty($this->proxyUsername)) {
                $configs['socks5_username'] = $this->proxyPort;
            }
            if (!empty($this->proxyPassword)) {
                $configs['socks5_password'] = $this->proxyPort;
            }
        }
        $configs['timeout'] = 3;
        return $configs;
    }
}

try {
    new ProxyTest();
} catch (Throwable $exception) {
    Logger::echo("Proxy test startup failed : {$exception->getMessage()}", LogLevel::ERROR);
}