<?php

declare(strict_types=1);

namespace MongoDB\BSON;

/**
 * Represents a BSON DBPointer type (deprecated in the BSON spec).
 *
 * @deprecated The BSON DBPointer type is deprecated. Use DBRef documents instead.
 */
final class DBPointer implements Type, \Stringable
{
    private string $ref;
    private string $id;

    private function __construct(string $ref, string $id)
    {
        $this->ref = $ref;
        $this->id  = $id;
    }

    /**
     * Static factory – the only public way to instantiate DBPointer.
     *
     * @deprecated
     */
    public static function create(string $ref, string $id): static
    {
        return new static($ref, $id);
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return sprintf('DBPointer(%s, %s)', $this->ref, $this->id);
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return [
            'ref' => $this->ref,
            'id'  => $this->id,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->ref = $data['ref'];
        $this->id  = $data['id'];
    }

    public static function __set_state(array $properties): static
    {
        return new static($properties['ref'], $properties['id']);
    }
}
