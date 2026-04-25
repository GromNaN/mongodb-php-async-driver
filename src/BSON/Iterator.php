<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use ArrayIterator;
use Exception;
use MongoDB\Driver\Exception\LogicException;
use MongoDB\Internal\BSON\Index\Field;

/**
 * A forward-only iterator over a decoded BSON document or array.
 *
 * Instances are created exclusively by internal decoder code via
 * {@see self::createFromDecodedData()}.  The constructor is private to
 * prevent userland instantiation.
 *
 * @method void next()
 * @method void rewind()
 * @method bool valid()
 */
final class Iterator extends ArrayIterator
{
    /**
     * Private constructor – use {@see self::createFromDecodedData()} instead.
     *
     * @param array<string|int, Field> $fields
     */
    private function __construct(
        public readonly Document|PackedArray $bson,
        array $fields,
    ) {
        parent::__construct($fields);
    }

    // ------------------------------------------------------------------
    // Internal factory (called by Document / PackedArray)
    // ------------------------------------------------------------------

    /**
     * Factory method intended for use by {@see Document} and {@see PackedArray}.
     *
     * @internal
     *
     * @param array<string|int, Field> $fields
     */
    public static function createFromDecodedData(Document|PackedArray $bson, array $fields): static
    {
        return new static($bson, $fields);
    }

    public function __serialize(): array
    {
        throw new Exception("Serialization of 'MongoDB\\BSON\\Iterator' is not allowed");
    }

    public function __debugInfo(): array
    {
        return ['bson' => $this->bson];
    }

    // ------------------------------------------------------------------
    // Iterator
    // ------------------------------------------------------------------

    final public function current(): mixed
    {
        if (! $this->valid()) {
            throw new LogicException('Cannot call current() on an exhausted iterator');
        }

        return parent::current()->getValue();
    }

    final public function key(): string|int
    {
        if (! $this->valid()) {
            throw new LogicException('Cannot call key() on an exhausted iterator');
        }

        return parent::key();
    }
}
