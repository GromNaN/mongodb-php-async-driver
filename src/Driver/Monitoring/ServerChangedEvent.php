<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\ServerDescription;

final class ServerChangedEvent
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ObjectId $topologyId,
        private readonly ServerDescription $previousDescription,
        private readonly ServerDescription $newDescription,
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

    public function getPreviousDescription(): ServerDescription
    {
        return $this->previousDescription;
    }

    public function getNewDescription(): ServerDescription
    {
        return $this->newDescription;
    }

    public function __debugInfo(): array
    {
        return [
            'host'           => $this->host,
            'port'           => $this->port,
            'topologyId'     => $this->topologyId,
            'newDescription' => $this->newDescription,
            'oldDescription' => $this->previousDescription,
        ];
    }
}
