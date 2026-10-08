# Db suites

Checks for `\CitOmni\Infrastructure\Service\Db` in two scripts:

- `run.php` runs without a database server: configuration, laziness, and everything that must fail before a connection is opened.
- `database.php` runs against a real MySQL/MariaDB server: queries and binding, batches, transactions and isolation, the statement cache, session settings, connection recovery, and error codes.

Both load the real service on the kernel doubles in `tests/support/doubles.php`, without Composer.

## Run

```
php tests/db/run.php
php tests/db/database.php
```

Expected results:

```
13 passed, 0 failed
23 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## Database

`database.php` connects as `root` to `127.0.0.1:3306` over TCP. The password is read from `CITOMNI_TEST_PASSWORD`; when it is not set, no password is sent. Use a disposable local server. Application configuration is never loaded, and the password reaches the Db service through an in-memory secrets double, never through a file or a command line.

Each run creates its own `citomni_infrastructure_db_test_<random>` database (`utf8mb4_unicode_ci`), and every check creates the tables it uses. Only that database is dropped in `finally`. If a run is interrupted, remove any leftover `citomni_infrastructure_db_test_*` database after checking that no run is still going.

When the server is down, `database.php` fails; nothing is skipped.

## How it works

In `run.php` the App has no secrets service unless a check registers one, so an accidental attempt to connect fails with `DbConnectException` ("DB secret ... is missing or invalid") instead of the exception the check expects. The debug output check connects to a closed local port on purpose, with a secrets stub that would show the password in any dump reaching the App.

In `database.php` a few checks use extra connections next to the service:

- The isolation check holds an uncommitted insert open on a second connection. Only a transaction started with `TransactionIsolation::ReadUncommitted` may see it; the next transaction runs at the session default again.
- The statement cache check reads `Com_stmt_prepare` from the service's own session.
- The recovery check kills the service's connection from the admin connection.
- The deadlock check builds a lock cycle with a second connection that has modified more rows, so InnoDB picks the Db side as the victim, whichever request closes the cycle.
- The lock wait check holds a row lock on a second connection with `innodb_lock_wait_timeout` at 1 second in the Db session.

## Checks

All checks pin existing behavior.

### run.php

| Check | Covers |
|---|---|
| host, user and name are required | Mandatory cfg |
| the legacy pass key is rejected instead of ignored | `db.pass`, in cfg and options |
| port, connect_timeout and statement_cache_limit take integers or digit strings in range | Validation and defaults |
| password_secret must be a non-empty string | `password_secret` |
| service options win over cfg | Option precedence |
| construction opens no connection and reads no secret | Lazy connection |
| debug output carries no password, also after a connection attempt | `__debugInfo()`; the password is not kept after a connect |
| a missing password secret fails as DbConnectException without connecting | Secret lookup and exception chain |
| empty SQL and empty WHERE clauses fail before connecting | Every entry point |
| table and column identifiers are validated before any SQL is sent | Identifier quoting |
| insertBatch() validates every row against the first row before connecting | Column sets |
| commit() and rollback() without a connection fail | `requireConnection()` |
| DbQueryException classifies duplicate entries and deadlocks by error code | 1062, 1213, 1205 |

### database.php

| Check | Covers |
|---|---|
| insert, fetch, exists, count, update and delete work on a real table | Read and write API, schema-qualified tables, native integer types |
| parameters bind by PHP type: int, float, bool as 0/1, null as SQL NULL and strings verbatim | Binding, binary-safe strings |
| a failed statement raises DbQueryException with its SQL and parameter types and adds no values | Error message, code, previous exception |
| a duplicate key surfaces as DbQueryException::isDuplicateEntry() | Error code 1062 |
| insertBatch() sends up to 1000 rows as one multi-row INSERT | Single statement path |
| insertBatch() above 1000 rows inserts in chunks in its own transaction and rolls back as a whole | Chunked path, rollback |
| the chunked insertBatch() path refuses to start inside an active transaction | Nested transaction guard |
| transaction() commits the callback result and rethrows a callback exception after rolling back | `transaction()`, `easyTransaction()` |
| nested transactions are rejected and leave the outer transaction intact | `beginTransaction()` guard |
| an isolation level applies to that one transaction only | `TransactionIsolation`, no leak into the session |
| the statement cache prepares each SQL once and evicts first in, first out | Cache limit 2 and 0, `setStatementCacheLimit()` |
| a result from select() stays readable when the cached statement runs again | Buffered results and cached statements |
| selectNoMysqlnd() streams the same rows as fetchAll() | Generator path |
| queryRaw() returns a result for reads and true for statements without one | Raw queries |
| queryRawMulti() returns results in order and leaves the connection usable after a failure | Multi-statement batches |
| sql_mode and time_zone from cfg, or the PHP time zone offset, apply to the session | Session settings |
| a failing session setting leaves no half-open connection | Connection state after a failed init |
| wrong credentials fail as DbConnectException without the password in the message | Connect errors |
| checkConnection(), ensureConnection() and reconnect() recover a killed connection | Recovery |
| reconnect() is refused inside a transaction | Recovery guard |
| lastInsertId(), affectedRows() and countQueries() report the latest work | Meta API |
| a real InnoDB deadlock surfaces as DbQueryException::isDeadlock() | Error code 1213 from a real deadlock |
| a lock wait timeout is not reported as a deadlock | Error code 1205 |

`php tests/run.php` runs every suite in the package.
