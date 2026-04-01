<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON;

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
use MongoDB\BSON\Symbol;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\Undefined as BsonUndefined;
use MongoDB\BSON\Unserializable;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\InvalidArgumentException as DriverInvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException as DriverUnexpectedValueException;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use stdClass;

use function array_key_exists;
use function array_merge;
use function bin2hex;
use function class_exists;
use function ctype_print;
use function ord;
use function rtrim;
use function sprintf;
use function strpos;
use function substr;
use function unpack;

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
    private const TYPE_UNDEFINED            = 0x06;
    private const TYPE_OBJECTID              = 0x07;
    private const TYPE_BOOLEAN               = 0x08;
    private const TYPE_UTCDATETIME           = 0x09;
    private const TYPE_NULL                  = 0x0A;
    private const TYPE_REGEX                 = 0x0B;
    private const TYPE_DBPOINTER            = 0x0C;
    private const TYPE_JAVASCRIPT            = 0x0D;
    private const TYPE_SYMBOL               = 0x0E;
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
 * @param string $bson              Raw BSON bytes
 * @param array  $typeMap           Keys: 'root', 'document', 'array', 'fieldPaths'
 * @param bool   $handlePersistable Whether to detect and instantiate Persistable classes
 */
    public static function decode(
        string $bson,
        array $typeMap = [],
        bool $handlePersistable = true,
        bool $ignoreRootKeys = false,
    ): array|object {
        // Resolve null type map values to defaults
        foreach ($typeMap as $k => $v) {
            if ($v !== null) {
                continue;
            }

            unset($typeMap[$k]);
        }

        // Compute Persistable-suppression flags from the filtered typeMap BEFORE merging with
        // defaults: if the user explicitly set root/document to 'object', they want stdClass and
        // Persistable detection must not override that.
        $noRootPersistable     = array_key_exists('root', $typeMap) && $typeMap['root'] === 'object';
        $noDocumentPersistable = array_key_exists('document', $typeMap) && $typeMap['document'] === 'object';

        $typeMap = array_merge(self::DEFAULT_TYPE_MAP, $typeMap);

        $offset = 0;

        try {
            return self::decodeDocument($bson, $offset, $typeMap, 'root', $handlePersistable, $ignoreRootKeys, $noRootPersistable, $noDocumentPersistable);
        } catch (RuntimeException $e) {
            throw new DriverUnexpectedValueException($e->getMessage(), previous: $e);
        }
    }

// -------------------------------------------------------------------------
// Private helpers
// -------------------------------------------------------------------------

/**
 * Decode a BSON document (or array) starting at $offset.
 *
 * @param int    $offset  Modified in-place to point past the document
 * @param string $context 'root' | 'document' | 'array'
 */
    private static function decodeDocument(
        string $bson,
        int &$offset,
        array $typeMap,
        string $context = 'document',
        bool $handlePersistable = false,
        bool $ignoreRootKeys = false,
        bool $noRootPersistable = false,
        bool $noDocumentPersistable = false,
        string $parentFieldPath = '',
    ): array|object {
        $startOffset = $offset;

        // Read document total length (int32 LE)
        $totalLen = self::readInt32Unsigned($bson, $offset);

        if ($totalLen < 5) {
            throw new RuntimeException(
                sprintf('Invalid BSON document length %d at offset %d', $totalLen, $startOffset),
            );
        }

        $endOffset = $startOffset + $totalLen;

        // For BSON arrays, collect elements sequentially (handles degenerate/duplicate BSON keys)
        // Also allow callers to force sequential collection at root via $ignoreRootKeys
        $isArray = ($context === 'array') || ($context === 'root' && $ignoreRootKeys);

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

            // Read key (cstring) and build field path for error messages
            $key = self::readCString($bson, $offset);
            $fieldPath = $parentFieldPath === '' ? $key : $parentFieldPath . '.' . $key;

            // Read value
            $value = self::decodeElement($bson, $offset, $typeByte, $typeMap, $handlePersistable, $fieldPath, $noRootPersistable, $noDocumentPersistable);

            // For arrays, always append in insertion order (handles degenerate BSON)
            if ($isArray) {
                $fields[] = $value;
            } else {
                $fields[$key] = $value;
            }
        }

        // Ensure offset is positioned right after the document
        $offset = $endOffset;

        // Determine target type from typeMap
        $targetType = match ($context) {
            'root'     => $typeMap['root']     ?? 'object',
            'array'    => $typeMap['array']    ?? 'array',
            default    => $typeMap['document'] ?? 'object',
        };

        // Resolve 'bson' shorthand based on context (root is handled in callers)
        if ($targetType === 'bson' && $context !== 'root') {
            $targetType = $context === 'array' ? 'bsonArray' : 'bsonDocument';
        }

        // Short-circuit for array/BSON-typed targets: no Persistable detection
        if ($targetType === 'array' || $targetType === 'bsonDocument' || $targetType === 'bsonArray') {
            return self::applyTargetType($fields, $targetType, $bson, $startOffset, $totalLen);
        }

        // Determine if Persistable detection is suppressed for this context.
        // It is suppressed when the user explicitly requested 'object' (= stdClass), meaning
        // they do not want __pclass-based class instantiation.
        $persistableDisabled = match ($context) {
            'root'  => $noRootPersistable,
            'array' => true,
            default => $noDocumentPersistable,
        };

        // Try __pclass Persistable override: if the document carries a __pclass Binary (subtype
        // 0x80) whose class name implements Persistable, use that class regardless of type map.
        if (
            $handlePersistable
            && ! $persistableDisabled
            && isset($fields['__pclass'])
            && $fields['__pclass'] instanceof Binary
            && $fields['__pclass']->getType() === Binary::TYPE_USER_DEFINED
        ) {
            $pclassName = $fields['__pclass']->getData();
            if (class_exists($pclassName)) {
                $rc = new ReflectionClass($pclassName);
                if ($rc->isInstantiable() && $rc->implementsInterface(Persistable::class)) {
                    $obj = $rc->newInstanceWithoutConstructor();
                    $obj->bsonUnserialize($fields); // pass ALL fields including __pclass

                    return $obj;
                }
            }
        }

        // Resolve 'object' target to stdClass
        if ($targetType === 'object') {
            return self::arrayToStdClass($fields);
        }

        // User-supplied class name: validate and instantiate
        return self::instantiateClass($targetType, $fields);
    }

/**
 * Decode a single BSON element value (without key) at $offset.
 *
 * @param int $offset Modified in-place
 * @param int $type   BSON type byte (int)
 */
    private static function decodeElement(
        string $bson,
        int &$offset,
        int $type,
        array $typeMap = [],
        bool $handlePersistable = false,
        string $fieldPath = '',
        bool $noRootPersistable = false,
        bool $noDocumentPersistable = false,
    ): mixed {
        return match ($type) {
            self::TYPE_DOUBLE => self::readDouble($bson, $offset),

            self::TYPE_STRING => self::readString($bson, $offset),

            self::TYPE_DOCUMENT => self::decodeDocument($bson, $offset, $typeMap, 'document', $handlePersistable, false, $noRootPersistable, $noDocumentPersistable, $fieldPath),

            self::TYPE_ARRAY => self::decodeDocument($bson, $offset, $typeMap, 'array', $handlePersistable, false, $noRootPersistable, $noDocumentPersistable, $fieldPath),

            self::TYPE_BINARY => self::readBinary($bson, $offset),

            self::TYPE_UNDEFINED => BsonUndefined::create(),

            self::TYPE_OBJECTID => self::readObjectId($bson, $offset),

            self::TYPE_BOOLEAN => self::readBoolean($bson, $offset),

            self::TYPE_UTCDATETIME => self::readUtcDateTime($bson, $offset),

            self::TYPE_NULL => null,

            self::TYPE_REGEX => self::readRegex($bson, $offset),

            self::TYPE_DBPOINTER => self::readDbPointer($bson, $offset),

            self::TYPE_JAVASCRIPT => self::readJavascript($bson, $offset),

            self::TYPE_SYMBOL => self::readSymbol($bson, $offset),

            self::TYPE_JAVASCRIPT_WITH_SCOPE => self::readJavascriptWithScope($bson, $offset, $typeMap, $noRootPersistable, $noDocumentPersistable),

            self::TYPE_INT32 => self::readInt32($bson, $offset),

            self::TYPE_TIMESTAMP => self::readTimestamp($bson, $offset),

            self::TYPE_INT64 => new Int64(self::readInt64($bson, $offset)),

            self::TYPE_DECIMAL128 => self::readDecimal128($bson, $offset),

            self::TYPE_MAXKEY => new MaxKey(),

            self::TYPE_MINKEY => new MinKey(),

            default => throw new DriverUnexpectedValueException(
                sprintf('Detected unknown BSON type 0x%02X for field path "%s". Are you using the latest driver?', $type, $fieldPath),
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
                sprintf('Unterminated BSON cstring at offset %d', $offset),
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
 * Read a BSON DBPointer value (deprecated type 0x0C).
 * Format: string (ref name) + 12-byte ObjectId bytes.
 */
    private static function readDbPointer(string $bson, int &$offset): DBPointer
    {
        $ref = self::readString($bson, $offset);
        $oid = bin2hex(substr($bson, $offset, 12));
        $offset += 12;

        return DBPointer::create($ref, $oid);
    }

/**
 * Read a BSON Symbol value (deprecated type 0x0E).
 * Format: same as string (int32 length + bytes + null).
 */
    private static function readSymbol(string $bson, int &$offset): Symbol
    {
        $sym = self::readString($bson, $offset);

        return Symbol::create($sym);
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
        array $typeMap,
        bool $noRootPersistable = false,
        bool $noDocumentPersistable = false,
    ): Javascript {
        // Total length of the javascript_with_scope value (includes the 4-byte length itself)
        $totalLen = self::readInt32Unsigned($bson, $offset);

        $code  = self::readString($bson, $offset);
        $scope = self::decodeDocument($bson, $offset, $typeMap, 'document', false, false, $noRootPersistable, $noDocumentPersistable);

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
 */
    private static function readDecimal128(string $bson, int &$offset): Decimal128
    {
        $bytes  = substr($bson, $offset, 16);
        $offset += 16;

        // If these look like a null-padded ASCII decimal string (produced by our
        // own encoder which cannot convert strings to proper IEEE 754 bytes), strip
        // the padding and restore the original string.  Real IEEE 754 decimal128
        // bytes from a MongoDB server start with non-ASCII bytes in practice.
        $trimmed = rtrim($bytes, "\x00");
        if ($trimmed !== '' && ctype_print($trimmed)) {
            return new Decimal128($trimmed);
        }

        // Raw IEEE 754 bytes – store as-is; a full binary decoder is out of scope.
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
 * @param string               $rawBson    Raw BSON for the full input (used for bsonDocument/bsonArray)
 * @param int                  $docOffset  Byte offset where this document started in $rawBson
 * @param int                  $docLen     Byte length of this document in $rawBson
 */
    private static function applyTargetType(
        array $fields,
        string $targetType,
        string $rawBson,
        int $docOffset,
        int $docLen,
    ): array|object {
        return match ($targetType) {
            'array' => $fields,

            'object' => self::arrayToStdClass($fields),

            'bsonDocument' => Document::fromBSON(substr($rawBson, $docOffset, $docLen)),

            'bsonArray' => PackedArray::fromBSON(substr($rawBson, $docOffset, $docLen)),

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
 */
    private static function instantiateClass(string $className, array $fields): object
    {
        try {
            $rc = new ReflectionClass($className);
        } catch (ReflectionException) {
            throw new DriverInvalidArgumentException(
                sprintf('Class %s does not exist', $className),
            );
        }

        if (! $rc->isInstantiable()) {
            $kind = $rc->isInterface() ? 'Interface' : 'Abstract class';

            throw new DriverInvalidArgumentException(
                sprintf('%s %s is not instantiatable', $kind, $className),
            );
        }

        if (! $rc->implementsInterface(Unserializable::class)) {
            throw new DriverInvalidArgumentException(
                sprintf('Class %s does not implement MongoDB\BSON\Unserializable', $className),
            );
        }

        $obj = $rc->newInstanceWithoutConstructor();
        $obj->bsonUnserialize($fields);

        return $obj;
    }
}
