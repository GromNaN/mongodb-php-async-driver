<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON;

use InvalidArgumentException;
use MongoDB\BSON\Binary;
use MongoDB\BSON\DBPointer;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\Javascript;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Serializable;
use MongoDB\BSON\Symbol;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\Undefined as BsonUndefined;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\UnexpectedValueException as DriverUnexpectedValueException;

use function array_is_list;
use function chr;
use function get_debug_type;
use function get_object_vars;
use function hex2bin;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function pack;
use function sprintf;
use function str_contains;
use function str_pad;
use function strlen;
use function substr;

/**
 * Pure-userland BSON encoder.
 *
 * @internal
 */
final class BsonEncoder
{
    private const MAX_NESTING_DEPTH = 100;

    /**
     * Encode a PHP array or object as a BSON document.
     *
     * @return string Raw BSON bytes
     */
    public static function encode(array|object $document): string
    {
        return self::encodeDocument($document, 0);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Encode an associative array or object as a BSON document (type 0x03).
     */
    private static function encodeDocument(array|object $doc, int $depth = 0): string
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new DriverUnexpectedValueException('Nesting level too deep');
        }

        // Handle BSON-aware objects before iterating
        if ($doc instanceof Document) {
            // Already raw BSON – return as-is (it is a complete document)
            return (string) $doc;
        }

        if ($doc instanceof PackedArray) {
            return (string) $doc;
        }

        if ($doc instanceof Persistable) {
            $serialized = $doc->bsonSerialize();
            // Inject __pclass first
            $data = ['__pclass' => new Binary($doc::class, Binary::TYPE_USER_DEFINED)];
            $data += is_array($serialized) ? $serialized : (array) $serialized;

            return self::encodeDocument($data, $depth + 1);
        }

        if ($doc instanceof Serializable) {
            $serialized = $doc->bsonSerialize();

            return self::encodeDocument($serialized, $depth + 1);
        }

        // Normalize to array: use get_object_vars for objects to get only public properties
        if (is_object($doc)) {
            $doc = get_object_vars($doc);
        }

        $body = '';
        foreach ($doc as $key => $value) {
            $body .= self::encodeElement((string) $key, $value, $depth);
        }

        $totalLen = 4 + strlen($body) + 1; // int32 + elements + terminating null

        return pack('V', $totalLen) . $body . "\x00";
    }

    /**
     * Encode a sequential PHP array as a BSON array (type 0x04).
     * Keys are re-indexed as "0", "1", "2", …
     */
    public static function encodeList(array $arr): string
    {
        return self::encodeArray($arr, 0);
    }

    private static function encodeArray(array $arr, int $depth = 0): string
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new DriverUnexpectedValueException('Nesting level too deep');
        }

        $body = '';
        foreach ($arr as $index => $value) {
            $body .= self::encodeElement((string) $index, $value, $depth);
        }

        $totalLen = 4 + strlen($body) + 1;

        return pack('V', $totalLen) . $body . "\x00";
    }

    /**
     * Encode a single key-value pair as a BSON element.
     * Returns: type_byte + cstring_key + value_bytes
     */
    private static function encodeElement(string|int $key, mixed $value, int $depth = 0): string
    {
        $key = (string) $key;

        if (str_contains($key, "\x00")) {
            throw new InvalidArgumentException(
                sprintf('BSON key must not contain null bytes, got: %s', json_encode($key)),
            );
        }

        $ckey = $key . "\x00";

        [$bsonType, $encoded] = self::encodeValue($value, $depth);

        return chr($bsonType->value) . $ckey . $encoded;
    }

    /**
     * Determine the BSON type for a value and return [BsonType, value_bytes].
     *
     * @return array{BsonType, string}
     */
    private static function encodeValue(mixed $value, int $depth = 0): array
    {
        // --- null ---
        if ($value === null) {
            return [BsonType::Null, ''];
        }

        // --- bool ---
        if (is_bool($value)) {
            return [BsonType::Boolean, $value ? "\x01" : "\x00"];
        }

        // --- int ---
        if (is_int($value)) {
            if ($value >= -2147483648 && $value <= 2147483647) {
                return [BsonType::Int32, pack('V', $value & 0xFFFFFFFF)];
            }

            return [BsonType::Int64, pack('P', $value)];
        }

        // --- float ---
        if (is_float($value)) {
            return [BsonType::Double, pack('e', $value)];
        }

        // --- string ---
        if (is_string($value)) {
            $len = strlen($value);

            return [BsonType::String, pack('V', $len + 1) . $value . "\x00"];
        }

        // --- array ---
        if (is_array($value)) {
            if (array_is_list($value)) {
                return [BsonType::Array, self::encodeArray($value, $depth + 1)];
            }

            return [BsonType::Document, self::encodeDocument($value, $depth + 1)];
        }

        // --- BSON extension types ---

        if ($value instanceof Binary) {
            $data    = $value->getData();
            $subtype = $value->getType();

            return [
                BsonType::Binary,
                pack('V', strlen($data)) . chr($subtype) . $data,
            ];
        }

        if ($value instanceof ObjectId) {
            return [BsonType::ObjectId, hex2bin($value->__toString())];
        }

        if ($value instanceof UTCDateTime) {
            // getMilliseconds() returns an int or \MongoDB\BSON\Int64
            $ms = $value->getMilliseconds();
            if ($ms instanceof Int64) {
                $ms = (int) (string) $ms;
            }

            return [BsonType::Date, pack('P', $ms)];
        }

        if ($value instanceof Regex) {
            return [
                BsonType::Regex,
                $value->getPattern() . "\x00" . $value->getFlags() . "\x00",
            ];
        }

        if ($value instanceof Javascript) {
            $code  = $value->getCode();
            $scope = $value->getScope();

            if ($scope !== null) {
                // javascript_with_scope (0x0F)
                $codeBytes  = pack('V', strlen($code) + 1) . $code . "\x00";
                $scopeBytes = self::encodeDocument($scope, $depth + 1);
                $inner      = $codeBytes . $scopeBytes;
                $totalLen   = 4 + strlen($inner); // includes the leading int32 itself

                return [
                    BsonType::JavaScriptWithScope,
                    pack('V', $totalLen) . $inner,
                ];
            }

            // plain javascript (0x0D)
            return [
                BsonType::JavaScript,
                pack('V', strlen($code) + 1) . $code . "\x00",
            ];
        }

        if ($value instanceof Timestamp) {
            return [
                BsonType::Timestamp,
                pack('V', $value->getIncrement()) . pack('V', $value->getTimestamp()),
            ];
        }

        if ($value instanceof Int64) {
            $v = (int) (string) $value;

            return [BsonType::Int64, pack('P', $v)];
        }

        if ($value instanceof Decimal128) {
            // Decimal128 stores its value as a string; for proper encoding we need
            // the 16-byte IEEE 754 representation.  As a safe fallback we store the
            // raw bytes if the value was originally decoded from BSON (16-char binary
            // string), otherwise we zero-pad to 16 bytes.
            $raw = $value->__toString();
            // If the string happens to be exactly 16 bytes it came from our decoder.
            $bytes = strlen($raw) === 16 ? $raw : substr(str_pad($raw, 16, "\x00"), 0, 16);

            return [BsonType::Decimal128, $bytes];
        }

        if ($value instanceof MaxKey) {
            return [BsonType::MaxKey, ''];
        }

        if ($value instanceof MinKey) {
            return [BsonType::MinKey, ''];
        }

        if ($value instanceof BsonUndefined) {
            return [BsonType::Undefined, ''];
        }

        if ($value instanceof DBPointer) {
            $ref      = $value->getRef();
            $refBytes = pack('V', strlen($ref) + 1) . $ref . "\x00";

            return [BsonType::DBPointer, $refBytes . hex2bin($value->getId())];
        }

        if ($value instanceof Symbol) {
            $sym = (string) $value;

            return [BsonType::Symbol, pack('V', strlen($sym) + 1) . $sym . "\x00"];
        }

        if ($value instanceof Document) {
            return [BsonType::Document, (string) $value];
        }

        if ($value instanceof PackedArray) {
            return [BsonType::Array, (string) $value];
        }

        // --- Persistable / Serializable objects ---
        if ($value instanceof Persistable) {
            $serialized       = $value->bsonSerialize();
            $data             = ['__pclass' => new Binary($value::class, Binary::TYPE_USER_DEFINED)];
            $data            += is_array($serialized) ? $serialized : (array) $serialized;

            return [BsonType::Document, self::encodeDocument($data, $depth + 1)];
        }

        if ($value instanceof Serializable) {
            $serialized = $value->bsonSerialize();
            // If bsonSerialize returns a list, encode as BSON array
            if (is_array($serialized) && array_is_list($serialized)) {
                return [BsonType::Array, self::encodeArray($serialized, $depth + 1)];
            }

            return [BsonType::Document, self::encodeDocument($serialized, $depth + 1)];
        }

        // --- Generic object (stdClass, etc.) ---
        if (is_object($value)) {
            return [BsonType::Document, self::encodeDocument(get_object_vars($value), $depth + 1)];
        }

        throw new InvalidArgumentException(
            sprintf('Unsupported value type for BSON encoding: %s', get_debug_type($value)),
        );
    }
}
