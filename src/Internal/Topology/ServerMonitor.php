<?php

declare(strict_types=1);

namespace MongoDB\Internal\Topology;

use Closure;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Internal\Connection\Connection;
use MongoDB\Internal\Protocol\OpMsgDecoder;
use MongoDB\Internal\Protocol\OpMsgEncoder;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function is_array;
use function microtime;

/**
 * Background monitor that periodically sends hello to a single MongoDB server
 * and notifies a callback whenever the server description changes.
 *
 * Each ServerMonitor runs inside its own Revolt/Amp fiber so that I/O does not
 * block the main event loop.  Callers must be operating inside a running Revolt
 * event loop (e.g. inside {@see \Amp\async()} or an existing fiber) to benefit
 * from the non-blocking behaviour.
 *
 * @internal
 */
final class ServerMonitor
{
    private bool $running = false;

    /** Dedicated monitoring connection (separate from the application pool). */
    private ?Connection $connection = null;

    /**
     * @param string  $host                    Hostname or IP of the target server.
     * @param int     $port                    TCP port.
     * @param Closure $onUpdate                Called with {@see InternalServerDescription} after each check.
     * @param int     $heartbeatFrequencyMs    Interval between successive checks (default 10 s).
     * @param int     $minHeartbeatFrequencyMs Minimum wait between checks after a failure (default 500 ms).
     */
    public function __construct(
        private string $host,
        private int $port,
        private Closure $onUpdate,
        private int $heartbeatFrequencyMs = 10_000,
        private int $minHeartbeatFrequencyMs = 500,
    ) {
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Start the monitoring fiber via the Revolt event loop.
     * Safe to call multiple times — a second call is a no-op.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        // Schedule the monitor loop as a background fiber.  async() queues
        // the fiber via EventLoop::queue() and is safe to call from any context
        // (fiber or main).  We must NOT await here — the caller is not inside
        // a suspendable fiber at this point, and we want the loop to run in the
        // background while the caller proceeds.
        async(fn () => $this->monitorLoop());
    }

    /**
     * Stop the monitoring fiber and close the monitoring connection.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->closeConnection();
    }

    /**
     * Wake up the monitor immediately for an unscheduled check.
     *
     * The current implementation restarts the monitor; a production driver
     * would use a more efficient signalling mechanism (e.g. a condition variable
     * or a semaphore), but for this pure-userland reference implementation,
     * stopping and restarting is functionally correct.
     */
    public function requestImmediateCheck(): void
    {
        if (! $this->running) {
            $this->start();

            return;
        }

        // Schedule an immediate extra check as a background fiber.
        async(function (): void {
            $sd = $this->checkServer();
            ($this->onUpdate)($sd);
        });
    }

    // -------------------------------------------------------------------------
    // Private — monitor loop
    // -------------------------------------------------------------------------

    /**
     * The main monitoring loop.  Runs until {@see self::stop()} is called.
     *
     * This method suspends the calling fiber via {@see \Amp\delay()} between
     * iterations so that other fibers can make progress.
     */
    private function monitorLoop(): void
    {
        while ($this->running) {
            $sd = $this->checkServer();

            ($this->onUpdate)($sd);

            if (! $this->running) {
                break;
            }

            // Sleep for the full heartbeat interval before the next check.
            delay($this->heartbeatFrequencyMs / 1000.0);
        }

        $this->closeConnection();
    }

    /**
     * Send a hello command to the server, measure round-trip time, and return
     * an {@see InternalServerDescription} reflecting the result.
     *
     * On network or command failure the server is marked as Unknown and the
     * monitor waits for minHeartbeatFrequencyMs before returning, so the
     * caller (monitorLoop) will re-check sooner than the full heartbeat interval.
     */
    private function checkServer(): InternalServerDescription
    {
        $startUs = (int) (microtime(true) * 1_000_000);

        try {
            $conn = $this->getConnection();

            $helloCmd = ['hello' => 1, '$db' => 'admin'];
            [$bytes] = OpMsgEncoder::encodeWithRequestId($helloCmd);

            $responseBytes = $conn->sendMessage($bytes);
            $endUs         = (int) (microtime(true) * 1_000_000);
            $rttMs         = (int) (($endUs - $startUs) / 1_000);

            $decoded  = OpMsgDecoder::decode($responseBytes);
            $body     = $decoded['body'];
            $response = is_array($body) ? $body : (array) $body;

            return InternalServerDescription::fromHello($this->host, $this->port, $response, $rttMs);
        } catch (Throwable $e) {
            // Discard the broken connection so the next check opens a fresh one.
            $this->closeConnection();

            // Wait for the minimum heartbeat interval before reporting failure.
            delay($this->minHeartbeatFrequencyMs / 1000.0);

            return (new InternalServerDescription(
                host: $this->host,
                port: $this->port,
            ))->withError($e);
        }
    }

    // -------------------------------------------------------------------------
    // Private — connection management
    // -------------------------------------------------------------------------

    /**
     * Return the existing monitoring connection, creating it if necessary.
     *
     * @throws ConnectionException on failure.
     */
    private function getConnection(): Connection
    {
        if ($this->connection !== null && ! $this->connection->isClosed()) {
            return $this->connection;
        }

        $conn = new Connection($this->host, $this->port);
        $conn->connect();

        $this->connection = $conn;

        return $conn;
    }

    /**
     * Close and discard the monitoring connection (if any).
     */
    private function closeConnection(): void
    {
        if ($this->connection === null) {
            return;
        }

        $this->connection->close();
        $this->connection = null;
    }
}
