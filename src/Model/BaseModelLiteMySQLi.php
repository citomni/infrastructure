<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Infrastructure\Model;

use CitOmni\Kernel\App;
use LiteMySQLi\LiteMySQLi;

/**
 * BaseModelLiteMySQLi: Pragmatic base model with lazy LiteMySQLi access.
 *
 * Responsibilities:
 * - Provide a consistent, low-overhead path to the database for models.
 *   1) Lazy-load the connection on first access (no I/O in the constructor).
 *   2) Expose `$this->db` for ergonomic calls in simple code paths.
 *   3) Expose establish() for explicit handles in hot paths.
 * - Enforce fail-fast behavior on misconfiguration or connection errors.
 *
 * Collaborators:
 * - \CitOmni\Kernel\App (read-only service locator/config access).
 * - \LiteMySQLi\LiteMySQLi (direct DB driver used for all operations).
 *
 * Configuration keys:
 * - db.host (string, required) - Database host.
 * - db.user (string, required) - Username.
 * - db.pass (string, optional) - Password.
 * - db.name (string, required) - Schema/database name.
 * - db.charset (string, default: "utf8mb4") - Connection charset.
 *
 * Error handling:
 * - Fail fast; no internal try/catch. Exceptions bubble to the global handler.
 * - Missing cfg keys surface as OutOfBoundsException from the Cfg wrapper.
 *
 * Typical usage:
 * - Extend this class for DB-backed models that want zero-magic and minimal overhead.
 *
 * Examples:
 *   // Core: Simple lookup using the lazy `$this->db` handle
 *   final class UserModel extends BaseModelLiteMySQLi {
 *   	public function findById(int $id): ?array {
 *   		return $this->db->fetchRow('SELECT * FROM user_account WHERE id = ?', [$id]) ?: null;
 *   	}
 *   }
 *
 *   // Scenario: Hot path loop; cache a local handle to avoid indirection
 *   $conn = $this->establish(); // direct LiteMySQLi
 *   foreach ($ids as $id) {
 *   	$row = $conn->fetchRow('SELECT * FROM user_account WHERE id = ?', [$id]);
 *   }
 *
 * Failure:
 * - Wrong credentials or unreachable host raise driver exceptions; these are not swallowed.
 *
 * Optional: Standalone
 * - Minimal bootstrap: construct App with cfg->db keys, new an anonymous class extending this base,
 *   call establish() to get the LiteMySQLi handle.
 */
abstract class BaseModelLiteMySQLi {

	/** @var App Application service locator (singleton per request/process). */
	protected App $app;

	/** @var LiteMySQLi|null Memoized DB connection (null until first establish()). */
	private ?LiteMySQLi $conn = null;


	/**
	 * Construct the base model and run optional subclass init().
	 *
	 * Behavior:
	 * - Stores the App reference for config/service access.
	 * - Calls init() if the subclass defines it (no I/O is performed here).
	 *
	 * Notes:
	 * - Keep init() lightweight; this constructor should remain side-effect free.
	 *
	 * Typical usage:
	 *   Instantiated by controllers/services to obtain a model instance.
	 *
	 * Examples:
	 *   $model = new UserModel($this->app);
	 *
	 * Failure:
	 * - No expected failures; $app must be a valid App instance.
	 *
	 * @param App $app Application container providing cfg and services.
	 * @return void
	 */
	public function __construct(App $app) {
		$this->app = $app;

		// Optional init hook for subclasses; keep it light to preserve fast construction
		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}


	/**
	 * Establish and memoize the LiteMySQLi connection.
	 *
	 * Behavior:
	 * - Returns the existing connection if already created.
	 * - On first call, constructs LiteMySQLi using cfg->db keys and stores the handle.
	 *
	 * Notes:
	 * - No retries or reconnect logic here; deterministic and cheap by design.
	 * - Charset defaults to "utf8mb4" if not specified in cfg.
	 *
	 * Typical usage:
	 *   Use in hot paths to capture a local `$conn` for repeated operations.
	 *
	 * Examples:
	 *
	 *   // First call creates the connection; subsequent calls reuse it
	 *   $conn = $this->establish();
	 *   $row  = $conn->fetchRow('SELECT * FROM user_account WHERE id = ?', [$id]);
	 *
	 *   // Idempotent
	 *   $a = $this->establish();
	 *   $b = $this->establish(); // same instance as $a
	 *
	 * Failure:
	 * - Missing cfg keys: OutOfBoundsException from the Cfg wrapper.
	 * - Driver/connection errors: Exceptions thrown by LiteMySQLi.
	 *
	 * @return LiteMySQLi Established and ready-to-use connection.
	 * @throws \OutOfBoundsException When required cfg->db keys are missing.
	 * @throws \Throwable On connection/auth/charset failures from the driver.
	 */
	protected function establish(): LiteMySQLi {
		if ($this->conn instanceof LiteMySQLi) {
			return $this->conn;
		}

		// Pull configuration from the deep, read-only cfg wrapper; fail fast on missing keys
		$cfg = $this->app->cfg->db;

		// Construct the driver; no try/catch here so failures surface to the global handler
		$this->conn = new LiteMySQLi(
			(string)$cfg->host,
			(string)$cfg->user,
			(string)$cfg->pass,
			(string)$cfg->name,
			$cfg->charset ?? 'utf8mb4'
		);

		return $this->conn;
	}


	/**
	 * Resolve magic property "db" to the LiteMySQLi connection.
	 *
	 * Behavior:
	 * - If `$name === 'db'`, ensures the connection exists and returns it.
	 * - Any other property name fails fast with OutOfBoundsException.
	 *
	 * Notes:
	 * - This is syntactic sugar for readability; use establish() in tight loops.
	 *
	 * Typical usage:
	 *   `$this->db->fetchRow(...)` in straightforward model methods.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   $row = $this->db->fetchRow('SELECT * FROM user_account WHERE id = ?', [$id]);
	 *
	 *   // Edge: Unknown property
	 *   // $this->foo  // throws OutOfBoundsException
	 *
	 * Failure:
	 * - Unknown properties: OutOfBoundsException with the requested name.
	 *
	 * @param string $name Requested property name ("db" only).
	 * @return mixed LiteMySQLi connection when $name is "db".
	 * @throws \OutOfBoundsException If property other than "db" is requested.
	 */
	public function __get(string $name): mixed {
		if ($name !== 'db') {
			throw new \OutOfBoundsException("Unknown property: {$name}");
		}
		// Lazy resolve the DB handle and return the memoized instance
		return $this->establish();
	}

}
