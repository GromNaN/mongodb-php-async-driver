<?php

declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

/**
 * Event emitted when a connection finishes its setup handshake and is ready for use.
 */
final class ConnectionReadyEvent
{
    // phpcs:ignore PSR2.Classes.PropertyDeclaration.Multiple,PSR2.Classes.PropertyDeclaration.ScopeMissing
    public string $address { get => $this->host . ':' . $this->port; }

    /** @param int $durationMicros Duration of the connection setup (TCP + hello + auth) in microseconds. */
    public function __construct(
        public readonly int $connectionId,
        public readonly string $host,
        public readonly int $port,
        public readonly int $durationMicros,
    ) {
    }
}
