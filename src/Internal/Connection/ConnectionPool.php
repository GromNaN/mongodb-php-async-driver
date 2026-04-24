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

    /** @var SplQueue<DeferredFuture<Connection>> */
    private SplQueue $waiters;

    /** Whether the pool has been permanently closed. */
    private bool $closed = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $maxPoolSize = 100,
        private readonly int $minPoolSize = 0,
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

        // 2. Create a new connection if the pool has remaining capacity.
        if ($this->totalConnections() < $this->maxPoolSize) {
            return $this->createAndConnect();
        }

        // 3. Pool is at capacity – enqueue the caller as a waiter.
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

        // If the connection has gone bad, just account for its removal.
        if ($conn->isClosed()) {
            $this->inUse = max(0, $this->inUse - 1);

            return;
        }

        $this->inUse = max(0, $this->inUse - 1);

        // Hand off to the oldest pending waiter, skipping any that already
        // timed out (lazy deletion — they stay in the queue until dequeued here).
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

        // Otherwise push back onto the idle queue.
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
     * @return array{idle: int, inUse: int, total: int}
     */
    public function getStats(): array
    {
        return [
            'idle'  => $this->idle->count(),
            'inUse' => $this->inUse,
            'total' => $this->totalConnections(),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Total number of connections owned by this pool (idle + in-use).
     */
    private function totalConnections(): int
    {
        return $this->idle->count() + $this->inUse;
    }

    /**
     * Create a brand-new Connection, run the hello handshake, and mark it as
     * in-use before returning it to the caller.
     *
     * @throws ConnectionException on connection/handshake failure.
     */
    private function createAndConnect(): Connection
    {
        $conn = new Connection($this->host, $this->port);

        try {
            $conn->connect($this->options);
        } catch (Throwable $e) {
            // Do not increment inUse for a connection that never became ready.
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
        }

        $this->inUse++;

        return $conn;
    }
}
