<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;

final class ServerClosedEvent
{
    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ObjectId $topologyId,
    ) {
    }

    /** @internal */
    public static function create(string $host, int $port, ObjectId $topologyId): self
    {
        return new self($host, $port, $topologyId);
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
