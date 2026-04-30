<?php

declare(strict_types=1);

namespace MongoDB\Internal\Session;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Serializable;

use function hrtime;
use function max;

/**
 * Logical session identifier.
 *
 * Wraps the UUID v4 Binary required by the MongoDB sessions spec.
 * Implementing BSON\Serializable lets the encoder call bsonSerialize()
 * directly instead of reflecting over a generic stdClass.
 *
 * The $lastUse timestamp (hrtime nanoseconds) is stored here to avoid a
 * separate pool-entry wrapper object. It is intentionally excluded from
 * bsonSerialize() so it is never sent to the server.
 *
 * @internal
 */
final class LogicalSessionId implements Serializable
{
    /** Monotonic nanosecond timestamp of the last time this session was used. */
    private(set) int $lastUse;

    public function __construct(public readonly Binary $id)
    {
        $this->lastUse = hrtime(true);
    }

    public function touch(): void
    {
        $this->lastUse = hrtime(true);
    }

    public function isExpired(int $sessionTimeoutMinutes): bool
    {
        $timeoutNs = max(0, $sessionTimeoutMinutes - 1) * 60 * 1_000_000_000;

        return hrtime(true) - $this->lastUse >= $timeoutNs;
    }

    /** @return array{id: Binary} */
    public function bsonSerialize(): array
    {
        return ['id' => $this->id];
    }
}
