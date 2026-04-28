<?php

declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

/**
 * Event emitted when a connection checkout fails.
 */
final class ConnectionCheckOutFailedEvent
{
    /** Checkout failed because the pool was closed. */
    public const REASON_POOL_CLOSED = 'poolClosed';

    /** Checkout failed because a new connection could not be established. */
    public const REASON_CONNECTION_ERROR = 'connectionError';

    /** Checkout failed because the wait queue timed out. */
    public const REASON_TIMEOUT = 'timeout';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Multiple,PSR2.Classes.PropertyDeclaration.ScopeMissing
    public string $address { get => $this->host . ':' . $this->port; }

    /**
     * @param self::REASON_* $reason         Reason for the checkout failure.
     * @param int            $durationMicros Duration from checkout start to failure, in microseconds.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $reason,
        public readonly int $durationMicros,
    ) {
    }
}
