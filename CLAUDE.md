# CLAUDE.md — Project guide for Claude Code

## AI assistance

This project is developed with [Claude Code](https://claude.ai/code) (`claude-sonnet-4-6`).
All commits carry an `Assisted-by: Claude Code/claude-sonnet-4-6` trailer.

## What this project is

A pure userland PHP 8.4+ MongoDB driver that replicates the `MongoDB\Driver\*` and `MongoDB\BSON\*` namespaces normally provided by `ext-mongodb`. Async I/O is handled by RevoltPHP (`revolt/event-loop`) and `amphp/socket`.

## Coding standards

The coding style is enforced by PHP_CodeSniffer with the PSR-12 standard.
Run `./vendor/bin/phpcbf` to automatically fix style issues, then `./vendor/bin/phpcs`
to identify any remaining issues. All code must pass `phpcs` before committing.

Don't try to follow the CS manually, especially `use` and `use function` statements
will be written automatically by `phpcbf` in the correct order. If you have to
manually edit a file, run `phpcbf` on it before committing to ensure the style is correct.

## Running tests

`ext-mongodb` must not be active. Check using `php --ri mongodb`

```bash
# Unit tests (no database needed)
./vendor/bin/phpunit --testdox --testsuite unit

# Integration tests (MongoDB on localhost:27017)
./vendor/bin/phpunit --testdox --testsuite integration

# ext-mongodb phpt compatibility tests (all)
tests/run-phpt.sh

# phpt — subset via glob(s) relative to tests/references/mongo-php-driver/tests/
tests/run-phpt.sh 'bson/bson-objectid-*.phpt'
tests/run-phpt.sh 'bson/bson-utcdatetime-*.phpt' 'bson/bson-binary-*.phpt'

# Official mongodb/mongodb library unified spec tests
# Always use tests/run-phpunit.sh so MONGODB_URI is injected correctly
tests/run-phpunit.sh mongodb://127.0.0.1:PORT/ \
    -c tests/references/mongo-php-library/phpunit.xml.dist \
    tests/references/mongo-php-library/tests/

# Subset with --filter (faster than running all and grepping)
tests/run-phpunit.sh mongodb://127.0.0.1:PORT/ \
    -c tests/references/mongo-php-library/phpunit.xml.dist \
    tests/references/mongo-php-library/tests/ --filter testCrud
```

Run tests after every non-trivial change. Commit only when all tests pass.

## Known test failures (pre-existing, not fixable in userland)

These failures are expected and should not block commits:

| Category | Reason |
|---|---|
| **Client-Side Encryption (CSE/CSFLE/QE)** | Requires `libmongocrypt`; not implemented |
| **Snapshot sessions** | `snapshot: true` read concern not implemented |
| **Stable API / Versioned API** | `serverApi` option is parsed but not sent in commands |
| **`Int64` comparison** (`$result->insertedCount == 0`) | PHP operator overloading limitation; use `(int)` cast or `==` with int literal |
| **Change stream resume token iteration** | Cursor iteration edge case; known open issue |
| **`killAllSessions` test isolation** | `UnifiedTestRunner::doSetUp()` calls `killAllSessions` before each test, which can invalidate pooled sessions from other tests running in the same suite |

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
src/functions.php            Global Monitoring functions (not autoloadable)
```

## Key invariants

- **PSR-4 autoloading**: `MongoDB\BSON\` → `src/BSON/`, `MongoDB\Driver\` → `src/Driver/`, `MongoDB\Internal\` → `src/Internal/`. No manual `require` chains.
- **No `uniqid()`**: use `bin2hex(random_bytes(N))` for all random identifiers.
- **BSON int64 decodes as PHP int**: `BsonDecoder` returns native PHP `int` for BSON type `0x12`, matching ext-mongodb behavior on 64-bit systems. `MongoDB\BSON\Int64` is only used when explicitly constructing BSON values or encoding.
- **`WriteConcern::isDefault()`**: returns `true` only for driver-internal defaults, never for user-constructed instances. Use `WriteConcern::createDefault()` internally.
- **`SyncRunner::run()`**: wraps async operations so they block when called from non-fiber context (plain PHP scripts) and suspend-only when called from inside a Revolt fiber.
- **No class_exists guards**: classes are plain PSR-4 files. The Composer autoloader won't load a file for an already-defined class, so no guards are needed.

## References

Git submodules in `tests/references/` — initialise with `git submodule update --init`:

| What | Where |
|---|---|
| Driver API stubs | `tests/references/mongo-php-driver/src/MongoDB/*.stub.php` and `tests/references/mongo-php-driver/src/BSON/*.stub.php` |
| High-level library | `tests/references/mongo-php-library/src/` |
| Client specifications | `tests/references/specifications/source/` (BSON, OP_MSG, SDAM, Server Selection, Auth, Sessions, …) |

## Common pitfalls

- **ext-mongodb conflict**: if test output says "Call to undefined method" on a public Driver class, the extension is loaded. Fix: `PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit ...`
- **namespace inside if block**: `namespace` cannot appear inside an `if` block — this was the original bug from the class_exists guards. All files now have `namespace` at the top level.
- **BsonDecoder missing methods**: `BsonDecoder` only exposes `decode(string $bson, array $typeMap): array|object`. For Extended JSON output use `ExtendedJson::toCanonical()` / `toRelaxed()`.
- **phpunit.xml**: using PHPUnit 9 — `<source>` element is PHPUnit 10+ and should not be present.
- **Library test OOM / wrong MONGODB_URI**: always use `tests/run-phpunit.sh mongodb://HOST:PORT/ -c tests/references/mongo-php-library/phpunit.xml.dist tests/references/mongo-php-library/tests/`. The wrapper sets `memory_limit=1G` and injects the correct `MONGODB_URI` (the default phpunit.xml.dist URI may point to the wrong server).
- **"operation was interrupted" (11601) in library tests**: caused by `killAllSessions` in test setup invalidating pooled server sessions. Run failing tests in isolation with `--filter` to confirm they pass on their own.
- **Filtering library tests**: use `--filter` (not grep on output) to run a subset — e.g. `vendor/bin/phpunit --filter 'testCrud.*aggregate'`.
