<?php

declare(strict_types=1);

namespace MongoDB\BSON\Internal\Index;

use MongoDB\Internal\BSON\BsonType;
use OutOfBoundsException;
use Stringable;

use function array_map;

/** @internal */
abstract class Index
{
    /** @var array<string|int, Field> */
    public readonly array $fields;

    public function __construct(
        Stringable $structure,
        /** @param list<array{key: string, bsonType: BsonType, keyOffset: int, keyLength: int, dataOffset?: int|null, dataLength?: int|null}> $fields */
        array $fields,
    ) {
        $this->fields = array_map(
            static fn (array $field): Field => new Field(
                $structure,
                $field['key'],
                $field['bsonType'],
                $field['keyOffset'],
                $field['keyLength'],
                $field['dataOffset'] ?? null,
                $field['dataLength'] ?? null,
            ),
            static::sortFields($fields),
        );
    }

    public function hasField(string|int $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function getFieldValue(string|int $key): mixed
    {
        return $this->getField($key)->getValue();
    }

    public function getField(string|int $key): Field
    {
        return $this->fields[$key] ?? throw new OutOfBoundsException('Field "' . $key . '" not found in BSON document');
    }

    abstract protected static function sortFields(array $fields): array;
}
