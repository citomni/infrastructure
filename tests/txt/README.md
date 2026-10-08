# Txt suite

Standalone checks for `\CitOmni\Infrastructure\Service\Txt`. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/txt/run.php
```

Expected result:

```
14 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service runs on the kernel doubles in `tests/support/doubles.php`, with a recording double as the log service. `CITOMNI_APP_PATH` points at a fresh `citomni_infrastructure_txt_test_<random>` directory under `sys_get_temp_dir()`. The checks write their language files below it, as `language/<lang>/...` for the app layer and `vendor/<vendor>/<package>/language/<lang>/...` for package layers. The directory is removed afterwards.

Files that a check rewrites are written through `writePhpFile()`, which also drops any OPcache copy, so the checks hold with `opcache.enable_cli` on.

The package's own language files and the template keys are checked in `tests/registry`.

## Checks

All checks pin existing behavior.

| Check | Covers |
|---|---|
| locale.language is required | `TxtConfigException` without the cfg key |
| locale.language must be "xx" or "xx_YY" | Language format |
| the app layer reads `language/<lang>/<file>.php` | App layer path |
| a vendor layer reads `vendor/<vendor>/<package>/language/<lang>/<file>.php` | Package layer path, surrounding slashes |
| file names may contain subdirectories | `emails/welcome-mail` |
| unsafe file names and malformed layers are rejected | File names that are empty or contain `..`, a leading or trailing `/`, an extension, a space, a leading `-` or a backslash; layers that are not exactly two `vendor/package` segments. Nothing is logged |
| a missing or empty key returns the default with placeholders applied and logs txt.missing_key | Fallback, log record and context |
| scalar values are returned as strings | int, float and bool values |
| a non-scalar value returns the default and logs txt.non_scalar_value | Arrays in language files |
| placeholders are %UPPERCASE% tokens taken from the vars keys | Placeholder replacement |
| a missing file returns defaults and is logged once as txt.missing_file | Missing files are cached as empty |
| a file that does not return an array is logged once as txt.invalid_file_payload | Invalid payloads are cached as empty |
| a language file is loaded once per service instance | Per-instance cache |
| lookups during an HTTP request log the request URI | Runtime context in log records |

`php tests/run.php` runs every suite in the package.
