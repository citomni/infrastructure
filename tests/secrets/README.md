# Secrets suite

Standalone checks for `\CitOmni\Infrastructure\Service\Secrets`. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/secrets/run.php
```

Expected result:

```
11 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service runs on the kernel doubles in `tests/support/doubles.php` with `CITOMNI_ENVIRONMENT` set to `dev`. Every check gets its own application root below a fresh `citomni_infrastructure_secrets_test_<random>` directory under `sys_get_temp_dir()`, which is removed afterwards. The secret values are fixed test strings.

`CITOMNI_ENVIRONMENT` is a constant, so the checks for `stage`, `prod`, an unsupported value and a missing constant run `probe.php` in a child process with the same PHP binary and php.ini.

| File | Role |
|---|---|
| `run.php` | Runner and checks. |
| `probe.php` | Child. Defines the given environment (or none), looks up one key and prints the outcome as JSON. |

## Checks

All checks pin existing behavior.

| Check | Covers |
|---|---|
| a missing secret file is an empty store | `has()` false, `get()` throws `OutOfBoundsException` |
| dev reads var/secrets/app.secret.dev.php | File selection for `dev` |
| keys are exact: case-sensitive, and empty or padded keys are rejected before any file is read | Key validation and its order |
| an empty string is a valid secret value | Empty passwords, which Db and Mailer accept |
| a secret file must return a flat map of string keys to string values | Payload validation |
| a directory at the secret path is rejected | Path validation |
| the file is read on first use and once per instance | Lazy loading, memoization |
| debug output contains neither keys nor values | `var_dump()` and `print_r()` |
| the shipped secret file stubs load as empty stores | `install/scaffold/var/secrets/*.stub` for all three environments |
| stage and prod read only their own file, without falling back to dev | File selection for `stage` and `prod` |
| an unsupported or missing CITOMNI_ENVIRONMENT is a RuntimeException | Environment validation |

`php tests/run.php` runs every suite in the package.
