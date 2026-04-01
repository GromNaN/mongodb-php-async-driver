<?php
declare(strict_types=1);

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
    private WriteConcern $writeConcern;

    /**
     * Private constructor. Use the internal factory to create instances.
     *
     * @see \MongoDB\Internal\WriteResult\WriteResultFactory::create()
     */
    private function __construct()
    {
    }

    /**
     * @internal Creates a new WriteResult instance.
     * Used by \MongoDB\Internal\WriteResult\WriteResultFactory
     */
    public static function createFromInternal(
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
        ?WriteConcern $writeConcern = null,
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
        $instance->writeConcern = $writeConcern ?? WriteConcern::createDefault();

        return $instance;
    }

    public function getInsertedCount(): ?int
    {
        if (! $this->acknowledged) {
            throw new Exception\LogicException(
                'MongoDB\Driver\WriteResult::getInsertedCount() should not be called for an unacknowledged write result',
            );
        }

        return $this->insertedCount;
    }

    public function getMatchedCount(): ?int
    {
        if (! $this->acknowledged) {
            throw new Exception\LogicException(
                'MongoDB\Driver\WriteResult::getMatchedCount() should not be called for an unacknowledged write result',
            );
        }

        return $this->matchedCount;
    }

    public function getModifiedCount(): ?int
    {
        if (! $this->acknowledged) {
            throw new Exception\LogicException(
                'MongoDB\Driver\WriteResult::getModifiedCount() should not be called for an unacknowledged write result',
            );
        }

        return $this->modifiedCount;
    }

    public function getDeletedCount(): ?int
    {
        if (! $this->acknowledged) {
            throw new Exception\LogicException(
                'MongoDB\Driver\WriteResult::getDeletedCount() should not be called for an unacknowledged write result',
            );
        }

        return $this->deletedCount;
    }

    public function getUpsertedCount(): ?int
    {
        if (! $this->acknowledged) {
            throw new Exception\LogicException(
                'MongoDB\Driver\WriteResult::getUpsertedCount() should not be called for an unacknowledged write result',
            );
        }

        return $this->upsertedCount;
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

    /** @return WriteError[] */
    public function getWriteErrors(): array
    {
        return $this->writeErrors;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged;
    }

    public function __debugInfo(): array
    {
        $upsertedIdsList = [];
        foreach ($this->upsertedIds as $index => $id) {
            $upsertedIdsList[] = ['index' => $index, '_id' => $id];
        }

        return [
            'nInserted'         => $this->acknowledged ? $this->insertedCount : null,
            'nMatched'          => $this->acknowledged ? $this->matchedCount : null,
            'nModified'         => $this->acknowledged ? $this->modifiedCount : null,
            'nRemoved'          => $this->acknowledged ? $this->deletedCount : null,
            'nUpserted'         => $this->acknowledged ? $this->upsertedCount : null,
            'upsertedIds'       => $upsertedIdsList,
            'writeErrors'       => $this->writeErrors,
            'writeConcernError' => $this->writeConcernError,
            'writeConcern'      => $this->writeConcern,
            'errorReplies'      => [],
        ];
    }
}
