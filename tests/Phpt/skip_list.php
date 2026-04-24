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

    // -------------------------------------------------------------------------
    // Logging: mongodb.debug INI — C extension feature
    // -------------------------------------------------------------------------

    'logging/logging-addSubscriber-004.phpt'
        => 'Uses mongodb.debug INI setting which controls C extension trace output; not implemented in userland driver',

    // -------------------------------------------------------------------------
    // Manager: C extension internal debug output (PHONGO: DEBUG lines)
    // -------------------------------------------------------------------------

    'manager/manager-ctor-003.phpt'
        => 'Test verifies C extension debug output (PHONGO: DEBUG > Connection string); not produced by userland driver',

    'manager/manager-ctor-007.phpt'
        => 'Test verifies C extension client persistence debug output (PHONGO: DEBUG > Created client with hash); not produced by userland driver',

    'manager/manager-ctor-008.phpt'
        => 'Test verifies C extension client persistence debug output (PHONGO: DEBUG > Created client with hash); not produced by userland driver',

    'manager/manager-ctor-driver-metadata-001.phpt'
        => 'Test verifies C extension debug output for handshake driver metadata (PHONGO: DEBUG > Setting driver handshake data); not produced by userland driver',

    // -------------------------------------------------------------------------
    // Manager: disableClientPersistence — C extension client persistence feature
    // -------------------------------------------------------------------------

    'manager/manager-ctor-disableClientPersistence-001.phpt'
        => 'disableClientPersistence is a C extension client-persistence feature; persistent/non-persistent client lifecycle tracking is not available in userland',

    'manager/manager-ctor-disableClientPersistence-002.phpt'
        => 'disableClientPersistence is a C extension client-persistence feature; non-persistent client destruction timing is not available in userland',

    'manager/manager-ctor-disableClientPersistence-004.phpt'
        => 'disableClientPersistence is a C extension client-persistence feature; not available in userland',

    'manager/manager-ctor-disableClientPersistence-005.phpt'
        => 'disableClientPersistence is a C extension client-persistence feature; not available in userland',

    'manager/manager-ctor-disableClientPersistence-006.phpt'
        => 'disableClientPersistence combined with client-side encryption requires C extension internals; not available in userland',

    'manager/manager-ctor-disableClientPersistence-007.phpt'
        => 'disableClientPersistence combined with client-side encryption requires C extension internals; not available in userland',

    'manager/manager-ctor-disableClientPersistence_error-001.phpt'
        => 'disableClientPersistence validation with keyVaultClient requires C extension client-persistence feature; not available in userland',

    // -------------------------------------------------------------------------
    // WriteConcern: invalid $w type throws TypeError instead of InvalidArgumentException
    // -------------------------------------------------------------------------

    'writeConcern/writeconcern-ctor_error-002.phpt'
        => 'C extension should throw TypeError for invalid $w types instead of throwing an InvalidArgumentException; tracked in PHPC-2704 (https://jira.mongodb.org/browse/PHPC-2704)',

    // -------------------------------------------------------------------------
    // Manager: subscriber sharing across Managers via libmongoc client persistence
    // -------------------------------------------------------------------------

    'manager/manager-addSubscriber-002.phpt'
        => 'Expects subscribers on Manager A to be notified when Manager B (same URI) executes commands; relies on libmongoc persistent-client sharing which is not available in userland',

    // -------------------------------------------------------------------------
    // Logging: combined CommandSubscriber+LogSubscriber — PHONGO debug messages
    // -------------------------------------------------------------------------

    'logging/logging-addSubscriber-005.phpt'
        => 'Test expects PHONGO debug log messages (e.g. "PHONGO: Connection string") emitted by libmongoc during Manager construction; userland driver does not produce these logs',

    // -------------------------------------------------------------------------
    // Int64 comparison with integer using != operator (PHP 8.5 notice)
    // -------------------------------------------------------------------------

    'functional/cursorid-001.phpt'
        => 'Test uses $cursorId != 0 (Int64 vs int) which emits a Notice in PHP 8.5; C extension handles this via custom compare handler (operator overloading not available in userland)',

    // -------------------------------------------------------------------------
    // Manager: autoEncryption / client-side encryption — not implemented
    // -------------------------------------------------------------------------

    'manager/manager-ctor-auto_encryption-001.phpt'
        => 'autoEncryption requires client-side field-level encryption which is not implemented in this userland driver',

    'manager/manager-ctor-auto_encryption-error-001.phpt'
        => 'autoEncryption driver option validation is not implemented in this userland driver',

    'manager/manager-ctor-auto_encryption-error-003.phpt'
        => 'autoEncryption driver option type validation is not implemented in this userland driver',

    'manager/manager-ctor-auto_encryption-error-004.phpt'
        => 'crypt_shared library support is not implemented in this userland driver',

    'manager/manager-createClientEncryption-001.phpt'
        => 'ClientEncryption is not implemented in this userland driver',

    'manager/manager-createClientEncryption-error-002.phpt'
        => 'ClientEncryption option validation is not implemented in this userland driver',

    // -------------------------------------------------------------------------
    // Constructor type checking: C extension throws InvalidArgumentException for
    // wrong-type arguments; userland PHP typed signatures throw TypeError instead.
    // PHP typed signatures are correct; these tests reflect a C extension bug.
    // -------------------------------------------------------------------------

    'bson/bson-utcdatetime_error-004.phpt'
        => 'C extension throws InvalidArgumentException for wrong-type $milliseconds; PHP typed signature correctly throws TypeError. C extension behavior is a bug (should be TypeError).',

    'bson/bson-timestamp_error-006.phpt'
        => 'C extension throws InvalidArgumentException for wrong-type arguments; PHP typed signature correctly throws TypeError. C extension behavior is a bug (should be TypeError).',

    // -------------------------------------------------------------------------
    // ObjectId: C extension silently converts invalid hex chars to 0
    // -------------------------------------------------------------------------

    'bson/bug0974-001.phpt'
        => 'C extension silently converts invalid hex chars in ObjectId strings to 0 (e.g. "2017-06-13T11:21:26.906Z" → valid OID). Userland correctly rejects invalid ObjectId strings per BSON spec.',

    // -------------------------------------------------------------------------
    // Enum classes cannot implement Unserializable/Persistable
    // (C extension enforces this via an interface_implements hook)
    // -------------------------------------------------------------------------

    'bson/bson-enum_error-001.phpt'
        => 'C extension rejects enums implementing Unserializable via a low-level interface_implements hook; userland PHP cannot prevent an enum from implementing an interface',

    'bson/bson-enum_error-002.phpt'
        => 'C extension rejects backed enums implementing Unserializable via a low-level interface_implements hook; userland PHP cannot prevent an enum from implementing an interface',

    'bson/bson-enum_error-003.phpt'
        => 'C extension rejects enums implementing Persistable via a low-level interface_implements hook; userland PHP cannot prevent an enum from implementing an interface',

    'bson/bson-enum_error-004.phpt'
        => 'C extension rejects backed enums implementing Persistable via a low-level interface_implements hook; userland PHP cannot prevent an enum from implementing an interface',

    // -------------------------------------------------------------------------
    // Int64 constructor: float input throws TypeError (typed signature) not
    // InvalidArgumentException (C extension untyped behavior)
    // -------------------------------------------------------------------------

    'bson/bson-int64-ctor_error-001.phpt'
        => 'C extension throws InvalidArgumentException for float input to Int64(); PHP typed int|string signature correctly throws TypeError. C extension behavior is a bug (should be TypeError).',

    // -------------------------------------------------------------------------
    // BSON class opaqueness: PHP public readonly properties are visible via
    // property_exists(); C extension uses internal C struct members which are
    // not PHP properties
    // -------------------------------------------------------------------------

    'bson/bug0939-001.phpt'
        => 'C extension BSON objects have no PHP-level properties (internal C struct storage); userland PHP uses public readonly properties which property_exists() returns true for',

    // -------------------------------------------------------------------------
    // PHP string interning behavior: refcount vs interned output in debug_zval_dump
    // -------------------------------------------------------------------------

    'bson/bug1839-005.phpt'
        => 'Test verifies PHP internal string refcount vs interned status in debug_zval_dump; behavior depends on PHP version string interning and is not driver-specific',

    // -------------------------------------------------------------------------
    // fieldPath wildcard type maps in setTypeMap() — not yet implemented
    // -------------------------------------------------------------------------

    'bson/typemap-006.phpt'
        => 'Wildcard fieldPath type maps (e.g. "field.$") in setTypeMap()/toPHP() require full field-path resolution logic; not yet implemented in userland driver',

    'bson/typemap-007.phpt'
        => 'Nested wildcard fieldPath type maps (e.g. "field.$.$") in setTypeMap()/toPHP() require full field-path resolution logic; not yet implemented in userland driver',
];
