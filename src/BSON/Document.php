<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use ArrayAccess;
use IteratorAggregate;
use JsonException;
use MongoDB\Driver\Exception\InvalidArgumentException as DriverInvalidArgumentException;
use MongoDB\Driver\Exception\LogicException as DriverLogicException;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Driver\Exception\UnexpectedValueException as DriverUnexpectedValueException;
use MongoDB\Internal\BSON\BsonDecoder;
use MongoDB\Internal\BSON\BsonEncoder;
use MongoDB\Internal\BSON\ExtendedJson;
use Stringable;
use WeakMap;

use function array_key_exists;
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
    /** Base64-encoded raw BSON bytes — public for get_object_vars() / var_export() compat. */
    public readonly string $data;

    /**
     * Cache for decoded PHP arrays: keyed by $this to avoid var_export pollution.
     *
     * @var WeakMap<static, array<string, mixed>>|null
     */
    private static ?WeakMap $decodedCache = null;

    // ------------------------------------------------------------------
    // Private constructor
    // ------------------------------------------------------------------

    private function __construct(string $bson)
    {
        $this->data = base64_encode($bson);
    }

    // ------------------------------------------------------------------
    // Static factories
    // ------------------------------------------------------------------

    /**
     * Create a Document from raw BSON bytes.
     */
    public static function fromBSON(string $bson): static
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
    public static function fromJSON(string $json): static
    {
        try {
            $phpValue = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DriverUnexpectedValueException(
                sprintf('Got parse error at "%s", position 1: "SPECIAL_EXPECTED"', substr($json, 0, 1)),
                previous: $e,
            );
        }

        if (! is_array($phpValue) && ! is_object($phpValue)) {
            throw new DriverUnexpectedValueException('Invalid Extended JSON string');
        }

        $decoded = ExtendedJson::fromValue((array) $phpValue);
        $bson    = BsonEncoder::encode((array) $decoded);

        return new static($bson);
    }

    /**
     * Create a Document from a PHP array or object.
     */
    public static function fromPHP(array|object $value): static
    {
        return new static(BsonEncoder::encode($value));
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
            throw new DriverRuntimeException(sprintf('Could not find key "%s" in BSON document', $key));
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
        $map = $typeMap ?? [];
        // Resolve 'bson' root to Document
        if (($map['root'] ?? null) === 'bson') {
            $map['root'] = 'bsonDocument';
        }

        return BsonDecoder::decode(base64_decode($this->data), $map, handlePersistable: true);
    }

    public function toCanonicalExtendedJSON(): string
    {
        $decoded = BsonDecoder::decode(
            base64_decode($this->data),
            ['root' => 'object', 'document' => 'object', 'array' => 'array'],
        );

        return ExtendedJson::toCanonical($decoded);
    }

    public function toRelaxedExtendedJSON(): string
    {
        $decoded = BsonDecoder::decode(
            base64_decode($this->data),
            ['root' => 'object', 'document' => 'object', 'array' => 'array'],
        );

        return ExtendedJson::toRelaxed($decoded);
    }

    // ------------------------------------------------------------------
    // Stringable
    // ------------------------------------------------------------------

    /** Returns the raw BSON bytes. */
    public function __toString(): string
    {
        return base64_decode($this->data);
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

    public function __serialize(): array
    {
        return ['data' => $this->data];
    }

    public function __unserialize(array $data): void
    {
        $bson = base64_decode($data['data'] ?? '');
        self::assertValidBson($bson, self::class);
        $this->data = base64_encode($bson);
    }

    public static function __set_state(array $properties): static
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
            'data'  => $this->data,
            'value' => $this->toPHP(['document' => 'bson', 'array' => 'bson']),
        ];
    }

    /**
     * Return the decoded PHP array (with nested Document/PackedArray), decoding from raw BSON if needed.
     *
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        $cache = self::$decodedCache ??= new WeakMap();
        if (! isset($cache[$this])) {
            $cache[$this] = (array) BsonDecoder::decode(base64_decode($this->data), [
                'root'     => 'array',
                'document' => 'bsonDocument',
                'array'    => 'bsonArray',
            ]);
        }

        return $cache[$this];
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
