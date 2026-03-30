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
];
