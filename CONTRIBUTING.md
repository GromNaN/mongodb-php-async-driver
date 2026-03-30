# Contributing

Thank you for your interest in contributing. This document covers everything you need to get started.

## Prerequisites

- PHP 8.4+ (`php --version`)
- Composer 2 (`composer --version`)
- MongoDB 4.0+ running locally on port 27017 for integration tests

`ext-mongodb` must **not** be active when running the test suite. Prefix every command with `PHP_INI_SCAN_DIR=""` if the extension is installed on your machine (see [README — Disabling ext-mongodb](README.md#disabling-ext-mongodb)).

## Setup

```bash
git clone https://github.com/mongodb/mongodb-driver-revoltphp
cd mongodb-driver-revoltphp
composer install
```

## Running tests

```bash
# Unit tests only (fast, no database needed)
PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit --testdox --testsuite unit

# Integration tests (requires MongoDB on localhost:27017)
PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit --testdox --testsuite integration

# All suites
PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit --testdox
```

All tests must pass before opening a pull request.

## Project layout

```
src/BSON/          Public MongoDB\BSON\* types
src/Driver/        Public MongoDB\Driver\* API
src/Internal/      Internal implementation (not public API)
tests/BSON/        Unit tests for BSON types and codec
tests/Driver/      Unit tests for Driver value objects
tests/Protocol/    Unit tests for wire protocol framing
tests/Auth/        Unit tests for SCRAM authentication
tests/Topology/    Unit tests for SDAM state machine
tests/Integration/ Integration tests against a real MongoDB server
```

`src/Internal/` is an implementation detail. Do not import `MongoDB\Internal\*` from outside the library.

## Adding a feature

1. **Open an issue first** for anything non-trivial. Describe what you want to implement and why.
2. **Branch** from `main`: `git checkout -b feature/my-feature`.
3. **Write tests first** — add unit tests in `tests/` before implementing, or alongside the implementation.
4. **Implement** in `src/`. Keep the public API surface (`MongoDB\BSON\*`, `MongoDB\Driver\*`) identical to ext-mongodb's stubs in `.refs/mongo-php-driver/src/`.
5. **Check syntax**: `find src tests -name "*.php" | xargs php -l`
6. **Run the full test suite**: `PHP_INI_SCAN_DIR="" ./vendor/bin/phpunit --testdox`
7. **Commit** with a clear message (imperative mood, present tense):
   - `Add SCRAM-SHA-256 server signature verification`
   - `Fix BsonDecoder int64 sign extension on decode`
8. **Open a pull request** against `main`.

## Commit style

- One logical change per commit.
- Subject line ≤ 72 characters, imperative mood.
- Body (optional) explains *why*, not *what*.
- Reference related issues: `Fixes #123`.

## Coding standards

- `declare(strict_types=1)` in every file.
- Follow PSR-12 formatting.
- No `uniqid()` — use `bin2hex(random_bytes(N))` for random identifiers.
- No global mutable state outside of the Topology/Connection layers.
- Internal classes in `MongoDB\Internal\*` may be changed without a deprecation cycle. Public classes (`MongoDB\BSON\*`, `MongoDB\Driver\*`) follow semantic versioning.

## Debugging wire protocol

Enable detailed logging by setting `MONGODB_DEBUG=1` before running a script:

```bash
PHP_INI_SCAN_DIR="" MONGODB_DEBUG=1 php my-script.php
```

To inspect raw BSON, use the `BsonEncoder`/`BsonDecoder` classes directly or compare against the reference in `.refs/mongo-php-driver/`.

## Reporting a bug

Please include:

- PHP version (`php --version`)
- MongoDB server version
- A minimal reproducible script
- The full exception message and stack trace
- Whether the same script works with `ext-mongodb` loaded

## Compatibility target

This library aims for full wire compatibility with ext-mongodb's public API as described by the stubs in `.refs/mongo-php-driver/src/`. When in doubt, defer to ext-mongodb's behaviour.
