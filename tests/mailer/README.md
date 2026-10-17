# Mailer suite

Standalone checks for `\CitOmni\Infrastructure\Service\Mailer` with the real PHPMailer. No database and no setup per session, but the package dependencies must be installed.

## Run

```
php tests/mailer/run.php
```

Expected result:

```
29 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## Requirements

PHPMailer is loaded from `vendor/phpmailer/phpmailer/src`, so run `composer install` in the package root once. Without it the suite reports `FAIL phpmailer/phpmailer is installed` and `0 passed, 1 failed`; it is not skipped. The Composer autoloader itself is not used.

Without `ext-intl` and `ext-mbstring`, PHPMailer cannot send to an internationalized domain, and the check for internationalized addresses verifies that `send()` refuses them with `InvalidArgumentException` instead.

## How it works

The real Mailer runs on the kernel doubles in `tests/support/doubles.php`, with `CITOMNI_ENVIRONMENT` set to `prod`, a recording double as the log service, and an in-memory secrets double. Its cfg is the shipped `mail` baseline from `Registry::CFG_COMMON` with a sender, a Reply-To address and a host name added, pointed at `smtp-server.php` on a free local port with encryption `none`. A small subclass, `InspectableMailer`, exposes the protected `newPhpMailer()` and `htmlToText()`; its subclass `SigningMailer` sets up S/MIME signing with files that do not exist.

| File | Role |
|---|---|
| `run.php` | Starts the SMTP server, runs the checks, stops the server. |
| `smtp-server.php` | Minimal SMTP server. Advertises `AUTH PLAIN` and neither `STARTTLS` nor `SMTPUTF8`, accepts any credentials, rejects recipients in the domain `reject.invalid`, leaves the end of the message data unanswered when a recipient is in the domain `silent.invalid`, and records every session (greeting, credentials, envelope, message) as one JSON line. |

The timeout check listens on a second local port without ever accepting, so the system completes the connection and nothing answers it.

The server writes into a fresh `citomni_infrastructure_mailer_test_<random>` directory under `sys_get_temp_dir()`, which is removed afterwards. No mail leaves the machine.

## Checks

Checks marked as a regression cover a defect that the previous Mailer or a draft of this one had, and fail when the defect is put back. All other checks pin the current behavior.

| Check | Covers |
|---|---|
| the shipped mail cfg is refused until the app sets a sender and an SMTP host | `MailerConfigException` for `mail.from.email`, then `mail.smtp.host`; the `mail` transport needs no host |
| a sender and an SMTP host give a STARTTLS transport that reads no secret before it sends | Baseline transport settings, required STARTTLS, no opportunistic TLS, no secret read before a send |
| transport and encryption follow the mail cfg | sendmail and its binary, `mail()`, `smtps`, `none`, no authentication without a username, host name |
| invalid mail cfg values are refused with their cfg path | Every checked key, including the old `encryption` value `tls`, the removed `qmail` transport, a padded secret key, every removed key (`format`, `logging`, `smtp.auth`, `smtp.auto_tls`, `smtp.keepalive`, `smtp.password`), and SMTP hosts with an `ssl://` or `tls://` prefix, also in a later entry, a space, port 0, an empty entry or a non-ASCII name; the message names the path, and for a removed key what replaces it and only the type of its value. A host list with ports and an IPv6 address is accepted |
| cfg addresses are checked the same whichever validator PHPMailer uses for the process | Regression: `init()` used PHPMailer's process-wide validator, which PHPMailer relaxes after a Unicode address |
| an HTML message goes out with the cfg sender and Reply-To, a generated text part and the Message-ID it returns | Defaults, headers, envelope, EHLO and Message-ID from `mail.hostname`, multipart/alternative |
| the generated text part keeps link targets and drops style, script and tags | Regression: the text part lost link targets and kept CSS. Quoted, bare and empty targets, a `>` inside a quoted target |
| a link left open in a long HTML body keeps its target and the rest of the text part | Regression: PCRE's backtrack limit emptied the text part |
| a text body goes out as text/plain, and a given text part replaces the generated one | `text` alone, `html` with `text`, no alternative for HTML without text |
| authenticated SMTP reads the named password secret at send time | `mail.smtp.password_secret`, credentials on the wire, lazy secret |
| SMTP without a username never authenticates or reads a secret | No `AUTH`, no secret read |
| a missing SMTP password throws before anything is sent | `OutOfBoundsException`, no connection |
| mail.smtp.host is tried entry by entry, and a port in an entry replaces mail.smtp.port | A refused first entry, then a second one whose port differs from `mail.smtp.port` |
| starttls refuses a server without STARTTLS instead of sending in plain text | No downgrade: neither credentials nor the message reach the server |
| a server that never answers fails the send after mail.smtp.timeout | Regression: the wait for a reply took PHPMailer's 300 seconds |
| a server that never answers the message data leaves accepted empty although it has the message | The wait for the reply to the message data ends after twice `mail.smtp.timeout`; `accepted` is `[]` although the server got the message |
| a partly refused send throws and names the recipients that got the message | Regression: `send()` reported a failure without saying that the message had gone out; `MailerSendException::$accepted` in the spelling of the arguments, the server's reply in the message |
| a refused send logs metadata, never a body or the password | `mailer.error` line in `mailer.jsonl`, with the server's reply in its error |
| log_errors and log_success decide what goes to mailer.jsonl | `mailer.success`, no line without `log_errors` |
| a log write that fails changes neither a refusal nor an accepted send | Best-effort logging |
| recipients take strings, lists and address => name pairs, a repeated address gets one copy, and Bcc stays out of the headers | Address arguments, duplicates across `to`, `cc` and `bcc` in any letter case |
| internationalized addresses go out in punycode, once each, and no recipient is lost | Regression: a Cc recipient that was also the Reply-To address was lost, and one also given in punycode left the To header; `accepted` in the spelling of the arguments |
| from and replyTo replace the cfg defaults for one message only | Envelope sender, `replyTo: []`, defaults back on the next message |
| invalid arguments throw InvalidArgumentException before anything is sent | No recipient or body, invalid addresses, an internationalized domain that punycode cannot express in `to` and in `from`, two senders, non-string entries, unreadable attachment |
| stack traces of a refused send hold neither the bodies nor the message | Regression: PHPMailer's exception held the message in its trace arguments. `SensitiveParameter` on `html` and `text`, `zend.exception_ignore_args` restored |
| attachments travel as MIME parts under the given file name | String keys as file names, the file's own name otherwise |
| a failed send leaves nothing behind for the next one | Regression: a failed composition left its recipients, and a Unicode address left PHPMailer's SMTPUTF8 mode and, from PHPMailer 6.10, its process-wide `eai` validator, for every later message |
| every address of a message meets the same validator, whatever their order | Regression: from PHPMailer 6.10, an address before the first one with UTF-8 met `php` and the same address after it `eai` |
| a message that PHPMailer cannot build from valid arguments throws MailerException and sends nothing | Regression: a signing failure was reported as invalid input. `MailerException` itself, PHPMailer's exception as the previous one, no secret read, no connection |

`php tests/run.php` runs every suite in the package.
