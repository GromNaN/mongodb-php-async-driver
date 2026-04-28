<?php

declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

/**
 * Event emitted when a connection pool is created.
 *
 * @see https://github.com/mongodb/specifications/blob/master/source/connection-monitoring-and-pooling/connection-monitoring-and-pooling.md
 */
final class ConnectionPoolCreatedEvent
{
    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Multiple,PSR2.Classes.PropertyDeclaration.ScopeMissing
    public string $address { get => $this->host . ':' . $this->port; }

    /** @param array<string, mixed> $options Pool configuration options. */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly array $options,
    ) {
    }
}
