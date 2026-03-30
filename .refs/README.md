# .refs — Local reference clones

This directory contains read-only reference repositories cloned at project setup. They are listed in `.gitignore` and are never committed.

Re-clone everything from scratch:

```bash
git clone --depth=1 https://github.com/mongodb/mongo-php-driver    .refs/mongo-php-driver
git clone --depth=1 https://github.com/mongodb/mongo-php-library    .refs/mongo-php-library
git clone --depth=1 https://github.com/mongodb/specifications        .refs/specifications
```

## Contents

### `mongo-php-driver/`

The official `ext-mongodb` C extension. We only care about the PHP stub files that describe the public API surface:

- `src/MongoDB/*.stub.php` — `MongoDB\Driver\*` class signatures
- `src/BSON/*.stub.php` — `MongoDB\BSON\*` class signatures

When adding or verifying a method, check the corresponding stub first.

### `mongo-php-library/`

The high-level `mongodb/mongodb` library (`MongoDB\Client`, `Collection`, `Database`, …) that sits on top of the driver. Useful when tracing how the library calls the driver API, or when verifying that our implementation is compatible.

### `specifications/`

The official MongoDB driver specifications. Authoritative source of truth for every protocol and behaviour we implement:

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
