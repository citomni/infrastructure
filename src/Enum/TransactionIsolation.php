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

namespace CitOmni\Infrastructure\Enum;

/**
 * Bounded set of transaction isolation levels selectable per transaction.
 *
 * This is a closed technical contract, not an SQL carrier: the cases name the
 * four SQL-standard isolation levels supported by both declared targets
 * (MySQL 8.0.16+ and MariaDB 10.6+). The mapping from a case to its transaction
 * control statement lives in the Db service, which owns SQL; this enum carries
 * no SQL text, so a caller can never smuggle an arbitrary SQL fragment through
 * it.
 *
 * Notes:
 * - Pure enum by design (no backing value): the value space must stay closed and
 *   there is no need for a scalar wire form.
 * - Passed to Db::beginTransaction()/transaction()/easyTransaction() to select
 *   isolation for a single transaction only.
 *
 * Typical usage:
 *   $db->transaction(function (Db $db) {
 *   	// ... work that must serialize under READ COMMITTED ...
 *   }, TransactionIsolation::ReadCommitted);
 */
enum TransactionIsolation {
	case ReadUncommitted;
	case ReadCommitted;
	case RepeatableRead;
	case Serializable;
}
