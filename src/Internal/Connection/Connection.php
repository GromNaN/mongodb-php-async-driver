<?php

declare(strict_types=1);

namespace MongoDB\Internal\Connection;

use Amp\CancelledException;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Driver\Exception\LogicException;
use MongoDB\Internal\Protocol\MessageHeader;
use MongoDB\Internal\Protocol\OpMsgDecoder;
use MongoDB\Internal\Protocol\OpMsgEncoder;
use MongoDB\Internal\Uri\UriOptions;

use function Amp\Socket\connect;
use function Amp\Socket\connectTls;
use function is_array;
use function min;
use function sprintf;
use function strlen;
use function substr;
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
    /**
     * Initial read buffer size (bytes).  Sized to cover the vast majority of
     * MongoDB responses for short commands (ping, insert ack, findOne, etc.)
     * in a single socket->read() call, reducing fiber suspensions from 2 to 1.
     */
    private const INITIAL_READ_SIZE = 512;

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
    /** Socket read/write timeout in seconds (0 = no timeout). */
    private float $socketTimeoutSecs = 0.0;

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
        $this->state = self::STATE_CONNECTING;

        $tlsEnabled = $options !== null && (
            ($options->tls ?? false) ||
            ($options->ssl ?? false) ||
            isset($options->tlsCAFile) ||
            isset($options->tlsCertificateKeyFile)
        );

        if ($tlsEnabled) {
            // Build TLS context from URI options.
            $tlsContext = new ClientTlsContext($this->host);

            if (isset($options->tlsCAFile)) {
                $tlsContext = $tlsContext->withCaFile($options->tlsCAFile);
            }

            if (isset($options->tlsCertificateKeyFile)) {
                $tlsContext = $tlsContext->withCertificate(new Certificate($options->tlsCertificateKeyFile));
            }

            // Both tlsAllowInvalidCertificates and tlsAllowInvalidHostnames disable
            // peer verification. amphp/socket ties verify_peer and verify_peer_name
            // together so we cannot disable hostname checks independently.
            if (
                ($options->tlsAllowInvalidCertificates ?? false) ||
                ($options->tlsAllowInvalidHostnames ?? false)
            ) {
                $tlsContext = $tlsContext->withoutPeerVerification();
            }

            // connectTls() establishes the TCP connection and performs the TLS
            // handshake, suspending the fiber until both complete.
            $this->socket = connectTls(
                $this->host . ':' . $this->port,
                (new ConnectContext())->withTlsContext($tlsContext),
            );
        } else {
            // amphp/socket connect() suspends the fiber internally.
            $this->socket = connect('tcp://' . $this->host . ':' . $this->port);
        }

        if (isset($options->socketTimeoutMS) && $options->socketTimeoutMS > 0) {
            $this->socketTimeoutSecs = $options->socketTimeoutMS / 1000.0;
        }

        $this->state  = self::STATE_CONNECTED;

        // Run server handshake (hello / isMaster).
        $this->runHello();

        // Authenticate if credentials were supplied.
        if ($options?->authMechanism ?? null) {
            $this->state = self::STATE_AUTHENTICATING;

            throw new LogicException('Authentication is not yet supported by the async driver');
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
     * **Fast path (common case):** reads {@see INITIAL_READ_SIZE} bytes in a
     * single socket->read() call.  Because most MongoDB responses for short
     * commands (ping, insert ack, find with small result set, …) fit well
     * within that budget and are typically delivered in one TCP segment, the
     * full message is available immediately and no second suspension is needed.
     *
     * **Slow path:** if the initial chunk does not yet contain the complete
     * message, the remaining bytes are read via {@see readExactly()}.
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

        // Read the first chunk — large enough to capture the entire response
        // for typical short commands in one suspension.
        $cancellation = $this->socketTimeoutSecs > 0.0
            ? new TimeoutCancellation($this->socketTimeoutSecs)
            : null;

        $buffer = '';

        try {
            // Loop until we have at least 4 bytes (the length prefix).
            // In virtually all cases this loop body executes exactly once.
            while (strlen($buffer) < 4) {
                $chunk = $this->socket->read(limit: self::INITIAL_READ_SIZE, cancellation: $cancellation);
                if ($chunk === null) {
                    throw new ConnectionTimeoutException('Failed to read 4 bytes: socket error or timeout');
                }

                $buffer .= $chunk;
            }
        } catch (CancelledException $e) {
            throw new ConnectionTimeoutException('socket error or timeout', 0, $e);
        }

        /** @var array{1: int} $u */
        $u             = unpack('V', substr($buffer, 0, 4));
        $messageLength = $u[1];

        if ($messageLength < MessageHeader::HEADER_SIZE) {
            throw new ConnectionException(
                sprintf('Received malformed message: length %d is too small', $messageLength),
            );
        }

        // Fast path: the initial chunk already holds the complete message.
        if (strlen($buffer) >= $messageLength) {
            $this->markUsed();

            return substr($buffer, 0, $messageLength);
        }

        // Slow path: read the remaining bytes.
        $buffer .= $this->readExactly($messageLength - strlen($buffer));

        $this->markUsed();

        return $buffer;
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
        $this->maxWireVersion = (int) self::extractHelloField($body, 'maxWireVersion', 0);
        $this->minWireVersion = (int) self::extractHelloField($body, 'minWireVersion', 0);
        $serviceId            = self::extractHelloField($body, 'serviceId', null);
        $this->serviceId      = $serviceId !== null ? (string) $serviceId : null;
    }

    private static function extractHelloField(array|object $body, string $key, mixed $default): mixed
    {
        return is_array($body) ? ($body[$key] ?? $default) : ($body->$key ?? $default);
    }

    /**
     * Read exactly $length bytes from the socket, blocking until all bytes
     * are available or the connection closes.
     *
     * @throws ConnectionException if the connection closes before $length bytes are received.
     */
    private function readExactly(int $length): string
    {
        $buffer      = '';
        $remaining   = $length;
        $cancellation = $this->socketTimeoutSecs > 0.0
            ? new TimeoutCancellation($this->socketTimeoutSecs)
            : null;

        while ($remaining > 0) {
            try {
                $chunk = $this->socket->read(limit: min($remaining, 65536), cancellation: $cancellation);
            } catch (CancelledException $e) {
                throw new ConnectionTimeoutException('socket error or timeout', 0, $e);
            }

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
