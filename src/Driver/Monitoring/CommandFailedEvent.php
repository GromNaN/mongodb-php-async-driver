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
        private readonly ?ObjectId $serviceId = null,
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

    public function getRequestId(): int
    {
        return $this->requestId;
    }

    public function getOperationId(): int
    {
        return $this->operationId;
    }

    public function getDurationMicros(): int
    {
        return $this->durationMicros;
    }

    public function getServiceId(): ?ObjectId
    {
        return $this->serviceId;
    }
}
