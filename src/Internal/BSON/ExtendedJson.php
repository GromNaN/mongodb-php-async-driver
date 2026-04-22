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
use MongoDB\Driver\Exception\UnexpectedValueException;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function array_map;
use function base64_decode;
use function base64_encode;
use function count;
use function hex2bin;
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
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;

use const INF;
use const JSON_PRESERVE_ZERO_FRACTION;
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
     * Preprocess a json_decode result (with associative:false) for fromValue.
     * stdClass objects are preserved as-is (recursing into values only) so that
     * BsonEncoder can distinguish JSON objects ({}) from JSON arrays ([]).
     */
    public static function normalizeJson(mixed $v): mixed
    {
        if ($v instanceof stdClass) {
            // Preserve stdClass to keep document-vs-array semantics for BsonEncoder.
            // Only recurse into the property values.
            $result = new stdClass();
            foreach ((array) $v as $k => $val) {
                $result->{(string) $k} = self::normalizeJson($val);
            }

            return $result;
        }

        if (is_array($v)) {
            return array_map(self::normalizeJson(...), $v);
        }

        return $v;
    }

    /**
     * Convert a PHP value decoded from Extended JSON back into BSON type objects.
     *
     * Recognizes the canonical ($binary, $oid, $date, etc.) wrapper patterns
     * produced by toCanonical() / toRelaxed() and converts them back to the
     * corresponding MongoDB\BSON\* instances.  Plain scalars pass through
     * unchanged.
     */
    public static function fromValue(mixed $value): mixed
    {
        // Track whether input was a stdClass so we can return stdClass for
        // non-extended-JSON objects, preserving BSON document encoding.
        $isObject = $value instanceof stdClass;
        if ($isObject) {
            $value = (array) $value;
        } elseif (! is_array($value)) {
            return $value;
        }

        // --- $oid ---
        if (array_key_exists('$oid', $value)) {
            if (! is_string($value['$oid']) || count($value) !== 1) {
                throw new UnexpectedValueException('Invalid $oid in Extended JSON');
            }

            return new ObjectId($value['$oid']);
        }

        // --- $binary ---
        if (array_key_exists('$binary', $value)) {
            $bin = $value['$binary'];
            // Canonical v2: { "$binary": { "base64": "...", "subType": "xx" } }
            if (is_array($bin)) {
                if (
                    count($value) !== 1
                    || count($bin) !== 2
                    || ! isset($bin['base64'], $bin['subType'])
                    || ! is_string($bin['base64'])
                    || ! is_string($bin['subType'])
                ) {
                    throw new UnexpectedValueException('Invalid $binary in Extended JSON');
                }

                return new Binary(base64_decode($bin['base64']), (int) hexdec($bin['subType']));
            }

            // Legacy v1: { "$binary": "base64string", "$type": "xx" }
            if (is_string($bin) && isset($value['$type']) && is_string($value['$type'])) {
                return new Binary(base64_decode($bin), (int) hexdec($value['$type']));
            }

            throw new UnexpectedValueException('Invalid $binary in Extended JSON');
        }

        // --- $uuid (degenerate legacy) ---
        if (array_key_exists('$uuid', $value)) {
            $uuid = $value['$uuid'];
            if (count($value) !== 1 || ! is_string($uuid)) {
                throw new UnexpectedValueException('Invalid $uuid in Extended JSON');
            }

            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                throw new UnexpectedValueException('Invalid $uuid value in Extended JSON');
            }

            return new Binary(hex2bin(str_replace('-', '', $uuid)), Binary::TYPE_UUID);
        }

        // --- $date ---
        if (array_key_exists('$date', $value)) {
            if (count($value) !== 1) {
                throw new UnexpectedValueException('Invalid $date in Extended JSON');
            }

            $d = $value['$date'];
            if (is_array($d)) {
                if (! is_string($d['$numberLong'] ?? null) || count($d) !== 1) {
                    throw new UnexpectedValueException('Invalid $date in Extended JSON');
                }

                return new UTCDateTime((int) $d['$numberLong']);
            }

            if (is_string($d)) {
                $dt = new DateTimeImmutable($d);
                $ms = (int) $dt->format('U') * 1000 + (int) $dt->format('v');

                return new UTCDateTime($ms);
            }

            throw new UnexpectedValueException('Invalid $date in Extended JSON');
        }

        // --- $regularExpression ---
        if (array_key_exists('$regularExpression', $value)) {
            $r = $value['$regularExpression'];
            if (
                count($value) !== 1
                || ! is_array($r)
                || count($r) !== 2
                || ! isset($r['pattern'], $r['options'])
                || ! is_string($r['pattern'])
                || ! is_string($r['options'])
            ) {
                throw new UnexpectedValueException('Invalid $regularExpression in Extended JSON');
            }

            return new Regex($r['pattern'], $r['options']);
        }

        // --- $regex + $options (legacy) — only when $regex is a plain string ---
        if (isset($value['$regex']) && isset($value['$options']) && is_string($value['$regex'])) {
            return new Regex($value['$regex'], $value['$options']);
        }

        // --- $timestamp ---
        if (array_key_exists('$timestamp', $value)) {
            $t = $value['$timestamp'];
            if (
                count($value) !== 1
                || ! is_array($t)
                || count($t) !== 2
                || ! array_key_exists('t', $t)
                || ! array_key_exists('i', $t)
                || ! is_int($t['t'])
                || ! is_int($t['i'])
            ) {
                throw new UnexpectedValueException('Invalid $timestamp in Extended JSON');
            }

            return new Timestamp($t['i'], $t['t']);
        }

        // --- $code ---
        if (array_key_exists('$code', $value)) {
            if (! is_string($value['$code'])) {
                throw new UnexpectedValueException('Invalid $code in Extended JSON');
            }

            $expectedCount = array_key_exists('$scope', $value) ? 2 : 1;
            if (count($value) !== $expectedCount) {
                throw new UnexpectedValueException('Invalid $code in Extended JSON');
            }

            if (array_key_exists('$scope', $value)) {
                $scope = $value['$scope'];
                if (! is_array($scope) && ! ($scope instanceof stdClass)) {
                    throw new UnexpectedValueException('Invalid $scope in Extended JSON: must be a document');
                }

                if (is_array($scope)) {
                    $scope = (object) array_map(self::fromValue(...), $scope);
                }

                return new Javascript($value['$code'], $scope);
            }

            return new Javascript($value['$code']);
        }

        // --- $numberInt ---
        if (array_key_exists('$numberInt', $value)) {
            if (! is_string($value['$numberInt']) || count($value) !== 1) {
                throw new UnexpectedValueException('Invalid $numberInt in Extended JSON');
            }

            return (int) $value['$numberInt'];
        }

        // --- $numberLong ---
        if (array_key_exists('$numberLong', $value)) {
            if (! is_string($value['$numberLong']) || count($value) !== 1) {
                throw new UnexpectedValueException('Invalid $numberLong in Extended JSON');
            }

            return new Int64($value['$numberLong']);
        }

        // --- $numberDouble ---
        if (array_key_exists('$numberDouble', $value)) {
            if (! is_string($value['$numberDouble']) || count($value) !== 1) {
                throw new UnexpectedValueException('Invalid $numberDouble in Extended JSON');
            }

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

        // --- $numberDecimal ---
        if (array_key_exists('$numberDecimal', $value)) {
            if (! is_string($value['$numberDecimal']) || count($value) !== 1) {
                throw new UnexpectedValueException('Invalid $numberDecimal in Extended JSON');
            }

            return new Decimal128($value['$numberDecimal']);
        }

        // --- $maxKey ---
        if (array_key_exists('$maxKey', $value)) {
            if ($value['$maxKey'] !== 1 || count($value) !== 1) {
                throw new UnexpectedValueException('Invalid $maxKey in Extended JSON');
            }

            return new MaxKey();
        }

        // --- $minKey ---
        if (array_key_exists('$minKey', $value)) {
            if ($value['$minKey'] !== 1 || count($value) !== 1) {
                throw new UnexpectedValueException('Invalid $minKey in Extended JSON');
            }

            return new MinKey();
        }

        // --- $dbPointer ---
        if (array_key_exists('$dbPointer', $value)) {
            $dbp = $value['$dbPointer'];
            if (
                count($value) !== 1
                || ! is_array($dbp)
                || count($dbp) !== 2
                || ! isset($dbp['$ref'], $dbp['$id'])
            ) {
                throw new UnexpectedValueException('Invalid $dbPointer in Extended JSON');
            }

            $ref = $dbp['$ref'] ?? '';
            $oid = is_array($dbp['$id'] ?? null) ? ($dbp['$id']['$oid'] ?? '') : '';

            return DBPointer::create($ref, $oid);
        }

        // --- $symbol ---
        if (is_string($value['$symbol'] ?? null)) {
            return Symbol::create($value['$symbol']);
        }

        // --- $undefined ---
        if (isset($value['$undefined']) && $value['$undefined'] === true) {
            return BsonUndefined::create();
        }

        // Recurse into plain associative arrays (BSON documents) and lists
        $result = [];
        foreach ($value as $k => $v) {
            if (str_contains((string) $k, "\x00")) {
                throw new UnexpectedValueException(
                    sprintf('BSON key must not contain null bytes: %s', $k),
                );
            }

            $result[$k] = self::fromValue($v);
        }

        // Restore stdClass for JSON objects so BsonEncoder encodes them as BSON
        // documents, not arrays (a PHP list like [0 => v] would be a BSON array).
        return $isObject ? (object) $result : $result;
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
                    '$scope' => self::canonicalizeValue($scope),
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

            // Relaxed: ISO-8601 only for non-negative ms within year [0, 9999]
            if ($ms >= 0) {
                $dt = $v->toDateTime();
                if ((int) $dt->format('Y') <= 9999) {
                    $fmt = (int) $dt->format('v') === 0 ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.v\Z';

                    return ['$date' => $dt->format($fmt)];
                }
            }

            return ['$date' => ['$numberLong' => (string) $ms]];
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
                    '$scope' => self::relaxValue($scope),
                ];
            }

            return ['$code' => $v->getCode()];
        }

        if ($v instanceof Int64) {
            // Relaxed: always emit Int64 as a native JSON integer
            return (int) (string) $v;
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
     * Returns true if the integer value is within the JavaScript safe-integer.
     */
    private static function isJsSafeInteger(int $v): bool
    {
        return $v >= -(2 ^ 53 - 1) && $v <= (2 ^ 53 - 1);
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
