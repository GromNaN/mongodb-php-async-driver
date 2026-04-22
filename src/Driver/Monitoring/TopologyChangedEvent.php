<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\ServerDescription;
use MongoDB\Driver\TopologyDescription;

final class TopologyChangedEvent
{
    private TopologyDescription $newDescription;
    private TopologyDescription $previousDescription;

    /**
     * @param list<ServerDescription> $previousServers
     * @param list<ServerDescription> $newServers
     */
    private function __construct(
        private readonly ObjectId $topologyId,
        private readonly string $previousTopologyType,
        private readonly string $newTopologyType,
        array $previousServers = [],
        array $newServers = [],
    ) {
        $this->previousDescription = TopologyDescription::createFromInternal($previousTopologyType, $previousServers);
        $this->newDescription      = TopologyDescription::createFromInternal($newTopologyType, $newServers);
    }

    /**
     * @internal
     *
     * @param list<ServerDescription> $previousServers
     * @param list<ServerDescription> $newServers
     */
    public static function create(ObjectId $topologyId, string $previousTopologyType, string $newTopologyType, array $previousServers = [], array $newServers = []): self
    {
        return new self($topologyId, $previousTopologyType, $newTopologyType, $previousServers, $newServers);
    }

    public function getTopologyId(): ObjectId
    {
        return $this->topologyId;
    }

    public function getPreviousDescription(): TopologyDescription
    {
        return $this->previousDescription;
    }

    public function getNewDescription(): TopologyDescription
    {
        return $this->newDescription;
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
            'newDescription' => $this->newDescription,
            'oldDescription' => $this->previousDescription,
        ];
    }
}
