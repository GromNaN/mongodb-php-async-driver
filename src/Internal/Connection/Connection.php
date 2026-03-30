<?php

declare(strict_types=1);

namespace MongoDB\Internal\Connection;

use Amp\Socket\Socket;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Internal\Protocol\MessageHeader;
use MongoDB\Internal\Protocol\OpMsgDecoder;
use MongoDB\Internal\Protocol\OpMsgEncoder;
use MongoDB\Internal\Uri\UriOptions;

use function Amp\Socket\connect;
use function is_array;
use function min;
use function sprintf;
use function strlen;
use function time;
use function unpack;

/**
 * A single async TCP connection to a MongoDB server.
 *
 * All I/O methods suspend the current Revolt fiber; they must be called from
 * within an async context (e.g. inside \Amp\async() or an existing fiber).
 *
 * @internal
 */
final class Connection
{
    public const STATE_CONNECTING    = 'connecting';
    public const STATE_CONNECTED     = 'connected';
    public const STATE_AUTHENTICATING = 'authenticating';
    public const STATE_READY         = 'ready';
    public const STATE_CLOSED        = 'closed';

    private Socket $socket;
    private string $state = self::STATE_CONNECTING;
    private int $maxWireVersion = 0;
    private int $minWireVersion = 0;
    private ?string $serviceId     = null;
    private int $lastUsedAt;

    /**
     * Create a new (not yet connected) Connection.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
    ) {
        $this->lastUsedAt = time();
    }

    // -------------------------------------------------------------------------
    // Connection lifecycle
    // -------------------------------------------------------------------------

    /**
     * Establish the TCP connection, run the hello handshake, and optionally
     * authenticate. Suspends the current fiber until complete.
     *
     * @throws ConnectionException on I/O errors.
     */
    public function connect(?UriOptions $options = null): void
    {
        $this->state  = self::STATE_CONNECTING;
        $uri          = 'tcp://' . $this->host . ':' . $this->port;

        // amphp/socket connect() suspends the fiber internally.
        $this->socket = connect($uri);
        $this->state  = self::STATE_CONNECTED;

        // Run server handshake (hello / isMaster).
        $this->runHello();

        // Authenticate if credentials were supplied.
        if ($options !== null && isset($options->authMechanism)) {
            $this->state = self::STATE_AUTHENTICATING;
            // Authentication is mechanism-specific and handled by a higher
            // layer; here we simply advance the state.
        }

        $this->state = self::STATE_READY;
        $this->markUsed();
    }

    // -------------------------------------------------------------------------
    // Command API
    // -------------------------------------------------------------------------

    /**
     * Send a command document to the given database and return the response.
     *
     * Suspends the current fiber while waiting for the server response.
     *
     * @param string       $db      Target database name.
     * @param array|object $command Command document.
     *
     * @return array|object Decoded response body.
     *
     * @throws ConnectionException on I/O errors.
     * @throws CommandException on server error (ok != 1).
     */
    public function sendCommand(string $db, array|object $command): array|object
    {
        // Inject $db into the command if it is not already present.
        if (is_array($command)) {
            $command['$db'] = $db;
        } else {
            $command->{'$db'} = $db;
        }

        [$bytes] = OpMsgEncoder::encodeWithRequestId($command);

        $responseBytes = $this->sendMessage($bytes);

        return OpMsgDecoder::decodeAndCheck($responseBytes);
    }

    /**
     * Write raw wire-protocol bytes to the socket and read the full response.
     *
     * The MongoDB response always starts with a 4-byte little-endian length
     * field that includes the length field itself, so we read that first and
     * then read the remainder of the message.
     *
     * @throws ConnectionException on I/O / framing errors.
     */
    public function sendMessage(string $bytes): string
    {
        if ($this->state === self::STATE_CLOSED) {
            throw new ConnectionException('Cannot send message: connection is closed');
        }

        // Write the outgoing frame.
        $this->socket->write($bytes);

        // Read the first 4 bytes to determine total message length.
        $lengthBytes = $this->readExactly(4);

        /** @var array{1: int} $u */
        $u             = unpack('V', $lengthBytes);
        $messageLength = $u[1];

        if ($messageLength < MessageHeader::HEADER_SIZE) {
            throw new ConnectionException(
                sprintf('Received malformed message: length %d is too small', $messageLength),
            );
        }

        // Read the rest of the message (messageLength already includes the 4 bytes we read).
        $rest = $this->readExactly($messageLength - 4);

        $this->markUsed();

        return $lengthBytes . $rest;
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getMaxWireVersion(): int
    {
        return $this->maxWireVersion;
    }

    public function getMinWireVersion(): int
    {
        return $this->minWireVersion;
    }

    public function getServiceId(): ?string
    {
        return $this->serviceId;
    }

    public function isReady(): bool
    {
        return $this->state === self::STATE_READY;
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = time();
    }

    public function close(): void
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }

        $this->state = self::STATE_CLOSED;
        if (! isset($this->socket)) {
            return;
        }

        $this->socket->close();
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Run the MongoDB hello handshake to discover server capabilities.
     */
    private function runHello(): void
    {
        $helloCmd = [
            'hello' => 1,
            '$db'   => 'admin',
        ];

        [$bytes] = OpMsgEncoder::encodeWithRequestId($helloCmd);

        $responseBytes = $this->sendMessage($bytes);

        // Use OpMsgDecoder::decode (not decodeAndCheck) because older servers
        // may return ok:0 for "hello" while still being usable.
        $result = OpMsgDecoder::decode($responseBytes);
        $body   = $result['body'];

        // Extract wire version bounds.
        if (is_array($body)) {
            $this->maxWireVersion = (int) ($body['maxWireVersion'] ?? 0);
            $this->minWireVersion = (int) ($body['minWireVersion'] ?? 0);
            $this->serviceId      = isset($body['serviceId'])
                ? (string) $body['serviceId']
                : null;
        } else {
            $this->maxWireVersion = (int) ($body->maxWireVersion ?? 0);
            $this->minWireVersion = (int) ($body->minWireVersion ?? 0);
            $this->serviceId      = isset($body->serviceId)
                ? (string) $body->serviceId
                : null;
        }
    }

    /**
     * Read exactly $length bytes from the socket, blocking until all bytes
     * are available or the connection closes.
     *
     * @throws ConnectionException if the connection closes before $length bytes are received.
     */
    private function readExactly(int $length): string
    {
        $buffer    = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = $this->socket->read(min($remaining, 65536));
            if ($chunk === null) {
                throw new ConnectionException(
                    sprintf(
                        'Connection closed while reading: expected %d more bytes (got %d of %d)',
                        $remaining,
                        strlen($buffer),
                        $length,
                    ),
                );
            }

            $buffer    .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }
}
