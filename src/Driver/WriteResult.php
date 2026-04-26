<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use function sprintf;

final class WriteResult
{
    private ?int $insertedCount;
    private ?int $matchedCount;
    private ?int $modifiedCount;
    private ?int $deletedCount;
    private ?int $upsertedCount;
    private array $upsertedIds;
    private Server $server;
    private ?WriteConcernError $writeConcernError;
    /** @var WriteError[] */
    private array $writeErrors;
    private bool $acknowledged;
    private WriteConcern $writeConcern;
    /** @var object[] */
    private array $errorReplies;

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
        ?int $insertedCount,
        ?int $matchedCount,
        ?int $modifiedCount,
        ?int $deletedCount,
        ?int $upsertedCount,
        array $upsertedIds,
        Server $server,
        ?WriteConcernError $writeConcernError,
        array $writeErrors,
        bool $acknowledged,
        ?WriteConcern $writeConcern = null,
        array $errorReplies = [],
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
        $instance->errorReplies = $errorReplies;

        return $instance;
    }

    public function getInsertedCount(): int
    {
        $this->assertAcknowledged(__METHOD__);

        return $this->insertedCount;
    }

    public function getMatchedCount(): int
    {
        $this->assertAcknowledged(__METHOD__);

        return $this->matchedCount;
    }

    public function getModifiedCount(): int
    {
        $this->assertAcknowledged(__METHOD__);

        return $this->modifiedCount;
    }

    public function getDeletedCount(): int
    {
        $this->assertAcknowledged(__METHOD__);

        return $this->deletedCount;
    }

    public function getUpsertedCount(): int
    {
        $this->assertAcknowledged(__METHOD__);

        return $this->upsertedCount;
    }

    public function getUpsertedIds(): array
    {
        $this->assertAcknowledged(__METHOD__);

        return $this->upsertedIds;
    }

    private function assertAcknowledged(string $method): void
    {
        if ($this->acknowledged) {
            return;
        }

        throw new Exception\LogicException(
            sprintf('%s() should not be called for an unacknowledged write result', $method),
        );
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

    public function getErrorReplies(): array
    {
        return $this->errorReplies;
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
            'nInserted'         => $this->insertedCount,
            'nMatched'          => $this->matchedCount,
            'nModified'         => $this->modifiedCount,
            'nRemoved'          => $this->deletedCount,
            'nUpserted'         => $this->upsertedCount,
            'upsertedIds'       => $upsertedIdsList,
            'writeErrors'       => $this->writeErrors,
            'writeConcernError' => $this->writeConcernError,
            'writeConcern'      => $this->writeConcern,
            'errorReplies'      => $this->errorReplies,
        ];
    }
}
