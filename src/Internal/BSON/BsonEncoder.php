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
    // BSON type constants
    private const TYPE_DOUBLE               = "\x01";
    private const TYPE_STRING               = "\x02";
    private const TYPE_DOCUMENT             = "\x03";
    private const TYPE_ARRAY                = "\x04";
    private const TYPE_BINARY               = "\x05";
    private const TYPE_UNDEFINED            = "\x06";
    private const TYPE_OBJECTID             = "\x07";
    private const TYPE_BOOLEAN              = "\x08";
    private const TYPE_UTCDATETIME          = "\x09";
    private const TYPE_NULL                 = "\x0A";
    private const TYPE_REGEX                = "\x0B";
    private const TYPE_DBPOINTER           = "\x0C";
    private const TYPE_JAVASCRIPT           = "\x0D";
    private const TYPE_SYMBOL               = "\x0E";
    private const TYPE_JAVASCRIPT_WITH_SCOPE = "\x0F";
    private const TYPE_INT32                = "\x10";
    private const TYPE_TIMESTAMP            = "\x11";
    private const TYPE_INT64                = "\x12";
    private const TYPE_DECIMAL128           = "\x13";
    private const TYPE_MAXKEY               = "\x7F";
    private const TYPE_MINKEY               = "\xFF";

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

        [$typeByte, $encoded] = self::encodeValue($value, $depth);

        return $typeByte . $ckey . $encoded;
    }

    /**
     * Determine the BSON type for a value and return [type_byte, value_bytes].
     *
     * @return array{string, string}
     */
    private static function encodeValue(mixed $value, int $depth = 0): array
    {
        // --- null ---
        if ($value === null) {
            return [self::TYPE_NULL, ''];
        }

        // --- bool ---
        if (is_bool($value)) {
            return [self::TYPE_BOOLEAN, $value ? "\x01" : "\x00"];
        }

        // --- int ---
        if (is_int($value)) {
            if ($value >= -2147483648 && $value <= 2147483647) {
                return [self::TYPE_INT32, pack('V', $value & 0xFFFFFFFF)];
            }

            return [self::TYPE_INT64, pack('P', $value)];
        }

        // --- float ---
        if (is_float($value)) {
            return [self::TYPE_DOUBLE, pack('e', $value)];
        }

        // --- string ---
        if (is_string($value)) {
            $len = strlen($value);

            return [self::TYPE_STRING, pack('V', $len + 1) . $value . "\x00"];
        }

        // --- array ---
        if (is_array($value)) {
            if (array_is_list($value)) {
                return [self::TYPE_ARRAY, self::encodeArray($value, $depth + 1)];
            }

            return [self::TYPE_DOCUMENT, self::encodeDocument($value, $depth + 1)];
        }

        // --- BSON extension types ---

        if ($value instanceof Binary) {
            $data    = $value->getData();
            $subtype = $value->getType();

            return [
                self::TYPE_BINARY,
                pack('V', strlen($data)) . chr($subtype) . $data,
            ];
        }

        if ($value instanceof ObjectId) {
            return [self::TYPE_OBJECTID, hex2bin($value->__toString())];
        }

        if ($value instanceof UTCDateTime) {
            // getMilliseconds() returns an int or \MongoDB\BSON\Int64
            $ms = $value->getMilliseconds();
            if ($ms instanceof Int64) {
                $ms = (int) (string) $ms;
            }

            return [self::TYPE_UTCDATETIME, pack('P', $ms)];
        }

        if ($value instanceof Regex) {
            return [
                self::TYPE_REGEX,
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
                    self::TYPE_JAVASCRIPT_WITH_SCOPE,
                    pack('V', $totalLen) . $inner,
                ];
            }

            // plain javascript (0x0D)
            return [
                self::TYPE_JAVASCRIPT,
                pack('V', strlen($code) + 1) . $code . "\x00",
            ];
        }

        if ($value instanceof Timestamp) {
            return [
                self::TYPE_TIMESTAMP,
                pack('V', $value->getIncrement()) . pack('V', $value->getTimestamp()),
            ];
        }

        if ($value instanceof Int64) {
            $v = (int) (string) $value;

            return [self::TYPE_INT64, pack('P', $v)];
        }

        if ($value instanceof Decimal128) {
            // Decimal128 stores its value as a string; for proper encoding we need
            // the 16-byte IEEE 754 representation.  As a safe fallback we store the
            // raw bytes if the value was originally decoded from BSON (16-char binary
            // string), otherwise we zero-pad to 16 bytes.
            $raw = $value->__toString();
            // If the string happens to be exactly 16 bytes it came from our decoder.
            $bytes = strlen($raw) === 16 ? $raw : str_pad(substr($raw, 0, 16), 16, "\x00");

            return [self::TYPE_DECIMAL128, $bytes];
        }

        if ($value instanceof MaxKey) {
            return [self::TYPE_MAXKEY, ''];
        }

        if ($value instanceof MinKey) {
            return [self::TYPE_MINKEY, ''];
        }

        if ($value instanceof BsonUndefined) {
            return [self::TYPE_UNDEFINED, ''];
        }

        if ($value instanceof DBPointer) {
            $ref     = $value->getRef();
            $refBytes = pack('V', strlen($ref) + 1) . $ref . "\x00";

            return [self::TYPE_DBPOINTER, $refBytes . hex2bin($value->getId())];
        }

        if ($value instanceof Symbol) {
            $sym = (string) $value;

            return [self::TYPE_SYMBOL, pack('V', strlen($sym) + 1) . $sym . "\x00"];
        }

        if ($value instanceof Document) {
            return [self::TYPE_DOCUMENT, (string) $value];
        }

        if ($value instanceof PackedArray) {
            return [self::TYPE_ARRAY, (string) $value];
        }

        // --- Persistable / Serializable objects ---
        if ($value instanceof Persistable) {
            $serialized       = $value->bsonSerialize();
            $data             = ['__pclass' => new Binary($value::class, Binary::TYPE_USER_DEFINED)];
            $data            += is_array($serialized) ? $serialized : (array) $serialized;

            return [self::TYPE_DOCUMENT, self::encodeDocument($data, $depth + 1)];
        }

        if ($value instanceof Serializable) {
            $serialized = $value->bsonSerialize();
            // If bsonSerialize returns a list, encode as BSON array
            if (is_array($serialized) && array_is_list($serialized)) {
                return [self::TYPE_ARRAY, self::encodeArray($serialized, $depth + 1)];
            }

            return [self::TYPE_DOCUMENT, self::encodeDocument($serialized, $depth + 1)];
        }

        // --- Generic object (stdClass, etc.) ---
        if (is_object($value)) {
            return [self::TYPE_DOCUMENT, self::encodeDocument(get_object_vars($value), $depth + 1)];
        }

        throw new InvalidArgumentException(
            sprintf('Unsupported value type for BSON encoding: %s', get_debug_type($value)),
        );
    }
}
