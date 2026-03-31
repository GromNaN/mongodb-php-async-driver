<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

final class TopologyChangedEvent
{
    public function __construct(
        private readonly string $topologyId,
        private readonly string $previousTopologyType,
        private readonly string $newTopologyType,
    ) {
    }

    public function getTopologyId(): string
    {
        return $this->topologyId;
    }

    public function getPreviousTopologyType(): string
    {
        return $this->previousTopologyType;
    }

    public function getNewTopologyType(): string
    {
        return $this->newTopologyType;
    }

    public function __debugInfo(): array
    {
        return [
            'topologyId'     => $this->topologyId,
            'newDescription' => $this->newTopologyType,
            'oldDescription' => $this->previousTopologyType,
        ];
    }
}
