# Registry suite

Standalone checks for the package wiring in `\CitOmni\Infrastructure\Boot\Registry` and the content it ships: service map, the cfg baseline and language files. It also pins that the package contributes no routes, controllers or templates. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/registry/run.php
```

Expected result:

```
17 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The suite loads `src/Boot/Registry.php` and the classes it names from their PSR-4 files under `src/`, on the kernel doubles in `tests/support/doubles.php`. `CITOMNI_APP_PATH` points at a fresh `citomni_infrastructure_registry_test_<random>` directory under `sys_get_temp_dir()`, removed afterwards; the log check writes below it.

Every service with package-owned cfg is constructed from `Registry::CFG_COMMON` alone, so a baseline value a service rejects fails here. Two services are constructed elsewhere or with more:

- `txt` reads `locale.language`, which `CFG_COMMON` does not declare. The check adds it on top of the baseline.
- `mailer` needs PHPMailer and is constructed from the baseline in `tests/mailer`.

The cfg checks scan `src/` with the tokenizer, so commented-out code is ignored. They follow `->cfg->...` chains, also through a local variable assigned from one in the same function, with or without a `??` fallback. The package owns a path when its root is in `CFG_COMMON`. `security` and `locale` are shared with other packages and the app, so below them the package owns only the keys `CFG_COMMON` ships there (`security.bruteforce`, `locale.format`).

Not covered by the scan: reads from arrays (`BruteForce` reads its contexts from `toArray()`, `Db` and `Mailer` check for their removed `pass` and `smtp.password` keys on `toArray()`), chains that end at a method call or a dynamic property name, variables followed across functions or through arrays, and a cfg node held in a property. A `??` after a closing parenthesis (`($cfg->port) ?? 1`) is not seen, so that read counts as unguarded; `?:` is no guard, since the read still throws on a missing key. Every `->cfg` property counts as App cfg.

## Checks

All checks pin existing behavior.

| Check | Covers |
|---|---|
| CLI gets the same service map and cfg baseline as HTTP | no `MAP_HTTP`, `MAP_CLI`, `CFG_HTTP` or `CFG_CLI` |
| every service id maps to a BaseService subclass under src/ | `MAP_COMMON` |
| the package contributes no routes, controllers or templates | no `ROUTES_HTTP`, no `view` cfg, no files in `src/Controller/` or `templates/` |
| log constructs from the shipped cfg baseline, creates var/logs and writes citomni_app.jsonl | `log` baseline |
| secrets constructs from the shipped cfg baseline without reading a file | `secrets` |
| curl accepts the shipped cfg baseline as request defaults | `curl` baseline, validated per request; the request uses an unsupported scheme, so libcurl fails before any IO |
| db constructs from the shipped cfg baseline without connecting | `db` baseline values |
| valueToSql and valueFromSql accept the shipped locale.format policy | `locale.format` baseline |
| bruteForce constructs from the shipped cfg baseline with a valid default context | `security.bruteforce.default` |
| formatNumber constructs from the shipped cfg baseline | `formatNumber` |
| txt constructs from the shipped cfg baseline plus locale.language | `txt` |
| shipped language files return flat maps of non-empty strings | `language/*/*.php` |
| the cfg scan finds known reads in src/ | The scan itself, so a change in the code style cannot make it pass blind |
| cfg is read with property syntax | Array syntax on a Cfg node, which the scan cannot follow |
| package-owned cfg is read without fallbacks | No `??`, `isset()`, `empty()` or `?->` on a read of package-owned cfg |
| every package-owned cfg path read in src/ exists in CFG_COMMON | A baseline for every path the package reads |
| cfg the package does not own is read with a fallback | App cfg such as `locale.language` |

`php tests/run.php` runs every suite in the package.
