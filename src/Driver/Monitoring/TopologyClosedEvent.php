<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;

final class TopologyClosedEvent
{
    private function __construct(
        private readonly ObjectId $topologyId,
    ) {
    }

    /** @internal */
    public static function create(ObjectId $topologyId): self
    {
        return new self($topologyId);
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
