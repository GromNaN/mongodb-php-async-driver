<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use Exception;
use Iterator as IteratorInterface;
use MongoDB\Driver\Exception\LogicException;

use function array_keys;

/**
 * A forward-only iterator over a decoded BSON document or array.
 *
 * Instances are created exclusively by internal decoder code via
 * {@see self::createFromDecodedData()}.  The constructor is private to
 * prevent userland instantiation.
 */
final class Iterator implements IteratorInterface
{
    public readonly Document|PackedArray $bson;

    /** @var list<string|int> Ordered list of keys. */
    private array $keys;

    private int $position;

    /**
     * Private constructor – use {@see self::createFromDecodedData()} instead.
     *
     * @param array<string|int, mixed> $data
     */
    private function __construct(Document|PackedArray $bson, private array $data)
    {
        $this->bson     = $bson;
        $this->keys     = array_keys($data);
        $this->position = 0;
    }

    // ------------------------------------------------------------------
    // Internal factory (called by BsonDecoder)
    // ------------------------------------------------------------------

    /**
     * Factory method intended for use by {@see \MongoDB\Internal\BSON\BsonDecoder}.
     *
     * @internal
     *
     * @param array<string|int, mixed> $data
     */
    public static function createFromDecodedData(Document|PackedArray $bson, array $data): static
    {
        return new static($bson, $data);
    }

    public function __clone(): void
    {
        $this->position = 0;
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

    public function current(): mixed
    {
        if (! $this->valid()) {
            throw new LogicException('Cannot call current() on an exhausted iterator');
        }

        return $this->data[$this->keys[$this->position]];
    }

    public function key(): string|int
    {
        if (! $this->valid()) {
            throw new LogicException('Cannot call key() on an exhausted iterator');
        }

        return $this->keys[$this->position];
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->keys[$this->position]);
    }
}
