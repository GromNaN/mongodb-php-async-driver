<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use Throwable;

final class ServerHeartbeatFailedEvent
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $durationMicros,
        private readonly Throwable $error,
        private readonly bool $awaited,
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

    public function getDurationMicros(): int
    {
        return $this->durationMicros;
    }

    public function getError(): Throwable
    {
        return $this->error;
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }

    public function __debugInfo(): array
    {
        return [
            'host'           => $this->host,
            'port'           => $this->port,
            'awaited'        => $this->awaited,
            'durationMicros' => $this->durationMicros,
            'error'          => $this->error,
        ];
    }
}
