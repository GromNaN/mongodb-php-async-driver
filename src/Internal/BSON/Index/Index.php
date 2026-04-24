<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON\Index;

use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use OutOfBoundsException;
use WeakMap;

use function array_map;
use function sprintf;

/** @internal */
abstract class Index
{
    /** @var WeakMap<Document|PackedArray, self>|null */
    private static ?WeakMap $cache = null;

    /** @var array<string|int, Field> */
    public readonly array $fields;

    final public function __construct(
        Document|PackedArray $structure,
        /** @param list<array{key: string, bsonType: int, keyOffset: int, keyLength: int, dataOffset?: int|null, dataLength?: int|null}> $fields */
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

    public static function forBson(Document|PackedArray $bson): self
    {
        self::$cache ??= new WeakMap();

        if (! isset(self::$cache[$bson])) {
            $fields             = (new Indexer())->getIndex((string) $bson);
            self::$cache[$bson] = $bson instanceof Document
                ? new DocumentIndex($bson, $fields)
                : new PackedArrayIndex($bson, $fields);
        }

        return self::$cache[$bson];
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
        return $this->fields[$key] ?? throw new OutOfBoundsException(sprintf('Field "%s" not found in BSON document', $key));
    }

    abstract protected static function sortFields(array $fields): array;
}
