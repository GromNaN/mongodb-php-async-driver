# CLAUDE.md — Project guide for Claude Code

## What this project is

A pure userland PHP 8.4+ MongoDB driver that replicates the `MongoDB\Driver\*` and `MongoDB\BSON\*` namespaces normally provided by `ext-mongodb`. Async I/O is handled by RevoltPHP (`revolt/event-loop`) and `amphp/socket`.

## Running tests

`ext-mongodb` must not be active. Always prefix commands with `PHP_INI_SCAN_DIR=""`:

```bash
# Unit tests (no database needed)
PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit --testdox --testsuite unit

# Integration tests (MongoDB on localhost:27017)
PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit --testdox --testsuite integration
```

Run tests after every non-trivial change. Commit only when all tests pass.

## Commit discipline

Commit at each debugging milestone — don't accumulate unrelated changes into one commit. Use imperative-mood subjects, ≤ 72 chars.

## Architecture overview

```
src/BSON/                    MongoDB\BSON\* public types
src/Driver/                  MongoDB\Driver\* public API
src/Driver/Exception/        Exception hierarchy
src/Driver/Monitoring/       APM events and subscriber interfaces
src/Internal/Auth/           SCRAM-SHA-256 / SCRAM-SHA-1
src/Internal/BSON/           BsonEncoder, BsonDecoder, ExtendedJson, TypeMapper
src/Internal/Connection/     Connection, ConnectionPool, SyncRunner
src/Internal/Operation/      OperationExecutor, CommandHelper
src/Internal/Protocol/       OP_MSG MessageHeader, OpMsgEncoder, OpMsgDecoder
src/Internal/Session/        SessionPool
src/Internal/Topology/       TopologyManager, ServerMonitor, SdamStateMachine, ServerSelector
src/Internal/Uri/            ConnectionString, UriOptions
src/bootstrap.php            Global Monitoring functions (not autoloadable)
```

## Key invariants

- **PSR-4 autoloading**: `MongoDB\BSON\` → `src/BSON/`, `MongoDB\Driver\` → `src/Driver/`, `MongoDB\Internal\` → `src/Internal/`. No manual `require` chains.
- **No `uniqid()`**: use `bin2hex(random_bytes(N))` for all random identifiers.
- **BSON int64 decodes as `Int64`**: `BsonDecoder` returns `MongoDB\BSON\Int64` objects for BSON type `0x12`, preserving type fidelity for canonical Extended JSON.
- **`WriteConcern::isDefault()`**: returns `true` only for driver-internal defaults, never for user-constructed instances. Use `WriteConcern::_createDefault()` internally.
- **`SyncRunner::run()`**: wraps async operations so they block when called from non-fiber context (plain PHP scripts) and suspend-only when called from inside a Revolt fiber.
- **No class_exists guards**: classes are plain PSR-4 files. The Composer autoloader won't load a file for an already-defined class, so no guards are needed.

## Reference stubs

The canonical API surface lives in `.refs/mongo-php-driver/src/`. When implementing or verifying a method signature, consult:

- `.refs/mongo-php-driver/src/MongoDB/Manager.stub.php`
- `.refs/mongo-php-driver/src/MongoDB/Cursor.stub.php`
- `.refs/mongo-php-driver/src/BSON/*.stub.php`
- etc.

## BSON codec quick reference

| BSON type byte | PHP decode result |
|---|---|
| `0x01` double | `float` |
| `0x02` string | `string` |
| `0x03` document | `stdClass` or `array` per typeMap |
| `0x04` array | `array` per typeMap |
| `0x05` binary | `MongoDB\BSON\Binary` |
| `0x07` objectid | `MongoDB\BSON\ObjectId` |
| `0x08` boolean | `bool` |
| `0x09` UTC datetime | `MongoDB\BSON\UTCDateTime` |
| `0x0A` null | `null` |
| `0x0B` regex | `MongoDB\BSON\Regex` |
| `0x10` int32 | `int` |
| `0x11` timestamp | `MongoDB\BSON\Timestamp` |
| `0x12` int64 | `MongoDB\BSON\Int64` |
| `0x13` decimal128 | `MongoDB\BSON\Decimal128` |
| `0x7F` maxkey | `MongoDB\BSON\MaxKey` |
| `0xFF` minkey | `MongoDB\BSON\MinKey` |

## Common pitfalls

- **ext-mongodb conflict**: if test output says "Call to undefined method" on a public Driver class, the extension is loaded. Fix: `PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit ...`
- **namespace inside if block**: `namespace` cannot appear inside an `if` block — this was the original bug from the class_exists guards. All files now have `namespace` at the top level.
- **BsonDecoder missing methods**: `BsonDecoder` only exposes `decode(string $bson, array $typeMap): array|object`. For Extended JSON output use `ExtendedJson::toCanonical()` / `toRelaxed()`.
- **phpunit.xml**: using PHPUnit 9 — `<source>` element is PHPUnit 10+ and should not be present.
