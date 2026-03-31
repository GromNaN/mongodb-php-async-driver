<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

final class TopologyOpeningEvent
{
    public function __construct(
        private readonly string $topologyId,
    ) {
    }

    public function getTopologyId(): string
    {
        return $this->topologyId;
    }

    public function __debugInfo(): array
    {
        return ['topologyId' => $this->topologyId];
    }
}
