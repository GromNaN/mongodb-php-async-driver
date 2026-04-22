<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use Stringable;

use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;

final class Decimal128 implements Decimal128Interface, JsonSerializable, Type, Stringable
{
    public readonly string $dec;

    public function __construct(string $value)
    {
        $this->dec = self::normalizeAndValidate($value);
    }

    // ------------------------------------------------------------------
    // Decimal128Interface / Stringable
    // ------------------------------------------------------------------

    public function __toString(): string
    {
        return $this->dec;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return ['$numberDecimal' => $this->dec];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['dec' => $this->dec];
    }

    public function __unserialize(array $data): void
    {
        if (! is_string($data['dec'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Decimal128 initialization requires "dec" string field',
            );
        }

        $this->dec = self::normalizeAndValidate($data['dec']);
    }

    public static function __set_state(array $properties): static
    {
        if (! is_string($properties['dec'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Decimal128 initialization requires "dec" string field',
            );
        }

        return new static($properties['dec']);
    }

    public function __debugInfo(): array
    {
        return ['dec' => $this->dec];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function normalizeAndValidate(string $value): string
    {
        // Normalize case-insensitive infinity/nan forms
        $lower = strtolower($value);

        if ($lower === 'inf' || $lower === 'infinity' || $lower === '+inf' || $lower === '+infinity') {
            return 'Infinity';
        }

        if ($lower === '-inf' || $lower === '-infinity') {
            return '-Infinity';
        }

        if ($lower === 'nan' || $lower === '+nan' || $lower === '-nan') {
            return 'NaN';
        }

        // Validate as numeric decimal string
        if (! preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $value)) {
            throw new InvalidArgumentException(
                sprintf('Error parsing Decimal128 string: %s', $value),
            );
        }

        return $value;
    }
}
