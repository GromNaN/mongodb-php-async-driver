<?php declare(strict_types=1);

namespace MongoDB\Driver;

final class WriteResult
{
    private int $insertedCount;
    private int $matchedCount;
    private int $modifiedCount;
    private int $deletedCount;
    private int $upsertedCount;
    private array $upsertedIds;
    private Server $server;
    private ?WriteConcernError $writeConcernError;
    /** @var WriteError[] */
    private array $writeErrors;
    private bool $acknowledged;

    /**
     * Private constructor. Use the internal factory to create instances.
     *
     * @see \MongoDB\Internal\WriteResult\WriteResultFactory::create()
     */
    private function __construct() {}

    /**
     * @internal Creates a new WriteResult instance.
     * Used by \MongoDB\Internal\WriteResult\WriteResultFactory
     */
    public static function _createFromInternal(
        int $insertedCount,
        int $matchedCount,
        int $modifiedCount,
        int $deletedCount,
        int $upsertedCount,
        array $upsertedIds,
        Server $server,
        ?WriteConcernError $writeConcernError,
        array $writeErrors,
        bool $acknowledged,
    ): static {
        $instance = new static();
        $instance->insertedCount = $insertedCount;
        $instance->matchedCount = $matchedCount;
        $instance->modifiedCount = $modifiedCount;
        $instance->deletedCount = $deletedCount;
        $instance->upsertedCount = $upsertedCount;
        $instance->upsertedIds = $upsertedIds;
        $instance->server = $server;
        $instance->writeConcernError = $writeConcernError;
        $instance->writeErrors = $writeErrors;
        $instance->acknowledged = $acknowledged;

        return $instance;
    }

    public function getInsertedCount(): ?int
    {
        return $this->acknowledged ? $this->insertedCount : null;
    }

    public function getMatchedCount(): ?int
    {
        return $this->acknowledged ? $this->matchedCount : null;
    }

    public function getModifiedCount(): ?int
    {
        return $this->acknowledged ? $this->modifiedCount : null;
    }

    public function getDeletedCount(): ?int
    {
        return $this->acknowledged ? $this->deletedCount : null;
    }

    public function getUpsertedCount(): ?int
    {
        return $this->acknowledged ? $this->upsertedCount : null;
    }

    public function getUpsertedIds(): array
    {
        return $this->upsertedIds;
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function getWriteConcernError(): ?WriteConcernError
    {
        return $this->writeConcernError;
    }

    /**
     * @return WriteError[]
     */
    public function getWriteErrors(): array
    {
        return $this->writeErrors;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged;
    }
}
