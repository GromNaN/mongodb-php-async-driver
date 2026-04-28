<?php

declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

/**
 * Event emitted when a connection is destroyed.
 */
final class ConnectionClosedEvent
{
    /** Connection was closed because the pool was closed. */
    public const REASON_POOL_CLOSED = 'poolClosed';

    /** Connection was closed because it became stale (e.g. pool was cleared). */
    public const REASON_STALE = 'stale';

    /** Connection was closed because it was idle too long. */
    public const REASON_IDLE = 'idle';

    /** Connection was closed due to a connection error. */
    public const REASON_ERROR = 'error';

    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Multiple,PSR2.Classes.PropertyDeclaration.ScopeMissing
    public string $address { get => $this->host . ':' . $this->port; }

    /** @param self::REASON_* $reason Reason for closing the connection. */
    public function __construct(
        public readonly int $connectionId,
        public readonly string $host,
        public readonly int $port,
        public readonly string $reason,
    ) {
    }
}
