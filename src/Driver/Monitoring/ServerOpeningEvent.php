<?php declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

final class ServerOpeningEvent
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $topologyId,
    ) {}

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getTopologyId(): string
    {
        return $this->topologyId;
    }
}
