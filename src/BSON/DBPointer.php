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

/**
 * Represents a BSON DBPointer type (deprecated in the BSON spec).
 *
 * @deprecated The BSON DBPointer type is deprecated. Use DBRef documents instead.
 */
final class DBPointer implements JsonSerializable, Type, Stringable
{
    public readonly string $ref;
    public readonly string $id;

    private function __construct(string $ref, string $id)
    {
        $this->ref = $ref;
        $this->id  = strtolower($id);
    }

    /**
     * Static factory – the only public way to instantiate DBPointer.
     *
     * @deprecated
     */
    public static function create(string $ref, string $id): static
    {
        self::validateId($id);

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
        return sprintf('[%s/%s]', $this->ref, $this->id);
    }

    // ------------------------------------------------------------------
    // JsonSerializable
    // ------------------------------------------------------------------

    public function jsonSerialize(): mixed
    {
        return [
            '$dbPointer' => [
                '$ref' => $this->ref,
                '$id'  => ['$oid' => $this->id],
            ],
        ];
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
        if (
            ! isset($data['ref'], $data['id']) ||
            ! is_string($data['ref']) ||
            ! is_string($data['id'])
        ) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\DBPointer initialization requires "ref" and "id" string fields',
            );
        }

        self::validateId($data['id']);

        $this->ref = $data['ref'];
        $this->id  = strtolower($data['id']);
    }

    public static function __set_state(array $properties): static
    {
        if (
            ! isset($properties['ref'], $properties['id']) ||
            ! is_string($properties['ref']) ||
            ! is_string($properties['id'])
        ) {
            throw new InvalidArgumentException(
                'MongoDB\BSON\DBPointer initialization requires "ref" and "id" string fields',
            );
        }

        self::validateId($properties['id']);

        return new static($properties['ref'], $properties['id']);
    }

    public function __debugInfo(): array
    {
        return [
            'ref' => $this->ref,
            'id'  => $this->id,
        ];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private static function validateId(string $id): void
    {
        if (! preg_match('/^[0-9a-fA-F]{24}$/', $id)) {
            throw new InvalidArgumentException(
                sprintf('Error parsing ObjectId string: %s', $id),
            );
        }
    }
}
