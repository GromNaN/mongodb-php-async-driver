<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;

final class CommandSucceededEvent
{
    private function __construct(
        private readonly string $commandName,
        private readonly object $reply,
        private readonly string $databaseName,
        private readonly int $requestId,
        private readonly int $operationId,
        private readonly int $durationMicros,
        private readonly string $host = '',
        private readonly int $port = 27017,
        private readonly ?ObjectId $serviceId = null,
        private readonly ?int $serverConnectionId = null,
    ) {
    }

    /** @internal */
    public static function create(
        string $commandName,
        object $reply,
        string $databaseName,
        int $requestId,
        int $operationId,
        int $durationMicros,
        string $host = '',
        int $port = 27017,
        ?ObjectId $serviceId = null,
        ?int $serverConnectionId = null,
    ): self {
        return new self($commandName, $reply, $databaseName, $requestId, $operationId, $durationMicros, $host, $port, $serviceId, $serverConnectionId);
    }

    public function getCommandName(): string
    {
        return $this->commandName;
    }

    public function getReply(): object
    {
        return $this->reply;
    }

    public function getDatabaseName(): string
    {
        return $this->databaseName;
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
            'reply'              => $this->reply,
            'operationId'        => (string) $this->operationId,
            'requestId'          => (string) $this->requestId,
            'serviceId'          => $this->serviceId,
            'serverConnectionId' => $this->serverConnectionId,
        ];
    }
}
