<?php declare(strict_types=1);

namespace MongoDB\Driver\Monitoring;

final class CommandSucceededEvent
{
    public function __construct(
        private readonly string $commandName,
        private readonly object $reply,
        private readonly string $databaseName,
        private readonly int $requestId,
        private readonly int $operationId,
        private readonly int $durationMicros,
        private readonly ?\MongoDB\BSON\ObjectId $serviceId = null,
    ) {}

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

    public function getServiceId(): ?\MongoDB\BSON\ObjectId
    {
        return $this->serviceId;
    }
}
