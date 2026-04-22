<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

final class ServerHeartbeatStartedEvent
{
    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $awaited,
    ) {
    }

    /** @internal */
    public static function create(string $host, int $port, bool $awaited): self
    {
        return new self($host, $port, $awaited);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }

    public function __debugInfo(): array
    {
        return [
            'host'    => $this->host,
            'port'    => $this->port,
            'awaited' => $this->awaited,
        ];
    }
}
