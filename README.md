# CitOmni Infrastructure

Lean, cross-mode infrastructure for CitOmni apps.
Predictable service maps, deterministic config (last-wins), no magic.
Ultra-fast PHP 8.2+, side-effect free, designed for **HTTP *and* CLI** runtimes. ♻️

---

## Highlights

* **Shared services** available in both HTTP & CLI (`db`, `log`, `txt`, `mailer`)
* **Deterministic boot** -> vendor baseline -> providers -> app (**last wins**)
* **No scanning** -> `$this->app->{id}` resolves instantly (cacheable maps)
* **Prod-friendly** -> config/service maps can be precompiled by the App
* **Infrastructure focus** -> DB (LiteMySQLi), logging, mail (PHPMailer), text/i18n

---

## Requirements

* PHP **8.2+**
* Extensions:

  * `ext-json` (standard)
  * `ext-iconv` or `ext-mbstring` (mailer UTF-8 normalization; one of them is used)
  * **`ext-gd`** (required for the optional `/captcha` route)
  * Freetype (optional) enables TTF text in captcha (falls back to bitmap fonts if missing)
* OPcache recommended in production

---

## Install

```bash
composer require citomni/infrastructure
composer dump-autoload -o
```

Ensure your app is PSR-4 mapped:

```json
{
	"autoload": { "psr-4": { "App\\": "src/" } }
}
```

Enable the provider in **`/config/providers.php`**:

```php
<?php
return [
	\CitOmni\Infrastructure\Boot\Services::class,
];
```

---

## Services (exported IDs)

This package contributes a **baseline service map** (app can override in `/config/services.php`):

| id       | class                                   | purpose                           |
| -------- | --------------------------------------- | --------------------------------- |
| `db`     | `CitOmni\Infrastructure\Service\Db`     | LiteMySQLi wrapper (lazy connect) |
| `log`    | `CitOmni\Infrastructure\Service\Log`    | LiteLog (JSONL etc., with rotate) |
| `txt`    | `CitOmni\Infrastructure\Service\Txt`    | Static text/i18n loader (LiteTxt) |
| `mailer` | `CitOmni\Infrastructure\Service\Mailer` | PHPMailer wrapper (+ logging)     |

---

## Constructor contract (all services)

```php
__construct(\CitOmni\Kernel\App $app, array $options = [])
```

---

**Usage:**

```php
// DB
$id  = $this->app->db->insert('crm_msg', ['msg_subject' => 'Hi']);
$row = $this->app->db->fetchRow('SELECT * FROM crm_msg WHERE id=?', [$id]);

// Logging
$this->app->log->write('orders.jsonl', 'order.create', ['id'=>$id,'total'=>$total]);

// Text (i18n)
$this->app->txt->get('err_invalid_email', 'contact', 'citomni/infrastructure', 'Invalid.');

// Mail
$this->app->mailer
	->to('user@example.com')
	->subject('Welcome, {name}')
	->templateVars(['name' => 'Sarah'])
	->body('<p>Hello {name}</p>', true)
	->send();
```

---

## Configuration (last wins)

At runtime the App builds config as:

1. Vendor infrastructure baseline
   `\CitOmni\Infrastructure\Boot\Services::CFG_HTTP` (and `CFG_CLI`)
2. Provider CFGs (if any) listed in `/config/providers.php`
3. App base cfg: `/config/citomni_http_cfg.php` or `/config/citomni_cli_cfg.php`
4. App env overlay: `/config/citomni_{http|cli}_cfg.{env}.php` (optional)

**Merge rules:** Associative arrays are deep-merged (**last wins**). Numeric lists are replaced by the last source.

### Important keys used by this package

```php
'db' => [
	'host' => 'localhost',
	'user' => 'root',
	'pass' => '',
	'name' => 'citomni',
	'charset' => 'utf8mb4',
],

'log' => [
	'path'         => CITOMNI_APP_PATH . '/var/logs',
	'default_file' => 'citomni_app.log',
	'max_bytes'    => 2_000_000,
	'max_files'    => 10, // null = unlimited
],

'txt' => [
	'log' => [
		'file' => 'litetxt_errors.jsonl',
		'path' => CITOMNI_APP_PATH . '/var/logs',
	],
],

'mail' => [
	'from'      => ['email' => '', 'name' => ''],
	'reply_to'  => ['email' => '', 'name' => ''],
	'format'    => 'html',      // 'html' | 'text'
	'transport' => 'smtp',      // 'smtp' | 'mail' | 'sendmail' | 'qmail'
	// 'sendmail_path' => '/usr/sbin/sendmail',
	'smtp' => [
		'host'       => '',
		'port'       => 587,
		'encryption' => null,     // 'tls' | 'ssl' | null
		'auth'       => true,
		'username'   => '',
		'password'   => '',
		'auto_tls'   => true,
		'timeout'    => 15,
		'keepalive'  => false,
	],
	'logging' => [
		'log_success'     => false, // dev aid
		'debug_transcript' => false,
		'max_lines'        => 200,
		'include_bodies'   => false, // keep false in prod
	],
],

'security' => [
	'csrf_protection'      => true,
	'csrf_field_name'      => 'csrf_token',
	'captcha_protection'   => true,
	'honeypot_protection'  => true,
	'form_action_switching'=> true,
],

'routes' => [
	'/kontakt.html' => [
		'controller'     => \CitOmni\Infrastructure\Controller\InfrastructureController::class,
		'action'         => 'contact',
		'methods'        => ['GET','POST'],
		'template_file'  => 'public/contact.html',
		'template_layer' => 'citomni/infrastructure',
	],
	'/captcha' => [
		'controller' => \CitOmni\Infrastructure\Controller\InfrastructureController::class,
		'action'     => 'captcha',
		'methods'    => ['GET'],
	],
],
```

> The HTTP router reads **routes as raw arrays** (`$this->app->cfg->routes[...]`).

---

## DB service (`db`)

Thin wrapper around **LiteMySQLi** with **lazy connection** and ergonomic `__call()` pass-through:

```php
$id = $this->app->db->insert('crm_msg', ['msg_subject' => 'Hi']);
$row = $this->app->db->fetchRow('SELECT * FROM crm_msg WHERE id=?', [$id]);
```

For models, you can extend `CitOmni\Infrastructure\Model\BaseModelLiteMySQLi` and access `$this->db`.

---

## Logging (`log`)

Backed by **LiteLog**:

```php
$this->app->log->write('order.jsonl', 'order.create', ['id'=>$id,'total'=>$total]);
```

Rotation controlled by `log.max_bytes` and `log.max_files`. Directory defaults to `var/logs`.

---

## Text / i18n (`txt`)

Static text loader via **LiteTxt**, with layered paths:

```php
$this->app->txt->get($key, $file, $layer='app', $default='', $vars=[]);
```

* `layer='app'` -> `CITOMNI_APP_PATH/language/{lang}/{file}.php`
* `layer='vendor/package'` -> `CITOMNI_APP_PATH/vendor/{layer}/language/{lang}/{file}.php`
* Language comes from `cfg['locale']['language']` (`'da'`, `'da_DK'`, etc.)
* **Placeholders**: `%UPPER_CASE%` -> replaced from `$vars` (e.g. `%APP_NAME%`)

Errors go to `txt.log.file` (default `litetxt_errors.jsonl`).

---

## Mailer (`mailer`)

PHPMailer wrapper with sensible defaults:

```php
$this->app->mailer
	->from('system@example.com', 'CitOmni')
	->to(['john@example.com','jane@example.com'])
	->subject('Welcome, {name}')
	->templateVars(['name' => 'Sarah'])
	->body('<p>Hello {name}</p>', true)
	->send();
```

* **Transport**: `smtp`, `mail`, `sendmail`, `qmail` (from cfg)
* **Default From/Reply-To** read from cfg
* **Templating**: `{var}` placeholders (mailer-only) via `templateVars()`
* Auto-generates `AltBody` from HTML if you don't set it
* Logging:

  * Success (dev-friendly): `mail_log.json` when `mail.logging.log_success=true`
  * Errors: `mailer_errors.json` with optional SMTP transcript (`debug_transcript`)

---

## Contact form (optional)

If you keep the provided routes:

* `GET|POST /kontakt.html` -> validates, stores in DB (`crm_msg`), emails app recipient
* `GET /captcha` -> returns a PNG captcha using `ext-gd`
  Fonts (optional) read from `vendor/citomni/infrastructure/assets/fonts/*.ttf`

**Security interplay**: honors `security.csrf_protection`, `captcha_protection`, and `honeypot_protection`.

**Recipient**: `cfg['identity']['email']` (fallback: `cfg['mail']['from']['email']`).

> If you plan to use the contact form routes, import the schema now (see Database schema below).

---

## Database schema

This package ships a ready-to-apply SQL schema for the contact form model:

* File: `vendor/citomni/infrastructure/sql/crm_msg.sql`
  Creates table **`crm_msg`** (InnoDB, `utf8mb4_unicode_ci`, PK `id` auto-increment). Works on MySQL 8+ / MariaDB 10.4+. 

### Option A — Manual import (recommended)

Use your preferred tool:

**MySQL CLI**

```bash
mysql -u <user> -p <database> < vendor/citomni/infrastructure/sql/crm_msg.sql
```

**phpMyAdmin / Adminer**

* Open your database
* Import the file: `vendor/citomni/infrastructure/sql/crm_msg.sql`

### Option B — Code-based, one-off installer (idempotent)

If you prefer to install via code, run once during setup/deploy:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

define('CITOMNI_ENVIRONMENT', 'cli');
define('CITOMNI_APP_PATH', __DIR__);

$app = new \CitOmni\Kernel\App(__DIR__ . '/config', \CitOmni\Kernel\Mode::CLI);
$sql = (string)\file_get_contents(__DIR__ . '/vendor/citomni/infrastructure/sql/crm_msg.sql');
$app->db->execute($sql);
echo "crm_msg installed.\n";
```

> Keep this script out of web-root; run it once, then delete it.
> The `Db` service must have `CREATE` privileges, otherwise use Option A.

### Notes

* The `CrmMessageModel` expects the table name **`crm_msg`** and the columns defined in the SQL file. 
* You can add indexes later to fit your reporting needs (e.g., `msg_added_dt`, `msg_from_email`).

---

## Why no auto-migrate?

CitOmni packages are **side-effect free** by design. Vendor code should not create or alter your database automatically. That keeps the runtime **predictable**, **reviewable**, and **safe** across environments.

**Why this policy exists**

* **Determinism & reviewability** – DB changes live in your app/ops repos, not hidden in vendor code.
* **Least privilege** – production credentials often lack `CREATE/ALTER`; installs shouldn’t assume elevated rights.
* **Safer deploys** – no surprise schema writes that can fail under load, lock tables, or break blue/green rollouts.
* **Compliance & audit** – schema changes pass through your change-management and CI/CD, with diffs and approvals.
* **Multi-env parity** – staging/prod may be managed by DBAs; the package must work without mutating state.

**What to do instead**

* Use the provided SQL once (see **Database schema** above), or
* Maintain **app-owned migrations** (idempotent SQL, `IF NOT EXISTS`, transactional where possible), executed by your deploy pipeline or a CLI command in your app.
* Track schema with a simple `schema_version` table (or your existing migration tool).

This keeps the infrastructure package **stateless**, while your application controls **when and how** the database evolves.

---

## Performance tips

* Composer:

  ```json
  { "config": { "optimize-autoloader": true, "classmap-authoritative": true, "apcu-autoloader": true } }
  ```

  Then: `composer dump-autoload -o`

* OPcache (prod):

  ```
  opcache.enable=1
  opcache.validate_timestamps=0
  opcache.revalidate_path=0
  opcache.save_comments=0
  realpath_cache_size=4096k
  realpath_cache_ttl=600
  ```

---

## Contributing

* PHP 8.2+, PSR-4, **tabs**, K&R braces
* Keep vendor files side-effect free (OPcache-friendly)
* Don't swallow exceptions in core; let the global error handler log

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:  
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Infrastructure** is open-source under the **MIT License**.  
See: [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.  
You may not use the CitOmni name or logo to imply endorsement or affiliation without prior written permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.  
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos,  
or imply sponsorship, endorsement, or affiliation without prior written permission.  
Do not register or use "citomni" (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.  
For details, see the project's [NOTICE](NOTICE).

---

## Author

Developed by **Lars Grove Mortensen** © 2012-present
Contributions and pull requests are welcome!

---

Built with ❤️ on the CitOmni philosophy: **low overhead**, **high performance**, and **ready for anything**.
