# CitOmni Infrastructure

Lean, shared infrastructure services for CitOmni HTTP and CLI applications: database access, input/output normalization, JSONL logging, application secrets, language files, outbound HTTP requests, email, and brute-force protection.

`citomni/infrastructure` supplies the practical building blocks that almost every application needs, without embedding them in a particular controller, command, or business workflow. The package contributes a service map and configuration defaults to `citomni/kernel`. Services are resolved lazily through the application and reused within the same `App` instance.

Its primary services are native CitOmni implementations. Database access uses MySQLi, HTTP requests use PHP's cURL extension, logging writes structured JSON Lines, and localization reads PHP language files. Email delivery delegates to PHPMailer, while the package owns its application-facing interface and configuration.

This is an **infrastructure package**, not an application framework, ORM, authentication system, or HTTP layer. It performs reusable technical work; the consuming application remains responsible for deciding when and why that work happens.

---

## Highlights

- **One provider for both HTTP and CLI**, with the same ten registered service IDs.
- **Lazy App services** through `$this->app->db`, `$this->app->log`, `$this->app->curl`, and their peers.
- **Native MySQLi database access** with positional bindings, convenience CRUD operations, transactions, and a bounded prepared-statement cache.
- **Explicit value conversion in both directions** between localized form input, SQL values, and display-ready values.
- **Append-only JSONL logging** with per-file locking, size-based rotation, and retention.
- **Environment-specific application secrets**, resolved lazily from app-local PHP files and never placed in normal configuration.
- **PHP language-file lookup** for application and Composer-package translations, with deterministic `%PLACEHOLDER%` replacement.
- **Outbound HTTP via cURL**, with a normalized response contract, optional connection reuse, and explicit redaction of selected query parameters.
- **Email composition and delivery** through PHPMailer, including SMTP secrets, recipients, attachments, simple templates, and controlled diagnostics.
- **Database-backed brute-force protection** for identifiers and IP addresses, with independently configured contexts and bounded counters.
- **Clear failure semantics**: invalid configuration and unavailable infrastructure fail visibly instead of silently switching strategies.
- **No transport coupling**: the same services work in an HTTP controller, an application operation, a registered service, or a CLI command.

---

## What this package is

`citomni/infrastructure` is CitOmni's shared, App-aware technical infrastructure package.

It answers questions such as:

- How does a repository execute a parameterized query against the application's database?
- How does an adapter turn `1.234,50` into a precise SQL `DECIMAL` string?
- Where does a component write an operational event that can be parsed later?
- How does a service read an SMTP password without placing it in the regular config tree?
- How does an application obtain a translation from its own or a dependency's language files?
- How does an operation call an external API and receive a consistent response shape?
- How does a mail workflow compose and send a message?
- How does an authentication workflow limit repeated failed attempts?

It does not decide which SQL tables represent a domain, which event warrants an email, which response an HTTP client receives, or which account is permitted to perform an action.

---

## What this package provides

| Service ID | Service | Purpose |
|---|---|---|
| `db` | `Db` | MySQLi connection, prepared queries, CRUD helpers, transactions |
| `valueToSql` | `ValueToSql` | Validate and normalize input for SQL storage |
| `valueFromSql` | `ValueFromSql` | Convert SQL values to localized, presentation-ready values |
| `log` | `Log` | JSONL logging, file locking, rotation, retention |
| `secrets` | `Secrets` | Read environment-specific application secrets |
| `txt` | `Txt` | Resolve language-file strings and placeholders |
| `curl` | `Curl` | Execute outbound HTTP requests |
| `mailer` | `Mailer` | Compose and send email through PHPMailer |
| `bruteForce` | `BruteForce` | Persist and evaluate failed-attempt counters |
| `formatNumber` | `FormatNumber` | Deprecated decimal conversion compatibility service |

All ten IDs are registered in both the HTTP and CLI maps. `formatNumber` remains available for existing consumers, but new code should use `valueToSql` and `valueFromSql`.

---

## What this package owns

The package owns its technical service contracts and their shared mechanics:

- Database connection setup, query execution, prepared-statement reuse, and transaction control.
- Locale-aware parsing and formatting for supported SQL-compatible values.
- Log file creation, structured event serialization, rotation, and retention.
- App-local secrets loading and validation.
- Language-file resolution, caching, and placeholder replacement.
- cURL request construction, response normalization, and transport diagnostics.
- PHPMailer integration, message state, SMTP configuration, and mail diagnostics.
- Brute-force counter rules and persistence through its own repositories.
- Infrastructure-specific exceptions and a bounded transaction-isolation enum.

The package's provider contributes services and configuration, **not routes, controllers, commands, or templates**.

---

## What this package does not own

`citomni/infrastructure` deliberately does **not** implement:

- HTTP routing, requests, responses, sessions, cookies, or CSRF protection.
- CLI command dispatch or scheduler registration.
- User identity, login workflows, MFA, permissions, or authorization.
- Domain-specific repositories, database schemas, or business decisions.
- Database migrations for host applications.
- An ORM, entity manager, or application query builder.
- A generic template engine or HTML escaping for email content.
- Incoming HTTP uploads or file-storage policy.
- Application-specific retry policies, API rate limits, or queue processing.
- Secret rotation or an external secret-management server.
- Automatic database installation merely because the Composer package is present.

A general database connection belongs here; an application's SQL belongs in that application's Repository classes. Similarly, the mailer transports a composed message, while an Operation decides whether a customer should receive it.

---

## Relationship to other CitOmni packages

```text
citomni/kernel
      ↑
citomni/infrastructure
      ↑
application and higher-level packages
      │
      ├── citomni/http        (optional HTTP transport)
      ├── citomni/cli         (optional CLI transport)
      └── citomni/authenticate (optional identity workflows)
```

`citomni/kernel` provides the `App`, the provider composition model, and the base service/repository contracts. Infrastructure uses those contracts but does not require the HTTP or CLI package.

`citomni/authenticate` and application-specific login flows may use `bruteForce`, `db`, and `secrets`; infrastructure itself does not know what a login session or authenticated user is.

---

## Requirements

- PHP **8.5+**.
- Composer autoloading.
- `citomni/kernel` **^1.0**.
- PHP extensions `ext-curl` and `ext-mysqli`.
- `phpmailer/phpmailer` **^6.9**.

Optional integrations and performance aids:

- `ext-iconv` or `ext-mbstring` for improved mailer text normalization.
- `ext-opcache` for production performance.
- `citomni/http`, `citomni/cli`, or `citomni/authenticate` according to the host application's needs.
- A reachable MySQL/MariaDB server for `db` and `bruteForce`. Database access is lazy, so unrelated services do not need to open a database connection.

The database implementation targets the MySQL/MariaDB dialect. Transaction-isolation handling is written for MySQL 8.0.16+ and MariaDB 10.6+.

Most buffered read operations require **mysqlnd**. Where that extension is unavailable, `selectNoMysqlnd()` provides a streaming alternative with specific lifetime constraints.

---

## Installation

Install the package as a Composer dependency of the application:

```bash
composer require citomni/infrastructure
```

Register the provider in the application's `config/providers.php`:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\Infrastructure\Boot\Registry::class,
];
```

Merge this entry with the application's existing providers rather than replacing registrations for other packages.

Services are available through the normal CitOmni lazy service map:

```php
$this->app->db;
$this->app->valueToSql;
$this->app->valueFromSql;
$this->app->log;
$this->app->secrets;
$this->app->txt;
$this->app->curl;
$this->app->mailer;
$this->app->bruteForce;
```

The provider does not automatically create a database, install the brute-force table, define the application's language, or provision secret values. Configure the features you intend to use, as described below.

---

## Quick start

A typical application can use the package without instantiating service classes directly.

### Normalize form values

```php
$price = $this->app->valueToSql->decimal('1.234,50', required: true);
$units = $this->app->valueToSql->integer('12', required: true, min: 1);

// $price === '1234.50'
// $units === 12
```

### Store values from a Repository

SQL belongs in the application's Repository layer:

```php
$id = $this->app->db->insert('products', [
	'name' => 'Example product',
	'price' => $price,
	'units' => $units,
]);

$product = $this->app->db->fetchRow(
	'SELECT id, name, price, units FROM products WHERE id = ?',
	[$id]
);
```

`products` is an illustrative **application-owned** table; the package does not create it.

### Format a database value

```php
$displayPrice = $this->app->valueFromSql->decimal($product['price']);

// With the default locale.format config: '1.234,50'
```

### Log an event

```php
$this->app->log->write('catalog.jsonl', 'product.created', [
	'product_id' => $id,
]);
```

### Call an external HTTP API

```php
$response = $this->app->curl->execute([
	'url' => 'https://api.example.com/products',
	'query' => ['limit' => 10],
]);

if ($response['is_http_success']) {
	$payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
}
```

These examples demonstrate the service boundaries. They are not a complete application workflow, and no request/response objects, routes, or table schemas are supplied by this package.

---

## Public API at a glance

The service map is the intended entry point. These are the primary methods currently exposed.

| Service | Public methods |
|---|---|
| `db` | `fetchValue()`, `fetchRow()`, `fetchAll()`, `countRows()`, `exists()`, `select()`, `selectNoMysqlnd()`, `execute()`, `executeMany()`, `insert()`, `insertBatch()`, `update()`, `delete()`, `queryRaw()`, `queryRawMulti()`, `beginTransaction()`, `commit()`, `rollback()`, `transaction()`, `easyTransaction()`, `checkConnection()`, `ensureConnection()`, `reconnect()`, `lastInsertId()`, `affectedRows()`, `countQueries()`, `getLastError()`, `getLastErrorCode()`, `setStatementCacheLimit()`, `clearStatementCache()`, `close()` |
| `valueToSql` | `integer()`, `decimal()`, `boolean()`, `date()`, `time()`, `dateTime()`, `text()`, `enum()`, `json()` |
| `valueFromSql` | `integer()`, `decimal()`, `boolean()`, `date()`, `time()`, `dateTimeLocal()`, `text()`, `json()` |
| `log` | `write()`, `setDir()`, `setMaxFileSize()`, `setMaxRotatedFiles()` |
| `secrets` | `has()`, `get()` |
| `txt` | `get()` |
| `curl` | `execute()` |
| `mailer` | `from()`, `to()`, `cc()`, `bcc()`, `replyTo()`, `subject()`, `body()`, `altBody()`, `attach()`, `templateVars()`, `setTemplate()`, `send()`, `logEmails()`, `resetMessage()`, `refreshConfig()`, `getLastErrorMessage()`, `getLastErrorContext()` |
| `bruteForce` | `assertStorageReady()`, `status()`, `record()`, `clear()`, `prune()` |
| `formatNumber` | `toDb()`, `fromDb()` — deprecated |

The following sections explain parameters, result shapes, configuration, and failure behavior that matter to consumers.

---

## Database service

`$this->app->db` provides a MySQLi-based database connection and a deliberately small query API. SQL stays explicit, parameters are bound separately, and convenience write methods cover ordinary table operations without introducing an ORM.

### Connection lifecycle

A `Db` instance is obtained lazily through the App service map. It reads configuration at initialization and connects on first database operation, not simply because the application has registered the provider.

The connection setup supports:

- Host, port, optional socket, username, and database name.
- Character set, defaulting to `utf8mb4`.
- An optional SQL mode.
- Explicit connection/session time zone, or the PHP application's configured time zone.
- A configurable connect timeout.
- A bounded prepared-statement cache.

The database password is read from the `Secrets` service at connection time. By default the key is `db.password`, with `db.password_secret` available to select another secret key.

**Do not put `db.pass` in app configuration.** The database service explicitly rejects that legacy setting. Put the password in the secret store instead.

The service sets PHP's process-wide MySQLi error reporting to `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`. This is intentional, but applications using MySQLi directly should be aware that the setting affects the process rather than one connection.

### Reading data

Use positional `?` placeholders for data values:

```php
// Inside an application Repository.
$email = 'customer@example.com';

$customer = $this->app->db->fetchRow(
	'SELECT id, email, status FROM customers WHERE email = ?',
	[$email]
);

$customerId = $this->app->db->fetchValue(
	'SELECT id FROM customers WHERE email = ?',
	[$email]
);

$rows = $this->app->db->fetchAll(
	'SELECT id, email FROM customers WHERE status = ? ORDER BY id',
	['active']
);
```

`fetchValue()` returns the first column of the first row, or `null`; `fetchRow()` returns one associative row, or `null`; `fetchAll()` returns an array of associative rows. The methods release their result resources for the caller.

For a result that should be iterated manually:

```php
$result = $this->app->db->select(
	'SELECT id, email FROM customers WHERE status = ?',
	['active']
);

try {
	while ($row = $result->fetch_assoc()) {
		// Process one row.
	}
} finally {
	$result->free();
}
```

`select()` returns a `mysqli_result` and **the caller must free it**. It requires `mysqlnd`.

`selectNoMysqlnd()` is the streaming alternative:

```php
foreach ($this->app->db->selectNoMysqlnd(
	'SELECT id, email FROM customers WHERE status = ?',
	['active']
) as $row) {
	// Consume this row before advancing the generator.
}
```

The yielded rows are backed by MySQLi bound-result references. Process each row in the loop; **do not retain the yielded arrays** across iterations without copying their scalar values. Avoid concurrent streaming generators on the same connection.

For existence checks, `exists($table, $where, $params)` accepts a **trusted SQL condition**, not arbitrary untrusted text:

```php
$exists = $this->app->db->exists('customers', 'email = ?', [$email]);
```

### Writing data

```php
$id = $this->app->db->insert('customers', [
	'email' => 'customer@example.com',
	'status' => 'active',
]);

$changed = $this->app->db->update(
	'customers',
	['status' => 'inactive'],
	'id = ?',
	[$id]
);

$deleted = $this->app->db->delete('customers', 'id = ?', [$id]);
```

`insert()` returns the insert ID. `update()` and `delete()` return affected row counts and require a non-empty `WHERE` expression. Table and column identifiers used by the helpers are validated and quoted; caller-provided conditions and raw SQL fragments remain the caller's responsibility.

For a custom statement, use `execute()`:

```php
$changed = $this->app->db->execute(
	'UPDATE customers SET status = ? WHERE id = ?',
	['inactive', $id]
);
```

`executeMany($sql, $paramSets)` executes the same prepared SQL with multiple parameter sets. `insertBatch($table, $rows)` inserts rows with the same column layout, using a bounded multi-row statement when practical and smaller executions for larger inputs. The bulk path may use its own transaction and is **not a nested-transaction facility**; plan surrounding transactions accordingly.

`queryRaw()` and `queryRawMulti()` execute unparameterized SQL. They are intended for trusted internal statements, not user-provided values; result resources returned by these low-level methods must be released by the caller.

### Transactions

Use `transaction()` for ordinary units of work:

```php
use CitOmni\Infrastructure\Enum\TransactionIsolation;
use CitOmni\Infrastructure\Service\Db;

$orderId = $this->app->db->transaction(
	static function (Db $db) use ($order, $lines): int {
		$orderId = $db->insert('orders', $order);

		foreach ($lines as $line) {
			$db->insert('order_lines', [
				'order_id' => $orderId,
				'product_id' => $line['product_id'],
				'quantity' => $line['quantity'],
			]);
		}

		return $orderId;
	},
	TransactionIsolation::ReadCommitted
);
```

The callback receives the current `Db` instance. A successful callback commits and returns its result. A thrown exception triggers rollback; if rollback succeeds, the original exception is rethrown.

An isolation argument applies to **that transaction only**. Available enum cases are `ReadUncommitted`, `ReadCommitted`, `RepeatableRead`, and `Serializable`.

Manual `beginTransaction()`, `commit()`, and `rollback()` are also available. **Nested transactions are not supported.** `easyTransaction()` is a legacy alias of `transaction()`.

### Statement cache and connection controls

Prepared statements are cached per database service instance, with a default capacity of 128. The bounded cache avoids repeatedly preparing identical SQL in a long-running job while keeping resource usage predictable.

```php
$this->app->db->setStatementCacheLimit(64);
$this->app->db->clearStatementCache();

$count = $this->app->db->countQueries();
$this->app->db->countQueries(reset: true);
```

A limit of `0` disables the cache. `close()` closes the database connection. `checkConnection()`, `ensureConnection()`, and `reconnect()` expose explicit connection-liveness behavior; a stale connection must not be silently replaced in the middle of an active transaction.

Connection errors use `DbConnectException`, while SQL execution failures use `DbQueryException`. Query errors include diagnostic SQL and parameter **types**, not interpolated parameter values. Avoid putting credentials directly into SQL literals, because SQL text itself may appear in diagnostics.

`DbQueryException` also provides `isDuplicateEntry()` for a MySQL/MariaDB duplicate-key violation (1062) and `isDeadlock()` for an actual deadlock (1213). A lock-wait timeout (1205) is **not** classified as a deadlock. These methods identify failure types; the calling Operation still owns any safe retry or conflict-resolution policy.

---

## Values between forms and SQL

The two value services are deliberately asymmetric:

- `valueToSql` validates potentially untrusted input and produces storage-ready values.
- `valueFromSql` validates database values and produces strings or types appropriate for display and HTML inputs.

Neither service executes SQL. They are useful at transport boundaries and in focused input/output adaptation code, while repositories remain responsible for persistence.

### ValueToSql

```php
$values = [
	'quantity' => $this->app->valueToSql->integer('1.200', required: true),
	'amount' => $this->app->valueToSql->decimal('1.234,50', required: true, scale: 2),
	'active' => $this->app->valueToSql->boolean('yes', required: true),
	'date' => $this->app->valueToSql->date('08-10-2026', required: true),
	'time' => $this->app->valueToSql->time('13:45'),
	'datetime' => $this->app->valueToSql->dateTime('2026-10-08T13:45'),
	'code' => $this->app->valueToSql->enum('new', ['new', 'paid']),
	'name' => $this->app->valueToSql->text('  Example  ', maxLen: 100),
	'metadata' => $this->app->valueToSql->json(['source' => 'web']),
];
```

With the default locale, these become `1200`, `'1234.50'`, `1`, `'2026-10-08'`, `'13:45:00'`, `'2026-10-08 13:45:00'`, `'new'`, `'Example'`, and valid JSON, respectively.

| Method | Input / output contract |
|---|---|
| `integer($value, $required=false, $min=PHP_INT_MIN, $max=PHP_INT_MAX, $allowNegative=true)` | Strict localized integer to PHP `int` or `null`; enforces bounds |
| `decimal($value, $required=false, $scale=2, $allowNegative=true)` | Strict localized decimal to precise dot-decimal SQL `string` or `null` |
| `boolean($value, $required=false)` | Recognized boolean input to `0`, `1`, or `null` |
| `date($value, $required=false)` | ISO or supported localized date to `YYYY-MM-DD` or `null` |
| `time($value, $required=false)` | `HH:MM[:SS]` to `HH:MM:SS` or `null` |
| `dateTime($value, $required=false)` | Local ISO-style date/time to SQL `YYYY-MM-DD HH:MM:SS` or `null` |
| `text($value, $required=false, $maxLen=0, $trim=true)` | String with optional trim and **byte-length** limit |
| `enum($value, $allowed, $required=false)` | Exact, case-sensitive membership check |
| `json($value, $required=false)` | Validate a JSON string or encode a PHP array/object |

`decimal()` is especially important for monetary data. **String input is never silently rounded.** Too many fractional digits are rejected instead. Native float input follows an explicit half-up rounding path, but floats are approximate; use strings for decimal amounts that require exactness.

The `$required` flag distinguishes absent optional values from invalid non-empty values. A malformed value raises `ValueToSqlException`, not `null`.

### ValueFromSql

```php
$display = [
	'quantity' => $this->app->valueFromSql->integer('1200'),
	'amount' => $this->app->valueFromSql->decimal('1234.50'),
	'active' => $this->app->valueFromSql->boolean(1),
	'date' => $this->app->valueFromSql->date('2026-10-08', format: 'DD-MM-YYYY'),
	'time' => $this->app->valueFromSql->time('13:45:00'),
	'datetime' => $this->app->valueFromSql->dateTimeLocal('2026-10-08 13:45:00'),
	'name' => $this->app->valueFromSql->text('  Example  '),
	'metadata' => $this->app->valueFromSql->json('{"source":"web"}'),
];
```

With the default locale, the important results are `'1.200'`, `'1.234,50'`, `true`, `'08-10-2026'`, `'13:45'`, `'2026-10-08T13:45'`, the untrimmed text, and a PHP array.

| Method | Output contract |
|---|---|
| `integer()` | Localized integer string, or `null`; supports large SQL integer strings without converting them to native int |
| `decimal()` | Localized decimal string, or `null`; grouping, scale, trailing zeros, and rounding can be overridden per call |
| `boolean()` | PHP `bool` or `null` from a valid SQL boolean representation |
| `date()` | String in `YYYY-MM-DD`, `DD-MM-YYYY`, `DD/MM/YYYY`, or `MM/DD/YYYY` format |
| `time()` | `HH:MM` or `HH:MM:SS` according to config or per-call override |
| `dateTimeLocal()` | `YYYY-MM-DDTHH:MM` or `YYYY-MM-DDTHH:MM:SS` for HTML datetime-local fields |
| `text()` | Original non-empty SQL string, preserving whitespace, or `null` |
| `json()` | Decoded PHP array, or `null`; rejects JSON scalar values |

`dateTimeLocal()` changes **format**, not time zone. It does not convert UTC timestamps to the application's local time zone. SQL `DATETIME` values have no zone metadata; any required conversion belongs in the calling application.

For `valueFromSql->decimal()`, the default policy for SQL decimal **strings** is to fail rather than silently round when reducing precision. Zeros beyond the scale carry no value and are dropped first, so with scale 2 `"1.500"` becomes `"1,50"`, while `"1.505"` fails. Explicit alternatives are `truncate` and `half_up`. These choices are defined under `locale.format`.

### Validation errors and fields

Conversion errors use `ValueToSqlException` and `ValueFromSqlException`. They provide stable message keys and parameter data for an adapter that wants to turn a validation failure into a localized form message.

```php
use CitOmni\Infrastructure\Exception\ValueToSqlException;

try {
	$amount = $this->app->valueToSql->decimal('not-a-number', required: true);
} catch (ValueToSqlException $e) {
	$fieldError = $e->withField('amount');
	$messageKey = $fieldError->getMessageKey();
	$messageParams = $fieldError->getMessageParams();
}
```

Catch such exceptions where the failure is **recoverable as a user-input error**. Invalid service configuration and programmer mistakes should normally fail fast. `ValueConfigurationException` and `ValueDefinitionException` describe those other failure categories.

---

## Deprecated FormatNumber service

`$this->app->formatNumber` remains registered for existing consumers, but `Registry` explicitly marks it deprecated. Prefer the two directional value services for new applications.

The legacy methods are:

```php
$sql = $this->app->formatNumber->toDb('1.234,50', precision: 10, scale: 2);
$text = $this->app->formatNumber->fromDb('1234.50', scale: 2);

// $sql === '1234.50'
// $text === '1.234,50'
```

`toDb()` supports comma-decimal UI numbers with either dots or ordinary ASCII spaces for thousands, checks `DECIMAL(precision, scale)` limits, and does not silently round. `fromDb()` accepts a dot-decimal SQL string and formats it with explicitly chosen separators. Unlike the current value services, its input conventions are fixed and it uses `Txt` for some error messages.

For new code, use:

```php
$sql = $this->app->valueToSql->decimal('1.234,50', scale: 2);
$text = $this->app->valueFromSql->decimal('1234.50', scale: 2);
```

The newer services use the shared `locale.format` policy and return structured value exceptions. They do **not** reproduce every legacy calling convention automatically; review locale rules and null/empty-value semantics when migrating.

---

## JSONL logging

`$this->app->log` writes one JSON object per line. This format can be processed with standard text tools and structured log collectors without special parsing of free-form messages.

```php
$this->app->log->write('payments.jsonl', 'payment.received', 'Payment accepted', [
	'payment_id' => 123,
	'currency' => 'DKK',
]);
```

A representative line:

```json
{"timestamp":"2026-10-08T18:00:00+02:00","category":"payment.received","message":"Payment accepted","context":{"payment_id":123,"currency":"DKK"}}
```

The timestamp comes from the runtime. The example above is illustrative, not a promise of a particular timestamp.

The public method is:

```php
$this->app->log->write(?string $file, string $category, string|array|object $message, array $context = []);
```

`$file = null` or `''` uses the configured default. A filename is a **flat basename**, never a directory path. Valid names are normalized to a `.jsonl` extension, including the registry's `citomni_app.log` default, which therefore writes to `citomni_app.jsonl`.

### Rotation and retention

- Default directory is `CITOMNI_APP_PATH . '/var/logs'`.
- Default maximum active file size is `2_000_000` bytes.
- Default retention is ten rotated files per filename family; `null` disables pruning.
- Each file uses a separate `.lock` sidecar and an exclusive file lock for append/rotation coordination.
- Rotation happens when necessary before and after append; archives receive timestamped filenames.
- A failed log write or rotation raises a log-specific exception. Failed archive pruning is non-fatal housekeeping.

These limits can also be changed at runtime:

```php
$this->app->log->setMaxFileSize(4_000_000);
$this->app->log->setMaxRotatedFiles(20);
$this->app->log->setDir('/var/app/logs', autoCreate: true);
```

`setDir()` requires an existing writable directory unless `autoCreate: true` is passed. The file-size limit must be at least 1,024 bytes; the retained archive count must be at least one or `null`.

Do not log passwords, tokens, full session data, or personal information that the operation does not need. JSONL is a structure and rotation policy, **not a data-classification or encryption system**.

---

## Application secrets

`$this->app->secrets` reads an app-local, environment-specific PHP file. This separates credentials from normal application configuration and avoids inadvertently exposing them through configuration dumps.

The active environment comes from `CITOMNI_ENVIRONMENT`:

| Environment | App-local secret file |
|---|---|
| `dev` | `var/secrets/app.secret.dev.php` |
| `stage` | `var/secrets/app.secret.stage.php` |
| `prod` | `var/secrets/app.secret.prod.php` |

An example `var/secrets/app.secret.dev.php`:

```php
<?php
declare(strict_types=1);

return [
	'db.password' => 'local-database-password',
	'mail.smtp.password' => 'local-smtp-password',
	'external_api.token' => 'local-api-token',
];
```

The file must return a **flat `array<string, string>`**. Dots in keys are namespacing characters, not nested arrays. The file is application-owned; keep real secrets outside version control and the public document root.

```php
$token = $this->app->secrets->get('external_api.token');

if ($this->app->secrets->has('external_api.optional_token')) {
	$optionalToken = $this->app->secrets->get('external_api.optional_token');
}
```

- `has()` checks the exact key and considers an empty string to be an existing value.
- `get()` returns the string or throws `OutOfBoundsException` for a missing key.
- Loading is deferred until first access and cached for the current App instance.
- A missing file is an empty store; an existing malformed/unreadable file fails visibly.
- There is **no fallback** from production to staging or development secrets.
- A newly started App instance sees rotated secrets; a previously loaded instance keeps its values.

The package contains `install/manifest.php`, which describes create-only scaffolding for the three files. This manifest is **not** a promise that a bare `composer require` has generated or populated them.

For normal deployments, provision the correct file, restrict its filesystem permissions, and verify that the active environment is the one intended. Database and SMTP credentials are consumed by their services only when needed.

---

## Text and localization

`$this->app->txt` reads PHP language files from either the host application or an installed package.

The application must define a valid `locale.language` such as `da` or `en`. The infrastructure registry defines `locale.format` defaults but **does not define `locale.language`**.

An application file at `language/da/messages.php` might contain:

```php
<?php
declare(strict_types=1);

return [
	'hello' => 'Hej %NAME%',
	'saved' => 'Ændringerne er gemt.',
];
```

Look up text with:

```php
$hello = $this->app->txt->get('hello', 'messages', 'app', 'Hej', [
	'name' => 'Lars',
]);

// 'Hej Lars'
```

The signature is:

```php
$this->app->txt->get($key, $file, $layer = 'app', $default = '', $vars = []);
```

- `app` resolves `CITOMNI_APP_PATH/language/<language>/<file>.php`.
- A layer such as `vendor/package` resolves `CITOMNI_APP_PATH/vendor/vendor/package/language/<language>/<file>.php`.
- `$file` may contain safe relative subdirectories, for example `emails/welcome`.
- Placeholder names are uppercased, so `['name' => 'Lars']` replaces `%NAME%`. Substitutions apply to fallback strings too.
- Files are loaded and cached per Txt service instance.
- Missing files, missing/empty keys, and unusable values fall back to `$default` and are logged.
- There is no automatic language fallback chain.

**Current logging detail**: `Txt` currently writes diagnostics through the `log` service to `txt.jsonl`. The registry still exposes older `txt.log.file` and `txt.log.path` defaults, but the current `Txt` implementation does **not** use those settings to select its log destination. Log placement follows the `log` service's directory.

Translation values are plain strings, not pre-escaped HTML. Escape them appropriately when inserting them into HTML, attributes, JavaScript, or another output context.

---

## Outbound HTTP with cURL

`$this->app->curl->execute()` accepts one explicit request array and returns a normalized array for both successful and unsuccessful HTTP status codes.

```php
$response = $this->app->curl->execute([
	'url' => 'https://api.example.com/v1/orders',
	'method' => 'GET',
	'headers' => [
		'Accept: application/json',
	],
	'query' => [
		'page' => 1,
		'api_key' => $this->app->secrets->get('external_api.token'),
	],
	'sensitive_query_keys' => ['api_key'],
]);

if (!$response['is_http_success']) {
	// Application-specific handling of a non-2xx HTTP response.
}
```

The secret query value is sent to the server unchanged, but its configured key is redacted from URL metadata, diagnostics, and exceptions. The list is **case-sensitive** and applies to top-level query keys; it is not a generic detector for secrets in arbitrary URLs, headers, or bodies.

### Request options

`url` is required. All other request keys are optional, and unknown keys are rejected.

| Key | Meaning / default |
|---|---|
| `url` | Request URL string; normally an absolute HTTP(S) URL; required |
| `method` | HTTP verb, default `GET` |
| `headers` | Raw `Header-Name: value` lines; no CR/LF/NUL injection |
| `body` | Raw request-body string, or `null` |
| `query` | Array added to the URL using RFC 3986 query encoding |
| `sensitive_query_keys` | Top-level query keys redacted from exposed URL metadata |
| `timeout` | Total transfer timeout, default 30 seconds; fractions supported |
| `connect_timeout` | Connection timeout, default 10 seconds |
| `follow_redirects` | `false` by default |
| `max_redirects` | 10 by default, relevant when redirects are enabled |
| `user_agent` | `CitOmni cURL` by default |
| `verify_peer`, `verify_host` | TLS certificate/hostname verification; enabled by default |
| `ca_info`, `proxy` | Optional CA bundle and proxy |
| `cookie_store` | Combined read/write cookie-jar file |
| `cookie_file`, `cookie_jar` | Separate cookie input/output files |
| `return_headers` | Capture/parse response headers, default `true` |
| `capture_info` | Include cURL transfer information, default `true` |
| `auto_referer`, `referer` | Redirect referer behavior and explicit referer |
| `basic_auth`, `bearer_token` | Authentication options; not to be combined with conflicting Authorization headers |
| `curl_options` | Advanced `CURLOPT_*` overrides applied last |
| `log_file`, `log_context` | Per-request logging file and structured context |

Raw `curl_options` are an escape hatch. Because they are applied last, they can override transport and security defaults; use only trusted, reviewed option sets.

### Response contract

```php
[
	'request' => [
		'method' => 'GET',
		'url' => 'https://api.example.com/v1/orders?page=1',
	],
	'status_code' => 200,
	'is_http_success' => true,
	'headers_raw' => "HTTP/1.1 200 OK\r\n...",
	'headers' => ['content-type' => 'application/json'],
	'body' => '{"orders":[]}',
	'body_bytes' => 13,
	'effective_url' => 'https://api.example.com/v1/orders?page=1',
	'content_type' => 'application/json',
	'info' => ['http_code' => 200],
]
```

This is an illustrative shape; metadata and byte counts depend on the actual request. `headers` uses lowercase names from the final response header block, with repeated headers represented as lists where necessary. `headers_raw` contains received response heads; `info` contains the full cURL transfer metadata when enabled (omitted in this abbreviated example).

**HTTP 4xx/5xx is not a transport exception.** Inspect `status_code` or `is_http_success`. cURL configuration and transport failures raise `CurlConfigException` and `CurlExecException` respectively.

The package does not decode JSON automatically, retry failed requests, enforce application API budgets, or decide whether a particular non-2xx response should be retried.

### Connection reuse and logging

With `curl.reuse_connections = true`, the service shares relevant cURL connection/DNS state across calls within the same App lifetime. This avoids unnecessary setup for repeated outbound requests. It is not a cross-process pool.

Transport errors are logged by default and completed requests are not. Enable success logs deliberately if required. A completed HTTP 500 is a completed HTTP transfer, not a `CurlExecException`.

Do not pass attacker-controlled URLs to cURL without application-level scheme/host/IP restrictions. The service checks that `url` is a non-empty string but **does not itself enforce an HTTP(S)-only scheme or an allowed host list**. libcurl protocol support, DNS resolution, redirects, and proxy configuration can otherwise enable server-side request forgery (SSRF).

---

## Email with Mailer

`$this->app->mailer` wraps PHPMailer in a reusable, fluent service. It supports SMTP, PHP `mail()`, Sendmail, and Qmail transports.

Configure `mail.from` and the selected transport before sending. SMTP credentials belong in the secret store, **not** under `mail.smtp.password` in the App config; the legacy config key is explicitly rejected.

```php
$sent = $this->app->mailer
	->to('customer@example.com', 'Customer')
	->subject('Your receipt')
	->body('<p>Thank you for your order.</p>', isHtml: true)
	->altBody('Thank you for your order.')
	->send();

if (!$sent) {
	$error = $this->app->mailer->getLastErrorMessage();
	// Let the owning workflow decide how to recover.
}
```

`send()` returns `bool` for PHPMailer send success/failure. Infrastructure errors outside that recoverable send path can still throw; do not assume every failure becomes `false`.

### Recipients and message composition

Available fluent methods include:

```php
$mailer = $this->app->mailer;

$mailer
	->from('no-reply@example.com', 'Example App')
	->to('first@example.com')
	->cc(['audit@example.com', 'team@example.com'])
	->bcc([['email' => 'archive@example.com', 'name' => 'Archive']])
	->replyTo('support@example.com')
	->subject('Report')
	->body('<p>Attached is your report.</p>', isHtml: true)
	->attach('/path/to/report.pdf', 'report.pdf');

$sent = $mailer->send();
```

A successful or failed `send()` resets the message's recipients, attachments, body, and template variables, so the App-scoped service can be reused for a subsequent message. SMTP keepalive, when enabled, concerns the connection, not reusing the previous message.

### Simple templates

`templateVars()` defines `{name}`-style replacements for subsequent body composition:

```php
$sent = $this->app->mailer
	->to('customer@example.com')
	->subject('Welcome')
	->templateVars(['name' => 'Customer'])
	->body('<p>Hello {name}!</p>')
	->send();
```

`setTemplate($fileOrString, $vars = [], $isFile = true, $isHtml = null)` can also load a readable file or process a literal template string:

```php
$mailer->setTemplate(
	'<p>Hello {name}!</p>',
	['name' => 'Customer'],
	isFile: false,
	isHtml: true
);
```

This is **literal string substitution**, not CitOmni's general TemplateEngine. It performs no automatic HTML escaping. HTML-escape untrusted variables before inserting them into an HTML template; use plain-text-safe substitution for text messages.

### Transport setup and diagnostics

Default transport is `smtp`, with port 587, authentication enabled, a 15-second SMTP timeout, and keepalive disabled. Set an SMTP host, username, sender, and `mail.smtp.password` secret for authenticated SMTP. TLS may be explicitly selected with `smtp.encryption = 'tls'` (STARTTLS) or `'ssl'`; the package does not assume that a port number alone chooses the encryption mode.

`mail.logging` controls operational diagnostics. Successful-mail logging is constrained to development and normally off. Error diagnostics may contain message metadata and, depending on environment and policy, content previews. **In development, a failed send records a short body preview even when `include_bodies` is false.** Treat mail logs as potentially sensitive. Avoid enabling `include_bodies` or SMTP transcripts without an explicit reason and access/retention controls.

Use `getLastErrorMessage()` or `getLastErrorContext()` when `send()` returns `false`. Call `resetMessage()` to discard an unfinished composition; `refreshConfig()` reapplies service configuration when the host intentionally requires it.

---

## Brute-force protection

`$this->app->bruteForce` provides database-backed failed-attempt tracking independent of HTTP routing and authentication policy.

It evaluates two subjects separately:

1. An **identifier**, such as a username or email address.
2. A **client IP address**, including a shared address used by multiple accounts.

Each configured context has its own thresholds and time windows. Only `default` is supplied in the baseline registry. An application that wishes to call `status('login', ...)` must define a `login` context first.

### Required database table

The service requires the `bruteforce_counters` table. Its schema is supplied in:

```text
sql/citomni_bruteforce.sql
```

**Important deployment warning:** This file is a MariaDB SQL dump and currently contains `DROP TABLE IF EXISTS bruteforce_counters`. Running it against a populated database **deletes existing brute-force counters**. Review the DDL, turn it into a controlled one-time migration, and never treat the dump as an idempotent production setup command.

The package does not create this table when the provider boots. An explicit readiness check is available:

```php
$this->app->bruteForce->assertStorageReady();
```

### Protect an action

Configure a `login` context under `security.bruteforce`, then use the service in the operation that owns the authentication attempt:

```php
$guard = $this->app->bruteForce->status('login', $email, $ip);

if ($guard['blocked']) {
	// The HTTP/CLI adapter decides how to report the delay.
	$retryAfter = $guard['retry_after_seconds'];
} else {
	// Attempt the protected operation.
	if (!$credentialsValid) {
		$this->app->bruteForce->record('login', $email, $ip);
	} else {
		$this->app->bruteForce->clear('login', $email);
	}
}
```

`status()` only reads counters; `record()` writes an unsuccessful attempt; `clear()` removes the specified buckets. **Record failures only**, and do not rely on `record()` to perform a preceding block check.

On a successful login, clearing the identifier bucket only is generally safer than clearing the IP bucket, because multiple unrelated users may share an IP behind NAT.

### Status result

```php
[
	'blocked' => false,
	'reason' => null,
	'retry_after_seconds' => 0,
	'blocked_until' => null,
	'identifier_attempts' => 2,
	'ip_attempts' => 3,
	'identifier_remaining' => 3,
	'ip_remaining' => 22,
	'max_identifier_attempts' => 5,
	'max_ip_attempts' => 25,
	'interval_minutes' => 15,
]
```

`reason` is `null`, `identifier`, `ip`, or `both`. `blocked_until` is a Unix timestamp when blocked. The counters and remaining budgets reflect the context configuration and the active attempt window.

Subjects are normalized and SHA-256 hashed before persistence; raw email addresses and IP addresses are not used as stored counter identifiers. This reduces direct exposure but is **not an anonymity guarantee** for guessable identifiers.

The special IP inputs `unknown` and `cli` do not create IP buckets; they can still accompany an identifier. Supply a real client IP when IP throttling matters.

### Cleanup

```php
$deletedRows = $this->app->bruteForce->prune();
```

Run `prune()` periodically from an **application-owned** CLI command or maintenance job. The package does not register a scheduler. Its default pruning age is seven days, with additional handling for orphaned contexts.

The service uses one row per context/subject rather than storing every failed attempt. Its SQL lives in `BruteForceRepository`, with table-readiness checks in `DatabaseSchemaRepository`.

---

## Configuration reference

The provider's `CFG_HTTP` and `CFG_CLI` supply the same baseline nodes. The consuming application may override them through normal CitOmni provider/application configuration composition.

The examples below document **recognized settings** and actual defaults; they are not a request to duplicate the entire registry into each application's config.

### Database — `db`

| Key | Default | Meaning |
|---|---|---|
| `host` | `localhost` | Database host |
| `user` | `citomni` | Username |
| `name` | `citomni` | Database name |
| `charset` | `utf8mb4` | Connection character set |
| `port` | `3306` | Optional TCP port |
| `socket` | `null` | Optional socket path |
| `connect_timeout` | `5` | Connection timeout in seconds |
| `password_secret` | `db.password` | Name of the secret to read on connection |
| `sql_mode` | `null` | Optional SQL session mode |
| `timezone` | PHP time zone | Optional database session time zone |
| `statement_cache_limit` | `128` | Maximum cached prepared statements; `0` disables |

`port`, `socket`, `connect_timeout`, `password_secret`, `sql_mode`, `timezone`, and `statement_cache_limit` are recognized by `Db` even though the baseline registry omits them.

No plaintext database password key belongs in this node.

### Logging — `log`

| Key | Default |
|---|---|
| `path` | `CITOMNI_APP_PATH . '/var/logs'` |
| `default_file` | `citomni_app.log` (normalized to `citomni_app.jsonl`) |
| `max_bytes` | `2_000_000` |
| `max_files` | `10` (or `null` for unlimited) |

### Language — `locale.language` and `txt`

The application must supply `locale.language` for the `txt` service. The package does not prescribe an application language.

Although the registry includes `txt.log.file = 'litetxt_errors.jsonl'` and `txt.log.path`, current missing-text diagnostics are written to `txt.jsonl` through the `log` service instead. Do not configure the legacy keys expecting them to relocate those messages.

### Values — `locale.format`

```php
'locale' => [
	'format' => [
		'decimal_separator' => ',',
		'thousand_separator' => '.',
		'group_thousands' => true,
		'decimal_scale' => 2,
		'decimal_string_rounding' => 'fail',
		'decimal_trim_trailing_zeros' => false,
		'time_include_seconds' => false,
		'datetime_local_include_seconds' => false,
	],
],
```

The decimal and thousands separators must be distinct. The decimal separator is one character; the thousands separator is empty or one character. Decimal scale ranges from 0 to 18. String rounding modes are case-sensitive `fail`, `truncate`, or `half_up`.

`valueToSql` uses the locale separators to parse input. `valueFromSql` also uses the grouping, decimal scale, rounding, trailing-zero, and time/datetime precision defaults. Method arguments can override supported display options per call.

### cURL — `curl`

```php
'curl' => [
	'timeout' => 30,
	'connect_timeout' => 10,
	'follow_redirects' => false,
	'max_redirects' => 10,
	'user_agent' => 'CitOmni cURL',
	'verify_peer' => true,
	'verify_host' => 2,
	'ca_info' => null,
	'proxy' => null,
	'return_headers' => true,
	'capture_info' => true,
	'auto_referer' => true,
	'log_errors' => true,
	'log_success' => false,
	'log_file' => 'curl.jsonl',
	'reuse_connections' => true,
],
```

In addition to registry configuration, the service definition may supply options for `defaults`, `log_errors`, `log_success`, and `log_file`. Per-request options take precedence when supported. Keep TLS verification on for production requests.

### Mail — `mail`

```php
'mail' => [
	'from' => ['email' => '', 'name' => ''],
	'reply_to' => ['email' => '', 'name' => ''],
	'format' => 'html',
	'transport' => 'smtp',
	'smtp' => [
		'host' => '',
		'port' => 587,
		'encryption' => null,
		'auth' => true,
		'username' => '',
		'auto_tls' => true,
		'timeout' => 15,
		'keepalive' => false,
	],
	'logging' => [
		'log_success' => false,
		'debug_transcript' => false,
		'max_lines' => 200,
		'include_bodies' => false,
	],
],
```

The optional `mail.sendmail_path` applies to Sendmail/Qmail transports. SMTP host may be a semicolon-separated list understood by PHPMailer.

For authenticated SMTP, add the secret `mail.smtp.password` to the active app-local secret file. **Do not add that key to the `mail.smtp` config array.**

### Brute-force — `security.bruteforce`

The registry provides a `default` context:

```php
'security' => [
	'bruteforce' => [
		'default' => [
			'max_identifier_attempts' => 5,
			'max_ip_attempts' => 25,
			'interval_minutes' => 15,
			'retry_after_seconds' => 900,
			'prune_after_seconds' => 604800,
		],
	],
],
```

To separate login, MFA, and other contexts, define additional named entries with their own values. Thresholds, windows, and retry delays must be positive; `prune_after_seconds` defaults to seven days where omitted.

The concrete action name is application-owned. `login`, `2fa`, or `password_reset` are not built-in policies; they are simply possible configuration keys.

---

## Error handling

Infrastructure errors represent different kinds of failures and should not all be handled the same way.

| Area | Exceptions / behavior | Intended handling |
|---|---|---|
| Database | `DbConnectException`, `DbQueryException` | Fail fast or recover explicitly at an owning transaction boundary |
| Input conversion | `ValueToSqlException` | Translate recoverable form errors in the transport adapter |
| Output conversion | `ValueFromSqlException` | Treat unexpected stored values as data-integrity problems |
| Conversion setup | `ValueConfigurationException`, `ValueDefinitionException` | Fix configuration/calling code; do not hide errors |
| Logging | `LogConfigException`, `LogDirectoryException`, `LogFileException`, `LogWriteException`, `LogRotationException` | Surface operational failure; do not assume a log event was persisted |
| Secrets | `OutOfBoundsException`, `RuntimeException`, `UnexpectedValueException` | Provision/correct the secret source |
| Text | `TxtConfigException`, `InvalidArgumentException`; missing entries fall back and log | Fix invalid configuration or language resources |
| cURL | `CurlConfigException`, `CurlExecException` | Reject invalid requests or handle transport failure explicitly |
| Mailer | `send()` returns `false` on PHPMailer send failure; other setup/secret errors can throw | Consult last error and decide whether to retry or queue |
| Brute-force | `BruteForceConfigException`, `BruteForceSetupException`, input exceptions | Fix context or persistence installation |

Do not wrap every infrastructure call in a broad `catch (\Throwable)` that silently produces a default result. Catch only where the owning layer has a real recovery path. The framework's global error handler should remain responsible for unexpected failures.

---

## Security and operational boundaries

Some concerns are important precisely because the package exposes low-level capabilities.

- **Prepared parameters protect values, not SQL syntax.** Never interpolate untrusted identifiers, `ORDER BY` fragments, `WHERE` fragments, or raw SQL.
- **Secret files must be private and environment-correct.** `has()` does not validate the business meaning of a secret; `get()` does not supply fallback credentials.
- **Log content is caller-owned.** The logger does not automatically redact arbitrary data. Sanitize or omit sensitive fields before writing.
- **cURL redaction is explicit and scoped.** `sensitive_query_keys` is helpful for diagnostics but does not replace secure URL handling or authorization-header discipline.
- **Mailer templates do not HTML-escape.** Escape in the calling workflow according to the target context.
- **Brute-force counters need controlled schema management.** The provided SQL dump is destructive if reapplied.
- **HTTP status and transport success are different.** An HTTP 429/500 response can be a successfully completed cURL request.
- **Configured services are not permission checks.** An application Operation or Policy still decides who can execute a protected action.

---

## Determinism and performance

The package favors predictable contracts and low work on normal application paths.

- Services are lazy App singletons rather than constructed on every use.
- `Db` does not connect until it is used and reuses prepared statements within its bounded cache.
- `Db::fetchValue()`, `fetchRow()`, and `fetchAll()` release their results; manual streaming remains explicit.
- `ValueToSql` and `ValueFromSql` normalize typed values without hidden database queries or locale globals.
- `Secrets` loads one small file once per App instance, with no network dependency.
- `Txt` caches loaded PHP language files per service instance.
- `Curl` may reuse its cURL connection/DNS state within one process.
- `Log` writes append-only records and isolates contention with per-file locks.
- `BruteForce` uses bounded counter rows rather than an ever-growing history of individual attempts.

External effects—database server behavior, filesystem permissions, SMTP delivery, TLS behavior, and network latency—are not deterministic. The package promises explicit policies and failures, not identical response times or successful delivery from unreliable dependencies.

---

## Current limitations

This package is intentionally smaller than an all-purpose infrastructure framework.

- `Db` targets MySQL/MariaDB and does not implement PostgreSQL, SQLite, ORM entities, or schema migrations.
- Buffered MySQLi reads depend on `mysqlnd`; the streaming alternative has reference/lifetime constraints.
- Nested database transactions are not supported.
- `Txt` has no automatic language fallback chain and no HTML escaping.
- The current `Txt` diagnostic file is fixed to `txt.jsonl` despite legacy registry settings.
- `Curl` has no automatic JSON body handling, retries, or SSRF protection for user-supplied URLs.
- `Mailer` templates use simple token replacement, not the CitOmni TemplateEngine.
- `BruteForce` requires a separately installed table and application-owned maintenance scheduling.
- `formatNumber` is a compatibility service marked deprecated by the registry.

The package also contains legacy model helper files under `src/Model/`. Those are not registered services and should not be confused with the current native `Db` API; in particular, the older LiteMySQLi model depends on a separate legacy class not declared as a runtime Composer requirement.

---

## Testing

Use a **source checkout**, not only the installed Composer distribution, because `tests/` is excluded from the package archive.

Run all included suites from the package root:

```bash
php tests/run.php
```

The runner discovers isolated `tests/*/run.php` suites and database-backed `tests/*/database.php` suites, running each in a separate PHP process. Individual tests can be run directly:

```bash
php tests/registry/run.php
php tests/secrets/run.php
php tests/log/run.php
php tests/txt/run.php
php tests/curl/run.php
php tests/mailer/run.php
php tests/value-to-sql/run.php
php tests/value-from-sql/run.php
php tests/db/database.php
php tests/brute-force/database.php
```

The database suites require a reachable **test** database server and suitable permissions. The test harness uses `CITOMNI_TEST_PASSWORD` for its database connection and `CITOMNI_TEST_PARALLEL=1` where parallel worker coverage is desired. These tests exercise database creation and cleanup; **never point them at a production database**.

The suites cover behavior such as provider maps, secret validation, JSONL locking/rotation, translation resolution, cURL request and redaction semantics, mail transport behavior, value normalization, database transactions, and brute-force concurrency.

There is currently **no `composer test` script** declared in this package's `composer.json`; invoke the test runner explicitly.

---

## Internal structure

The current source layout is organized by responsibility:

```text
src/
├── Boot/
│   └── Registry.php
├── Enum/
│   └── TransactionIsolation.php
├── Exception/
│   ├── BruteForce*.php
│   ├── Curl*.php
│   ├── Db*.php
│   ├── Log*.php
│   ├── TxtConfigException.php
│   └── Value*.php
├── Model/
│   ├── BaseModelLiteMySQLi.php
│   └── LiteDbBenchModel.php
├── Repository/
│   ├── BruteForceRepository.php
│   └── DatabaseSchemaRepository.php
└── Service/
    ├── BruteForce.php
    ├── Curl.php
    ├── Db.php
    ├── FormatNumber.php
    ├── Log.php
    ├── Mailer.php
    ├── Secrets.php
    ├── Txt.php
    ├── ValueFromSql.php
    └── ValueToSql.php

install/
└── manifest.php                  # Secret-file scaffolding metadata
sql/
└── citomni_bruteforce.sql        # Schema dump; review before importing
language/
└── da/format_number.php          # Legacy format-number translations
tests/
└── ...                           # Isolated and DB-backed regression suites
```

The boundaries are deliberate:

- `Boot/Registry.php` registers services and defaults for HTTP and CLI.
- `Service/` contains App-aware reusable capabilities, not application SQL repositories or transport adapters.
- `Repository/` owns SQL for the package's persistent brute-force state.
- `Enum/` provides a closed vocabulary for database isolation choices.
- `Exception/` gives callers stable, infrastructure-specific failure categories.
- `Model/` retains legacy compatibility code, separate from the current service interface.

`Db` is the generic database driver service; domain SQL written by consuming applications still belongs in their own Repository classes.

---

## Coding and architectural principles

`citomni/infrastructure` follows the wider CitOmni contract:

- PHP 8.5+.
- PSR-4 autoloading.
- PascalCase classes, camelCase methods and variables, UPPER_SNAKE_CASE constants.
- Tab indentation and K&R braces.
- English PHPDoc and inline comments.
- Fail fast on invalid configuration and programmer misuse.
- No HTTP-specific or CLI-specific behavior in shared infrastructure services.
- SQL for persistent application features in Repositories.
- No implicit service resolution outside the App service map.
- No abstraction merely to conceal a straightforward native PHP operation.
- Explicit resource ownership, bounded caches, and low runtime overhead.

---

## Coding & Documentation Conventions

All CitOmni packages follow the shared conventions:

[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Infrastructure** is open-source under the **MIT License**.

See [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**. Usage of the name or logo must follow the policy in [NOTICE](NOTICE). Do not imply endorsement or affiliation without prior written permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.

You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.

Do not register or use "citomni" or confusingly similar terms in company names, domains, social handles, or top-level vendor/package names.

For details, see [NOTICE](NOTICE) and [TRADEMARKS.md](TRADEMARKS.md).

---

## Author

Developed by Lars Grove Mortensen (c) 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.
