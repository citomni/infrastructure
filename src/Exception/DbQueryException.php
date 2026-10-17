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

namespace CitOmni\Infrastructure\Exception;

/**
 * DbQueryException: Exception for a database statement or transaction call that fails.
 *
 * Marks a failure of the caller's work rather than of the connection setup: invalid SQL or
 * arguments, a failed prepare, bind or execute, a missing result set, or a transaction call
 * that is not allowed in the current state. Classifying the server error belongs here;
 * deciding what to do about it belongs to the caller.
 *
 * Rules:
 * - The code is the server's error number whenever the server reported one; the classifiers
 *   read it.
 * - A message never contains a bound parameter value, which may be a secret or personal
 *   data. SQL text and parameter types are allowed.
 */
class DbQueryException extends DbException {

	/** Server error number ER_DUP_ENTRY: A unique key already holds the value. */
	private const MYSQL_DUPLICATE_ENTRY_CODE = 1062;

	/** Server error number ER_LOCK_DEADLOCK: The engine rolled the transaction back to break a deadlock. */
	private const MYSQL_DEADLOCK_CODE        = 1213;

	/**
	 * Tell whether the statement failed on a duplicate key.
	 *
	 * Notes:
	 * - Only classifies. Whether the domain state really conflicts is for the caller to check.
	 *
	 * @return bool  True for server error 1062, a value that a unique key already holds.
	 */
	public function isDuplicateEntry(): bool {
		return $this->getCode() === self::MYSQL_DUPLICATE_ENTRY_CODE;
	}

	/**
	 * Tell whether the statement failed on a transaction deadlock.
	 *
	 * Behavior:
	 * - True only for server error 1213, where the engine has already rolled the whole
	 *   transaction back and restarting the transaction is the sanctioned recovery.
	 * - False for a lock wait timeout (error 1205): Only the waiting statement is rolled back
	 *   there, so it needs other handling.
	 *
	 * Notes:
	 * - Error 1213 means the same on MySQL 8.0.16+ and MariaDB 10.6+.
	 * - Only classifies. Whether and how to retry belongs to the owner of the workflow.
	 *
	 * @return bool
	 */
	public function isDeadlock(): bool {
		return $this->getCode() === self::MYSQL_DEADLOCK_CODE;
	}
}
