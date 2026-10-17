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
 * BaseModelLiteMySQLi: Base model with a lazy LiteMySQLi connection.
 *
 * Gives a model subclass one LiteMySQLi connection per instance, opened on first use from the
 * db cfg and the db.password secret, for code that talks to LiteMySQLi directly instead of
 * through the db service. The driver, its API and its failures belong to LiteMySQLi, which
 * the app must install itself.
 *
 * Rules:
 * - Open no connection before the first use.
 * - Catch nothing: Cfg, secret and driver failures reach the global error handler.
 */
abstract class BaseModelLiteMySQLi {

	/** Application container, for cfg and services. */
	protected App $app;

	/** Connection of this instance, or null until the first establish(). */
	private ?LiteMySQLi $conn = null;


	/**
	 * Store the App and run the optional init() hook of the subclass.
	 *
	 * Behavior:
	 * - Calls init() once, when the subclass declares it. Opens no connection.
	 *
	 * @param  App  $app  App whose cfg and secrets hold the connection settings.
	 */
	public function __construct(App $app) {
		$this->app = $app;

		// Optional init hook for subclasses; keep it light to preserve fast construction
		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}


	/**
	 * Return the connection of this instance, opening it on the first call.
	 *
	 * Behavior:
	 * - The first call connects with cfg db.host, db.user, db.name and db.charset and the
	 *   secret db.password; later calls return the same connection.
	 * - Connects once, without retry or reconnect.
	 *
	 * Notes:
	 * - A subclass that keeps the returned connection in a local variable saves the __get()
	 *   call of $this->db in a hot loop.
	 *
	 * @return LiteMySQLi
	 * @throws \OutOfBoundsException  When one of the db cfg keys or the secret db.password is missing.
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
			$this->app->secrets->get('db.password'),
			(string)$cfg->name,
			(string)$cfg->charset
		);

		return $this->conn;
	}


	/**
	 * Resolve $this->db to the connection of this instance.
	 *
	 * Behavior:
	 * - Opens the connection on first access.
	 *
	 * @param  string  $name  Property name; only db is known.
	 * @return mixed  The LiteMySQLi connection.
	 * @throws \OutOfBoundsException  When $name is not db, or when establish() throws it.
	 * @see \CitOmni\Infrastructure\Model\BaseModelLiteMySQLi::establish()  How the connection is opened.
	 */
	public function __get(string $name): mixed {
		if ($name !== 'db') {
			throw new \OutOfBoundsException("Unknown property: {$name}");
		}
		return $this->establish();
	}

}
