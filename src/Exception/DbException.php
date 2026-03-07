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
 * Base exception for all database-layer failures in CitOmni.
 *
 * Use this as the common catch target when the caller does not need to
 * distinguish between connection failures and query/runtime failures.
 *
 * Behavior:
 * - DbConnectException extends this class for connect/session-init failures.
 * - DbQueryException extends this class for prepare/bind/execute/query failures.
 * - Keeps the DB exception tree explicit and framework-local.
 *
 * Typical usage:
 *   try {
 *   	$this->app->db->execute('DELETE FROM users WHERE id = ?', [$id]);
 *   } catch (DbException $e) {
 *   	// Handle any DB-layer failure.
 *   }
 */
class DbException extends \RuntimeException {

}
