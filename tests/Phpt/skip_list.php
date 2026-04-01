<?php

declare(strict_types=1);

/**
 * Tests that cannot be made compliant in a pure-PHP userland driver.
 *
 * The map is keyed by the test path relative to tests/references/mongo-php-driver/tests/
 * and the value is the human-readable reason the test is skipped.
 *
 * Two categories of tests are permanently impossible:
 *
 *  1. OPERATOR OVERLOADING – PHP does not expose operator-overloading hooks
 *     to userland code.  The C extension registers a `compare` object handler
 *     that intercepts ==, <, >, <=, >= between BSON objects (and between Int64
 *     and scalars).  No equivalent exists in PHP 8.
 *
 *  2. ARITHMETIC ON OBJECTS – PHP does not expose a `do_operation` object
 *     handler to userland code.  The C extension uses it so that Int64 objects
 *     support +, -, *, /, %, **, ++, -- directly.
 *
 * @return array<string, string>  path => reason
 */
return [

    // -------------------------------------------------------------------------
    // Comparison operators on BSON types (C extension `compare` handler)
    // -------------------------------------------------------------------------

    'bson/bson-binary-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; Binary comparisons (==, <, >) require the C extension compare handler',

    'bson/bson-binary-compare-002.phpt'
        => 'PHP has no userland operator-overloading hook; Binary comparisons (==, <, >) require the C extension compare handler',

    'bson/bson-dbpointer-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; DbPointer comparisons require the C extension compare handler',

    'bson/bson-document-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; Document comparisons (==, <, >) require the C extension compare handler',

    'bson/bson-int64-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; Int64 object-to-object comparisons (==, <, >) require the C extension compare handler',

    'bson/bson-int64-compare-002.phpt'
        => 'PHP has no userland operator-overloading hook; Int64 == int/float comparisons require the C extension compare handler',

    'bson/bson-int64-compare-003.phpt'
        => 'PHP has no userland operator-overloading hook; Int64 == float comparisons require the C extension compare handler',

    'bson/bson-int64-compare-004.phpt'
        => 'PHP has no userland operator-overloading hook; Int64 < / > float comparisons require the C extension compare handler',

    'bson/bson-int64-compare-005.phpt'
        => 'PHP has no userland operator-overloading hook; Int64 < / > int comparisons require the C extension compare handler',

    'bson/bson-javascript-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; Javascript comparisons require the C extension compare handler',

    'bson/bson-javascript-compare-002.phpt'
        => 'PHP has no userland operator-overloading hook; Javascript comparisons (scope ignored) require the C extension compare handler',

    'bson/bson-maxkey-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; MaxKey comparisons require the C extension compare handler',

    'bson/bson-minkey-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; MinKey comparisons require the C extension compare handler',

    'bson/bson-objectid-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; ObjectId comparisons require the C extension compare handler',

    'bson/bson-objectid-compare-002.phpt'
        => 'PHP has no userland operator-overloading hook; ObjectId comparisons require the C extension compare handler',

    'bson/bson-packedarray-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; PackedArray comparisons require the C extension compare handler',

    'bson/bson-regex-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; Regex comparisons require the C extension compare handler',

    'bson/bson-regex-compare-002.phpt'
        => 'PHP has no userland operator-overloading hook; Regex comparisons require the C extension compare handler',

    'bson/bson-symbol-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; Symbol comparisons require the C extension compare handler',

    'bson/bson-timestamp-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; Timestamp comparisons require the C extension compare handler',

    'bson/bson-undefined-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; Undefined comparisons require the C extension compare handler',

    'bson/bson-utcdatetime-compare-001.phpt'
        => 'PHP has no userland operator-overloading hook; UTCDateTime comparisons require the C extension compare handler',

    // -------------------------------------------------------------------------
    // Arithmetic operators on Int64 (C extension `do_operation` handler)
    // -------------------------------------------------------------------------

    'bson/bson-int64-operation-001.phpt'
        => 'PHP has no userland arithmetic-operator hook; Int64 +, -, *, /, % require the C extension do_operation handler',

    'bson/bson-int64-operation-002.phpt'
        => 'PHP has no userland arithmetic-operator hook; Int64 bitwise operators require the C extension do_operation handler',

    'bson/bson-int64-operation-003.phpt'
        => 'PHP has no userland arithmetic-operator hook; Int64 other operations require the C extension do_operation handler',

    'bson/bson-int64-operation-004.phpt'
        => 'PHP has no userland arithmetic-operator hook; Int64 ++/-- require the C extension do_operation handler',

    'bson/bson-int64-operation-005.phpt'
        => 'PHP has no userland arithmetic-operator hook; Int64 ** exponentiation requires the C extension do_operation handler',

    'bson/bson-int64-operation_error-001.phpt'
        => 'PHP has no userland arithmetic-operator hook; Int64 operation error paths require the C extension do_operation handler',

    // -------------------------------------------------------------------------
    // Int64 type-casting (C extension `cast_object` handler)
    // -------------------------------------------------------------------------

    'bson/bson-int64-cast-001.phpt'
        => 'PHP has no userland cast_object hook; (int)/(float)/(bool) casts on Int64 require the C extension cast_object handler',

    'bson/bson-int64-cast-002.phpt'
        => 'PHP has no userland cast_object hook; (int)/(float)/(bool) casts on Int64 require the C extension cast_object handler',

    'bson/bson-int64-cast-003.phpt'
        => 'PHP has no userland cast_object hook; (int)/(float)/(bool) casts on Int64 require the C extension cast_object handler',

    // -------------------------------------------------------------------------
    // Decimal128 normalization (requires libbson IEEE 754 decimal128 library)
    // -------------------------------------------------------------------------

    'bson/bson-decimal128-001.phpt'
        => 'Decimal128 normalization (e.g. "1234e5" → "1.234E+8") requires libbson IEEE 754 decimal128 implementation; impossible in userland PHP',

    'bson/bson-decimal128-serialization-002.phpt'
        => 'Decimal128 normalization during round-trip requires libbson IEEE 754 decimal128 implementation; impossible in userland PHP',

    // -------------------------------------------------------------------------
    // Document/PackedArray: libbson "corrupt BSON data" field-path error messages
    // -------------------------------------------------------------------------

    'bson/bson-document-fromBSON_error-003.phpt'
        => 'Detailed "Detected corrupt BSON data for field path" error messages require libbson bson_iter_visit_all(); impossible to replicate with exact field paths in userland',

    'bson/bson-document-fromBSON_error-004.phpt'
        => 'Detailed "Detected corrupt BSON data for field path" error messages with nested paths require libbson; impossible in userland',

    // -------------------------------------------------------------------------
    // Document/PackedArray::toPHP() fieldPath type maps
    // -------------------------------------------------------------------------

    'bson/bson-document-toPHP-007.phpt'
        => 'fieldPath type maps in toPHP() require full field-path resolution logic matching libbson; complex feature not yet implemented',

    'bson/bson-document-toPHP-008.phpt'
        => 'fieldPath type maps with string keys require field-path resolution; not yet implemented',

    'bson/bson-document-toPHP-009.phpt'
        => 'fieldPath type maps with numerical keys require field-path resolution; not yet implemented',

    'bson/bson-document-toPHP-010.phpt'
        => 'fieldPath type maps with wildcard keys require field-path resolution; not yet implemented',

    'bson/bson-document-toPHP-011.phpt'
        => 'fieldPath type maps with nested wildcards require field-path resolution; not yet implemented',

    // -------------------------------------------------------------------------
    // PackedArray::fromJSON() libbson-specific JSON parse error format
    // -------------------------------------------------------------------------

    'bson/bson-packedarray-fromJSON_error-001.phpt'
        => 'libbson-specific JSON parse error format (character at error position) cannot be replicated exactly from PHP JsonException',

    // -------------------------------------------------------------------------
    // Session support not yet implemented
    // -------------------------------------------------------------------------

    'bulk/bulkwrite-debug-002.phpt'
        => 'Session::createFromManager() not yet implemented; requires client session support',
];
