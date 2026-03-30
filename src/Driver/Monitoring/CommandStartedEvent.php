<?php
declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

use MongoDB\BSON\ObjectId;

final class CommandStartedEvent
{
    public function __construct(
        private readonly string $commandName,
        private readonly object $command,
        private readonly string $databaseName,
        private readonly int $requestId,
        private readonly int $operationId,
        private readonly ?ObjectId $serviceId = null,
    ) {
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

    public function getRequestId(): int
    {
        return $this->requestId;
    }

    public function getOperationId(): int
    {
        return $this->operationId;
    }

    public function getServiceId(): ?ObjectId
    {
        return $this->serviceId;
    }
}
