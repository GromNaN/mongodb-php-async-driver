<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;

final class ServerClosedEvent
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ObjectId $topologyId,
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

    public function getTopologyId(): ObjectId
    {
        return $this->topologyId;
    }

    public function __debugInfo(): array
    {
        return [
            'host'       => $this->host,
            'port'       => $this->port,
            'topologyId' => $this->topologyId,
        ];
    }
}
