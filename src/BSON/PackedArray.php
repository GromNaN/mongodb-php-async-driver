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
use MongoDB\Internal\BSON\Index\PackedArrayIndex;
use OutOfBoundsException;
use Stringable;

use function array_is_list;
use function base64_decode;
use function base64_encode;
use function get_debug_type;
use function is_array;
use function is_int;
use function json_decode;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

use const JSON_THROW_ON_ERROR;

/**
 * Represents a BSON array (packed array – keys must be sequential integers
 * starting at 0).
 */
final class PackedArray implements IteratorAggregate, ArrayAccess, Type, Stringable
{
    /** Base64-encoded raw BSON bytes — public for get_object_vars() / var_export() compat. */
    public readonly string $data;

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

        if (! is_array($phpValue)) {
            throw new DriverUnexpectedValueException(
                'Received invalid JSON array: expected key 0, but found ""',
            );
        }

        // Validate sequential integer keys starting at 0
        $expected = 0;
        foreach ($phpValue as $key => $v) {
            if ((string) $key !== (string) $expected) {
                throw new DriverUnexpectedValueException(
                    sprintf('Received invalid JSON array: expected key %d, but found "%s"', $expected, $key),
                );
            }

            $expected++;
        }

        $decoded = ExtendedJson::fromValue($phpValue);
        $bson    = BsonEncoder::encodeList(is_array($decoded) ? $decoded : (array) $decoded);

        return new static($bson);
    }

    public static function fromPHP(array $value): static
    {
        if (! array_is_list($value)) {
            throw new DriverInvalidArgumentException('Expected value to be a list, but given array is not');
        }

        return new static(BsonEncoder::encodeList($value));
    }

    // ------------------------------------------------------------------
    // Key access
    // ------------------------------------------------------------------

    public function has(int $index): bool
    {
        return PackedArrayIndex::forBson($this)->hasField($index);
    }

    public function get(int $index): mixed
    {
        try {
            return PackedArrayIndex::forBson($this)->getFieldValue($index);
        } catch (OutOfBoundsException) {
            throw new DriverRuntimeException(sprintf('Could not find index "%d" in BSON array', $index));
        }
    }

    // ------------------------------------------------------------------
    // Conversion
    // ------------------------------------------------------------------

    public function toPHP(?array $typeMap = null): array|object
    {
        $map = $typeMap ?? ['root' => 'array'];
        // Resolve 'bson' root to PackedArray
        if (($map['root'] ?? null) === 'bson') {
            $map['root'] = 'bsonArray';
        }

        // For array root, ignore degenerate BSON keys (reindex by insertion order)
        $rootTarget = $map['root'] ?? 'array';
        $ignoreRoot = ($rootTarget === 'array' || $rootTarget === 'bsonArray');

        return BsonDecoder::decode(base64_decode($this->data), $map, ignoreRootKeys: $ignoreRoot);
    }

    public function toCanonicalExtendedJSON(): string
    {
        return ExtendedJson::toCanonical(BsonDecoder::decode(base64_decode($this->data), ['root' => 'array'], preserveInt64: true));
    }

    public function toRelaxedExtendedJSON(): string
    {
        return ExtendedJson::toRelaxed(BsonDecoder::decode(base64_decode($this->data), ['root' => 'array'], preserveInt64: true));
    }

    // ------------------------------------------------------------------
    // Stringable
    // ------------------------------------------------------------------

    public function __toString(): string
    {
        return base64_decode($this->data);
    }

    // ------------------------------------------------------------------
    // IteratorAggregate
    // ------------------------------------------------------------------

    public function getIterator(): Iterator
    {
        $data = [];
        foreach (PackedArrayIndex::forBson($this)->fields as $i => $field) {
            $data[$i] = $field->getValue();
        }

        return Iterator::createFromDecodedData($this, $data);
    }

    // ------------------------------------------------------------------
    // ArrayAccess
    // ------------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        if (is_int($offset)) {
            return $this->has($offset);
        }

        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $type = get_debug_type($offset);
        if ($type === 'int') {
            return $this->get($offset);
        }

        throw new DriverRuntimeException(
            sprintf('Could not find index of type "%s" in BSON array', $type),
        );
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new DriverLogicException('Cannot write to MongoDB\BSON\PackedArray offset');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new DriverLogicException('Cannot unset MongoDB\BSON\PackedArray offset');
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
            'value' => $this->toPHP(['root' => 'array', 'document' => 'bson', 'array' => 'bson']),
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
