<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

class ServerException extends RuntimeException
{
    public function __construct(string $message, int $code, protected object $resultDocument)
    {
        parent::__construct($message, $code);
    }

    public function getResultDocument(): object
    {
        return $this->resultDocument;
    }
}
