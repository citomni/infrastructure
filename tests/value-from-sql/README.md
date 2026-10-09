# ValueFromSql suite

Standalone checks for `\CitOmni\Infrastructure\Service\ValueFromSql`, which formats SQL result values for UI output and form fields. No database, no Composer autoloader, no files written, and no setup per session.

## Run

```
php tests/value-from-sql/run.php
```

Expected result:

```
17 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service runs on the kernel doubles in `tests/support/doubles.php`. Unless a check overrides it, it uses the shipped `locale.format` baseline from `Registry::CFG_HTTP`: decimal separator `,`, thousand separator `.`, grouping on, scale 2, rounding `fail`, no trimming of trailing zeros, and no seconds in time and datetime-local values.

The checks are tables of inputs with their expected output, or with the `ValueFromSqlException` message key they must be rejected with.

## Checks

The checks marked as regressions failed before the fixes they cover; all other checks pin existing behavior.

| Check | Covers |
|---|---|
| init requires every locale.format policy key with a valid value | Each of the eight keys missing, and invalid values for each |
| integer() groups digits as text, strips leading zeros and normalizes -0 | BIGINT beyond 64 bits, grouping overrides and separators |
| integer() rejects separators, decimals and non-integer types | Error keys |
| null and empty input is null unless required | All eight methods; the `*_required` keys |
| decimal() formats SQL dot-decimals with the locale separators and pads to scale | Output form, scale 0, a dot-decimal locale |
| decimal() applies the rounding modes fail, truncate and half_up to string input | String-based rounding with carry, negative zero, rounding from cfg |
| decimal() drops zeros beyond the scale before rounding, so fail only fails on digits that carry a value | Regression: `fail` also failed on zeros beyond the scale, e.g. `1.500` at scale 2, while scale 0 already dropped them |
| decimal() with trim_trailing_zeros drops zeros and then the separator | Trimming |
| decimal() rejects locale formats, scientific notation and non-finite floats | Error keys, invalid overrides |
| decimal() rounds float input half-up to scale regardless of the rounding mode | Float input |
| boolean() accepts 0 and 1 as int, string or bool | Accepted forms and error keys |
| date() validates SQL DATE and formats it on request | Output formats, zero and impossible dates, unsupported formats |
| time() follows cfg time_include_seconds unless overridden per call | Precision, range and format |
| dateTimeLocal() turns SQL DATETIME into datetime-local | Precision, malformed values |
| text() returns stored text unchanged, whitespace included | No trimming |
| json() decodes JSON objects and arrays only | Scalars and invalid JSON are rejected |
| withField() returns a new exception with the field and the original as previous | Regression: `withField()` cloned the exception and always failed with an `Error`. The copy reports the file and line of the `withField()` call |

`php tests/run.php` runs every suite in the package.
