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

namespace CitOmni\Infrastructure\Repository;

use CitOmni\Kernel\Repository\BaseRepository;

/**
 * DatabaseSchemaRepository: Repository for checks against the schema of the current database.
 *
 * Answers cheap questions about the schema, such as whether a table exists, so that a service
 * can report a missing installation clearly. What a missing table means belongs to the
 * caller, and creating or migrating tables belongs to the app's deployment.
 *
 * Rules:
 * - Read schema metadata only; never change the schema.
 */
class DatabaseSchemaRepository extends BaseRepository {

	/**
	 * Tell whether a table exists in the current database.
	 *
	 * Notes:
	 * - Whether the name is compared case-sensitively depends on the server's
	 *   lower_case_table_names and file system.
	 *
	 * @param  string  $tableName  Letters, digits and underscores only.
	 * @return bool
	 * @throws \InvalidArgumentException  When the name holds another character or is empty.
	 */
	public function tableExists(string $tableName): bool {
		if (!\preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
			throw new \InvalidArgumentException('Table name contains unsupported characters.');
		}

		$row = $this->app->db->fetchRow(
			'SELECT 1 AS found
			FROM information_schema.tables
			WHERE table_schema = DATABASE()
				AND table_name = ?
			LIMIT 1',
			[$tableName]
		);

		return $row !== null;
	}

}
