<?php

declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

/**
 * Event emitted when a connection is successfully checked out of the pool.
 */
final class ConnectionCheckedOutEvent
{
    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Multiple,PSR2.Classes.PropertyDeclaration.ScopeMissing
    public string $address { get => $this->host . ':' . $this->port; }

    /** @param int $durationMicros Duration from checkout start to connection ready, in microseconds. */
    public function __construct(
        public readonly int $connectionId,
        public readonly string $host,
        public readonly int $port,
        public readonly int $durationMicros,
    ) {
    }
}
