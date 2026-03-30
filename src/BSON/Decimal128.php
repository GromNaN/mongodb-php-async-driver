<?php

declare(strict_types=1);

namespace MongoDB\BSON;

final class Decimal128 implements Decimal128Interface, \JsonSerializable, Type, \Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    // ------------------------------------------------------------------
    // Decimal128Interface / Stringable
    // ------------------------------------------------------------------

    public function __toString(): string
    {
        return $this->value;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return ['$numberDecimal' => $this->value];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['value' => $this->value];
    }

    public function __unserialize(array $data): void
    {
        $this->value = $data['value'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['value']);
    }
}
