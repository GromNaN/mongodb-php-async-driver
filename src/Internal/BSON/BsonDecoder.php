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
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\UnexpectedValueException;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use stdClass;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function bin2hex;
use function class_exists;
use function gmp_add;
use function gmp_and;
use function gmp_init;
use function gmp_mul;
use function gmp_pow;
use function gmp_strval;
use function mb_check_encoding;
use function ord;
use function sprintf;
use function str_contains;
use function str_repeat;
use function strlen;
use function strpos;
use function strrev;
use function substr;
use function unpack;

use const PHP_INT_SIZE;

/**
 * Pure-userland BSON decoder.
 *
 * @internal
 */
final class BsonDecoder
{
    /**
     * Default type map applied when none (or a partial one) is supplied.
     */
    private const array DEFAULT_TYPE_MAP = [
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
        bool $preserveInt64 = false,
    ): array|object {
        $noRootPersistable     = array_key_exists('root', $typeMap) && $typeMap['root'] === 'object';
        $noDocumentPersistable = array_key_exists('document', $typeMap) && $typeMap['document'] === 'object';

        $typeMap = array_merge(self::DEFAULT_TYPE_MAP, array_filter($typeMap, static fn ($v) => $v !== null));

        $offset = 0;

        try {
            return self::decodeDocument($bson, $offset, $typeMap, 'root', $handlePersistable, $ignoreRootKeys, $noRootPersistable, $noDocumentPersistable, '', $preserveInt64);
        } catch (RuntimeException $e) {
            throw new UnexpectedValueException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Decode the value of a single typed BSON field at a known byte offset.
     *
     * $offset must point to the first byte of the complete field encoding:
     *   - for length-prefixed types (String, Binary, Code, Symbol, CodeWithScope)
     *     this is the first byte of the int32 length header
     *   - for all other types it is the first byte of the raw value
     *
     * Types with no data (Null, Undefined, MinKey, MaxKey) ignore $offset.
     */
    public static function decodeFieldValue(string $bson, int $type, int $offset): mixed
    {
        $o = $offset;

        return match ($type) {
            BsonType::Null        => null,
            BsonType::Undefined   => BsonUndefined::create(),
            BsonType::MinKey      => new MinKey(),
            BsonType::MaxKey      => new MaxKey(),
            BsonType::Double      => self::readDouble($bson, $o),
            BsonType::String      => self::readString($bson, $o),
            BsonType::ObjectId    => self::readObjectId($bson, $o),
            BsonType::Boolean     => self::readBoolean($bson, $o),
            BsonType::Date        => self::readUTCDateTime($bson, $o),
            BsonType::Regex       => self::readRegex($bson, $o),
            BsonType::DBPointer   => self::readDbPointer($bson, $o),
            BsonType::Code        => self::readJavascript($bson, $o),
            BsonType::Symbol      => self::readSymbol($bson, $o),
            BsonType::Int32       => self::readInt32($bson, $o),
            BsonType::Timestamp   => self::readTimestamp($bson, $o),
            BsonType::Int64       => self::readInt64($bson, $o, true),
            BsonType::Decimal128  => self::readDecimal128($bson, $o),
            BsonType::Binary      => self::readBinary($bson, $o),
            BsonType::Document    => self::readSubDocumentAsBson($bson, $o),
            BsonType::Array       => self::readSubArrayAsBson($bson, $o),
            BsonType::CodeWithScope => self::readJavascriptWithScopeAsBson($bson, $o),
            default => throw new UnexpectedValueException(
                sprintf('Detected unknown BSON type 0x%02X', $type & 0xFF),
            ),
        };
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
        bool $preserveInt64 = false,
    ): array|object {
        $startOffset = $offset;

        $totalLen = self::readInt32Unsigned($bson, $offset);

        if ($totalLen < 5) {
            throw new RuntimeException(
                sprintf('Invalid BSON document length %d at offset %d', $totalLen, $startOffset),
            );
        }

        $endOffset = $startOffset + $totalLen;

        if ($endOffset > strlen($bson)) {
            throw new RuntimeException(
                sprintf('BSON document length %d at offset %d exceeds input length %d', $totalLen, $startOffset, strlen($bson)),
            );
        }

        $isArray = ($context === 'array') || ($context === 'root' && $ignoreRootKeys);

        $fields          = [];
        $foundTerminator = false;

        while ($offset < $endOffset) {
            $typeByte = ord($bson[$offset]);
            $offset++;

            if ($typeByte === 0x00) {
                $foundTerminator = true;
                break;
            }

            $type = $typeByte;

            $key       = self::readCString($bson, $offset);
            $fieldPath = $parentFieldPath === '' ? $key : $parentFieldPath . '.' . $key;

            $value = self::decodeElement($bson, $offset, $type, $typeMap, $handlePersistable, $fieldPath, $noRootPersistable, $noDocumentPersistable, $preserveInt64);

            if ($isArray) {
                $fields[] = $value;
            } else {
                $fields[$key] = $value;
            }
        }

        if (! $foundTerminator || $offset !== $endOffset) {
            throw new RuntimeException(
                sprintf('BSON document at offset %d is missing its terminator or has extra data (offset %d, expected %d)', $startOffset, $offset, $endOffset),
            );
        }

        $targetType = match ($context) {
            'root'  => $typeMap['root']     ?? 'object',
            'array' => $typeMap['array']    ?? 'array',
            default => $typeMap['document'] ?? 'object',
        };

        if ($targetType === 'bson' && $context !== 'root') {
            $targetType = $context === 'array' ? 'bsonArray' : 'bsonDocument';
        }

        if ($targetType === 'array' || $targetType === 'bsonDocument' || $targetType === 'bsonArray') {
            return self::applyTargetType($fields, $targetType, $bson, $startOffset, $totalLen);
        }

        $persistableDisabled = match ($context) {
            'root'  => $noRootPersistable,
            'array' => true,
            default => $noDocumentPersistable,
        };

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
                    $obj->bsonUnserialize($fields);

                    return $obj;
                }
            }
        }

        if ($targetType === 'object' || $targetType === stdClass::class) {
            return self::arrayToStdClass($fields);
        }

        return self::instantiateClass($targetType, $fields);
    }

    /**
     * Decode a single BSON element value (without key) at $offset.
     *
     * @param int $offset Modified in-place
     * @param int $type   BSON type value (sign-extended from type byte)
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
        bool $preserveInt64 = false,
    ): mixed {
        return match ($type) {
            BsonType::Double      => self::readDouble($bson, $offset),
            BsonType::String      => self::readString($bson, $offset),
            // When a fieldPaths entry exists for the current path, use 'root' as context so
            // that typeMap['root'] (set by typeMapForFieldPath) is used as the target type.
            // This keeps typeMap['document'] intact for sub-documents within that field.
            BsonType::Document    => self::decodeDocument($bson, $offset, self::typeMapForFieldPath($typeMap, $fieldPath), isset($typeMap['fieldPaths'][$fieldPath]) ? 'root' : 'document', $handlePersistable, false, $noRootPersistable, $noDocumentPersistable, $fieldPath, $preserveInt64),
            BsonType::Array       => self::decodeDocument($bson, $offset, self::typeMapForFieldPath($typeMap, $fieldPath), isset($typeMap['fieldPaths'][$fieldPath]) ? 'root' : 'array', $handlePersistable, false, $noRootPersistable, $noDocumentPersistable, $fieldPath, $preserveInt64),
            BsonType::Binary      => self::readBinary($bson, $offset),
            BsonType::Undefined   => BsonUndefined::create(),
            BsonType::ObjectId    => self::readObjectId($bson, $offset),
            BsonType::Boolean     => self::readBoolean($bson, $offset),
            BsonType::Date        => self::readUTCDateTime($bson, $offset),
            BsonType::Null        => null,
            BsonType::Regex       => self::readRegex($bson, $offset),
            BsonType::DBPointer   => self::readDbPointer($bson, $offset),
            BsonType::Code        => self::readJavascript($bson, $offset),
            BsonType::Symbol      => self::readSymbol($bson, $offset),
            BsonType::CodeWithScope => self::readJavascriptWithScope($bson, $offset, $typeMap, $noRootPersistable, $noDocumentPersistable, $preserveInt64),
            BsonType::Int32       => self::readInt32($bson, $offset),
            BsonType::Timestamp   => self::readTimestamp($bson, $offset),
            BsonType::Int64       => self::readInt64($bson, $offset, $preserveInt64),
            BsonType::Decimal128  => self::readDecimal128($bson, $offset),
            BsonType::MaxKey      => new MaxKey(),
            BsonType::MinKey      => new MinKey(),
            default => throw new UnexpectedValueException(
                sprintf('Detected unknown BSON type 0x%02X for field path "%s". Are you using the latest driver?', $type & 0xFF, $fieldPath),
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Low-level read helpers (offset passed by reference, advanced past the value)
    // -------------------------------------------------------------------------

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

    private static function readInt32Unsigned(string $bson, int &$offset): int
    {
        if ($offset + 4 > strlen($bson)) {
            throw new RuntimeException(
                sprintf('Not enough bytes to read int32 at offset %d', $offset),
            );
        }

        /** @var array{1: int} $u */
        $u = unpack('V', substr($bson, $offset, 4));
        $offset += 4;

        return $u[1];
    }

    private static function readInt32(string $bson, int &$offset): int
    {
        $v = self::readInt32Unsigned($bson, $offset);

        if ($v >= 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    private static function readInt64(string $bson, int &$offset, bool $preserveInt64 = false): int|Int64
    {
        if ($offset + 8 > strlen($bson)) {
            throw new RuntimeException(
                sprintf('Not enough bytes to read int64 at offset %d', $offset),
            );
        }

        /** @var array{1: int} $u */
        $u = unpack('P', substr($bson, $offset, 8));
        $offset += 8;

        if ($preserveInt64 || PHP_INT_SIZE < 8) {
            return new Int64((string) $u[1]);
        }

        return $u[1];
    }

    private static function readDouble(string $bson, int &$offset): float
    {
        if ($offset + 8 > strlen($bson)) {
            throw new RuntimeException(
                sprintf('Not enough bytes to read double at offset %d', $offset),
            );
        }

        /** @var array{1: float} $u */
        $u = unpack('e', substr($bson, $offset, 8));
        $offset += 8;

        return $u[1];
    }

    private static function readString(string $bson, int &$offset): string
    {
        $lenOffset = $offset;
        $len       = self::readInt32Unsigned($bson, $offset);

        if ($len < 1) {
            throw new RuntimeException(
                sprintf('Invalid BSON string length %d at offset %d', $len, $lenOffset),
            );
        }

        if ($offset + $len > strlen($bson)) {
            throw new RuntimeException(
                sprintf('Not enough bytes for string of length %d at offset %d', $len, $offset),
            );
        }

        if (ord($bson[$offset + $len - 1]) !== 0) {
            throw new RuntimeException(
                sprintf('BSON string at offset %d is not null-terminated', $offset),
            );
        }

        $str     = substr($bson, $offset, $len - 1);
        $offset += $len;

        if (! mb_check_encoding($str, 'UTF-8')) {
            throw new RuntimeException(
                sprintf('BSON string at offset %d contains invalid UTF-8', $lenOffset),
            );
        }

        return $str;
    }

    private static function readBinary(string $bson, int &$offset): Binary
    {
        $len     = self::readInt32Unsigned($bson, $offset);
        $bsonLen = strlen($bson);

        if ($offset >= $bsonLen) {
            throw new RuntimeException(
                sprintf('Not enough bytes for binary subtype at offset %d', $offset),
            );
        }

        $subtype = ord($bson[$offset]);
        $offset++;

        if ($offset + $len > $bsonLen) {
            throw new RuntimeException(
                sprintf('Not enough bytes for binary data of length %d at offset %d', $len, $offset),
            );
        }

        $data    = substr($bson, $offset, $len);
        $offset += $len;

        // Subtype 0x02 (old binary) has a redundant int32 length prefix inside the data;
        // the inner length must equal the outer length minus the 4-byte header.
        if ($subtype === Binary::TYPE_OLD_BINARY) {
            if ($len < 4) {
                throw new RuntimeException(
                    sprintf('Binary subtype 0x02 requires at least 4 bytes for inner length, got %d', $len),
                );
            }

            /** @var array{1: int} $inner */
            $inner    = unpack('V', substr($data, 0, 4));
            $innerLen = $inner[1];

            if ($innerLen !== $len - 4) {
                throw new RuntimeException(
                    sprintf('Binary subtype 0x02 inner length %d does not match expected %d', $innerLen, $len - 4),
                );
            }

            $data = substr($data, 4);
        }

        return new Binary($data, $subtype);
    }

    private static function readObjectId(string $bson, int &$offset): ObjectId
    {
        $bytes  = substr($bson, $offset, 12);
        $offset += 12;

        return new ObjectId(bin2hex($bytes));
    }

    private static function readBoolean(string $bson, int &$offset): bool
    {
        $byte = ord($bson[$offset]);
        $offset++;

        if ($byte !== 0x00 && $byte !== 0x01) {
            throw new RuntimeException(
                sprintf('Invalid BSON boolean value 0x%02X at offset %d', $byte, $offset - 1),
            );
        }

        return $byte === 0x01;
    }

    private static function readUTCDateTime(string $bson, int &$offset): UTCDateTime
    {
        $ms = self::readInt64($bson, $offset);

        return new UTCDateTime($ms);
    }

    private static function readRegex(string $bson, int &$offset): Regex
    {
        $pattern = self::readCString($bson, $offset);
        $flags   = self::readCString($bson, $offset);

        return new Regex($pattern, $flags);
    }

    private static function readDbPointer(string $bson, int &$offset): DBPointer
    {
        $ref = self::readString($bson, $offset);

        if ($offset + 12 > strlen($bson)) {
            throw new RuntimeException(
                sprintf('Not enough bytes for DBPointer OID at offset %d (need 12, have %d)', $offset, strlen($bson) - $offset),
            );
        }

        $oid     = bin2hex(substr($bson, $offset, 12));
        $offset += 12;

        return DBPointer::create($ref, $oid);
    }

    private static function readSymbol(string $bson, int &$offset): Symbol
    {
        $sym = self::readString($bson, $offset);

        return Symbol::create($sym);
    }

    private static function readJavascript(string $bson, int &$offset): Javascript
    {
        $code = self::readString($bson, $offset);

        if (str_contains($code, "\x00")) {
            throw new RuntimeException(
                sprintf('JavaScript code string at offset %d contains embedded null bytes', $offset),
            );
        }

        return new Javascript($code);
    }

    private static function readJavascriptWithScope(
        string $bson,
        int &$offset,
        array $typeMap,
        bool $noRootPersistable = false,
        bool $noDocumentPersistable = false,
        bool $preserveInt64 = false,
    ): Javascript {
        $startOffset = $offset;
        $totalLen    = self::readInt32Unsigned($bson, $offset);

        // Minimum: 4 (self) + 4 (code string len) + 1 (empty code null) + 5 (empty scope) = 14
        if ($totalLen < 14) {
            throw new RuntimeException(
                sprintf('Invalid CodeWithScope length %d at offset %d', $totalLen, $startOffset),
            );
        }

        $endOffset = $startOffset + $totalLen;

        if ($endOffset > strlen($bson)) {
            throw new RuntimeException(
                sprintf('CodeWithScope length %d at offset %d exceeds input length %d', $totalLen, $startOffset, strlen($bson)),
            );
        }

        $code  = self::readString($bson, $offset);
        $scope = self::decodeDocument($bson, $offset, $typeMap, 'document', false, false, $noRootPersistable, $noDocumentPersistable, '', $preserveInt64);

        if ($offset !== $endOffset) {
            throw new RuntimeException(
                sprintf('CodeWithScope at offset %d has incorrect length (expected to end at %d, ended at %d)', $startOffset, $endOffset, $offset),
            );
        }

        return new Javascript($code, $scope);
    }

    /**
     * Variant used by decodeFieldValue: returns the scope as a raw Document
     * instead of applying a type map.
     */
    private static function readJavascriptWithScopeAsBson(string $bson, int &$offset): Javascript
    {
        $startOffset = $offset;
        $totalLen    = self::readInt32Unsigned($bson, $offset);

        // Minimum: 4 (self) + 4 (code string len) + 1 (empty code null) + 5 (empty scope) = 14
        if ($totalLen < 14) {
            throw new RuntimeException(
                sprintf('Invalid CodeWithScope length %d at offset %d', $totalLen, $startOffset),
            );
        }

        $endOffset = $startOffset + $totalLen;

        if ($endOffset > strlen($bson)) {
            throw new RuntimeException(
                sprintf('CodeWithScope length %d at offset %d exceeds input length %d', $totalLen, $startOffset, strlen($bson)),
            );
        }

        $code = self::readString($bson, $offset);

        if ($offset + 4 > strlen($bson)) {
            throw new RuntimeException(
                sprintf('Not enough bytes to read JavaScript scope length at offset %d', $offset),
            );
        }

        $scopeLen = (int) (unpack('V', substr($bson, $offset, 4))[1]);

        if ($offset + $scopeLen > strlen($bson)) {
            throw new RuntimeException(
                sprintf('Not enough bytes for JavaScript scope of length %d at offset %d', $scopeLen, $offset),
            );
        }

        $scope   = Document::fromBSON(substr($bson, $offset, $scopeLen));
        $offset += $scopeLen;

        if ($offset !== $endOffset) {
            throw new RuntimeException(
                sprintf('CodeWithScope at offset %d has incorrect length (expected to end at %d, ended at %d)', $startOffset, $endOffset, $offset),
            );
        }

        return new Javascript($code, $scope);
    }

    private static function readTimestamp(string $bson, int &$offset): Timestamp
    {
        $increment = self::readInt32Unsigned($bson, $offset);
        $timestamp = self::readInt32Unsigned($bson, $offset);

        return new Timestamp($increment, $timestamp);
    }

    private static function readDecimal128(string $bson, int &$offset): Decimal128
    {
        $bytes   = substr($bson, $offset, 16);
        $offset += 16;

        return self::decimalFromBytes($bytes);
    }

    /**
     * Decode a 16-byte IEEE 754-2008 Decimal128 BID binary representation.
     */
    private static function decimalFromBytes(string $bytes): Decimal128
    {
        $b15    = ord($bytes[15]);
        $b14    = ord($bytes[14]);
        $sign   = ($b15 >> 7) & 1;
        $combo5 = ($b15 >> 2) & 0x1F;

        if ($combo5 >= 0x1E) {
            return new Decimal128($combo5 === 0x1E ? ($sign ? '-Infinity' : 'Infinity') : 'NaN');
        }

        if ($combo5 >= 0x18) {
            $biasedExp = (($b15 & 0x1F) << 9) | ($b14 << 1) | ((ord($bytes[13]) >> 7) & 1);

            return new Decimal128(self::decimalToString($sign, '0', $biasedExp - 6176));
        }

        $biasedExp = (($b15 & 0x7F) << 7) | ($b14 >> 1);
        $exp       = $biasedExp - 6176;
        $highGmp   = gmp_init('0x' . bin2hex(strrev(substr($bytes, 8, 8))));
        $lowGmp    = gmp_init('0x' . bin2hex(strrev(substr($bytes, 0, 8))));
        $high49    = gmp_and($highGmp, gmp_init('0x0001FFFFFFFFFFFF'));
        $coeff     = gmp_add(gmp_mul($high49, gmp_pow(gmp_init(2), 64)), $lowGmp);

        return new Decimal128(self::decimalToString($sign, gmp_strval($coeff), $exp));
    }

    private static function decimalToString(int $sign, string $coeffStr, int $exp): string
    {
        $prefix = $sign ? '-' : '';

        if ($coeffStr === '0') {
            if ($exp === 0) {
                return $prefix . '0';
            }

            if ($exp > 0 || $exp < -6) {
                return $prefix . '0E' . ($exp > 0 ? '+' : '') . $exp;
            }

            return $prefix . '0.' . str_repeat('0', -$exp);
        }

        $d           = strlen($coeffStr);
        $adjustedExp = $exp + $d - 1;

        if ($exp <= 0 && $adjustedExp >= -6) {
            if ($exp === 0) {
                return $prefix . $coeffStr;
            }

            $decimalPlaces = -$exp;
            if ($decimalPlaces >= $d) {
                return $prefix . '0.' . str_repeat('0', $decimalPlaces - $d) . $coeffStr;
            }

            return $prefix . substr($coeffStr, 0, $d - $decimalPlaces) . '.' . substr($coeffStr, $d - $decimalPlaces);
        }

        $mantissa = $d === 1 ? $coeffStr : ($coeffStr[0] . '.' . substr($coeffStr, 1));

        return $prefix . $mantissa . 'E' . ($adjustedExp >= 0 ? '+' : '') . $adjustedExp;
    }

    /**
     * Read a sub-document at $offset and return it as a raw Document.
     * Reads the embedded int32 length; $offset is not modified.
     */
    private static function readSubDocumentAsBson(string $bson, int $offset): Document
    {
        $len = (int) (unpack('V', substr($bson, $offset, 4))[1]);

        return Document::fromBSON(substr($bson, $offset, $len));
    }

    /**
     * Read a sub-array at $offset and return it as a raw PackedArray.
     * Reads the embedded int32 length; $offset is not modified.
     */
    private static function readSubArrayAsBson(string $bson, int $offset): PackedArray
    {
        $len = (int) (unpack('V', substr($bson, $offset, 4))[1]);

        return PackedArray::fromBSON(substr($bson, $offset, $len));
    }

    // -------------------------------------------------------------------------
    // Type-map application
    // -------------------------------------------------------------------------

    private static function applyTargetType(
        array $fields,
        string $targetType,
        string $rawBson,
        int $docOffset,
        int $docLen,
    ): array|object {
        return match ($targetType) {
            'array'        => $fields,
            'object'       => self::arrayToStdClass($fields),
            'bsonDocument' => Document::fromBSON(substr($rawBson, $docOffset, $docLen)),
            'bsonArray'    => PackedArray::fromBSON(substr($rawBson, $docOffset, $docLen)),
            default        => self::instantiateClass($targetType, $fields),
        };
    }

    /** @param array<string, mixed> $data */
    private static function arrayToStdClass(array $data): stdClass
    {
        $obj = new stdClass();
        foreach ($data as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }

    /**
     * Return a typeMap for a child document/array, applying any fieldPaths override for $fieldPath.
     *
     * fieldPaths uses absolute paths from the root, so they are kept unchanged.
     * Only 'root' is overridden when a direct match exists, so that 'document' and 'array'
     * from the parent typeMap are always inherited by sub-documents.
     */
    private static function typeMapForFieldPath(array $typeMap, string $fieldPath): array
    {
        if (isset($typeMap['fieldPaths'][$fieldPath])) {
            $typeMap['root'] = $typeMap['fieldPaths'][$fieldPath];
        }

        return $typeMap;
    }

    /**
     * @param class-string         $className
     * @param array<string, mixed> $fields
     */
    private static function instantiateClass(string $className, array $fields): object
    {
        try {
            $rc = new ReflectionClass($className);
        } catch (ReflectionException) {
            throw new InvalidArgumentException(
                sprintf('Class %s does not exist', $className),
            );
        }

        if (! $rc->isInstantiable()) {
            $kind = $rc->isInterface() ? 'Interface' : 'Abstract class';

            throw new InvalidArgumentException(
                sprintf('%s %s is not instantiatable', $kind, $className),
            );
        }

        if (! $rc->implementsInterface(Unserializable::class)) {
            throw new InvalidArgumentException(
                sprintf('Class %s does not implement MongoDB\BSON\Unserializable', $className),
            );
        }

        $obj = $rc->newInstanceWithoutConstructor();
        $obj->bsonUnserialize($fields);

        return $obj;
    }
}
