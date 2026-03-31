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
use function preg_match;
use function sprintf;

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
        if (! isset($data['integer']) || ! is_string($data['integer'])) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Int64 initialization requires "integer" string field',
            );
        }

        $this->integer = self::parseString($data['integer']);
    }

    public static function __set_state(array $properties): static
    {
        if (! isset($properties['integer']) || ! is_string($properties['integer'])) {
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

        $int = (int) $value;

        if ((string) $int !== $value) {
            throw new InvalidArgumentException(
                sprintf('Error parsing "%s" as 64-bit integer for MongoDB\BSON\Int64 initialization', $value),
            );
        }

        return $value;
    }
}
