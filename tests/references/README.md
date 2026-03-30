# tests/references — Git submodules

This directory contains read-only reference repositories tracked as Git
submodules.  After cloning the project, initialise them with:

```bash
git submodule update --init
```

## Contents

### `mongo-php-driver/`

The official `ext-mongodb` C extension.

- `src/MongoDB/*.stub.php` — `MongoDB\Driver\*` class signatures
- `src/BSON/*.stub.php` — `MongoDB\BSON\*` class signatures
- `tests/` — `.phpt` test suite, executed against the userland driver via
  `vendor/bin/phpunit --testsuite phpt`

### `mongo-php-library/`

The high-level `mongodb/mongodb` library (`MongoDB\Client`, `Collection`,
`Database`, …) that sits on top of the driver.  Its test suite is run in CI
to verify API compatibility.

The library's `composer.json` is patched in CI before installing dependencies
to replace the `ext-mongodb` platform requirement with our driver package:

```bash
composer -d tests/references/mongo-php-library config --unset require.ext-mongodb
composer -d tests/references/mongo-php-library config \
    repositories.driver '{"type":"path","url":"../../..","options":{"symlink":true}}'
composer -d tests/references/mongo-php-library \
    require --no-update mongodb/mongodb-driver-revoltphp:@dev
composer -d tests/references/mongo-php-library install --no-interaction --prefer-dist
PHP_INI_SCAN_DIR="" tests/references/mongo-php-library/vendor/bin/phpunit --testsuite unit
```

### `specifications/`

The official MongoDB driver specifications.

| Spec | Path |
|---|---|
| BSON | `source/bson/` |
| OP_MSG wire protocol | `source/message/` |
| Server Discovery and Monitoring (SDAM) | `source/server-discovery-and-monitoring/` |
| Server Selection | `source/server-selection/` |
| Connection String / URI | `source/connection-string/` |
| Authentication (SCRAM, X.509, …) | `source/auth/` |
| Driver Sessions | `source/sessions/` |
| Transactions | `source/transactions/` |
| Retryable Reads / Writes | `source/retryable-reads/`, `source/retryable-writes/` |
| Change Streams | `source/change-streams/` |
| GridFS | `source/gridfs/` |
| CRUD | `source/crud/` |
