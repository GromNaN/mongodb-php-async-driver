<?php

declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use MongoDB\BSON\Document;
use MongoDB\Driver\BulkWriteCommandResult;
use MongoDB\Driver\WriteConcernError;

final class BulkWriteCommandException extends ServerException
{
    private ?Document $errorReply = null;

    private ?BulkWriteCommandResult $partialResult = null;

    /** @var list<WriteError> */
    private array $writeErrors = [];

    /** @var list<WriteConcernError> */
    private array $writeConcernErrors = [];

    /** @internal */
    public static function create(
        string $message,
        int $code,
        ?object $resultDocument = null,
        ?Document $errorReply = null,
        ?BulkWriteCommandResult $partialResult = null,
        array $writeErrors = [],
        array $writeConcernErrors = [],
    ): static {
        $instance = new static($message, $code, $resultDocument);
        $instance->errorReply        = $errorReply;
        $instance->partialResult     = $partialResult;
        $instance->writeErrors       = $writeErrors;
        $instance->writeConcernErrors = $writeConcernErrors;

        return $instance;
    }

    /**
     * The top-level server error reply document, populated when the server returned an error response.
     */
    public function getErrorReply(): ?Document
    {
        return $this->errorReply;
    }

    /**
     * Partial result containing counts from operations that succeeded before the error occurred.
     */
    public function getPartialResult(): ?BulkWriteCommandResult
    {
        return $this->partialResult;
    }

    /** @return list<WriteError> */
    public function getWriteErrors(): array
    {
        return $this->writeErrors;
    }

    /** @return list<WriteConcernError> */
    public function getWriteConcernErrors(): array
    {
        return $this->writeConcernErrors;
    }
}
