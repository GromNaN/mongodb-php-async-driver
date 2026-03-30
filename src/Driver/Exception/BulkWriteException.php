<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use MongoDB\Driver\WriteResult;

class BulkWriteException extends ServerException
{
    public function __construct(string $message, int $code, object $resultDocument, protected WriteResult $writeResult)
    {
        parent::__construct($message, $code, $resultDocument);
    }

    public function getWriteResult(): WriteResult
    {
        return $this->writeResult;
    }
}
