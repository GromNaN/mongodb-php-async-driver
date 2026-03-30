<?php

declare(strict_types=1);

namespace MongoDB\BSON;

/**
 * Represents a BSON undefined type (deprecated in the BSON spec).
 *
 * @deprecated The BSON undefined type is deprecated. Use null instead.
 */
final class Undefined implements Type
{
    private function __construct()
    {
    }

    /**
     * Static factory – the only public way to instantiate Undefined.
     *
     * @deprecated
     */
    public static function create(): static
    {
        return new static();
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
