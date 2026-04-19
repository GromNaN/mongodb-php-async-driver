<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON\Index;

use InvalidArgumentException;
use MongoDB\Internal\BSON\BsonDecoder;
use MongoDB\Internal\BSON\BsonType;
use OutOfBoundsException;
use Stringable;
use WeakReference;

/** @internal */
final class Field
{
    /** @var WeakReference<Stringable> */
    private readonly WeakReference $source;
    private bool $isInitialized = false;
    private mixed $value;

    public function __construct(
        Stringable $source,
        public readonly string $key,
        public readonly int $bsonType,
        public readonly int $keyOffset,
        public readonly int $keyLength,
        public readonly int|null $dataOffset = null,
        public readonly int|null $dataLength = null,
    ) {
        if (
            $this->bsonType === BsonType::Undefined
            || $this->bsonType === BsonType::Null
            || $this->bsonType === BsonType::MinKey
            || $this->bsonType === BsonType::MaxKey
        ) {
            if ($this->dataLength !== 0 || $this->dataOffset !== null) {
                throw new InvalidArgumentException('Invalid data offset or length');
            }
        } else {
            if ($this->dataLength === null || $this->dataOffset === null) {
                throw new InvalidArgumentException('Invalid data offset or length');
            }

            if ($this->dataLength < 0 || $this->dataOffset <= $this->keyOffset + $this->keyLength) {
                throw new InvalidArgumentException('Invalid data offset or length');
            }
        }

        $this->source = WeakReference::create($source);
    }

    public function getValue(): mixed
    {
        if (! $this->isInitialized) {
            $this->readValue();
        }

        return $this->value;
    }

    private function readValue(): void
    {
        $source = $this->source->get();
        if (! $source instanceof Stringable) {
            throw new OutOfBoundsException('BSON document is no longer valid');
        }

        $this->value         = BsonDecoder::decodeFieldValue((string) $source, $this->bsonType, (int) $this->dataOffset);
        $this->isInitialized = true;
    }
}
