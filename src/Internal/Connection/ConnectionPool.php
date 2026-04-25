<?php

declare(strict_types=1);

namespace MongoDB\Internal\Connection;

use Amp\DeferredFuture;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Internal\Uri\UriOptions;
use SplQueue;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function assert;
use function max;
use function sprintf;

/**
 * Manages a pool of {@see Connection} objects to a single MongoDB server.
 *
 * Callers must run inside a Revolt/Amp fiber context because `acquire()` may
 * suspend the current fiber when no connection is immediately available.
 *
 * @internal
 */
final class ConnectionPool
{
    /** @var SplQueue<Connection> */
    private SplQueue $idle;

    /** Number of connections currently checked out by callers. */
    private int $inUse = 0;

    /** Number of connections currently being established (pending). */
    private int $pendingConnections = 0;

    /** @var SplQueue<DeferredFuture<Connection>> */
    private SplQueue $waiters;

    /** Whether the pool has been permanently closed. */
    private bool $closed = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $maxPoolSize = 100,
        private readonly int $minPoolSize = 0,
        private readonly int $maxConnecting = 2,
        private readonly int $waitQueueTimeoutMS = 0,
        private ?UriOptions $options = null,
    ) {
        $this->idle    = new SplQueue();
        $this->waiters = new SplQueue();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Acquire a ready connection from the pool.
     *
     * Returns immediately if an idle connection is available or the pool has
     * capacity for a new connection. Otherwise suspends the calling fiber until
     * a connection becomes available (or the wait-queue timeout expires).
     *
     * @throws ConnectionException if the pool is closed or the wait times out.
     */
    public function acquire(): Connection
    {
        if ($this->closed) {
            throw new ConnectionException('Connection pool is closed');
        }

        // 1. Pop a healthy idle connection if one is available.
        while (! $this->idle->isEmpty()) {
            $conn = $this->idle->dequeue();
            assert($conn instanceof Connection);
            if ($conn->isClosed()) {
                // Discard stale connection; do not count it as inUse.
                continue;
            }

            $this->inUse++;

            return $conn;
        }

        // 2. Create a new connection if both pool capacity and connecting limit allow it.
        if ($this->totalConnections() < $this->maxPoolSize && $this->pendingConnections < $this->maxConnecting) {
            return $this->createAndConnect();
        }

        // 3. Pool is at capacity or maxConnecting is reached – enqueue the caller as a waiter.
        $deferred = new DeferredFuture();
        $this->waiters->enqueue($deferred);

        if ($this->waitQueueTimeoutMS > 0) {
            // Schedule a timeout that rejects the waiter if still pending.
            // Lazy deletion: the deferred stays in the queue but is skipped when
            // dequeued in release() because isComplete() will return true.
            $timeoutMs = $this->waitQueueTimeoutMS;
            async(static function () use ($deferred, $timeoutMs): void {
                delay($timeoutMs / 1000.0);
                if ($deferred->isComplete()) {
                    return; // Already resolved by release() — nothing to do.
                }

                $deferred->error(
                    new ConnectionException(
                        sprintf(
                            'Timed out waiting for a connection after %d ms',
                            $timeoutMs,
                        ),
                    ),
                );
            });
        }

        $conn = $deferred->getFuture()->await();
        assert($conn instanceof Connection);

        $this->inUse++;

        return $conn;
    }

    /**
     * Return a connection to the pool after use.
     *
     * If waiters are queued the connection is handed directly to the oldest
     * one; otherwise it is pushed onto the idle queue.
     */
    public function release(Connection $conn): void
    {
        $conn->markUsed();

        $this->inUse = max(0, $this->inUse - 1);

        // If the connection has gone bad, open a slot for a waiter to create a fresh one.
        if ($conn->isClosed()) {
            $this->scheduleWaiter();

            return;
        }

        // Hand off to the oldest pending waiter (blocked due to maxPoolSize), skipping any
        // that already timed out (lazy deletion).
        while (! $this->waiters->isEmpty()) {
            /** @var DeferredFuture<Connection> $deferred */
            $deferred = $this->waiters->dequeue();
            if ($deferred->isComplete()) {
                continue; // Timed out — discard and try the next waiter.
            }

            $this->inUse++;
            $deferred->complete($conn);

            return;
        }

        // No waiters — push back onto the idle queue.
        $this->idle->enqueue($conn);
    }

    /**
     * Permanently close the pool: close all idle connections and reject every
     * pending waiter with a ConnectionException.
     */
    public function close(): void
    {
        $this->closed = true;

        // Drain idle connections.
        while (! $this->idle->isEmpty()) {
            $conn = $this->idle->dequeue();
            assert($conn instanceof Connection);
            $conn->close();
        }

        // Reject all pending waiters.
        while (! $this->waiters->isEmpty()) {
            /** @var DeferredFuture<Connection> $deferred */
            $deferred = $this->waiters->dequeue();
            if ($deferred->isComplete()) {
                continue;
            }

            $deferred->error(new ConnectionException('Connection pool has been closed'));
        }
    }

    /**
     * Return a snapshot of pool statistics.
     *
     * @return array{idle: int, inUse: int, pending: int, total: int}
     */
    public function getStats(): array
    {
        return [
            'idle'    => $this->idle->count(),
            'inUse'   => $this->inUse,
            'pending' => $this->pendingConnections,
            'total'   => $this->totalConnections(),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Total number of connections owned by this pool (idle + in-use + pending).
     */
    private function totalConnections(): int
    {
        return $this->idle->count() + $this->inUse + $this->pendingConnections;
    }

    /**
     * Create a brand-new Connection, run the hello handshake, and mark it as
     * in-use before returning it to the caller.
     *
     * Increments {@see $pendingConnections} for the duration of the handshake
     * and decrements it when done (success or failure), then attempts to serve
     * any waiter that was blocked because maxConnecting was reached.
     *
     * @throws ConnectionException on connection/handshake failure.
     */
    private function createAndConnect(): Connection
    {
        $this->pendingConnections++;

        try {
            $conn = new Connection($this->host, $this->port);
            $conn->connect($this->options);
        } catch (Throwable $e) {
            throw new ConnectionException(
                sprintf(
                    'Failed to connect to %s:%d: %s',
                    $this->host,
                    $this->port,
                    $e->getMessage(),
                ),
                (int) $e->getCode(),
                $e,
            );
        } finally {
            $this->pendingConnections--;
            // A maxConnecting slot just freed up — let a waiter proceed.
            $this->scheduleWaiter();
        }

        $this->inUse++;

        return $conn;
    }

    /**
     * After a pending-connection slot frees up, check whether a queued waiter
     * can now start establishing a new connection.
     *
     * Waiters blocked by maxPoolSize are served by {@see release()} (they
     * receive a recycled connection).  This method serves waiters that were
     * blocked solely because maxConnecting was reached while the pool still had
     * capacity — they need a brand-new connection created for them.
     *
     * A background fiber is used so that the connecting handshake does not
     * block the caller of scheduleWaiter().
     */
    private function scheduleWaiter(): void
    {
        // Nothing to do if neither limit allows creating a new connection.
        if ($this->totalConnections() >= $this->maxPoolSize || $this->pendingConnections >= $this->maxConnecting) {
            return;
        }

        // Drain completed (timed-out) waiters and grab the first live one.
        while (! $this->waiters->isEmpty()) {
            /** @var DeferredFuture<Connection> $deferred */
            $deferred = $this->waiters->dequeue();
            if ($deferred->isComplete()) {
                continue; // Already timed out — skip.
            }

            // Establish a connection for this waiter in a background fiber so
            // that the current fiber (which called scheduleWaiter) is not blocked.
            $this->pendingConnections++;

            async(function () use ($deferred): void {
                try {
                    $conn = new Connection($this->host, $this->port);
                    $conn->connect($this->options);

                    if (! $deferred->isComplete()) {
                        // Do NOT increment inUse here; acquire() does it after await().
                        $deferred->complete($conn);
                    } else {
                        // Waiter was served by another path while we were connecting.
                        // Close the superfluous connection to avoid file-descriptor leaks.
                        $conn->close();
                    }
                } catch (Throwable $e) {
                    if (! $deferred->isComplete()) {
                        $deferred->error(
                            new ConnectionException(
                                sprintf(
                                    'Failed to connect to %s:%d: %s',
                                    $this->host,
                                    $this->port,
                                    $e->getMessage(),
                                ),
                                (int) $e->getCode(),
                                $e,
                            ),
                        );
                    }
                } finally {
                    $this->pendingConnections--;
                    // Cascade: try to serve the next waiter if another slot opened.
                    $this->scheduleWaiter();
                }
            });

            return; // One background connection started — stop here.
        }
    }
}
