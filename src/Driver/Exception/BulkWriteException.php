<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use MongoDB\Driver\WriteResult;
use stdClass;
use Throwable;

class BulkWriteException extends ServerException
{
    public function __construct(string $message = '', int $code = 0, ?object $resultDocument = null, protected ?WriteResult $writeResult = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $resultDocument ?? new stdClass(), $previous);
    }

    final public function getWriteResult(): WriteResult
    {
        return $this->writeResult;
    }
}
