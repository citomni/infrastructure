# Slugger suite

Standalone checks for `\CitOmni\Infrastructure\Service\Slugger`: the policy it reads from cfg `slug` and the service options, and the whole public contract of `slugify()`, `normalize()`, `validate()`, `enforceLength()`, `isReserved()` and `ensureUnique()`. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/slugger/run.php
```

Expected result:

```
18 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service runs on the kernel doubles in `tests/support/doubles.php`, on the `slug` baseline that `Registry::CFG_COMMON` ships, with the keys of each check on top. The input of the checks writes every non-ASCII character as a `\u{...}` escape, so a composed letter and its decomposed form cannot be mistaken for each other.

The locale check runs `slugify()` under every locale among `C`, `POSIX`, `C.UTF-8`, `C.utf8`, `da_DK.UTF-8` and `de_DE.UTF-8` that the system has, and restores the locale afterwards.

## Checks

| Check | Covers |
|---|---|
| the shipped baseline is a valid policy: dash, lowercase, 140 bytes, transliteration, no reserved slugs | Construction from the baseline and its effect |
| each service option wins over the cfg key of the same name | Every key as an option, with a different value in cfg for `reserved` |
| an invalid policy value throws SluggerConfigException naming the cfg key or the option | Every key with wrong types and values, among them a separator that `disallowed_pattern` matches, a pattern without delimiters, one that does not compile and one that matches the empty string, a map and an int in `reserved`; the path `slug.<key>` or `slugger.options.<key>`, and the message |
| a reserved slug is compared without the case of ASCII letters, numeric ones included | `isReserved()` for `ADMIN` and `404`, not for a longer, padded or other slug; `validate()` of a reserved and a padded reserved slug |
| slugify() replaces each run of disallowed characters with one separator, trims separators and lowercases | Punctuation, whitespace, tabs and newlines, separators in the input, and input with no allowed character |
| slugify() transliterates Danish, German and accented Latin letters, composed or decomposed | The Danish and German letters in both cases, French, Polish, Icelandic, Romanian and ligature letters, and every decomposed letter of the map, plus accents that are dropped |
| slugify() gives the same slug under every locale | Each locale of the list that the system has |
| slugify() leaves characters outside the map to disallowed_pattern | Cyrillic, a symbol and a byte that is not UTF-8 under the shipped pattern; letters kept by a pattern with the `u` modifier, and `\InvalidArgumentException` from `slugify()` and `validate()` for text that is not UTF-8 under such a pattern |
| slugify() without transliteration leaves the letters to disallowed_pattern | `transliterate` off |
| slugify() shortens a long slug at a separator with enforceLength() | With and without a separator |
| normalize() cleans like slugify() without transliterating or shortening | Cleaning, no transliteration, no shortening, an empty result, `lowercase` off |
| validate() accepts a slug of the policy, trimmed, and rejects everything else | Every rejection of `validate()`; `lowercase` off |
| a separator of several characters is replaced, collapsed, trimmed and checked as a whole | `~~` as separator: replacement, collapsing, trimming, three tildes as one separator and a tilde, and the checks of `validate()` |
| enforceLength() cuts at the last separator from 60 percent of the length, else at the length | Limits below 1, a slug that fits, a separator exactly at and just before 60 percent, a trailing separator, a slug of separators only |
| enforceLength() never cuts inside a UTF-8 character | Two-byte and four-byte characters, a first character longer than the limit, and bytes that are not UTF-8 |
| ensureUnique() returns the normalized base when it is free, else the first free numbered variant | The candidates asked about, in order, up to the last attempt |
| ensureUnique() keeps the number of a candidate longer than max_length and shortens the base | A shortened base, a base whose separator lies before 60 percent, and the number alone |
| ensureUnique() rejects an invalid limit, an empty or invalid base, a candidate that fails validate() and a full set | `\InvalidArgumentException` for each, also for a reserved candidate |

`php tests/run.php` runs every suite in the package.
