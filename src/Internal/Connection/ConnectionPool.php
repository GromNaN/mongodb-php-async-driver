<?php

declare(strict_types=1);

namespace MongoDB\Internal\Connection;

use Amp\DeferredFuture;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Monitoring\ConnectionCheckOutFailedEvent;
use MongoDB\Driver\Monitoring\ConnectionClosedEvent;
use MongoDB\Internal\Monitoring\Dispatcher;
use MongoDB\Internal\Uri\UriOptions;
use SplQueue;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function assert;
use function hrtime;
use function intdiv;
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

    /** Monotonically increasing connection ID counter (per-pool). */
    private int $nextConnectionId = 1;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $maxPoolSize = 100,
        private readonly int $minPoolSize = 0,
        private readonly int $maxConnecting = 2,
        private readonly int $waitQueueTimeoutMS = 0,
        private ?UriOptions $options = null,
        private ?Dispatcher $dispatcher = null,
    ) {
        $this->idle    = new SplQueue();
        $this->waiters = new SplQueue();

        $this->dispatcher?->dispatchConnectionPoolCreated($this->host, $this->port, [
            'maxPoolSize'        => $this->maxPoolSize,
            'minPoolSize'        => $this->minPoolSize,
            'maxConnecting'      => $this->maxConnecting,
            'waitQueueTimeoutMS' => $this->waitQueueTimeoutMS,
        ]);
        $this->dispatcher?->dispatchConnectionPoolReady($this->host, $this->port);
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
        $checkOutStartedAt = hrtime(true);

        $this->dispatcher?->dispatchConnectionCheckOutStarted($this->host, $this->port);

        if ($this->closed) {
            $durationMicros = intdiv(hrtime(true) - $checkOutStartedAt, 1_000);
            $this->dispatcher?->dispatchConnectionCheckOutFailed($this->host, $this->port, ConnectionCheckOutFailedEvent::REASON_POOL_CLOSED, $durationMicros);

            throw new ConnectionException('Connection pool is closed');
        }

        try {
            $conn = $this->doAcquire();
        } catch (Throwable $e) {
            $durationMicros = intdiv(hrtime(true) - $checkOutStartedAt, 1_000);
            $reason = $e->getMessage() === sprintf('Timed out waiting for a connection after %d ms', $this->waitQueueTimeoutMS)
                ? ConnectionCheckOutFailedEvent::REASON_TIMEOUT
                : ConnectionCheckOutFailedEvent::REASON_CONNECTION_ERROR;
            $this->dispatcher?->dispatchConnectionCheckOutFailed($this->host, $this->port, $reason, $durationMicros);

            throw $e;
        }

        $durationMicros = intdiv(hrtime(true) - $checkOutStartedAt, 1_000);
        $this->dispatcher?->dispatchConnectionCheckedOut($this->host, $this->port, $conn->getConnectionId(), $durationMicros);

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

        $connId = $conn->getConnectionId();
        $this->dispatcher?->dispatchConnectionCheckedIn($this->host, $this->port, $connId);

        // If the connection has gone bad, close it and open a slot for a waiter.
        if ($conn->isClosed()) {
            $this->dispatcher?->dispatchConnectionClosed($this->host, $this->port, $connId, ConnectionClosedEvent::REASON_ERROR);
            $this->scheduleWaiter();

            return;
        }

        // If the pool has been closed while this connection was checked out, destroy it.
        if ($this->closed) {
            $conn->close();
            $this->dispatcher?->dispatchConnectionClosed($this->host, $this->port, $connId, ConnectionClosedEvent::REASON_POOL_CLOSED);

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
     * Close all idle connections when the pool is garbage-collected.
     *
     * This prevents file-descriptor leaks when a Manager (and its pool) goes
     * out of scope without an explicit close() call — the common case in
     * per-test Manager instances.
     */
    public function __destruct()
    {
        while (! $this->idle->isEmpty()) {
            $conn = $this->idle->dequeue();
            assert($conn instanceof Connection);
            $conn->close();
        }
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
            $connId = $conn->getConnectionId();
            $conn->close();
            $this->dispatcher?->dispatchConnectionClosed($this->host, $this->port, $connId, ConnectionClosedEvent::REASON_POOL_CLOSED);
        }

        $this->dispatcher?->dispatchConnectionPoolClosed($this->host, $this->port);

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
     * Core acquire logic without the CMAP checkout events.
     *
     * @throws ConnectionException on connection failure or wait-queue timeout.
     */
    private function doAcquire(): Connection
    {
        // 1. Pop a healthy idle connection if one is available.
        while (! $this->idle->isEmpty()) {
            $conn = $this->idle->dequeue();
            assert($conn instanceof Connection);
            if ($conn->isClosed()) {
                // Discard stale connection; do not count it as inUse.
                $this->dispatcher?->dispatchConnectionClosed($this->host, $this->port, $conn->getConnectionId(), ConnectionClosedEvent::REASON_STALE);
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

        $connId = $this->nextConnectionId++;
        $conn   = new Connection($this->host, $this->port, $connId);

        $this->dispatcher?->dispatchConnectionCreated($this->host, $this->port, $connId);

        $createdAt = hrtime(true);

        try {
            $conn->connect($this->options);
        } catch (Throwable $e) {
            $this->dispatcher?->dispatchConnectionClosed($this->host, $this->port, $connId, ConnectionClosedEvent::REASON_ERROR);

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

        $this->dispatcher?->dispatchConnectionReady($this->host, $this->port, $connId, intdiv(hrtime(true) - $createdAt, 1_000));

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

            $connId = $this->nextConnectionId++;
            $conn   = new Connection($this->host, $this->port, $connId);

            $this->dispatcher?->dispatchConnectionCreated($this->host, $this->port, $connId);

            $createdAt = hrtime(true);

            async(function () use ($deferred, $conn, $connId, $createdAt): void {
                try {
                    $conn->connect($this->options);

                    $this->dispatcher?->dispatchConnectionReady($this->host, $this->port, $connId, intdiv(hrtime(true) - $createdAt, 1_000));

                    if (! $deferred->isComplete()) {
                        // Do NOT increment inUse here; acquire() does it after await().
                        $deferred->complete($conn);
                    } else {
                        // Waiter was served by another path while we were connecting.
                        // Close the superfluous connection to avoid file-descriptor leaks.
                        $conn->close();
                        $this->dispatcher?->dispatchConnectionClosed($this->host, $this->port, $connId, ConnectionClosedEvent::REASON_STALE);
                    }
                } catch (Throwable $e) {
                    $this->dispatcher?->dispatchConnectionClosed($this->host, $this->port, $connId, ConnectionClosedEvent::REASON_ERROR);
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
