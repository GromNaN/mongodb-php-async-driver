<?php

declare(strict_types=1);

namespace MongoDB\BSON;

final class Int64 implements \JsonSerializable, Type, \Stringable
{
    private int $value;

    public function __construct(int|string $value)
    {
        $this->value = (int) $value;
    }

    // ------------------------------------------------------------------
    // Stringable
    // ------------------------------------------------------------------

    public function __toString(): string
    {
        return (string) $this->value;
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return ['$numberLong' => (string) $this->value];
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
