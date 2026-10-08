# ValueToSql suite

Standalone checks for `\CitOmni\Infrastructure\Service\ValueToSql`, which normalizes UI and form input for SQL parameter binding. No database, no Composer autoloader, no files written, and no setup per session.

## Run

```
php tests/value-to-sql/run.php
```

Expected result:

```
18 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service runs on the kernel doubles in `tests/support/doubles.php`. Unless a check sets other separators, it uses the shipped `locale.format` baseline from `Registry::CFG_HTTP`: decimal separator `,` and thousand separator `.`.

The checks are tables of inputs with their expected output, or with the `ValueToSqlException` message key they must be rejected with. The message key is what higher layers translate, so it is part of the contract.

## Checks

The check marked as a regression failed before the fix it covers; all other checks pin existing behavior.

| Check | Covers |
|---|---|
| init requires locale.format with one decimal separator and a different or empty thousand separator | `ValueConfigurationException` |
| integer() accepts an optional sign and strict thousand grouping | Grouping, signs, leading zeros, the 64-bit limits |
| integer() rejects decimals, wrong grouping, other characters and non-string types | `err_value_to_sql_integer_invalid`; space and disabled grouping |
| integer() enforces min, max, allowNegative and the 64-bit range | Range errors and their params, reversed bounds |
| empty input is null unless required, for every method | `null`, `''` and whitespace for all nine methods; the `*_required` keys |
| decimal() normalizes to dot-decimal and pads to scale without rounding | Output form, scale padding, `too_many_decimals` with its params; zeros count as fraction digits (`1,500` at scale 2, `12,00` at scale 0) |
| decimal() accepts only the configured separators | Wrong separators and grouping; a dot-decimal locale |
| decimal() with space grouping also accepts no-break spaces | U+00A0 and U+202F |
| decimal() takes int and float input, rounding floats half-up to scale | Numeric input, non-finite floats, `allowNegative`, invalid types and scales |
| boolean() accepts the documented tokens case-insensitively and returns 0 or 1 | Tokens and types |
| date() accepts YYYY-MM-DD, DD-MM-YYYY and DD/MM/YYYY and checks the calendar | Formats, impossible dates |
| time() accepts HH:MM and HH:MM:SS from 00:00:00 to 23:59:59 | Formats and range |
| dateTime() accepts datetime-local and SQL input and normalizes to YYYY-MM-DD HH:MM:SS | `T` separator, whitespace, impossible values, time zone suffixes |
| text() takes strings only, trims by default and limits length in bytes | `maxLen` counts bytes, not characters |
| enum() matches the trimmed input exactly against a list of strings | Case-sensitive match, invalid allowed lists |
| json() validates JSON strings and encodes arrays and objects | Validation, encoding flags, encode failures |
| ValueToSqlException carries the message key, params and an optional field | Exception accessors |
| withField() returns a new exception with the field and the original as previous | Regression: `withField()` cloned the exception and always failed with an `Error`. The copy reports the file and line of the `withField()` call |

`php tests/run.php` runs every suite in the package.
