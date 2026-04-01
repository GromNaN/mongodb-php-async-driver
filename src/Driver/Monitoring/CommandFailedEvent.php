<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;
use Throwable;

final class CommandFailedEvent
{
    public function __construct(
        private readonly string $commandName,
        private readonly string $databaseName,
        private readonly Throwable $error,
        private readonly int $requestId,
        private readonly int $operationId,
        private readonly int $durationMicros,
        private readonly string $host = '',
        private readonly int $port = 27017,
        private readonly ?ObjectId $serviceId = null,
        private readonly ?int $serverConnectionId = null,
        private readonly ?object $reply = null,
    ) {
    }

    public function getCommandName(): string
    {
        return $this->commandName;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
    }

    public function getError(): Throwable
    {
        return $this->error;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getRequestId(): string
    {
        return (string) $this->requestId;
    }

    public function getOperationId(): string
    {
        return (string) $this->operationId;
    }

    public function getDurationMicros(): int
    {
        return $this->durationMicros;
    }

    public function getReply(): object
    {
        return $this->reply ?? (object) [];
    }

    public function getServiceId(): ?ObjectId
    {
        return $this->serviceId;
    }

    public function getServerConnectionId(): ?int
    {
        return $this->serverConnectionId;
    }

    public function __debugInfo(): array
    {
        return [
            'host'               => $this->host,
            'port'               => $this->port,
            'commandName'        => $this->commandName,
            'durationMicros'     => $this->durationMicros,
            'error'              => $this->error,
            'reply'              => $this->reply ?? (object) [],
            'operationId'        => (string) $this->operationId,
            'requestId'          => (string) $this->requestId,
            'serviceId'          => $this->serviceId,
            'serverConnectionId' => $this->serverConnectionId,
        ];
    }
}
