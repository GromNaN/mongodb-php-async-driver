<?php declare(strict_types=1);

namespace MongoDB\Driver\Exception;

class BulkWriteException extends ServerException
{
    protected \MongoDB\Driver\WriteResult $writeResult;

    public function __construct(string $message, int $code, object $resultDocument, \MongoDB\Driver\WriteResult $writeResult)
    {
        parent::__construct($message, $code, $resultDocument);
        $this->writeResult = $writeResult;
    }

    public function getWriteResult(): \MongoDB\Driver\WriteResult
    {
        return $this->writeResult;
    }
}
