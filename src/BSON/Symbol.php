<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;
use MongoDB\Driver\Exception\InvalidArgumentException;
use Stringable;

use function is_string;
use function str_contains;

/**
 * Represents a BSON symbol type (deprecated in the BSON spec).
 *
 * @deprecated The BSON symbol type is deprecated. Use strings instead.
 */
final class Symbol implements JsonSerializable, Type, Stringable
{
    private function __construct(public readonly string $symbol)
    {
    }

    /**
     * Static factory method – the only public way to instantiate Symbol
     * (mirrors the restricted-constructor pattern of deprecated types).
     *
     * @deprecated
     */
    public static function create(string $symbol): static
    {
        return new static($symbol);
    }

    public function __toString(): string
    {
        return $this->symbol;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return ['$symbol' => $this->symbol];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['symbol' => $this->symbol];
    }

    public function __unserialize(array $data): void
    {
        if (! is_string($data['symbol'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Symbol initialization requires "symbol" string field',
            );
        }

        if (str_contains($data['symbol'], "\0")) {
            throw new InvalidArgumentException('Symbol cannot contain null bytes');
        }

        $this->symbol = $data['symbol'];
    }

    public static function __set_state(array $properties): static
    {
        if (! is_string($properties['symbol'] ?? null)) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\Symbol initialization requires "symbol" string field',
            );
        }

        return new static($properties['symbol']);
    }

    public function __debugInfo(): array
    {
        return ['symbol' => $this->symbol];
    }
}
