<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni Infrastructure - Lean cross-mode infrastructure services for CitOmni applications (HTTP & CLI).
 * Source:  https://github.com/citomni/infrastructure
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Infrastructure\Service;

use CitOmni\Kernel\Service\BaseService;
use LiteMySQLi\LiteMySQLi;

/**
 * Db: Thin database facade over a single, lazy LiteMySQLi connection.
 *
 * Responsibilities:
 * - Hold exactly one LiteMySQLi connection per request/process (lazy on first use).
 * - Expose a direct handle via establish()/db() for hot paths.
 * - Proxy unknown methods to LiteMySQLi using __call() for ergonomic call sites.
 *
 * Collaborators:
 * - Reads configuration from App->cfg->db.
 * - Delegates all SQL execution to LiteMySQLi (this class adds no query logic).
 *
 * Configuration keys:
 * - db.host (string, required) - Database host.
 * - db.user (string, required) - Username.
 * - db.pass (string, required) - Password.
 * - db.name (string, required) - Database/schema name.
 * - db.charset (string, required) - Connection charset (e.g., "utf8mb4").
 *
 * Error handling:
 * - Fail fast; this class does not catch. Misconfiguration or connect errors bubble to the global handler.
 *
 * Typical usage:
 * - Use when a model needs to run SQL with minimal overhead and no ORM magic.
 *
 * Examples:
 * 
 *   // 1) Default is lazy: No connection until first use
 *   final class LogOnlyModel extends BaseModelLiteMySQLi {
 *		public function write(string $msg): void {
 *			// No DB touch here; stays purely in memory/IO outside DB
 *		}
 *		public function maybeTouchDb(bool $need): void {
 *			if ($need) {
 *				$conn = $this->establish(); // opens DB only when actually needed
 *				$conn->execute('INSERT INTO logs(msg) VALUES (?)', [$msg]);
 *			}
 *		}
 *   }
 * 
 *	// 2) Eager connect (opt-in): Open DB during construction via init()
 *	final class OrderModel extends BaseModelLiteMySQLi {
 *		protected function init(): void {
 *			$this->establish(); // eager open for hot paths
 *		}
 *		public function find(int $id): ?array {
 *			return $this->db->fetchRow('SELECT * FROM orders WHERE id = ?', [$id]) ?: null;
 *		}
 *	}
 *
 * Failure:
 * - Missing cfg keys or invalid values: OutOfBoundsException/TypeError from Cfg or LiteMySQLi.
 * - Connect/auth/charset errors: Exceptions thrown by LiteMySQLi constructor/methods.
 */
class Db extends BaseService {

	/** @var LiteMySQLi|null Singleton connection handle (created lazily). */
	private ?LiteMySQLi $connection = null;


	/**
	 * Establish a singleton LiteMySQLi connection if not already created.
	 *
	 * Behavior:
	 * - Creates the connection on first call using cfg keys (db.host/user/pass/name/charset).
	 * - Reuses the same connection for subsequent calls within the request/process.
	 * - Returns the live LiteMySQLi handle for direct, fastest possible calls.
	 *
	 * Notes:
	 * - No pooling, no retries, no auto-reconnect policy here (keep it deterministic and cheap).
	 * - Configuration access uses Cfg; missing keys fail fast by design.
	 *
	 * Typical usage:
	 *   Called in hot paths to cache a local $db variable and avoid __call overhead.
	 *
	 * Examples:
	 *
	 *   // First call creates the connection; later calls reuse it
	 *   $db = $this->app->db->establish();
	 *   $user = $db->fetchRow('SELECT * FROM users WHERE id = ?', [$id]);
	 *
	 *   // Idempotent: calling establish() twice returns the same handle
	 *   $a = $this->app->db->establish();
	 *   $b = $this->app->db->establish(); // same instance as $a
	 *
	 * Failure:
	 * - Bubble-up from Cfg or LiteMySQLi if configuration is invalid or connection fails.
	 *
	 * @return LiteMySQLi The live LiteMySQLi connection.
	 * @throws \OutOfBoundsException When required cfg keys are missing.
	 * @throws \Throwable On connect/auth/charset errors from LiteMySQLi.
	 */
	public function establish(): LiteMySQLi {
	
		// Lazy-init the connection exactly once per request/process
		if ($this->connection === null) {
		
			// Read required settings from deep, read-only cfg wrapper
			$this->connection = new LiteMySQLi(
				$this->app->cfg->db->host,
				$this->app->cfg->db->user,
				$this->app->cfg->db->pass,
				$this->app->cfg->db->name,
				$this->app->cfg->db->charset
			);
		}
		return $this->connection;
	}


	/**
	 * Return the live connection, establishing it if needed.
	 *
	 * Behavior:
	 * - Ensures a connection exists by calling establish() when necessary.
	 * - Returns the cached LiteMySQLi instance thereafter.
	 *
	 * Notes:
	 * - Prefer this for readability; prefer establish() if you will call many DB methods in a loop.
	 *
	 * Typical usage:
	 *   Called by services/controllers that need a handle but do not micro-optimize indirection.
	 *
	 * Examples:
	 *
	 *   // Lazy access
	 *   $db = $this->app->db->db();
	 *   $rows = $db->fetchAll('SELECT * FROM articles ORDER BY id DESC LIMIT 10');
	 *
	 *   // Single-shot through __call (see below) is also fine for terse code
	 *   $count = $this->app->db->fetchValue('SELECT COUNT(*) FROM articles');
	 *
	 * Failure:
	 * - Same as establish(); exceptions bubble up unchanged.
	 *
	 * @return LiteMySQLi Live connection instance (never null after establish()).
	 * @throws \Throwable On configuration/connect errors surfaced by establish().
	 */
	public function db(): LiteMySQLi {
		
		// Start off with establishing a connection
		$this->establish();
		
		// Safe: After establish() returns, $this->connection is guaranteed non-null
		return $this->connection;
	}


	/**
	 * Proxy unknown method calls to the LiteMySQLi instance.
	 *
	 * Behavior:
	 * - Ensures the connection exists, then forwards $method with $args to LiteMySQLi.
	 * - Returns the underlying LiteMySQLi method's return value verbatim.
	 *
	 * Notes:
	 * - No method_exists guard by design: typos raise immediate errors (fail fast beats silent bugs).
	 * - Use establish() for tight loops to avoid repeated __call indirection.
	 *
	 * Typical usage:
	 *   Terse one-liners like $this->app->db->fetchRow(...).
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   $row = $this->app->db->fetchRow('SELECT * FROM t WHERE id = ?', [$id]);
	 *
	 * Failure:
	 * - Unknown methods or LiteMySQLi exceptions bubble up unchanged.
	 *
	 * @param string $method Target LiteMySQLi method name.
	 * @param array<int,mixed> $args Positional arguments to pass through.
	 * @return mixed Return value from the underlying LiteMySQLi call.
	 * @throws \Throwable On unknown methods or any LiteMySQLi error.
	 */
	public function __call(string $method, array $args): mixed {
		// Route call through the live handle; keep this wrapper zero-magic
		return $this->db()->{$method}(...$args);
	}


	/**
	 * Close the connection and forget the handle.
	 *
	 * Behavior:
	 * - Calls ->close() on the underlying connection when available.
	 * - Sets the local handle to null; subsequent use will re-establish.
	 * - Idempotent: Safe to call multiple times.
	 *
	 * Notes:
	 * - Useful in long-running CLI tasks to free resources between batches.
	 *
	 * Typical usage:
	 *   Called after a batch job or before forking in a worker.
	 *
	 * Examples:
	 *
	 *   // Close proactively (especially in a CLI command)
	 *   $this->app->db->close(); // NOTE: It's idempotent, so rather one time too many than none at all ;)
	 *
	 * Failure:
	 * - No exceptions expected; defensive checks avoid calling close() on null.
	 *
	 * @return void
	 */
	public function close(): void {
	
		// Close if a handle exists and the driver exposes a close() method
		if ($this->connection !== null && \method_exists($this->connection, 'close')) {
			$this->connection->close();
		}
		
		// Drop the handle; the next call will re-create lazily
		$this->connection = null;
	}
	
	
}
