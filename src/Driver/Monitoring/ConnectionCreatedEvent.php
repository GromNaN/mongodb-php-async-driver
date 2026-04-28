<?php

declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

/**
 * Event emitted when a new connection object is created (before handshake).
 */
final class ConnectionCreatedEvent
{
    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Multiple,PSR2.Classes.PropertyDeclaration.ScopeMissing
    public string $address { get => $this->host . ':' . $this->port; }

    public function __construct(
        public readonly int $connectionId,
        public readonly string $host,
        public readonly int $port,
    ) {
    }
}
