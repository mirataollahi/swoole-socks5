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
    public ?int $targetSocketReceiverCid = null;

    /** Socks5 Protocol Info */
    public ?SocksVersion $socksVersion = null;
    public array $supportAuthMethods = [];
    public HandshakeStatus $handshakeStatus;
    private bool $authNeeded = false;

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

        try {
            // If handshake not finished:
            if ($this->handshakeStatus !== HandshakeStatus::FINISHED) {
                // If we have chosen USER_PASS_AUTH and handshake was completed but auth not done yet:
                if ($this->authNeeded && $this->handshakeStatus === HandshakeStatus::RUNNING) {
                    $this->handleAuthPacket($data);
                    // After auth success, handshakeStatus set to FINISHED.
                    return;
                }

                // Otherwise, handle handshake packet
                if (strlen($data) < 3) {
                    throw new InvalidPacketLengthException("Initial handshake packet too short");
                }

                if (Utils::hexCompare($data[0], '0x05')) {
                    // Handle handshake and method selection
                    $this->handleHandshakePacket($data);
                } else {
                    throw new HandshakeErrorException("Unsupported SOCKS version or handshake error");
                }
                return;
            }

            // Handshake finished:
            // If target socket not established yet, we are dealing with a control packet or something unexpected
            if ($this->targetSocket === null) {
                if (strlen($data) < 3) {
                    throw new InvalidPacketLengthException("Control packet too short");
                }

                // SOCKS5 request must start with 0x05
                if (Utils::hexCompare($data[0], '0x05')) {
                    $this->handleSocks5ControlPacket($data);
                } else {
                    // If not starting with 0x05, it might be raw data (though usually shouldn't happen before connect)
                    $this->logger->info("Packet doesn't start with 0x05 after handshake. Possibly invalid or unexpected data.");
                    // We can't forward anywhere yet, drop it or close:
                    $this->logger->warning("No target connected, closing client.");
                    $this->close();
                }
            } else {
                // If we have a target socket and are connected, forward data to target
                if ($this->isConnected) {
                    $this->logger->info("Forwarding data to target for client $this->fd");
                    $this->targetSocket->send($data);
                } else {
                    $this->logger->error("Target socket not connected for client $this->fd");
                }
            }
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * Handle SOCKS5 control packet after handshake and auth done.
     */
    public function handleSocks5ControlPacket(string $data): void
    {
        $commandCode = ord($data[1]); // Command: 0x01=CONNECT,0x02=BIND,0x03=UDP ASSOC
        // $rsvCode = ord($data[2]); // Normally 0x00
        // Address Code = ord($data[3]); // Address type
        // We'll parse them directly in handleConnectCommandPacket if needed

        if ($commandCode === 0x01) {
            $this->logger->info("Handling command CONNECT...");
            $this->handleConnectCommandPacket($data);
        } elseif ($commandCode === 0x02) {
            $this->logger->info("Handling command BIND... (not implemented)");
            // TODO: Implement BIND logic if needed.
            $this->sendToClient("\x05\x07\x00\x01\x00\x00\x00\x00" . pack('n', 0)); // Command not supported
            $this->close();
        } elseif ($commandCode === 0x03) {
            $this->logger->info("Handling command UDP ASSOCIATE... (not implemented)");
            // TODO: Implement UDP associate logic if needed.
            $this->sendToClient("\x05\x07\x00\x01\x00\x00\x00\x00" . pack('n', 0)); // Command not supported
            $this->close();
        } else {
            $this->logger->error("Unknown SOCKS5 command code: $commandCode");
            $this->sendToClient("\x05\x07\x00\x01\x00\x00\x00\x00" . pack('n', 0)); // Command not supported
            $this->close();
        }
    }

    /**
     * Step 1: Handle SOCKS5 handshake (version/method selection)
     * Client Packet Format:
     * [0]: SOCKS VERSION (0x05)
     * [1]: Number of auth methods (n)
     * [2]: Auth methods
     * @throws InvalidPacketLengthException
     */
    private function handleHandshakePacket(string $data): void
    {
        $this->handshakeStatus = HandshakeStatus::RUNNING;
        $this->logger->info("Handling SOCKS5 handshake...");

        $this->socksVersion = SocksVersion::VERSION_5;
        $methodCount = ord($data[1]);

        if (strlen($data) < 2 + $methodCount) {
            $this->logger->error("Invalid handshake packet length for client $this->fd");
            throw new InvalidPacketLengthException();
        }

        $methods = substr($data, 2, $methodCount);
        $this->supportAuthMethods = [];

        for ($i = 0; $i < $methodCount; $i++) {
            $method = ord($methods[$i]);
            switch ($method) {
                case AuthMethod::NOT_AUTH:
                    $this->logger->info("Client $this->fd supports NO_AUTH");
                    $this->supportAuthMethods[] = AuthMethod::NOT_AUTH;
                    break;
                case AuthMethod::USER_PASS_AUTH:
                    $this->logger->info("Client $this->fd supports USER_PASS_AUTH");
                    $this->supportAuthMethods[] = AuthMethod::USER_PASS_AUTH;
                    break;
                case AuthMethod::GSS_API_AUTH:
                    $this->logger->info("Client $this->fd supports GSS_API_AUTH (unsupported by server)");
                    // We do not support GSS, so we won't select it.
                    break;
                default:
                    $this->logger->warning("Client $this->fd sent unsupported auth method: $method");
            }
        }

        // Choose an auth method
        $chosenMethod = $this->selectAuthMethod();
        if ($chosenMethod === AuthMethod::NO_ACCEPTABLE_AUTH) {
            $this->logger->error("No acceptable authentication methods for client $this->fd");
            $this->sendToClient("\x05\xFF");
            $this->close();
            return;
        }

        if ($chosenMethod === AuthMethod::NOT_AUTH) {
            // No authentication required
            $this->sendToClient("\x05\x00");
            $this->handshakeStatus = HandshakeStatus::FINISHED;
            $this->logger->success("Handshake finished with NO_AUTH for client $this->fd");
        } elseif ($chosenMethod === AuthMethod::USER_PASS_AUTH) {
            // User/pass authentication required
            $this->authNeeded = true;
            $this->sendToClient("\x05\x02");
            $this->logger->info("Sent user-pass auth request to client $this->fd. Waiting for auth credentials...");
            // Handshake still running, will be completed after auth success
        }
    }

    /**
     * Select the best auth method based on what the client supports
     */
    private function selectAuthMethod(): int
    {
        // Prefer no auth if available
        if (in_array(AuthMethod::NOT_AUTH, $this->supportAuthMethods, true)) {
            return AuthMethod::NOT_AUTH;
        }

        // Otherwise use user/pass if available
        if (in_array(AuthMethod::USER_PASS_AUTH, $this->supportAuthMethods, true)) {
            return AuthMethod::USER_PASS_AUTH;
        }

        // No acceptable auth
        return AuthMethod::NO_ACCEPTABLE_AUTH;
    }

    /**
     * Handle user/password authentication packet
     * Client Packet Format:
     * [0]: Auth version (0x01)
     * [1]: Username length (uLen)
     * [2..uLen+1]: Username
     * [uLen+2]: Password length (pLen)
     * [uLen+3..uLen+3+pLen-1]: Password
     */
    private function handleAuthPacket(string $data): void
    {
        $this->logger->info("Handling user/pass authentication for client $this->fd");

        if (strlen($data) < 2) {
            $this->logger->error("Auth packet too short for client $this->fd");
            $this->sendToClient("\x01\x01");
            $this->close();
            return;
        }

        $authVersion = ord($data[0]);
        if ($authVersion !== 0x01) {
            $this->logger->error("Unsupported auth version $authVersion for client $this->fd");
            $this->sendToClient("\x01\x01"); // Auth failure
            $this->close();
            return;
        }

        $usernameLen = ord($data[1]);
        $minLength = 2 + $usernameLen + 1;
        if (strlen($data) < $minLength) {
            $this->logger->error("Auth packet too short to contain username for client $this->fd");
            $this->sendToClient("\x01\x01");
            $this->close();
            return;
        }

        $username = substr($data, 2, $usernameLen);
        $posAfterUsername = 2 + $usernameLen;
        if (strlen($data) < $posAfterUsername + 1) {
            $this->logger->error("Auth packet too short to contain password length for client $this->fd");
            $this->sendToClient("\x01\x01");
            $this->close();
            return;
        }

        $passwordLen = ord($data[$posAfterUsername]);
        if (strlen($data) < $posAfterUsername + 1 + $passwordLen) {
            $this->logger->error("Auth packet too short to contain full password for client $this->fd");
            $this->sendToClient("\x01\x01");
            $this->close();
            return;
        }

        $password = substr($data, $posAfterUsername + 1, $passwordLen);
        $this->logger->info("Client $this->fd attempting auth with username: $username");

        // Replace with real authentication logic
        $isAuthSuccessful = $this->authenticate($username, $password);

        if ($isAuthSuccessful) {
            $this->logger->success("Authentication successful for client $this->fd");
            $this->sendToClient("\x01\x00"); // Auth success
            $this->handshakeStatus = HandshakeStatus::FINISHED;
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
     * Handle CONNECT command: parse address and port and connect to target.
     */
    private function handleConnectCommandPacket(string $data): void
    {
        $this->logger->info("Handling CONNECT command from client $this->fd...");

        $addressType = ord($data[3]);

        if ($addressType === 0x01) {
            // IPv4
            if (strlen($data) < 10) {
                $this->logger->error("Invalid IPv4 request from client $this->fd");
                $this->sendToClient("\x05\x04\x00\x01\x00\x00\x00\x00".pack('n',0));
                $this->close();
                return;
            }
            $host = inet_ntop(substr($data, 4, 4));
            $port = unpack('n', substr($data, 8, 2))[1];
        } elseif ($addressType === 0x03) {
            // Domain name
            $domainLen = ord($data[4]);
            if (strlen($data) < 5 + $domainLen + 2) {
                $this->logger->error("Invalid domain request from client $this->fd");
                $this->sendToClient("\x05\x04\x00\x01\x00\x00\x00\x00".pack('n',0));
                $this->close();
                return;
            }
            $host = substr($data, 5, $domainLen);
            $port = unpack('n', substr($data, 5 + $domainLen, 2))[1];
        } elseif ($addressType === 0x04) {
            // IPv6 - Not implemented
            $this->logger->error("IPv6 not supported for client $this->fd");
            $this->sendToClient("\x05\x08\x00\x01\x00\x00\x00\x00".pack('n',0));
            $this->close();
            return;
        } else {
            $this->logger->error("Unsupported address type from client $this->fd");
            $this->sendToClient("\x05\x08\x00\x01\x00\x00\x00\x00".pack('n',0));
            $this->close();
            return;
        }

        $this->logger->info("Client $this->fd requests connection to $host:$port");
        $this->createTargetSocketCoroutine($host, $port);
    }

    /**
     * Create target socket and connect to the destination host.
     */
    public function createTargetSocketCoroutine(string $host, int $port): void
    {
        $this->targetSocketReceiverCid = go(function () use ($host, $port) {
            $this->targetSocket = new Socket(AF_INET, SOCK_STREAM, 0);
            if (!$this->targetSocket->connect($host, $port, 3)) {
                $this->logger->error("Failed to connect to $host:$port for client $this->fd.");
                $this->sendToClient("\x05\x05\x00\x01\x00\x00\x00\x00" . pack('n', 0)); // Connection refused
                $this->close();
                return;
            }

            $this->isConnected = true;
            $this->logger->success("Connected to $host:$port for client $this->fd.");
            // Send success response
            $this->sendToClient("\x05\x00\x00\x01\x00\x00\x00\x00" . pack('n', 0));

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
     * Forward raw data to the target if connected.
     */
    public function handleClientDataRowPacket(string $data): void
    {
        if ($this->targetSocket && $this->isConnected) {
            $this->targetSocket->send($data);
            $this->logger->info("Forwarded data from client $this->fd to target.");
        } else {
            $this->logger->error("No target socket or not connected for client $this->fd. Dropping data.");
        }
    }

    private function sendToClient(string $data): void
    {
        if ($this->server->exist($this->fd)) {
            $this->server->send($this->fd, $data);
            $this->logger->info("Response sent to client $this->fd");
        } else {
            $this->logger->warning("Failed to send data to client $this->fd: client not found");
        }
    }

    public function handleException(Throwable $exception): void
    {
        $this->logger->error("Error handling client packet: {$exception->getMessage()}");

        if ($exception instanceof HandshakeErrorException) {
            $this->logger->error("Unsupported SOCKS version from client $this->fd");
        } elseif ($exception instanceof InvalidPacketLengthException) {
            $this->logger->error("Invalid packet length from client $this->fd");
        } elseif ($exception instanceof InvalidSocksVersionException) {
            $this->logger->error("Invalid SOCKS version from client $this->fd");
        } else {
            $className = get_class($exception);
            $this->logger->error("Unknown exception $className: {$exception->getMessage()}");
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->isClosing) {
            $this->logger->info("Client $this->fd is already closing ...");
            return;
        }

        $this->isClosing = true;
        $this->logger->warning("Closing client $this->fd");
        Coroutine::sleep(0.1); // Slight delay to ensure logs flush

        // Close target socket if exists
        if ($this->targetSocket !== null && $this->isConnected) {
            $this->targetSocket->close();
        }

        // Close client connection
        if ($this->server->exist($this->fd)) {
            $this->server->close($this->fd);
        }

        if ($this->targetSocketReceiverCid) {
            Coroutine::cancel($this->targetSocketReceiverCid);
            $this->targetSocketReceiverCid = null;
        }
    }
}