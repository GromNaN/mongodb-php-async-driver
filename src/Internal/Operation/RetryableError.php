<?php

declare(strict_types=1);

namespace MongoDB\Internal\Operation;

use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\ConnectionException;
use Throwable;

use function in_array;
use function is_array;

/**
 * Helper to classify driver exceptions as retryable per the Retryable Reads
 * and Retryable Writes specifications.
 *
 * @internal
 */
final class RetryableError
{
    /** @see https://github.com/mongodb/specifications/blob/master/source/retryable-reads/retryable-reads.rst */
    private const array RETRYABLE_ERROR_CODES = [
        6, 7, 89, 91, 134, 189, 262, 9001, 10107, 11600, 11602, 13435, 13436,
    ];

    /**
     * Returns true when the given exception should trigger a retry attempt.
     *
     * An error is retryable when:
     *  - It is a ConnectionException (covers ConnectionTimeoutException via inheritance).
     *  - It is a CommandException whose error code is in RETRYABLE_ERROR_CODES.
     *  - It is a CommandException whose result document's errorLabels array contains
     *    'RetryableWriteError' or 'RetryableError'.
     */
    public static function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! ($e instanceof CommandException)) {
            return false;
        }

        if (in_array($e->getCode(), self::RETRYABLE_ERROR_CODES, true)) {
            return true;
        }

        $resultDoc = $e->getResultDocument();
        $doc       = (array) $resultDoc;
        $labels    = $doc['errorLabels'] ?? [];

        if (! is_array($labels)) {
            $labels = (array) $labels;
        }

        return in_array('RetryableWriteError', $labels, true)
            || in_array('RetryableError', $labels, true);
    }
}
