<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Document;
use MongoDB\BSON\Int64;
use MongoDB\BSON\Javascript;
use MongoDB\BSON\MaxKey;
use MongoDB\BSON\MinKey;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\PackedArray;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\Unserializable;
use MongoDB\BSON\UTCDateTime;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Pure-userland BSON decoder.
 *
 * @internal
 */
final class BsonDecoder
{
// BSON type byte values
private const TYPE_DOUBLE                = 0x01;
private const TYPE_STRING                = 0x02;
private const TYPE_DOCUMENT              = 0x03;
private const TYPE_ARRAY                 = 0x04;
private const TYPE_BINARY                = 0x05;
private const TYPE_OBJECTID              = 0x07;
private const TYPE_BOOLEAN               = 0x08;
private const TYPE_UTCDATETIME           = 0x09;
private const TYPE_NULL                  = 0x0A;
private const TYPE_REGEX                 = 0x0B;
private const TYPE_JAVASCRIPT            = 0x0D;
private const TYPE_JAVASCRIPT_WITH_SCOPE = 0x0F;
private const TYPE_INT32                 = 0x10;
private const TYPE_TIMESTAMP             = 0x11;
private const TYPE_INT64                 = 0x12;
private const TYPE_DECIMAL128            = 0x13;
private const TYPE_MAXKEY                = 0x7F;
private const TYPE_MINKEY                = 0xFF;

/**
 * Default type map applied when none (or a partial one) is supplied.
 */
private const DEFAULT_TYPE_MAP = [
    'root'     => 'object',
    'document' => 'object',
    'array'    => 'array',
];

// -------------------------------------------------------------------------
// Public API
// -------------------------------------------------------------------------

/**
 * Decode a raw BSON byte string into a PHP value.
 *
 * @param string $bson    Raw BSON bytes
 * @param array  $typeMap Keys: 'root', 'document', 'array', 'fieldPaths'
 * @return array|object
 */
public static function decode(string $bson, array $typeMap = []): array|object
{
    $typeMap = array_merge(self::DEFAULT_TYPE_MAP, $typeMap);

    $offset = 0;
    return self::decodeDocument($bson, $offset, $typeMap, 'root');
}

// -------------------------------------------------------------------------
// Private helpers
// -------------------------------------------------------------------------

/**
 * Decode a BSON document (or array) starting at $offset.
 *
 * @param string $bson
 * @param int    $offset Modified in-place to point past the document
 * @param array  $typeMap
 * @param string $context 'root' | 'document' | 'array'
 * @return array|object
 */
private static function decodeDocument(
    string $bson,
    int &$offset,
    array $typeMap,
    string $context = 'document'
): array|object {
    $startOffset = $offset;

    // Read document total length (int32 LE)
    $totalLen = self::readInt32Unsigned($bson, $offset);

    if ($totalLen < 5) {
        throw new RuntimeException(
            sprintf('Invalid BSON document length %d at offset %d', $totalLen, $startOffset)
        );
    }

    $endOffset = $startOffset + $totalLen;

    // Collect elements into a plain PHP array first
    $fields = [];

    while ($offset < $endOffset - 1) {
        // Read type byte
        $typeByte = ord($bson[$offset]);
        $offset++;

        if ($typeByte === 0x00) {
            // Terminating null – end of document
            break;
        }

        // Read key (cstring)
        $key = self::readCString($bson, $offset);

        // Read value
        $value = self::decodeElement($bson, $offset, $typeByte, $typeMap);

        $fields[$key] = $value;
    }

    // Ensure offset is positioned right after the document
    $offset = $endOffset;

    // Determine target type from typeMap
    $targetType = match ($context) {
        'root'     => $typeMap['root']     ?? 'object',
        'array'    => $typeMap['array']    ?? 'array',
        default    => $typeMap['document'] ?? 'object',
    };

    return self::applyTargetType($fields, $targetType, $bson, $startOffset, $totalLen);
}

/**
 * Decode a single BSON element value (without key) at $offset.
 *
 * @param string $bson
 * @param int    $offset Modified in-place
 * @param int    $type   BSON type byte (int)
 * @param array  $typeMap
 * @return mixed
 */
private static function decodeElement(
    string $bson,
    int &$offset,
    int $type,
    array $typeMap = []
): mixed {
    return match ($type) {
        self::TYPE_DOUBLE => self::readDouble($bson, $offset),

        self::TYPE_STRING => self::readString($bson, $offset),

        self::TYPE_DOCUMENT => self::decodeDocument($bson, $offset, $typeMap, 'document'),

        self::TYPE_ARRAY => self::decodeDocument($bson, $offset, $typeMap, 'array'),

        self::TYPE_BINARY => self::readBinary($bson, $offset),

        self::TYPE_OBJECTID => self::readObjectId($bson, $offset),

        self::TYPE_BOOLEAN => self::readBoolean($bson, $offset),

        self::TYPE_UTCDATETIME => self::readUtcDateTime($bson, $offset),

        self::TYPE_NULL => null,

        self::TYPE_REGEX => self::readRegex($bson, $offset),

        self::TYPE_JAVASCRIPT => self::readJavascript($bson, $offset),

        self::TYPE_JAVASCRIPT_WITH_SCOPE => self::readJavascriptWithScope($bson, $offset, $typeMap),

        self::TYPE_INT32 => self::readInt32($bson, $offset),

        self::TYPE_TIMESTAMP => self::readTimestamp($bson, $offset),

        self::TYPE_INT64 => new Int64(self::readInt64($bson, $offset)),

        self::TYPE_DECIMAL128 => self::readDecimal128($bson, $offset),

        self::TYPE_MAXKEY => new MaxKey(),

        self::TYPE_MINKEY => new MinKey(),

        default => throw new RuntimeException(
            sprintf('Unknown BSON type 0x%02X at offset %d', $type, $offset)
        ),
    };
}

// -------------------------------------------------------------------------
// Low-level read helpers
// -------------------------------------------------------------------------

/**
 * Read a null-terminated C-string from $bson at $offset.
 * Advances $offset past the null byte.
 */
private static function readCString(string $bson, int &$offset): string
{
    $nullPos = strpos($bson, "\x00", $offset);

    if ($nullPos === false) {
        throw new RuntimeException(
            sprintf('Unterminated BSON cstring at offset %d', $offset)
        );
    }

    $str    = substr($bson, $offset, $nullPos - $offset);
    $offset = $nullPos + 1;

    return $str;
}

/**
 * Read an unsigned 32-bit little-endian integer.
 * Does NOT sign-extend (returns 0–4294967295).
 */
private static function readInt32Unsigned(string $bson, int &$offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('V', substr($bson, $offset, 4));
    $offset += 4;
    return $u[1];
}

/**
 * Read a signed 32-bit little-endian integer.
 */
private static function readInt32(string $bson, int &$offset): int
{
    $v = self::readInt32Unsigned($bson, $offset);

    // Sign-extend: values >= 0x80000000 are negative in two's complement
    if ($v >= 0x80000000) {
        $v -= 0x100000000;
    }

    return $v;
}

/**
 * Read a 64-bit little-endian integer.
 * On 64-bit PHP, pack('P') / unpack('P') handles unsigned 64-bit;
 * PHP natively stores it as a signed int64 on 64-bit systems.
 */
private static function readInt64(string $bson, int &$offset): int
{
    /** @var array{1: int} $u */
    $u = unpack('P', substr($bson, $offset, 8));
    $offset += 8;
    return $u[1];
}

/**
 * Read a 64-bit IEEE 754 little-endian double.
 */
private static function readDouble(string $bson, int &$offset): float
{
    /** @var array{1: float} $u */
    $u = unpack('e', substr($bson, $offset, 8));
    $offset += 8;
    return $u[1];
}

/**
 * Read a BSON UTF-8 string (int32 length + bytes + null terminator).
 */
private static function readString(string $bson, int &$offset): string
{
    $len    = self::readInt32Unsigned($bson, $offset); // includes null terminator
    $str    = substr($bson, $offset, $len - 1);        // exclude null
    $offset += $len;                                   // skip string bytes + null

    return $str;
}

/**
 * Read a BSON Binary value.
 */
private static function readBinary(string $bson, int &$offset): Binary
{
    $len     = self::readInt32Unsigned($bson, $offset);
    $subtype = ord($bson[$offset]);
    $offset++;
    $data    = substr($bson, $offset, $len);
    $offset += $len;

    return new Binary($data, $subtype);
}

/**
 * Read a 12-byte BSON ObjectId.
 */
private static function readObjectId(string $bson, int &$offset): ObjectId
{
    $bytes  = substr($bson, $offset, 12);
    $offset += 12;

    return new ObjectId(bin2hex($bytes));
}

/**
 * Read a single BSON boolean byte.
 */
private static function readBoolean(string $bson, int &$offset): bool
{
    $byte = ord($bson[$offset]);
    $offset++;
    return $byte !== 0x00;
}

/**
 * Read a BSON UTC datetime (int64 milliseconds).
 */
private static function readUtcDateTime(string $bson, int &$offset): UTCDateTime
{
    $ms = self::readInt64($bson, $offset);
    return new UTCDateTime($ms);
}

/**
 * Read two cstrings as a BSON Regex.
 */
private static function readRegex(string $bson, int &$offset): Regex
{
    $pattern = self::readCString($bson, $offset);
    $flags   = self::readCString($bson, $offset);

    return new Regex($pattern, $flags);
}

/**
 * Read a BSON JavaScript code value (no scope).
 */
private static function readJavascript(string $bson, int &$offset): Javascript
{
    $code = self::readString($bson, $offset);
    return new Javascript($code);
}

/**
 * Read a BSON JavaScript with scope value.
 */
private static function readJavascriptWithScope(
    string $bson,
    int &$offset,
    array $typeMap
): Javascript {
    // Total length of the javascript_with_scope value (includes the 4-byte length itself)
    $totalLen = self::readInt32Unsigned($bson, $offset);

    $code     = self::readString($bson, $offset);
    $scope    = self::decodeDocument($bson, $offset, $typeMap, 'document');

    return new Javascript($code, $scope);
}

/**
 * Read a BSON Timestamp (two uint32 values: increment, timestamp).
 */
private static function readTimestamp(string $bson, int &$offset): Timestamp
{
    $increment = self::readInt32Unsigned($bson, $offset);
    $timestamp = self::readInt32Unsigned($bson, $offset);

    return new Timestamp($increment, $timestamp);
}

/**
 * Read a 16-byte BSON Decimal128.
 * The raw bytes are stored as a binary string internally (not parsed to decimal).
 */
private static function readDecimal128(string $bson, int &$offset): Decimal128
{
    $bytes  = substr($bson, $offset, 16);
    $offset += 16;

    // Store the raw 16 bytes as the Decimal128 string representation for
    // round-trip fidelity.  A full IEEE 754 decimal128 parser is out of scope;
    // here we keep the raw bytes so the encoder can reconstruct the value.
    return new Decimal128($bytes);
}

// -------------------------------------------------------------------------
// Type-map application
// -------------------------------------------------------------------------

/**
 * Convert a plain PHP array of decoded fields to the type requested by $targetType.
 *
 * @param array<string, mixed> $fields
 * @param string               $targetType 'array'|'object'|'bsonDocument'|'bsonArray'|class-string
 * @param string               $rawBson     Raw BSON for the full input (used for bsonDocument/bsonArray)
 * @param int                  $docOffset   Byte offset where this document started in $rawBson
 * @param int                  $docLen      Byte length of this document in $rawBson
 * @return array|object
 */
private static function applyTargetType(
    array $fields,
    string $targetType,
    string $rawBson,
    int $docOffset,
    int $docLen
): array|object {
    return match ($targetType) {
        'array' => $fields,

        'object' => self::arrayToStdClass($fields),

        'bsonDocument' => Document::fromPHP($fields),

        'bsonArray' => PackedArray::fromPHP(array_values($fields)),

        default => self::instantiateClass($targetType, $fields),
    };
}

/**
 * Recursively convert an associative array to a stdClass, preserving
 * nested stdClass objects for sub-documents.
 *
 * @param array<string, mixed> $data
 */
private static function arrayToStdClass(array $data): stdClass
{
    $obj = new stdClass();
    foreach ($data as $key => $value) {
        $obj->$key = $value;
    }
    return $obj;
}

/**
 * Instantiate a user-supplied class and populate it with decoded fields.
 *
 * @param class-string         $className
 * @param array<string, mixed> $fields
 * @return object
 */
private static function instantiateClass(string $className, array $fields): object
{
    if (!class_exists($className)) {
        throw new InvalidArgumentException(
            sprintf('Type map class "%s" does not exist', $className)
        );
    }

    $obj = (new \ReflectionClass($className))->newInstanceWithoutConstructor();

    if ($obj instanceof Unserializable) {
        $obj->bsonUnserialize($fields);
        return $obj;
    }

    // Fallback: populate public properties or use array cast
    foreach ($fields as $key => $value) {
        $obj->$key = $value;
    }

    return $obj;
}
}
