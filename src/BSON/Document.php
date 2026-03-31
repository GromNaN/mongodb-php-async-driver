<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use ArrayAccess;
use BadMethodCallException;
use InvalidArgumentException;
use IteratorAggregate;
use MongoDB\Internal\BSON\BsonDecoder;
use MongoDB\Internal\BSON\BsonEncoder;
use MongoDB\Internal\BSON\ExtendedJson;
use RuntimeException;
use Stringable;

use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function is_array;
use function is_object;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Represents a BSON document.
 *
 * Instances must be created via one of the static factory methods:
 *   - {@see self::fromBSON()}
 *   - {@see self::fromJSON()}
 *   - {@see self::fromPHP()}
 *
 * The constructor is private; the class is immutable once created.
 */
final class Document implements IteratorAggregate, ArrayAccess, Type, Stringable
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
     * Create a Document from raw BSON bytes.
     */
    public static function fromBSON(string $bson): static
    {
        return new static($bson, null);
    }

    /**
     * Create a Document from a MongoDB Extended JSON string.
     */
    public static function fromJSON(string $json): static
    {
        // Parse Extended JSON by decoding it as a PHP array then re-encoding
        $phpValue = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($phpValue) && ! is_object($phpValue)) {
            throw new InvalidArgumentException('Invalid Extended JSON string');
        }

        $decoded = (array) $phpValue;

        return new static(null, $decoded);
    }

    /**
     * Create a Document from a PHP array or object.
     */
    public static function fromPHP(array|object $value): static
    {
        $decoded = (array) $value;

        return new static(null, $decoded);
    }

    // ------------------------------------------------------------------
    // Key access
    // ------------------------------------------------------------------

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->decode());
    }

    public function get(string $key): mixed
    {
        $decoded = $this->decode();

        if (! array_key_exists($key, $decoded)) {
            throw new RuntimeException(sprintf('Key "%s" does not exist in the document.', $key));
        }

        return $decoded[$key];
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
        $decoded = BsonDecoder::decode($this->getBson(), ['root' => 'array']);

        return ExtendedJson::toCanonical($decoded);
    }

    public function toRelaxedExtendedJSON(): string
    {
        $decoded = BsonDecoder::decode($this->getBson(), ['root' => 'array']);

        return ExtendedJson::toRelaxed($decoded);
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
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException('MongoDB\BSON\Document is immutable and does not support offsetSet().');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('MongoDB\BSON\Document is immutable and does not support offsetUnset().');
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
            $this->bson = BsonEncoder::encode($this->decoded ?? []);
        }

        return $this->bson;
    }

    public function __debugInfo(): array
    {
        return [
            'data'  => base64_encode($this->getBson()),
            'value' => $this->toPHP(['document' => 'bson', 'array' => 'bson']),
        ];
    }

    /**
     * Return the decoded PHP array, decoding from raw BSON if necessary.
     *
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        if ($this->decoded === null) {
            $this->decoded = (array) BsonDecoder::decode($this->bson, ['root' => 'array']);
        }

        return $this->decoded;
    }
}
