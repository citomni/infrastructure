# Registry suite

Standalone checks for the package wiring in `\CitOmni\Infrastructure\Boot\Registry` and the content it ships: service map, routes, the cfg baseline, language files and templates. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/registry/run.php
```

Expected result:

```
13 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The suite loads `src/Boot/Registry.php` and the classes it names from their PSR-4 files under `src/`, on the kernel doubles in `tests/support/doubles.php`. `CITOMNI_APP_PATH` points at a fresh `citomni_infrastructure_registry_test_<random>` directory under `sys_get_temp_dir()`, removed afterwards; the log check writes below it.

Every service with package-owned cfg is constructed from `Registry::CFG_HTTP` alone, so a baseline value a service rejects fails here. Two services are constructed elsewhere or with more:

- `txt` reads `locale.language`, which `CFG_HTTP` does not declare. The check adds it on top of the baseline.
- `mailer` needs PHPMailer and is constructed from the baseline in `tests/mailer`.

## Checks

All checks pin existing behavior.

| Check | Covers |
|---|---|
| CLI gets the same service map and cfg baseline as HTTP | `MAP_CLI`, `CFG_CLI` |
| every service id maps to a BaseService subclass under src/ | `MAP_HTTP` |
| every HTTP route names a public action on a package controller | `ROUTES_HTTP` |
| log constructs from the shipped cfg baseline, creates var/logs and writes citomni_app.jsonl | `log` baseline |
| secrets constructs from the shipped cfg baseline without reading a file | `secrets` |
| curl accepts the shipped cfg baseline as request defaults | `curl` baseline, validated per request; the request uses an unsupported scheme, so libcurl fails before any IO |
| db constructs from the shipped cfg baseline without connecting | `db` baseline values |
| valueToSql and valueFromSql accept the shipped locale.format policy | `locale.format` baseline |
| bruteForce constructs from the shipped cfg baseline with a valid default context | `security.bruteforce.default` |
| formatNumber constructs from the shipped cfg baseline | `formatNumber` |
| txt constructs from the shipped cfg baseline plus locale.language | `txt` |
| shipped language files return flat maps of non-empty strings | `language/*/*.php` |
| every $txt() key the templates read from citomni/infrastructure exists in every language | `templates/*/*.html` against `language/*/` |

`php tests/run.php` runs every suite in the package.
