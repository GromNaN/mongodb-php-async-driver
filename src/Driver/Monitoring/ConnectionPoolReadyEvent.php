<?php

declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

/**
 * Event emitted when a connection pool becomes ready (operational).
 */
final class ConnectionPoolReadyEvent
{
    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Multiple,PSR2.Classes.PropertyDeclaration.ScopeMissing
    public string $address { get => $this->host . ':' . $this->port; }

    public function __construct(
        public readonly string $host,
        public readonly int $port,
    ) {
    }
}
