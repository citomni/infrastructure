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
 * TransactionIsolation: Enum of the isolation levels that one transaction can select.
 *
 * A closed technical contract, not an SQL carrier: The cases name the four SQL-standard
 * isolation levels that both declared targets support (MySQL 8.0.16+ and MariaDB 10.6+), and
 * they carry no SQL text, so a caller can never smuggle an SQL fragment through them. Turning
 * a case into its transaction control statement is left to the Db service, which owns SQL.
 *
 * Rules:
 * - Pure enum, without a backing value: The value space stays closed and has no wire form.
 *
 * @see \CitOmni\Infrastructure\Service\Db::transaction()  Selects the isolation for one transaction.
 */
enum TransactionIsolation {

	/** READ UNCOMMITTED: A read may see changes that other transactions have not committed. */
	case ReadUncommitted;

	/** READ COMMITTED: Each read sees only data committed before that read began. */
	case ReadCommitted;

	/** REPEATABLE READ: Repeated reads within the transaction see the same snapshot. */
	case RepeatableRead;

	/** SERIALIZABLE: Transactions behave as if they ran one after another. */
	case Serializable;
}
