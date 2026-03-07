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
 * Connection-level database exception.
 *
 * Thrown when the database service fails before normal query execution can
 * begin, or when connection/session initialization becomes invalid.
 *
 * Behavior:
 * - Used for failures in mysqli_init(), real_connect(), set_charset(), and
 *   session setup such as sql_mode/time_zone initialization.
 * - Signals that the connection itself is unavailable or not fully usable.
 * - Extends DbException so callers may either catch this specifically or
 *   catch DbException for all DB-layer failures.
 *
 * Typical usage:
 *   try {
 *   	$this->app->db->beginTransaction();
 *   } catch (DbConnectException $e) {
 *   	// Database is unreachable or session init failed.
 *   }
 */
final class DbConnectException extends DbException {

}
