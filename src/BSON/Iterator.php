<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use Iterator as IteratorInterface;

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
    /** @var list<string|int> Ordered list of keys. */
    private array $keys;

    private int $position;

    /**
     * Private constructor – use {@see self::createFromDecodedData()} instead.
     *
     * @param array<string|int, mixed> $data
     */
    private function __construct(private array $data)
    {
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
    public static function createFromDecodedData(array $data): static
    {
        return new static($data);
    }

    // ------------------------------------------------------------------
    // Iterator
    // ------------------------------------------------------------------

    public function current(): mixed
    {
        return $this->data[$this->keys[$this->position]];
    }

    public function key(): string|int
    {
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
