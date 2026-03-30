<?php

declare(strict_types=1);

namespace MongoDB\Internal\Protocol;

use const PHP_INT_MAX;

/**
 * Thread-safe (single-process) monotonically increasing request-ID generator.
 *
 * @internal
 */
final class RequestIdGenerator
{
    private static int $counter = 0;

    /**
     * Return the next request ID, wrapping around to 1 when PHP_INT_MAX is reached.
     */
    public static function next(): int
    {
        if (self::$counter >= PHP_INT_MAX) {
            self::$counter = 0;
        }

        return ++self::$counter;
    }
}
