<?php

declare(strict_types=1);

namespace MongoDB\Internal\BSON;

use DateTimeImmutable;
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
use MongoDB\BSON\Regex;
use MongoDB\BSON\Symbol;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\Undefined as BsonUndefined;
use MongoDB\BSON\UTCDateTime;
use stdClass;

use function array_is_list;
use function array_map;
use function base64_decode;
use function base64_encode;
use function hexdec;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_object;
use function is_string;
use function json_encode;
use function round;
use function sprintf;
use function str_contains;

use const INF;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const NAN;

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
 * @internal
 *
 * @see https://www.mongodb.com/docs/manual/reference/mongodb-extended-json/
 */
final class ExtendedJson
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Serialize $value to Canonical Extended JSON.
     *
     * Uses libbson-style spacing: { "key" : value } and [ val, val ].
     */
    public static function toCanonical(array|object $value): string
    {
        $normalized = self::canonicalizeValue($value);

        return self::encodeJson($normalized);
    }

    /**
     * Serialize $value to Relaxed Extended JSON.
     *
     * Uses libbson-style spacing: { "key" : value } and [ val, val ].
     */
    public static function toRelaxed(array|object $value): string
    {
        $normalized = self::relaxValue($value);

        return self::encodeJson($normalized);
    }

    /**
     * Convert a PHP value decoded from Extended JSON back into BSON type objects.
     *
     * Recognises the canonical ($binary, $oid, $date, etc.) wrapper patterns
     * produced by toCanonical() / toRelaxed() and converts them back to the
     * corresponding MongoDB\BSON\* instances.  Plain scalars pass through
     * unchanged.
     */
    public static function fromValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // Detect extended JSON type wrappers (associative arrays with a single
        // $ key, or a known two-key pattern).

        if (isset($value['$oid']) && is_string($value['$oid'])) {
            return new ObjectId($value['$oid']);
        }

        if (isset($value['$binary'])) {
            $bin = $value['$binary'];
            // Canonical v2: { "$binary": { "base64": "...", "subType": "xx" } }
            if (is_array($bin) && isset($bin['base64'], $bin['subType'])) {
                return new Binary(
                    base64_decode($bin['base64']),
                    (int) hexdec($bin['subType']),
                );
            }

            // Legacy v1: { "$binary": "base64string", "$type": "xx" }
            if (is_string($bin) && isset($value['$type']) && is_string($value['$type'])) {
                return new Binary(
                    base64_decode($bin),
                    (int) hexdec($value['$type']),
                );
            }
        }

        if (isset($value['$date'])) {
            $d = $value['$date'];
            if (is_array($d) && isset($d['$numberLong'])) {
                return new UTCDateTime((int) $d['$numberLong']);
            }

            if (is_string($d)) {
                // ISO-8601 relaxed format
                return new UTCDateTime((int) (round((new DateTimeImmutable($d))->getTimestamp() * 1000)));
            }
        }

        if (isset($value['$regularExpression'])) {
            $r = $value['$regularExpression'];

            return new Regex($r['pattern'] ?? '', $r['options'] ?? '');
        }

        if (isset($value['$regex']) && isset($value['$options'])) {
            return new Regex($value['$regex'], $value['$options']);
        }

        if (isset($value['$timestamp'])) {
            $t = $value['$timestamp'];

            return new Timestamp((int) ($t['i'] ?? 0), (int) ($t['t'] ?? 0));
        }

        if (isset($value['$code'])) {
            return new Javascript($value['$code'], isset($value['$scope']) ? (object) $value['$scope'] : null);
        }

        if (isset($value['$numberInt'])) {
            return (int) $value['$numberInt'];
        }

        if (isset($value['$numberLong'])) {
            return new Int64((int) $value['$numberLong']);
        }

        if (isset($value['$numberDouble'])) {
            $s = $value['$numberDouble'];
            if ($s === 'NaN') {
                return NAN;
            }

            if ($s === 'Infinity') {
                return INF;
            }

            if ($s === '-Infinity') {
                return -INF;
            }

            return (float) $s;
        }

        if (isset($value['$numberDecimal'])) {
            return new Decimal128($value['$numberDecimal']);
        }

        if (isset($value['$maxKey'])) {
            return new MaxKey();
        }

        if (isset($value['$minKey'])) {
            return new MinKey();
        }

        if (isset($value['$dbPointer']) && is_array($value['$dbPointer'])) {
            $dbp = $value['$dbPointer'];
            $ref = $dbp['$ref'] ?? '';
            $oid = is_array($dbp['$id'] ?? null) ? ($dbp['$id']['$oid'] ?? '') : '';

            return DBPointer::create($ref, $oid);
        }

        if (isset($value['$symbol']) && is_string($value['$symbol'])) {
            return Symbol::create($value['$symbol']);
        }

        if (isset($value['$undefined']) && $value['$undefined'] === true) {
            return BsonUndefined::create();
        }

        // Recurse into plain associative arrays (BSON documents) and lists
        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = self::fromValue($v);
        }

        return $result;
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

        if ($v instanceof DBPointer) {
            return [
                '$dbPointer' => [
                    '$ref' => $v->getRef(),
                    '$id'  => ['$oid' => $v->getId()],
                ],
            ];
        }

        if ($v instanceof Symbol) {
            return ['$symbol' => (string) $v];
        }

        if ($v instanceof BsonUndefined) {
            return ['$undefined' => true];
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

            // Non-list (string-keyed) array represents a BSON document.
            // Return stdClass so json_encode produces {} for empty documents.
            $result = new stdClass();
            foreach ($v as $key => $item) {
                $result->$key = self::canonicalizeValue($item);
            }

            return $result;
        }

        if ($v instanceof stdClass) {
            $result = new stdClass();
            foreach ((array) $v as $key => $item) {
                $result->$key = self::canonicalizeValue($item);
            }

            return $result;
        }

        if (is_object($v)) {
            $result = new stdClass();
            foreach ((array) $v as $key => $item) {
                $result->$key = self::canonicalizeValue($item);
            }

            return $result;
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

            // Relaxed: use ISO-8601 date string; omit milliseconds when zero
            $dt = $v->toDateTime();
            $fmt = ((int) $dt->format('v') === 0) ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.v\Z';

            return ['$date' => $dt->format($fmt)];
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

        if ($v instanceof DBPointer) {
            return [
                '$dbPointer' => [
                    '$ref' => $v->getRef(),
                    '$id'  => ['$oid' => $v->getId()],
                ],
            ];
        }

        if ($v instanceof Symbol) {
            return ['$symbol' => (string) $v];
        }

        if ($v instanceof BsonUndefined) {
            return ['$undefined' => true];
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

            // Non-list (string-keyed) array represents a BSON document.
            $result = new stdClass();
            foreach ($v as $key => $item) {
                $result->$key = self::relaxValue($item);
            }

            return $result;
        }

        if ($v instanceof stdClass) {
            $result = new stdClass();
            foreach ((array) $v as $key => $item) {
                $result->$key = self::relaxValue($item);
            }

            return $result;
        }

        if (is_object($v)) {
            $result = new stdClass();
            foreach ((array) $v as $key => $item) {
                $result->$key = self::relaxValue($item);
            }

            return $result;
        }

        return $v;
    }

    // -------------------------------------------------------------------------
    // Utility helpers
    // -------------------------------------------------------------------------

    /**
     * Format a PHP float as a decimal string suitable for Canonical Extended JSON.
     * Uses 20 significant digits (%.20g) to match the libbson / ext-mongodb output
     * format. Appends ".0" for integer-looking results (e.g. 4 → "4.0").
     */
    private static function doubleToString(float $v): string
    {
        $s = sprintf('%.20g', $v);

        // Ensure a decimal point or exponent is present so consumers know it's a float
        if (! str_contains($s, '.') && ! str_contains($s, 'e') && ! str_contains($s, 'E')) {
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

    /**
     * Encode a normalized value as JSON using libbson-style spacing:
     *   objects → { "key" : value, "key2" : value2 }
     *   arrays  → [ value, value2 ]
     *
     * This matches the output of bson_as_canonical_extended_json() so that
     * phpt tests comparing against C-driver output pass.
     */
    private static function encodeJson(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        if (is_int($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_float($value)) {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                if ($value === []) {
                    return '[ ]';
                }

                $items = array_map(self::encodeJson(...), $value);

                return '[ ' . implode(', ', $items) . ' ]';
            }

            // Associative array → object
            if ($value === []) {
                return '{ }';
            }

            $pairs = [];
            foreach ($value as $k => $v) {
                $key     = json_encode((string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $pairs[] = $key . ' : ' . self::encodeJson($v);
            }

            return '{ ' . implode(', ', $pairs) . ' }';
        }

        if ($value instanceof stdClass) {
            $arr = (array) $value;
            if ($arr === []) {
                return '{ }';
            }

            $pairs = [];
            foreach ($arr as $k => $v) {
                $key     = json_encode((string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $pairs[] = $key . ' : ' . self::encodeJson($v);
            }

            return '{ ' . implode(', ', $pairs) . ' }';
        }

        // Fallback for any other type
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
