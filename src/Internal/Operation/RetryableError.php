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
     * Rules differ slightly between reads and writes:
     *
     * For READS ($forWrite = false):
     *  - ConnectionException → always retryable.
     *  - CommandException with 'RetryableWriteError' / 'RetryableError' label → retryable.
     *  - CommandException with a code in RETRYABLE_ERROR_CODES → retryable (all server versions).
     *
     * For WRITES ($forWrite = true):
     *  - ConnectionException → always retryable.
     *  - CommandException with 'RetryableWriteError' / 'RetryableError' label → retryable.
     *  - CommandException from a pre-4.4 server (maxWireVersion < 9) with no errorLabels
     *    AND whose code is in RETRYABLE_ERROR_CODES → retryable.
     *  - MongoDB 4.4+ always adds 'RetryableWriteError' when the error is retryable; absence
     *    of the label means the error is NOT retryable (MUST NOT fall back to error codes).
     *
     * @param InternalServerDescription|null $server   Server that produced the error (null = unknown/pre-4.4).
     * @param bool                           $forWrite True when checking retryability for a write operation.
     */
    public static function isRetryable(Throwable $e, ?InternalServerDescription $server = null, bool $forWrite = false): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! ($e instanceof CommandException)) {
            return false;
        }

        $resultDoc = $e->getResultDocument();
        $doc       = (array) $resultDoc;

        // Check explicit errorLabels first; if present they are authoritative for both reads and writes.
        if (array_key_exists('errorLabels', $doc)) {
            $labels = $doc['errorLabels'];
            if (! is_array($labels)) {
                $labels = (array) $labels;
            }

            return in_array('RetryableWriteError', $labels, true)
                || in_array('RetryableError', $labels, true);
        }

        // For writes on MongoDB 4.4+ (maxWireVersion >= 9): server always adds RetryableWriteError
        // to retryable errors.  Absence of the label means the error is NOT retryable for writes.
        if ($forWrite) {
            $maxWireVersion = (int) ($server?->helloResponse['maxWireVersion'] ?? 0);

            if ($maxWireVersion >= 9) {
                return false;
            }
        }

        // For reads (all server versions) or pre-4.4 writes: fall back to hardcoded error codes.
        return in_array($e->getCode(), self::RETRYABLE_ERROR_CODES, true);
    }
}
