<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;

final class TopologyClosedEvent
{
    public function __construct(
        private readonly ObjectId $topologyId,
    ) {
    }

    public function getTopologyId(): ObjectId
    {
        return $this->topologyId;
    }

    public function __debugInfo(): array
    {
        return ['topologyId' => $this->topologyId];
    }
}
