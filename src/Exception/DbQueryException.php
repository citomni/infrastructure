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
 * Query-level database exception.
 *
 * Thrown when the connection exists, but a database operation fails during
 * query preparation, parameter binding, execution, transaction control, or
 * result handling.
 *
 * Behavior:
 * - Used for invalid SQL, prepare() failures, bind_param() failures,
 *   execute() failures, transaction failures, and result-state issues.
 * - Message payload may include SQL text and parameter types, but not raw
 *   parameter values.
 * - Extends DbException so callers may either catch this specifically or
 *   catch DbException for all DB-layer failures.
 *
 * Typical usage:
 *   try {
 *   	$row = $this->app->db->fetchRow('SELECT * FROM users WHERE id = ?', [$id]);
 *   } catch (DbQueryException $e) {
 *   	// Query was invalid or execution failed.
 *   }
 */
final class DbQueryException extends DbException {

}
