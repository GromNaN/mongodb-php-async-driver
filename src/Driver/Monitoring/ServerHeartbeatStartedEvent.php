<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

final class ServerHeartbeatStartedEvent
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $awaited,
    ) {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }
}
