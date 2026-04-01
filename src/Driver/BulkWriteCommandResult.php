<?php

declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Document;

final class BulkWriteCommandResult
{
    private int $insertedCount;
    private int $matchedCount;
    private int $modifiedCount;
    private int $upsertedCount;
    private int $deletedCount;
    private bool $acknowledged;
    private ?Document $insertResults;
    private ?Document $updateResults;
    private ?Document $deleteResults;

    private function __construct()
    {
    }

    /** @internal */
    public static function createFromInternal(
        int $insertedCount,
        int $matchedCount,
        int $modifiedCount,
        int $upsertedCount,
        int $deletedCount,
        bool $acknowledged,
        ?Document $insertResults = null,
        ?Document $updateResults = null,
        ?Document $deleteResults = null,
    ): static {
        $instance = new static();
        $instance->insertedCount = $insertedCount;
        $instance->matchedCount  = $matchedCount;
        $instance->modifiedCount = $modifiedCount;
        $instance->upsertedCount = $upsertedCount;
        $instance->deletedCount  = $deletedCount;
        $instance->acknowledged  = $acknowledged;
        $instance->insertResults = $insertResults;
        $instance->updateResults = $updateResults;
        $instance->deleteResults = $deleteResults;

        return $instance;
    }

    public function getInsertedCount(): int
    {
        return $this->insertedCount;
    }

    public function getMatchedCount(): int
    {
        return $this->matchedCount;
    }

    public function getModifiedCount(): int
    {
        return $this->modifiedCount;
    }

    public function getUpsertedCount(): int
    {
        return $this->upsertedCount;
    }

    public function getDeletedCount(): int
    {
        return $this->deletedCount;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged;
    }

    /**
     * Returns per-insert results keyed by operation index, or null if verboseResults was not requested.
     */
    public function getInsertResults(): ?Document
    {
        return $this->insertResults;
    }

    /**
     * Returns per-update results keyed by operation index, or null if verboseResults was not requested.
     */
    public function getUpdateResults(): ?Document
    {
        return $this->updateResults;
    }

    /**
     * Returns per-delete results keyed by operation index, or null if verboseResults was not requested.
     */
    public function getDeleteResults(): ?Document
    {
        return $this->deleteResults;
    }
}
