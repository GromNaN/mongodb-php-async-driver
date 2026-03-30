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
use MongoDB\BSON\UTCDateTime;
use InvalidArgumentException;
use stdClass;

/**
 * Converts PHP values (including BSON extension types) to Extended JSON v2.
 *
 * Two modes are supported:
 *
 *   Canonical  – all numbers are represented as Extended JSON type wrappers,
 *                giving exact round-trip fidelity.
 *
 *   Relaxed    – native JSON numbers are used where safe (integers that fit
 *                in a JavaScript 53-bit safe integer, finite doubles), while
 *                other values still use wrappers.
 *
 * @see https://www.mongodb.com/docs/manual/reference/mongodb-extended-json/
 *
 * @internal
 */
final class ExtendedJson
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Serialize $value to Canonical Extended JSON.
     *
     * @param array|object $value
     */
    public static function toCanonical(array|object $value): string
    {
        $normalized = self::canonicalizeValue($value);

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Serialize $value to Relaxed Extended JSON.
     *
     * @param array|object $value
     */
    public static function toRelaxed(array|object $value): string
    {
        $normalized = self::relaxValue($value);

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // -------------------------------------------------------------------------
    // Canonical helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively convert $v into a structure that, when passed through
     * json_encode(), produces Canonical Extended JSON.
     */
    private static function canonicalizeValue(mixed $v): mixed
    {
        // ---- BSON extension types ----------------------------------------

        if ($v instanceof ObjectId) {
            return ['$oid' => (string) $v];
        }

        if ($v instanceof Binary) {
            return [
                '$binary' => [
                    'base64'  => base64_encode($v->getData()),
                    'subType' => sprintf('%02x', $v->getType()),
                ],
            ];
        }

        if ($v instanceof UTCDateTime) {
            $ms = $v->getMilliseconds();
            if ($ms instanceof Int64) {
                $ms = (string) $ms;
            } else {
                $ms = (string) $ms;
            }
            return ['$date' => ['$numberLong' => $ms]];
        }

        if ($v instanceof Regex) {
            return [
                '$regularExpression' => [
                    'pattern' => $v->getPattern(),
                    'options' => $v->getFlags(),
                ],
            ];
        }

        if ($v instanceof Timestamp) {
            return [
                '$timestamp' => [
                    't' => $v->getTimestamp(),
                    'i' => $v->getIncrement(),
                ],
            ];
        }

        if ($v instanceof Javascript) {
            $scope = $v->getScope();
            if ($scope !== null) {
                return [
                    '$code'  => $v->getCode(),
                    '$scope' => self::canonicalizeValue((array) $scope),
                ];
            }
            return ['$code' => $v->getCode()];
        }

        if ($v instanceof Int64) {
            return ['$numberLong' => (string) $v];
        }

        if ($v instanceof Decimal128) {
            return ['$numberDecimal' => (string) $v];
        }

        if ($v instanceof MaxKey) {
            return ['$maxKey' => 1];
        }

        if ($v instanceof MinKey) {
            return ['$minKey' => 1];
        }

        if ($v instanceof Document) {
            return self::canonicalizeValue(BsonDecoder::decode((string) $v, ['root' => 'array', 'document' => 'array', 'array' => 'array']));
        }

        if ($v instanceof PackedArray) {
            return self::canonicalizeValue(BsonDecoder::decode((string) $v, ['root' => 'array', 'document' => 'array', 'array' => 'array']));
        }

        // ---- PHP scalar types --------------------------------------------

        if (is_int($v)) {
            // Canonical: always wrap integers
            if ($v >= -2147483648 && $v <= 2147483647) {
                return ['$numberInt' => (string) $v];
            }
            return ['$numberLong' => (string) $v];
        }

        if (is_float($v)) {
            if (is_nan($v)) {
                return ['$numberDouble' => 'NaN'];
            }
            if (is_infinite($v)) {
                return ['$numberDouble' => $v > 0 ? 'Infinity' : '-Infinity'];
            }
            // Canonical: always wrap doubles
            return ['$numberDouble' => self::doubleToString($v)];
        }

        if (is_bool($v) || $v === null || is_string($v)) {
            return $v;
        }

        // ---- Compound types ----------------------------------------------

        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(self::canonicalizeValue(...), $v);
            }
            $result = [];
            foreach ($v as $key => $item) {
                $result[$key] = self::canonicalizeValue($item);
            }
            return $result;
        }

        if ($v instanceof stdClass) {
            return self::canonicalizeValue((array) $v);
        }

        if (is_object($v)) {
            return self::canonicalizeValue((array) $v);
        }

        return $v;
    }

    // -------------------------------------------------------------------------
    // Relaxed helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively convert $v into a structure that, when passed through
     * json_encode(), produces Relaxed Extended JSON.
     */
    private static function relaxValue(mixed $v): mixed
    {
        // ---- BSON extension types ----------------------------------------

        if ($v instanceof ObjectId) {
            return ['$oid' => (string) $v];
        }

        if ($v instanceof Binary) {
            return [
                '$binary' => [
                    'base64'  => base64_encode($v->getData()),
                    'subType' => sprintf('%02x', $v->getType()),
                ],
            ];
        }

        if ($v instanceof UTCDateTime) {
            $ms = $v->getMilliseconds();
            if ($ms instanceof Int64) {
                $ms = (int) (string) $ms;
            }
            // Relaxed: use ISO-8601 date string
            $dt = $v->toDateTime();
            return ['$date' => $dt->format('Y-m-d\TH:i:s.v\Z')];
        }

        if ($v instanceof Regex) {
            return [
                '$regularExpression' => [
                    'pattern' => $v->getPattern(),
                    'options' => $v->getFlags(),
                ],
            ];
        }

        if ($v instanceof Timestamp) {
            return [
                '$timestamp' => [
                    't' => $v->getTimestamp(),
                    'i' => $v->getIncrement(),
                ],
            ];
        }

        if ($v instanceof Javascript) {
            $scope = $v->getScope();
            if ($scope !== null) {
                return [
                    '$code'  => $v->getCode(),
                    '$scope' => self::relaxValue((array) $scope),
                ];
            }
            return ['$code' => $v->getCode()];
        }

        if ($v instanceof Int64) {
            $intVal = (int) (string) $v;
            // Relaxed: use native number if within JS safe-integer range
            if (self::isJsSafeInteger($intVal)) {
                return $intVal;
            }
            return ['$numberLong' => (string) $v];
        }

        if ($v instanceof Decimal128) {
            return ['$numberDecimal' => (string) $v];
        }

        if ($v instanceof MaxKey) {
            return ['$maxKey' => 1];
        }

        if ($v instanceof MinKey) {
            return ['$minKey' => 1];
        }

        if ($v instanceof Document) {
            return self::relaxValue(BsonDecoder::decode((string) $v, ['root' => 'array', 'document' => 'array', 'array' => 'array']));
        }

        if ($v instanceof PackedArray) {
            return self::relaxValue(BsonDecoder::decode((string) $v, ['root' => 'array', 'document' => 'array', 'array' => 'array']));
        }

        // ---- PHP scalar types --------------------------------------------

        if (is_int($v)) {
            // Relaxed: native integers are always safe in PHP (fits in 64-bit)
            return $v;
        }

        if (is_float($v)) {
            if (is_nan($v)) {
                return ['$numberDouble' => 'NaN'];
            }
            if (is_infinite($v)) {
                return ['$numberDouble' => $v > 0 ? 'Infinity' : '-Infinity'];
            }
            // Relaxed: native float for finite doubles
            return $v;
        }

        if (is_bool($v) || $v === null || is_string($v)) {
            return $v;
        }

        // ---- Compound types ----------------------------------------------

        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(self::relaxValue(...), $v);
            }
            $result = [];
            foreach ($v as $key => $item) {
                $result[$key] = self::relaxValue($item);
            }
            return $result;
        }

        if ($v instanceof stdClass) {
            return self::relaxValue((array) $v);
        }

        if (is_object($v)) {
            return self::relaxValue((array) $v);
        }

        return $v;
    }

    // -------------------------------------------------------------------------
    // Utility helpers
    // -------------------------------------------------------------------------

    /**
     * Format a PHP float as a decimal string suitable for Extended JSON.
     * Ensures at least one decimal place so it round-trips as a double.
     */
    private static function doubleToString(float $v): string
    {
        // Use enough precision to represent the value exactly
        $s = sprintf('%.17g', $v);

        // Ensure a decimal point or exponent is present so consumers know it's a float
        if (!str_contains($s, '.') && !str_contains($s, 'e') && !str_contains($s, 'E')) {
            $s .= '.0';
        }

        return $s;
    }

    /**
     * Returns true if the integer value is within the JavaScript safe-integer
     * range: -(2^53 - 1) to (2^53 - 1).
     */
    private static function isJsSafeInteger(int $v): bool
    {
        return $v >= -9007199254740991 && $v <= 9007199254740991;
    }
}
