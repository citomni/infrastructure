# Log suite

Standalone checks for `\CitOmni\Infrastructure\Service\Log`. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/log/run.php
```

Expected result:

```
20 passed, 0 failed, 1 skipped
```

The skipped check starts several writer processes at once. It runs only when `CITOMNI_TEST_PARALLEL` is `1`:

```
:: cmd
set "CITOMNI_TEST_PARALLEL=1"
php tests/log/run.php

# PowerShell
$env:CITOMNI_TEST_PARALLEL = '1'
php tests/log/run.php
```

Expected result with the multi-process check:

```
21 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service runs on the kernel doubles in `tests/support/doubles.php`. `CITOMNI_APP_PATH` points at a fresh `citomni_infrastructure_log_test_<random>` directory under `sys_get_temp_dir()`; every check writes into its own subdirectory, and the whole directory is removed afterwards.

| File | Role |
|---|---|
| `run.php` | Runner and checks. |
| `worker.php` | Child for the multi-process check. Builds the service, reports ready through a file, waits for the start gate, then writes its records. The gate opens only when every worker is ready. |

Rotated names carry the UTC time of the rotation. Pruning orders rotated files by modification time, and files modified within the same second by the timestamp and sequence number in their names. The first pruning check ages the rotated files by ten seconds after each write instead of sleeping, so the rotations look seconds apart. The checks for rotations within one second start right after a new second has begun; the other pruning checks seed files with chosen names and modification times.

## Checks

The checks marked as regressions failed before the fix they cover; all other checks pin existing behavior.

| Check | Covers |
|---|---|
| init creates the configured directory and default_file becomes a .jsonl file | `log.path`, `log.default_file`, the sidecar `.lock` file |
| without a log cfg node the logger writes citomni_app.jsonl under CITOMNI_APP_PATH/var/logs | Defaults |
| each entry is one JSON line: timestamp, category, message, then context only when non-empty | Line format, key order, `DATE_ATOM` |
| unicode and slashes are written unescaped, invalid UTF-8 is substituted | JSON flags |
| objects are encoded as JSON, and entries JSON cannot encode keep timestamp and category | Objects, the bounded normalization fallback, the fallback line |
| flat file names are normalized to .jsonl | File name normalization |
| unsafe file names are rejected without creating files | Path traversal, hidden files, characters outside `[A-Za-z0-9._-]` |
| invalid cfg values are rejected at init | `path`, `default_file`, `max_bytes`, `max_files` |
| setDir() rejects a missing directory unless autoCreate is set | `setDir()` |
| the write that reaches max_bytes rotates the file to `<name>_<date>_<time>_<pid>.jsonl` | Rotation after append, rotated file name |
| a file already at max_bytes is rotated before the next append | Rotation before append |
| rotation prunes the oldest rotated files down to max_files | Pruning |
| rotations within one second prune the oldest files and keep the newest | Regression: a rotation reused a name that pruning had just freed, and pruning then deleted the newest file |
| rotations from processes in different time zones keep the newest files | Regression: names in local time, so a process in another time zone numbered from 0 again and reused freed names |
| a rotation is numbered past the rotations of other processes in the same second | Regression: a rotation took the first free name for its own process id. Rotations of other seconds do not count |
| pruning orders equal modification times by the timestamp and sequence number in the name | Regression: plain name order, which also put `_10` before `_2` |
| pruning orders by modification time before the name | Names that disagree with the rotation order, e.g. local time across the end of daylight saving time |
| pruning deletes only rotated files of the log file being written | Regression: pruning `app` deleted `app_failed.jsonl` and other files matching `app_*.jsonl` |
| pruning works in a directory with glob characters in its path | Regression: `glob()` read `[1]` in `logs[1]` as a character class, so pruning there deleted files in `logs1` and never its own |
| max_files null keeps every rotated file | No pruning |
| concurrent writers lose no records across rotations | With `CITOMNI_TEST_PARALLEL=1` only: 4 processes write 150 records each to one file with `max_bytes` 4096 and no pruning. Every record must appear exactly once across the live and rotated files. |

`php tests/run.php` runs every suite in the package.
