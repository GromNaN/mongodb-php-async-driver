<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use JsonSerializable;

final class MaxKey implements MaxKeyInterface, JsonSerializable, Type
{
    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    final public function jsonSerialize(): mixed
    {
        return ['$maxKey' => 1];
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    final public function __serialize(): array
    {
        return [];
    }

    final public function __unserialize(array $data): void
    {
        // No state to restore.
    }

    final public static function __set_state(array $properties): static
    {
        return new static();
    }
}
