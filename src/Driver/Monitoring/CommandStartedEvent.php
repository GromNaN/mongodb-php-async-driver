<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;

final class CommandStartedEvent
{
    private function __construct(
        private readonly string $commandName,
        private readonly object $command,
        private readonly string $databaseName,
        private readonly int $requestId,
        private readonly int $operationId,
        private readonly string $host = '',
        private readonly int $port = 27017,
        private readonly ?ObjectId $serviceId = null,
        private readonly ?int $serverConnectionId = null,
    ) {
    }

    /** @internal */
    public static function create(
        string $commandName,
        object $command,
        string $databaseName,
        int $requestId,
        int $operationId,
        string $host = '',
        int $port = 27017,
        ?ObjectId $serviceId = null,
        ?int $serverConnectionId = null,
    ): self {
        return new self($commandName, $command, $databaseName, $requestId, $operationId, $host, $port, $serviceId, $serverConnectionId);
    }

    public function getCommandName(): string
    {
        return $this->commandName;
    }

    public function getCommand(): object
    {
        return $this->command;
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
            'host'              => $this->host,
            'port'              => $this->port,
            'commandName'       => $this->commandName,
            'databaseName'      => $this->databaseName,
            'command'           => $this->command,
            'operationId'       => (string) $this->operationId,
            'requestId'         => (string) $this->requestId,
            'serviceId'         => $this->serviceId,
            'serverConnectionId' => $this->serverConnectionId,
        ];
    }
}
