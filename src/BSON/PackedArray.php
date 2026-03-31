<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use ArrayAccess;
use BadMethodCallException;
use IteratorAggregate;
use MongoDB\Internal\BSON\BsonDecoder;
use MongoDB\Internal\BSON\BsonEncoder;
use MongoDB\Internal\BSON\ExtendedJson;
use RuntimeException;
use Stringable;

use function array_key_exists;
use function array_values;
use function base64_decode;
use function base64_encode;
use function is_array;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Represents a BSON array (packed array – keys must be sequential integers
 * starting at 0).
 *
 * Instances must be created via one of the static factory methods:
 *   - {@see self::fromBSON()}
 *   - {@see self::fromJSON()}
 *   - {@see self::fromPHP()}
 *
 * The constructor is private; the class is immutable once created.
 */
final class PackedArray implements IteratorAggregate, ArrayAccess, Type, Stringable
{
    /** Raw BSON bytes. Lazily populated by encoder when created from PHP. */
    private ?string $bson;

    /** Decoded PHP representation. Lazily populated on first access. */
    private ?array $decoded;

    // ------------------------------------------------------------------
    // Private constructor
    // ------------------------------------------------------------------

    private function __construct(?string $bson, ?array $decoded)
    {
        $this->bson    = $bson;
        $this->decoded = $decoded;
    }

    // ------------------------------------------------------------------
    // Static factories
    // ------------------------------------------------------------------

    /**
     * Create a PackedArray from raw BSON bytes.
     */
    public static function fromBSON(string $bson): static
    {
        return new static($bson, null);
    }

    /**
     * Create a PackedArray from a MongoDB Extended JSON string.
     */
    public static function fromJSON(string $json): static
    {
        $phpValue = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        $decoded = is_array($phpValue) ? array_values($phpValue) : array_values((array) $phpValue);

        return new static(null, $decoded);
    }

    /**
     * Create a PackedArray from a PHP array or object.
     */
    public static function fromPHP(array|object $value): static
    {
        $decoded = array_values((array) $value);

        return new static(null, $decoded);
    }

    // ------------------------------------------------------------------
    // Key access
    // ------------------------------------------------------------------

    public function has(int $index): bool
    {
        return array_key_exists($index, $this->decode());
    }

    public function get(int $index): mixed
    {
        $decoded = $this->decode();

        if (! array_key_exists($index, $decoded)) {
            throw new RuntimeException(
                sprintf('Index %d does not exist in the packed array.', $index),
            );
        }

        return $decoded[$index];
    }

    // ------------------------------------------------------------------
    // Conversion
    // ------------------------------------------------------------------

    /**
     * Convert to a PHP value applying an optional type map.
     *
     * @param array<string, string>|null $typeMap
     */
    public function toPHP(?array $typeMap = null): array|object
    {
        return BsonDecoder::decode($this->getBson(), $typeMap ?? []);
    }

    public function toCanonicalExtendedJSON(): string
    {
        return ExtendedJson::toCanonical(BsonDecoder::decode($this->getBson(), ['root' => 'array']));
    }

    public function toRelaxedExtendedJSON(): string
    {
        return ExtendedJson::toRelaxed(BsonDecoder::decode($this->getBson(), ['root' => 'array']));
    }

    // ------------------------------------------------------------------
    // Stringable
    // ------------------------------------------------------------------

    /** Returns the raw BSON bytes. */
    public function __toString(): string
    {
        return $this->getBson();
    }

    // ------------------------------------------------------------------
    // IteratorAggregate
    // ------------------------------------------------------------------

    public function getIterator(): Iterator
    {
        return Iterator::createFromDecodedData($this->decode());
    }

    // ------------------------------------------------------------------
    // ArrayAccess
    // ------------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((int) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((int) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException('MongoDB\BSON\PackedArray is immutable and does not support offsetSet().');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('MongoDB\BSON\PackedArray is immutable and does not support offsetUnset().');
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    public function __serialize(): array
    {
        return ['bson' => base64_encode($this->getBson())];
    }

    public function __unserialize(array $data): void
    {
        $this->bson    = base64_decode($data['bson']);
        $this->decoded = null;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Return the raw BSON bytes, encoding from the decoded form if necessary.
     */
    private function getBson(): string
    {
        if ($this->bson === null) {
            $this->bson = BsonEncoder::encodeArray($this->decoded ?? []);
        }

        return $this->bson;
    }

    public function __debugInfo(): array
    {
        return [
            'data'  => base64_encode($this->getBson()),
            'value' => $this->toPHP(['root' => 'array', 'document' => 'bson', 'array' => 'bson']),
        ];
    }

    /**
     * Return the decoded PHP array, decoding from raw BSON if necessary.
     *
     * @return array<int, mixed>
     */
    private function decode(): array
    {
        if ($this->decoded === null) {
            $this->decoded = array_values(
                (array) BsonDecoder::decode($this->bson, ['root' => 'array']),
            );
        }

        return $this->decoded;
    }
}
