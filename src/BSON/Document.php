<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use ArrayAccess;
use InvalidArgumentException;
use IteratorAggregate;
use JsonException;
use MongoDB\Driver\Exception\InvalidArgumentException as DriverInvalidArgumentException;
use MongoDB\Driver\Exception\LogicException as DriverLogicException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\Exception\UnexpectedValueException as DriverUnexpectedValueException;
use MongoDB\Internal\BSON\BsonDecoder;
use MongoDB\Internal\BSON\BsonEncoder;
use MongoDB\Internal\BSON\ExtendedJson;
use MongoDB\Internal\BSON\Index\DocumentIndex;
use OutOfBoundsException;
use Stringable;

use function base64_decode;
use function base64_encode;
use function get_debug_type;
use function is_array;
use function is_object;
use function json_decode;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

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
    // ------------------------------------------------------------------
    // Private constructor
    // ------------------------------------------------------------------

    private function __construct(private readonly string $data)
    {
    }

    // ------------------------------------------------------------------
    // Static factories
    // ------------------------------------------------------------------

    /**
     * Create a Document from raw BSON bytes.
     */
    final public static function fromBSON(string $bson): static
    {
        $len = strlen($bson);
        if ($len < 4) {
            throw new DriverUnexpectedValueException('Could not read document from BSON reader');
        }

        $claimed = unpack('V', substr($bson, 0, 4))[1];
        if ($claimed < 5) {
            throw new DriverUnexpectedValueException('Could not read document from BSON reader');
        }

        if ($claimed > $len) {
            throw new DriverUnexpectedValueException('Could not read document from BSON reader');
        }

        if ($claimed < $len) {
            throw new DriverUnexpectedValueException('Reading document did not exhaust input buffer');
        }

        return new static($bson);
    }

    /**
     * Create a Document from a MongoDB Extended JSON string.
     */
    final public static function fromJSON(string $json): static
    {
        try {
            $phpValue = json_decode($json, associative: false, depth: 101, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DriverUnexpectedValueException(
                sprintf('Got parse error at "%s", position 1: "SPECIAL_EXPECTED"', substr($json, 0, 1)),
                previous: $e,
            );
        }

        if (! is_array($phpValue) && ! is_object($phpValue)) {
            throw new DriverUnexpectedValueException('Invalid Extended JSON string');
        }

        try {
            $decoded = ExtendedJson::fromValue(ExtendedJson::normalizeJson($phpValue));
            $bson    = BsonEncoder::encode((array) $decoded);
        } catch (InvalidArgumentException $e) {
            throw new DriverUnexpectedValueException($e->getMessage(), previous: $e);
        }

        return new static($bson);
    }

    /**
     * Create a Document from a PHP array or object.
     */
    final public static function fromPHP(array|object $value): static
    {
        return new static(BsonEncoder::encode($value));
    }

    // ------------------------------------------------------------------
    // Key access
    // ------------------------------------------------------------------

    final public function has(string $key): bool
    {
        return DocumentIndex::forBson($this)->hasField($key);
    }

    final public function get(string $key): mixed
    {
        try {
            return DocumentIndex::forBson($this)->getFieldValue($key);
        } catch (OutOfBoundsException) {
            throw new DriverRuntimeException(sprintf('Could not find key "%s" in BSON document', $key));
        }
    }

    // ------------------------------------------------------------------
    // Conversion
    // ------------------------------------------------------------------

    /**
     * Convert to a PHP value applying an optional type map.
     *
     * @param array<string, string>|null $typeMap
     */
    final public function toPHP(?array $typeMap = null): array|object
    {
        $map = $typeMap ?? [];
        // Resolve 'bson' root to Document
        if (($map['root'] ?? null) === 'bson') {
            $map['root'] = 'bsonDocument';
        }

        return BsonDecoder::decode($this->data, $map, handlePersistable: true, preserveInt64: true);
    }

    final public function toCanonicalExtendedJSON(): string
    {
        $decoded = BsonDecoder::decode(
            $this->data,
            ['root' => 'object', 'document' => 'object', 'array' => 'array'],
            preserveInt64: true,
        );

        return ExtendedJson::toCanonical($decoded);
    }

    final public function toRelaxedExtendedJSON(): string
    {
        $decoded = BsonDecoder::decode(
            $this->data,
            ['root' => 'object', 'document' => 'object', 'array' => 'array'],
            preserveInt64: true,
        );

        return ExtendedJson::toRelaxed($decoded);
    }

    // ------------------------------------------------------------------
    // Stringable
    // ------------------------------------------------------------------

    /** Returns the raw BSON bytes. */
    final public function __toString(): string
    {
        return $this->data;
    }

    // ------------------------------------------------------------------
    // IteratorAggregate
    // ------------------------------------------------------------------

    final public function getIterator(): Iterator
    {
        $index = DocumentIndex::forBson($this);

        return Iterator::createFromDecodedData(
            $this,
            $index->fields,
        );
    }

    // ------------------------------------------------------------------
    // ArrayAccess
    // ------------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        if (! is_array($offset) && ! is_object($offset)) {
            return $this->has((string) $offset);
        }

        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $type = get_debug_type($offset);
        if ($type === 'string' || $type === 'int') {
            return $this->get((string) $offset);
        }

        throw new DriverRuntimeException(
            sprintf('Could not find key of type "%s" in BSON document', $type),
        );
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new DriverLogicException('Cannot write to MongoDB\BSON\Document property');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new DriverLogicException('Cannot unset MongoDB\BSON\Document property');
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    public function __set(string $name, mixed $value): void
    {
        throw new DriverLogicException('Cannot write to MongoDB\BSON\Document property');
    }

    public function __unset(string $name): void
    {
        throw new DriverLogicException('Cannot unset MongoDB\BSON\Document property');
    }

    // ------------------------------------------------------------------
    // Serialization helpers
    // ------------------------------------------------------------------

    final public function __serialize(): array
    {
        return ['data' => base64_encode($this->data)];
    }

    final public function __unserialize(array $data): void
    {
        $bson = base64_decode($data['data'] ?? '');
        self::assertValidBson($bson, self::class);
        $this->data = $bson;
    }

    final public static function __set_state(array $properties): static
    {
        $bson = base64_decode($properties['data'] ?? '');
        self::assertValidBson($bson, self::class);

        return new static($bson);
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    public function __debugInfo(): array
    {
        return [
            'data'  => base64_encode($this->data),
            'value' => BsonDecoder::decode($this->data, ['document' => 'bson', 'array' => 'bson'], handlePersistable: true),
        ];
    }

    private static function assertValidBson(string $bson, string $className): void
    {
        $len     = strlen($bson);
        $claimed = $len >= 4 ? unpack('V', substr($bson, 0, 4))[1] : 0;
        if ($len < 5 || $claimed !== $len) {
            throw new DriverInvalidArgumentException(
                sprintf('%s initialization requires valid BSON', $className),
            );
        }
    }
}
