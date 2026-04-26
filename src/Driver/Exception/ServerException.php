<?php
declare(strict_types=1);

namespace MongoDB\Driver\Exception;

use stdClass;
use Throwable;

use function is_array;

class ServerException extends RuntimeException
{
    protected object $resultDocument;

    public function __construct(string $message = '', int $code = 0, ?object $resultDocument = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        $this->resultDocument = $resultDocument ?? new stdClass();

        // Extract errorLabels from the result document (top-level) or writeConcernError.
        $doc = (array) $this->resultDocument;
        $labels = $doc['errorLabels'] ?? null;
        if ($labels === null) {
            $wce = isset($doc['writeConcernError']) ? (array) $doc['writeConcernError'] : [];
            $labels = $wce['errorLabels'] ?? null;
        }

        if (! is_array($labels)) {
            return;
        }

        $this->errorLabels = $labels;
    }

    public function getResultDocument(): object
    {
        return $this->resultDocument;
    }
}
