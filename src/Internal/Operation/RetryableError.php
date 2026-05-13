<?php

declare(strict_types=1);

namespace MongoDB\Internal\Operation;

use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Internal\Topology\InternalServerDescription;
use Throwable;

use function array_key_exists;
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
     *  - It is a CommandException whose result document contains 'RetryableWriteError'
     *    or 'RetryableError' in its errorLabels array.
     *  - It is a CommandException from a pre-4.4 server (maxWireVersion < 9) with no
     *    errorLabels in the result document AND whose error code is in RETRYABLE_ERROR_CODES.
     *
     * For MongoDB 4.4+ (maxWireVersion >= 9) the server always adds 'RetryableWriteError'
     * to errors it considers retryable.  If the label is absent drivers MUST NOT fall back
     * to the hardcoded error-code list — the absence of the label means "not retryable".
     *
     * @param InternalServerDescription|null $server Server that produced the error (null = unknown/pre-4.4).
     */
    public static function isRetryable(Throwable $e, ?InternalServerDescription $server = null): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! ($e instanceof CommandException)) {
            return false;
        }

        $resultDoc = $e->getResultDocument();
        $doc       = (array) $resultDoc;

        // Check explicit errorLabels first; if present they are authoritative.
        if (array_key_exists('errorLabels', $doc)) {
            $labels = $doc['errorLabels'];
            if (! is_array($labels)) {
                $labels = (array) $labels;
            }

            return in_array('RetryableWriteError', $labels, true)
                || in_array('RetryableError', $labels, true);
        }

        // MongoDB 4.4+ (maxWireVersion >= 9): server always adds RetryableWriteError for
        // retryable errors.  Absence of the label means the error is NOT retryable.
        $maxWireVersion = (int) ($server?->helloResponse['maxWireVersion'] ?? 0);

        if ($maxWireVersion >= 9) {
            return false;
        }

        // Pre-4.4 server: fall back to hardcoded error codes.
        return in_array($e->getCode(), self::RETRYABLE_ERROR_CODES, true);
    }
}
