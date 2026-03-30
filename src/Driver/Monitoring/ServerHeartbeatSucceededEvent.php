<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

final class ServerHeartbeatSucceededEvent
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $durationMicros,
        private readonly object $reply,
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

    public function getReply(): object
    {
        return $this->reply;
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }
}
