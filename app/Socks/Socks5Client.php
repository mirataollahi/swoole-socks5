<?php declare(strict_types = 1);

namespace App\Socks;

use App\BaseServer;
use App\Exceptions\HandshakeErrorException;
use App\Exceptions\InvalidPacketLengthException;
use App\Exceptions\InvalidSocksVersionException;
use App\Tools\Helpers\Utils;
use App\Tools\Logger\Logger;
use App\Types\AuthMethod;
use App\Types\HandshakeStatus;
use App\Types\ProxyClient;
use App\Types\SocksVersion;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Server;
use Throwable;

class Socks5Client extends ProxyClient
{
    private ?Socket $targetSocket = null;
    private bool $isConnected = false;
    public ?int $targetSocketReceiverCid = null;
    public ?SocksVersion $socksVersion = null;
    public array $supportAuthMethods = [];
    public HandshakeStatus $handshakeStatus = HandshakeStatus::NOT_STARTED;
    private bool $authNeeded = false;
    public array $openTargetSockets = [];


    /** Initialize socks5 client connection after connected */
    public function initialize(): void
    {
        $this->logger->success("Client $this->fd connected");
    }

    /** handle received tcp packet from the client connection */
    public function onPacket(string $packet): void
    {
        if ($this->isClosing) {
            $this->logger->warning("Ignoring packet since client $this->fd is closing");
            return;
        }

        $len = strlen($packet);
        $this->logger->info("Client $this->fd received packet length: $len");

        try {
            // Handshake not finished
            if ($this->handshakeStatus !== HandshakeStatus::FINISHED) {
                // If we need auth and handshake is running, handle auth packet
                if ($this->authNeeded && $this->handshakeStatus === HandshakeStatus::RUNNING) {
                    $this->handleAuthPacket($packet);
                    return; // After successful auth, handshake is finished
                }

                // Normal handshake
                if ($len < 3) {
                    throw new InvalidPacketLengthException("Handshake packet too short");
                }

                if (Utils::hexCompare($packet[0], '0x05')) {
                    $this->handleHandshakePacket($packet);
                } else {
                    throw new HandshakeErrorException("Unsupported SOCKS version or handshake error");
                }
                return;
            }

            // Handshake finished:
            if ($this->targetSocket === null) {
                // Expecting a SOCKS5 control packet or something invalid
                if ($len < 3) {
                    throw new InvalidPacketLengthException("Control packet too short");
                }

                if (Utils::hexCompare($packet[0], '0x05')) {
                    $this->handleSocks5ControlPacket($packet);
                } else {
                    $this->logger->warning("Invalid data after handshake, no target connected. Closing client $this->fd.");
                    $this->close();
                }
                return;
            }

            // Target socket is established
            if ($this->isConnected && !$this->targetSocket->isClosed()) {
                // Forward client data to the target
                $this->targetSocket->send($packet,1.5);
            } else {
                $this->logger->error("Target socket not connected for client $this->fd. Dropping data.");
            }
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * Handle SOCKS5 control packets after handshake.
     */
    public function handleSocks5ControlPacket(string $data): void
    {
        $cmd = ord($data[1]); // Command: CONNECT=0x01, BIND=0x02, UDP=0x03

        switch ($cmd) {
            case 0x01:
                $this->logger->info("Handling CONNECT command for client $this->fd");
                $this->handleConnectCommandPacket($data);
                break;
            case 0x02:
                $this->logger->info("Handling BIND command (not implemented) for client $this->fd");
                $this->sendToClient("\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00");
                $this->close();
                break;
            case 0x03:
                $this->logger->info("Handling UDP ASSOCIATE command (not implemented) for client $this->fd");
                $this->sendToClient("\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00");
                $this->close();
                break;
            default:
                $this->logger->error("Unknown SOCKS5 command $cmd for client $this->fd");
                $this->sendToClient("\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00");
                $this->close();
        }
    }

    /**
     * Handle SOCKS5 handshake (method selection)
     * @throws InvalidPacketLengthException
     */
    private function handleHandshakePacket(string $data): void
    {
        $this->handshakeStatus = HandshakeStatus::RUNNING;
        $this->logger->info("Handling SOCKS5 handshake for client $this->fd");

        $this->socksVersion = SocksVersion::VERSION_5;
        $methodCount = ord($data[1]);
        $totalLen = strlen($data);
        if ($totalLen < 2 + $methodCount) {
            throw new InvalidPacketLengthException("Invalid handshake packet length");
        }

        $methods = substr($data, 2, $methodCount);

        $this->supportAuthMethods = [];
        for ($i = 0; $i < $methodCount; $i++) {
            $method = ord($methods[$i]);
            if ($method === AuthMethod::NOT_AUTH) {
                $this->supportAuthMethods[] = AuthMethod::NOT_AUTH;
            } elseif ($method === AuthMethod::USER_PASS_AUTH) {
                $this->supportAuthMethods[] = AuthMethod::USER_PASS_AUTH;
            }
            // GSS/others are unsupported; ignore for performance.
        }

        $chosenMethod = $this->selectAuthMethod();
        if ($chosenMethod === AuthMethod::NO_ACCEPTABLE_AUTH) {
            $this->logger->error("No acceptable auth methods for client $this->fd");
            $this->sendToClient("\x05\xFF");
            $this->close();
            return;
        }

        if ($chosenMethod === AuthMethod::NOT_AUTH) {
            $this->sendToClient("\x05\x00");
            $this->handshakeStatus = HandshakeStatus::FINISHED;
            $this->logger->success("No auth needed; handshake finished for client $this->fd");
        } else {
            // USER_PASS_AUTH
            $this->authNeeded = true;
            $this->sendToClient("\x05\x02");
            $this->logger->info("User/pass auth requested from client $this->fd");
        }
    }

    /**
     * Select auth method based on client support.
     */
    private function selectAuthMethod(): int
    {
        return in_array(AuthMethod::NOT_AUTH, $this->supportAuthMethods, true)
            ? AuthMethod::NOT_AUTH
            : (in_array(AuthMethod::USER_PASS_AUTH, $this->supportAuthMethods, true)
                ? AuthMethod::USER_PASS_AUTH
                : AuthMethod::NO_ACCEPTABLE_AUTH);
    }

    /**
     * Handle user/password authentication packet.
     */
    private function handleAuthPacket(string $data): void
    {
        $len = strlen($data);
        if ($len < 2) {
            $this->sendToClient("\x01\x01");
            $this->close();
            return;
        }

        if (ord($data[0]) !== 0x01) {
            $this->sendToClient("\x01\x01");
            $this->close();
            return;
        }

        $usernameLen = ord($data[1]);
        $pos = 2 + $usernameLen;
        if ($len < $pos + 1) {
            $this->sendToClient("\x01\x01");
            $this->close();
            return;
        }

        $username = substr($data, 2, $usernameLen);
        $passwordLen = ord($data[$pos]);
        $pos += 1;
        if ($len < $pos + $passwordLen) {
            $this->sendToClient("\x01\x01");
            $this->close();
            return;
        }

        $password = substr($data, $pos, $passwordLen);
        if ($this->authenticate($username, $password)) {
            $this->sendToClient("\x01\x00");
            $this->handshakeStatus = HandshakeStatus::FINISHED;
            $this->logger->success("Authenticated client $this->fd");
        } else {
            $this->sendToClient("\x01\x01");
            $this->close();
        }
    }

    private function authenticate(string $username, string $password): bool
    {
        $this->logger->info("Authenticating client auth info .... ");
        $serverUsername = BaseServer::$socksUsername;
        $serverPassword = BaseServer::$socksPassword;
        if (empty($serverUsername) && empty($serverPassword)) {
            $this->logger->success("Client authenticated . server auth info is empty");
            return true;
        }

        // Hardcoded for demonstration; replace with real validation.
        return $username === BaseServer::$socksUsername && $password === BaseServer::$socksPassword;
    }

    /**
     * Handle CONNECT command: parse address and port, connect to target.
     */
    private function handleConnectCommandPacket(string $data): void
    {
        $addressType = ord($data[3]);
        $length = strlen($data);
        switch ($addressType) {
            case 0x01: // IPv4
                if ($length < 10) {
                    $this->sendToClient("\x05\x04\x00\x01\x00\x00\x00\x00\x00\x00");
                    $this->close();
                    return;
                }
                $host = inet_ntop(substr($data, 4, 4));
                $port = unpack('n', substr($data, 8, 2))[1];
                break;
            case 0x03: // Domain
                if ($length < 5) {
                    $this->sendToClient("\x05\x04\x00\x01\x00\x00\x00\x00\x00\x00");
                    $this->close();
                    return;
                }
                $dLength = ord($data[4]);
                if ($length < 5 + $dLength + 2) {
                    $this->sendToClient("\x05\x04\x00\x01\x00\x00\x00\x00\x00\x00");
                    $this->close();
                    return;
                }
                $host = substr($data, 5, $dLength);
                $port = unpack('n', substr($data, 5 + $dLength, 2))[1];
                break;
            case 0x04: // IPv6 not supported here
                $this->sendToClient("\x05\x08\x00\x01\x00\x00\x00\x00\x00\x00");
                $this->close();
                return;
            default:
                $this->sendToClient("\x05\x08\x00\x01\x00\x00\x00\x00\x00\x00");
                $this->close();
                return;
        }

        $this->logger->info("Client $this->fd connecting to $host:$port");
        $this->createTargetSocketCoroutine($host, $port);
    }

    /**
     * Create a target socket and connect asynchronously.
     */
    public function createTargetSocketCoroutine(string $host, int $port): void
    {
        $this->targetSocketReceiverCid = go(function () use ($host, $port) {
            $sock = new Socket(AF_INET, SOCK_STREAM, 0);
            $this->logger->info("TCP Client connecting to $host:$port for client $this->fd ...");
            if (!$sock->connect($host, $port, 3)) {
                $this->logger->warning("Connection to $host:$port failed for client $this->fd");
                $this->sendToClient("\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00");
                $this->close();
                return;
            }

            $this->targetSocket = $sock;
            $this->isConnected = true;
            $this->logger->success("Connected to $host:$port for client $this->fd");

            // Notify client of success
            $this->sendToClient("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

            while (true) {
                $data = $this->targetSocket->recv();
                if ($data === '' || $data === false) {
                    $this->logger->warning("Target closed for client $this->fd");
                    $this->close();
                    break;
                }
                /** Send data to proxy client */
                $this->sendToClient($data);}
        });
    }

    private function sendToClient(string $data): void
    {
        if ($this->server->exist($this->fd)) {
            $this->server->send($this->fd, $data);
        } else {
            $this->logger->warning("Client $this->fd not found during send");
        }
    }

    public function handleException(Throwable $exception): void
    {
        $msg = $exception->getMessage();
        $this->logger->error("Exception for client $this->fd: $msg");

        // Specialized handling
        if ($exception instanceof HandshakeErrorException) {
            $this->logger->error("Handshake error for client $this->fd");
        } elseif ($exception instanceof InvalidPacketLengthException) {
            $this->logger->error("Invalid packet length for client $this->fd");
        } elseif ($exception instanceof InvalidSocksVersionException) {
            $this->logger->error("Invalid SOCKS version for client $this->fd");
        } else {
            $this->logger->error("Unknown exception ".get_class($exception)." for client $this->fd: $msg");
        }

        $this->close();
    }

    public function free(int $flags = 0): void
    {
        $this->logger->info("Closing socks5 client $this->fd");

        // Close target socket if open
        if ($this->targetSocket && $this->isConnected) {
            $this->targetSocket->close();
            $this->logger->info("Clean up client $this->fd : Target socket closed successfully");
        }

        // Close client connection if still exists
        if ($this->server->exist($this->fd)) {
            $this->server->close($this->fd);
            $this->logger->info("Clean up client $this->fd : Client fore closed from server");
        }

        // Cancel target receiver coroutine if needed
        if ($this->targetSocketReceiverCid) {
            if (!Coroutine::exists($this->targetSocketReceiverCid)){
                $this->targetSocketReceiverCid = null;
                $this->logger->info("Clean up client $this->fd : Target socket data already closed");
            }
            else {
                Coroutine::cancel($this->targetSocketReceiverCid);
                $this->targetSocketReceiverCid = null;
                $this->logger->info("Clean up client $this->fd : Target socket data receiver coroutine closed");
            }
        }
    }
}