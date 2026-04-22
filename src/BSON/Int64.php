<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use Stringable;

use function get_debug_type;
use function is_float;
use function is_int;
use function is_string;
use function ltrim;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;

final class Int64 implements JsonSerializable, Type, Stringable
{
    public readonly string $integer;

    public function __construct(mixed $value)
    {
        if (is_float($value)) {
            throw new InvalidArgumentException(
                sprintf('Expected value to be integer or string, float given'),
            );
        }

        if (is_int($value)) {
            $this->integer = (string) $value;

            return;
        }

        if (is_string($value)) {
            $this->integer = self::parseString($value);

            return;
        }

        throw new InvalidArgumentException(
            sprintf('Expected value to be integer or string, %s given', get_debug_type($value)),
        );
    }

    // ------------------------------------------------------------------
    // Stringable
    // ------------------------------------------------------------------

    public function __toString(): string
    {
        return $this->integer;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return ['$numberLong' => $this->integer];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['integer' => $this->integer];
    }

    public function __unserialize(array $data): void
    {
        if (! is_string($data['integer'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Int64 initialization requires "integer" string field',
            );
        }

        $this->integer = self::parseString($data['integer']);
    }

    public static function __set_state(array $properties): static
    {
        if (! is_string($properties['integer'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Int64 initialization requires "integer" string field',
            );
        }

        return new static($properties['integer']);
    }

    public function __debugInfo(): array
    {
        return ['integer' => $this->integer];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function parseString(string $value): string
    {
        if (! preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException(
                sprintf('Error parsing "%s" as 64-bit integer for MongoDB\BSON\Int64 initialization', $value),
            );
        }

        // Strip leading zeros (but keep a single "0") to normalise before range check.
        $normalized = ltrim($value, '-0') ?: '0';
        $isNegative = str_starts_with($value, '-');

        // INT64_MIN = -9223372036854775808, INT64_MAX = 9223372036854775807
        $limit = $isNegative ? '9223372036854775808' : '9223372036854775807';

        if (strlen($normalized) > strlen($limit) || (strlen($normalized) === strlen($limit) && $normalized > $limit)) {
            throw new InvalidArgumentException(
                sprintf('Error parsing "%s" as 64-bit integer for MongoDB\BSON\Int64 initialization', $value),
            );
        }

        return $value;
    }
}
