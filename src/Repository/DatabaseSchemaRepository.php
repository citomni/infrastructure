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
 * Repository for cheap database schema checks.
 *
 * Behavior:
 * - Checks database metadata using the active database connection.
 * - Keeps schema inspection SQL out of Operations, Controllers, and Services.
 *
 * Notes:
 * - This is not a migration system. That beast lives elsewhere, preferably
 *   behind a fence with a warning sign.
 * - Methods are intentionally small and deterministic.
 *
 * Typical usage:
 *   $schemaRepo = new DatabaseSchemaRepository($this->app);
 *
 *   if (!$schemaRepo->tableExists('bruteforce_counters')) {
 *       // The caller decides what that means.
 *   }
 */
final class DatabaseSchemaRepository extends BaseRepository {

	/**
	 * Check whether a table exists in the current database.
	 *
	 * @param string $tableName Table name to check.
	 * @return bool True when the table exists.
	 * @throws \InvalidArgumentException When the table name contains unsupported characters.
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
