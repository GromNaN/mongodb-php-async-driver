<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use stdClass;

class ServerException extends RuntimeException
{
    protected object $resultDocument;

    public function __construct(string $message = '', int $code = 0, ?object $resultDocument = null)
    {
        parent::__construct($message, $code);

        $this->resultDocument = $resultDocument ?? new stdClass();
    }

    public function getResultDocument(): object
    {
        return $this->resultDocument;
    }
}
