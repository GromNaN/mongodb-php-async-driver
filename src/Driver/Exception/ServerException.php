<?php declare(strict_types=1);

namespace MongoDB\Driver\Exception;

class ServerException extends RuntimeException
{
    protected object $resultDocument;

    public function __construct(string $message, int $code, object $resultDocument)
    {
        parent::__construct($message, $code);
        $this->resultDocument = $resultDocument;
    }

    public function getResultDocument(): object
    {
        return $this->resultDocument;
    }
}
