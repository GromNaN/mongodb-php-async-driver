<?php

declare(strict_types=1);

namespace MongoDB\BSON;

/**
 * Represents a BSON symbol type (deprecated in the BSON spec).
 *
 * @deprecated The BSON symbol type is deprecated. Use strings instead.
 */
final class Symbol implements Type, \Stringable
{
    private string $symbol;

    private function __construct(string $symbol)
    {
        $this->symbol = $symbol;
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
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['symbol' => $this->symbol];
    }

    public function __unserialize(array $data): void
    {
        $this->symbol = $data['symbol'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['symbol']);
    }
}
