# Mailer suite

Standalone checks for `\CitOmni\Infrastructure\Service\Mailer` with the real PHPMailer. No database and no setup per session, but the package dependencies must be installed.

## Run

```
php tests/mailer/run.php
```

Expected result:

```
14 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## Requirements

PHPMailer is loaded from `vendor/phpmailer/phpmailer/src`, so run `composer install` in the package root once. Without it the suite reports `FAIL phpmailer/phpmailer is installed` and `0 passed, 1 failed`; it is not skipped. The Composer autoloader itself is not used.

## How it works

The real Mailer runs on the kernel doubles in `tests/support/doubles.php`, with `CITOMNI_ENVIRONMENT` set to `prod`, a recording double as the log service, and an in-memory secrets double. Its cfg is the shipped `mail` baseline from `Registry::CFG_HTTP`, pointed at `smtp-server.php` on a free local port. A small subclass, `InspectableMailer`, exposes the PHPMailer instance for checks on its state.

| File | Role |
|---|---|
| `run.php` | Starts the SMTP server, runs the checks, stops the server. |
| `smtp-server.php` | Minimal SMTP server. Advertises `AUTH PLAIN` and no `STARTTLS`, accepts any credentials, rejects recipients in the domain `reject.invalid`, and records every session (credentials, envelope, message) as one JSON line. |

The server writes into a fresh `citomni_infrastructure_mailer_test_<random>` directory under `sys_get_temp_dir()`, which is removed afterwards. No mail leaves the machine.

## Checks

The check marked as a regression failed before the fix it covers; all other checks pin existing behavior.

| Check | Covers |
|---|---|
| the shipped mail cfg builds an SMTP mailer without reading the password | Baseline transport settings, no secret read at construction |
| the legacy mail.smtp.password cfg key is rejected | Committed credentials fail fast |
| transport and encryption follow the mail cfg | sendmail, qmail, mail, unknown transports; `tls`, `ssl`; auth off |
| an HTML message is sent with From and Reply-To from cfg, {vars} injected and a generated text part | Defaults, template vars, generated AltBody, headers and envelope |
| authenticated SMTP reads mail.smtp.password from Secrets at send time and clears it afterwards | Lazy secret, credentials on the wire, password reset |
| SMTP without authentication never reads a secret | No AUTH, no secret read |
| a missing SMTP password throws before anything is sent | `OutOfBoundsException`, message reset |
| a rejected recipient makes send() return false and logs a masked error without bodies | `mailer_errors.jsonl`, masked username, body hashes only, no password |
| include_bodies puts both bodies into the error log | `mail.logging.include_bodies` |
| log_success writes nothing outside dev | Success logging policy in `prod` |
| recipients take strings, lists and email/name pairs, and Bcc stays out of the headers | `to()`, `cc()`, `bcc()` |
| a message with only altBody() is sent as text/plain with that text as its body | Regression: `send()` left Body empty, and PHPMailer refused the message |
| every send() resets the message and keeps the cfg defaults | `resetMessage()` |
| attach() and setTemplate() reject unreadable files, and an attachment travels as a MIME part | Attachments and templates |

`php tests/run.php` runs every suite in the package.
