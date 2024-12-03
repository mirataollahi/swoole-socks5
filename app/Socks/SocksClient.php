<?php

namespace App\Socks;

use App\Exceptions\HandshakeErrorException;
use App\Exceptions\InvalidPacketLengthException;
use App\Exceptions\InvalidSocksVersionException;
use App\Tools\Logger;
use App\Tools\Utils;
use App\Types\AuthMethod;
use App\Types\HandshakeStatus;
use App\Types\SocksVersion;
use Swoole\Coroutine;
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
    public bool $isClosing = false;
    public Server $server;


    /** Socks5 Protocol Info */
    public ?SocksVersion $socksVersion = null;
    public array $supportAuthMethods = [];
    public HandshakeStatus $handshakeStatus;


    public function __construct(Server $server, int $fd, int $reactorId, int $workerId, array $userInfo = [])
    {
        $this->handshakeStatus = HandshakeStatus::NOT_STARTED;
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
        if ($this->isClosing) {
            $this->logger->error("Client packet ignored because client is closing ...");
            return;
        }

        if ($this->targetSocket === null) {
            try {
                if (strlen($data) < 3) {
                    throw new InvalidPacketLengthException();
                }

                /**
                 * Socks5 Control Packets :
                 * When the client sends a request (e.g., CONNECT, BIND, or UDP ASSOCIATE), the first byte is 0x05
                 */

                if (Utils::hexCompare($data[0], '0x05')) {
                    $this->logger->info("Client packet is a control packet and start with 0x05");
                    $this->handleSocks5ControlPacket($data);
                } else {
                    $this->logger->info("Client packet is a data row and must transfer to destination host");
                    $this->handleClientDataRowPacket($data);
                }
            } catch (Throwable $exception) {
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
     */
    public function handleSocks5ControlPacket(string $data): void
    {
        if (Utils::hexCompare($data[0], '0x05')) {
            if ($this->handshakeStatus === HandshakeStatus::NOT_STARTED || $this->handshakeStatus === HandshakeStatus::FAILED) {
                $this->handleHandshakePacket($data);
                return;
            }

            $data = substr($data, 2); // Remove SOCKS5 protocol header
            $this->validateSocksPacket($data);

            /** Command is CONNECT   */
            if (Utils::hexCompare($data[1], '0x01')) {
                $this->handleConnectCommandPacket($data);
            }
        } else {
            $this->logger->error("Invalid SOCKS version from client $this->fd");
            $this->close();
        }
    }

    /**
     * Step 1: Handle SOCKS5 handshake
     * Client Packet Format :
     * 1 - Socks Version (0x05 for SOCKS5) [1Bytes]
     * 2 - Client support auth methods (0x00 no auth) (0x00 user_pass_auth) [1Bytes]
     * 3 - Supported authentication methods [XBytes]
     *
     * Client HEX : [Socket_version] [number_of_auth_methods] [methods]
     * Sample :   05 01 00     =>       Socks5  1Method NoAuth
     *
     */
    private function handleHandshakePacket(string $data): void
    {
        $this->handshakeStatus = HandshakeStatus::RUNNING;
        $this->logger->info("Handling proxy handshaking ...");
        $this->socksVersion = SocksVersion::VERSION_5;
        $authCount = Utils::bin2hex($data[1]);
        for ($authId = 1; $authId <= $authCount; $authId++) {
            if (($authId + 1) >= strlen($data)){
                break;
            }
            $authType = Utils::bin2hex($data[$authId + 1]);
            switch ($authType){
                case AuthMethod::NOT_AUTH:
                    $this->logger->info("Auth method NO_AUTH selected for client $this->fd");
                    $this->supportAuthMethods [] = AuthMethod::NOT_AUTH;
                    break;
                case AuthMethod::USER_PASS_AUTH:
                    $this->logger->info("Auth method USER_PASS_AUTH selected for client $this->fd");
                    $this->supportAuthMethods [] = AuthMethod::USER_PASS_AUTH;
                    break;
                case AuthMethod::GSS_API_AUTH:
                    $this->logger->info("Auth method GSS_API_AUTH selected for client $this->fd");
                    $this->supportAuthMethods [] = AuthMethod::GSS_API_AUTH;
                    break;
                case AuthMethod::NO_ACCEPTABLE_AUTH:
                    $this->logger->warning("Auth method NO_ACCEPTABLE_AUTH sent by client as supported auth .The auth flag is Wrong is socks5");
                    $this->supportAuthMethods [] = AuthMethod::NO_ACCEPTABLE_AUTH;
                    break;
                default:
                    $this->logger->warning("Client sent $authType as its supported auth and the type not supported by the server");
            }
        }
        $selectedAuthCount = count($this->supportAuthMethods);
        $this->logger->success("Client successfully selected $selectedAuthCount auth count");
        $response = "\x05\x00";
        $this->sendToClient($response);
        $this->handshakeStatus = HandshakeStatus::FINISHED;
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
    private function handleAuthPacket(string $data): void
    {
        /** Server Response Hex : [RESULT]  */
        /** Success Response is 0x00  */
        /** Fail Response is 0x01  */
        if (!$this->supportAuthMethods) {
            $this->logger->warning("Auth request received but authentication is not enabled for client $this->fd");
            return;
        }

        $authVersion = bin2hex($data[0]);
        // $authVersion !== 0x01
        if (!Utils::hexCompare($data[0], '0x01')) {
            $this->logger->error("Unsupported auth version $authVersion for client $this->fd");
            $this->sendToClient("\x01\x01"); // Auth failure
            $this->close();
            return;
        }

        $usernameLen = ord($data[1]);
        $username = substr($data, 2, $usernameLen);
        $passwordLen = ord($data[2 + $usernameLen]);
        $password = substr($data, 3 + $usernameLen, $passwordLen);

        $this->logger->info("Client $this->fd attempting auth with username: $username");

        // Replace with real authentication logic
        $isAuthSuccessful = $this->authenticate($username, $password);

        if ($isAuthSuccessful) {
            $this->logger->success("Authentication successful for client $this->fd");
            $this->sendToClient("\x01\x00"); // Auth success
        } else {
            $this->logger->error("Authentication failed for client $this->fd");
            $this->sendToClient("\x01\x01"); // Auth failure
            $this->close();
        }
    }

    private function authenticate(string $username, string $password): bool
    {
        // Example hardcoded credentials; replace with a proper auth mechanism.
        $validUsername = "user";
        $validPassword = "pass";

        return $username === $validUsername && $password === $validPassword;
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

    private function handleConnectCommandPacket(string $data): void
    {
        $this->logger->info("Handling CONNECT command from client ...");
        $addressType = ord($data[3]);

        if (Utils::hexCompare($data[3], '0x01')) {
            // IPv4 address
            if (strlen($data) < 8) {
                $this->logger->error("Connect command failed : Invalid IPv4 address in request from client $this->fd");
                return;
            }
            $host = inet_ntop(substr($data, 4, 4));
            $port = unpack('n', substr($data, 8, 2))[1];
        } elseif (Utils::hexCompare($data[3], '0x03')) {
            // Domain name address
            $domainLen = ord($data[4]);
            if (strlen($data) < 5 + $domainLen + 2) {
                $this->logger->error("Invalid domain name in request from client $this->fd");
                return;
            }
            $host = substr($data, 5, $domainLen);
            $port = unpack('n', substr($data, 5 + $domainLen, 2))[1];
        } else {
            $this->logger->error("Unsupported address type from client $this->fd");
            return;
        }

        $this->logger->info("Client $this->fd requests connection to $host:$port");
        $this->createTargetSocketCoroutine($host, $port);
    }

    /**
     * Creates target socket and connects to destination.
     */
    public function createTargetSocketCoroutine(string $host, string $port): void
    {
        go(function () use ($host, $port) {
            $this->targetSocket = new Socket(AF_INET, SOCK_STREAM, 0);
            $this->isConnected = $this->targetSocket->connect($host, $port, 3);

            if (!$this->isConnected) {
                $this->logger->error("Failed to connect to $host:$port for client $this->fd.");
                $this->sendToClient("\x05\x05\x00\x01\x00\x00\x00\x00" . pack('n', 0)); // Connection refused
                $this->close();
                return;
            }

            $this->logger->success("Connected to $host:$port for client $this->fd.");
            $this->sendToClient("\x05\x00\x00\x01\x00\x00\x00\x00" . pack('n', 0)); // Connection success

            // Start reading from target
            go(function () {
                while (true) {
                    $data = $this->targetSocket->recv();
                    if ($data === '' || $data === false) {
                        $this->logger->warning("Target connection closed for client $this->fd.");
                        $this->close();
                        break;
                    }
                    $this->sendToClient($data);
                }
            });
        });
    }

    /**
     * he actual data being relayed between the client and the target server does not adhere to the SOCKS5 header structure.
     * The proxy simply forwards raw TCP or UDP packets, and these packets do not necessarily start with 0x05
     */
    public function handleClientDataRowPacket(string $data): void
    {
        if ($this->targetSocket) {
            $this->targetSocket->send($data);
            $this->logger->info("Forwarded data from client $this->fd to target.");
        } else {
            $this->logger->error("No target socket for client $this->fd. Dropping data.");
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
        if ($exception instanceof HandshakeErrorException) {
            $this->logger->error("Handle Packet Error : Unsupported SOCKS version from client $this->fd");
        } else if ($exception instanceof InvalidPacketLengthException) {
            $this->logger->error("Handle Packet Error : Packet size is not valid");
        } else if ($exception instanceof InvalidSocksVersionException) {
            $this->logger->error("Handle Packet Error : invalid socks version");
        } else {
            $className = basename($exception);
            $this->logger->error("Unknown request exception $className : {$exception->getMessage()}");
        }
    }

    public function close(): void
    {
        if ($this->isClosing) {
            $this->logger->info("Client currently is closing ... ");
        }
        $this->isClosing = true;
        Coroutine::sleep(1);
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
