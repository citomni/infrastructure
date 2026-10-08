# BruteForce database suite

Checks for `\CitOmni\Infrastructure\Service\BruteForce` with `BruteForceRepository` and `DatabaseSchemaRepository` against a real MySQL/MariaDB server and the shipped schema in `sql/citomni_bruteforce.sql`. The real classes, including the Db service, run on the kernel doubles in `tests/support/doubles.php`, without Composer.

## Run

```
php tests/brute-force/database.php
```

Expected result:

```
14 passed, 0 failed, 1 skipped
```

The skipped check starts several worker processes at once. It runs only when `CITOMNI_TEST_PARALLEL` is `1`:

```
:: cmd
set "CITOMNI_TEST_PARALLEL=1"
php tests/brute-force/database.php

# PowerShell
$env:CITOMNI_TEST_PARALLEL = '1'
php tests/brute-force/database.php
```

Expected result with the multi-process check:

```
15 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## Database

The suite connects as `root` to `127.0.0.1:3306` over TCP. The password is read from `CITOMNI_TEST_PASSWORD`; when it is not set, no password is sent. Use a disposable local server. Application configuration is never loaded. The workers inherit the password through the environment; it is never passed on a command line.

Each run creates its own `citomni_infrastructure_brute_force_test_<random>` database (`utf8mb4_unicode_ci`) and drops only that database in `finally`. `sql/citomni_bruteforce.sql` is imported before every check, so each check starts from an empty `bruteforce_counters` table. If a run is interrupted, remove any leftover `citomni_infrastructure_brute_force_test_*` database after checking that no run is still going.

When the server is down, the suite fails; nothing is skipped.

## How it works

The service reads the clock with `time()`. Instead of waiting, checks move the stored timestamps of a subject back (`window_start`, `updated_at`, and an active `blocked_until`), as if that time had passed.

| File | Role |
|---|---|
| `database.php` | Runner and checks. |
| `worker.php` | Child for the multi-process check. Opens its connection, reports ready through a file, waits for the start gate, then records failures through the real service. The gate opens only when every worker is ready. |

## Checks

All checks pin existing behavior.

| Check | Covers |
|---|---|
| assertStorageReady() fails with a setup exception until citomni_bruteforce.sql is imported | `BruteForceSetupException`, `DatabaseSchemaRepository::tableExists()` |
| the identifier is blocked when its failures reach max_identifier_attempts | Counting, block, `retry_after_seconds`, `blocked_until`, status shape |
| identifiers are trimmed and lower-cased, and only their SHA-256 hashes are stored | Normalization, no plain identifiers in the table |
| IP addresses are throttled on their own, and reason is "both" when both are blocked | IP dimension, reasons, the later block wins |
| IPv6 addresses are lower-cased before hashing | IPv6 normalization |
| the transport sentinels "unknown" and "cli" give no IP subject | Sentinels in `status()`, `record()` and `clear()` |
| invalid input is rejected before any lookup | Context, subjects, IP validation, unconfigured contexts |
| contexts are validated on first use and the config node is required | `BruteForceConfigException` |
| a blocked subject is not counted further | No writes while blocked |
| an expired window is ignored by status() and restarted by the next failure | Rolling window |
| an active block outlives its window, and an expired block allows a fresh window | Block versus window |
| clear() removes only the requested buckets, also for unconfigured contexts | Bucket scope |
| prune() applies prune_after_seconds per context and removes orphans after 30 days | Per-context and orphan pruning, the 7-day default |
| status() never writes | Read-only status |
| concurrent failures create one bucket per subject and lose no increments | With `CITOMNI_TEST_PARALLEL=1` only: 4 processes record 25 failures each for the same identifier and IP at once. Each subject must end with one row and 100 attempts. |

`php tests/run.php` runs every suite in the package.
