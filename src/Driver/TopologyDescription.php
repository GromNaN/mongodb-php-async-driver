<?php
declare(strict_types=1);

namespace MongoDB\Driver;

final class TopologyDescription
{
    public const TYPE_UNKNOWN                    = 'Unknown';
    public const TYPE_SINGLE                     = 'Single';
    public const TYPE_SHARDED                    = 'Sharded';
    public const TYPE_REPLICA_SET_NO_PRIMARY     = 'ReplicaSetNoPrimary';
    public const TYPE_REPLICA_SET_WITH_PRIMARY   = 'ReplicaSetWithPrimary';
    public const TYPE_LOAD_BALANCED              = 'LoadBalanced';

    /** @param list<ServerDescription> $servers */
    private function __construct(
        private readonly string $type,
        private array $servers = [],
    ) {
    }

    /** @param list<ServerDescription> $servers */
    public static function createFromInternal(string $type, array $servers = []): self
    {
        return new self($type, $servers);
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return list<ServerDescription> */
    public function getServers(): array
    {
        return $this->servers;
    }

    public function hasReadableServer(?ReadPreference $readPreference = null): bool
    {
        return $this->servers !== [];
    }

    public function hasWritableServer(): bool
    {
        return $this->servers !== [];
    }

    public function __debugInfo(): array
    {
        return [
            'servers' => $this->servers,
            'type'    => $this->type,
        ];
    }
}
