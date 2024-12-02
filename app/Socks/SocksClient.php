<?php

namespace App\Socks;

use App\Exceptions\HandshakeErrorException;
use App\Exceptions\InvalidPacketLengthException;
use App\Exceptions\InvalidSocksVersionException;
use App\Tools\Logger;
use App\Types\SocksVersion;
use Swoole\Coroutine\Socket;
use Swoole\Server;
use Throwable;

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


    /** Socks5 Protocol Info */
    public ?SocksVersion $socksVersion = null;
    public bool $useAuth = false;
    public bool $isHandshake = false;



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
        $this->logger->info("Received Data :  $data");
        if ($this->targetSocket === null) {
            try {
                $this->handleProxyRequest($data);
            }
            catch (Throwable $exception){
                $this->handleException($exception);
            }
        } else {
            if ($this->isConnected) {
                $this->logger->info("Forwarding data to target for client $this->fd");
                $this->targetSocket->send($data);
            } else {
                $this->logger->error("Target socket not connected for client $this->fd");
            }
        }
    }

    /**
     * @throws InvalidPacketLengthException
     * @throws InvalidSocksVersionException
     */
    public function handleProxyRequest(string $data): void
    {
        /** All packets at least must have 2 characters */
        if (strlen($data) < 3) {
            throw new InvalidPacketLengthException();
        }


        /** All packets in socks5 start with  0x05 */
        if (ord($data[0]) == "0x05"){
            throw new InvalidSocksVersionException();
        }



        $this->handleHandshakeRequest($data);


        // Step 2: Handle client request
        $data = substr($data, 2);


        /** Validate socket packet size before handling contents */
        $this->validateSocksPacket($data);

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

    /**
     * Step 1: Handle SOCKS5 handshake
     * Client Packet Format :
     * 1 - Socks Version (0x05 for SOCKS5) [1Bytes]
     * 2 - Number of auth methods client supports (0x00 for no auth) [1Bytes]
     * 3 - Supported authentication methods [XBytes]
     *
     * Client HEX : [Socket_version] [number_of_auth_methods] [methods]
     * Sample :   05 01 00     =>       Socks5  1Method NoAuth
     *
     */
    private function handleHandshakeRequest(string $data): void
    {
        if (ord($data[0]) == "0x05")
        {
            $this->logger->info("Handling proxy handshaking ...");
            $this->socksVersion = SocksVersion::VERSION_5;
            $this->useAuth = false;
            $response = "\x05\x00";
            $this->sendToClient($response);
            $this->isHandshake = true;
        } else {
            $this->logger->error("Socks5 handshake failed : Unsupported SOCKS version from client $this->fd");
            $this->close();
        }
    }


    /**
     * Step 1.3 : Optional Auth
     * Client Packet Format
     * 1 - Auth Version (0x01) [1 Bytes]
     * 2 - Username Length [1 Bytes]
     * 3 - Username [n Bytes]
     * 4 - Password Length [1 Bytes]
     * 5 - Password [m Bytes]
     *
     *  Client HEX : [AuthVersion] [UsernameLength] [Username] [PasswordLength] [Password]
     */
    private function handleAuthRequest(string $data): void
    {
        /** Server Response Hex : [RESULT]  */
        /** Success Response is 0x00  */
        /** Fail Response is 0x01  */
    }

    /**
     * @throws InvalidPacketLengthException
     */
    private function validateSocksPacket(string $data): void
    {
        if (strlen($data) < 4) {
            $this->logger->error("Invalid SOCKS5 request format from client $this->fd");
            throw new InvalidPacketLengthException();
        }
    }

    private function sendToClient(string $data): void
    {
        if ($this->server->exist($this->fd)) {
            $this->server->send($this->fd, $data);
            $this->logger->success("Request sent to client $data");
        } else {
            $this->logger->warning("Failed to send data to client $this->fd: client not found");
        }
    }

    public function handleException(Throwable $exception): void
    {
        $this->logger->error("Error in handling client packet : {$exception->getMessage()}");

        /** Handle Exceptions */
        if ($exception instanceof HandshakeErrorException){
            $this->logger->error("Handle Packet Error : Unsupported SOCKS version from client $this->fd");
        }
        else if ($exception instanceof InvalidPacketLengthException){
            $this->logger->error("Handle Packet Error : Packet size is not valid");
        }
        else if ($exception instanceof InvalidSocksVersionException){
            $this->logger->error("Handle Packet Error : invalid socks version");
        }
        else {
            $className = basename($exception);
            $this->logger->error("Unknown request exception $className : {$exception->getMessage()}");
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
