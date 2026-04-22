<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use Exception;

final class ServerHeartbeatFailedEvent
{
    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $durationMicros,
        private readonly Exception $error,
        private readonly bool $awaited,
    ) {
    }

    /** @internal */
    public static function create(string $host, int $port, int $durationMicros, Exception $error, bool $awaited): self
    {
        return new self($host, $port, $durationMicros, $error, $awaited);
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

    public function getError(): Exception
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
