<?php

declare(strict_types=1);

namespace MongoDB\BSON;

final class MinKey implements MinKeyInterface, \JsonSerializable, Type
{
    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return ['$minKey' => 1];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return [];
    }

    public function __unserialize(array $data): void
    {
        // No state to restore.
    }

    public static function __set_state(array $properties): static
    {
        return new static();
    }
}
