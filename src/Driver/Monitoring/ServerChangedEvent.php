<?php declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

final class ServerChangedEvent
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $topologyId,
        private readonly \MongoDB\Driver\ServerDescription $previousDescription,
        private readonly \MongoDB\Driver\ServerDescription $newDescription,
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

    public function getPreviousDescription(): \MongoDB\Driver\ServerDescription
    {
        return $this->previousDescription;
    }

    public function getNewDescription(): \MongoDB\Driver\ServerDescription
    {
        return $this->newDescription;
    }
}
