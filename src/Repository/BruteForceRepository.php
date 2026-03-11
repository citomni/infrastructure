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
 * BruteForceRepository: Persistence layer for brute force counter rows.
 *
 * All SQL for the bruteforce_counters table lives here. The BruteForce service
 * delegates all data access to this class and contains zero SQL.
 *
 * Behavior:
 * - Uses one row per context + subject_type + subject_hash (bucketed counters).
 * - Reads return plain arrays with documented shapes.
 * - Writes return affected row counts where useful.
 * - No transaction management - callers own transaction scope if needed.
 * - First inserts use INSERT IGNORE to avoid duplicate-key exceptions under
 *   concurrent requests racing to create the same bucket.
 *
 * Notes:
 * - Table name is a class constant for single-point change.
 * - The table must be created manually (see BruteForce service PHPDoc for schema).
 * - Unique index on (context, subject_type, subject_hash) enforces one row per bucket.
 * - Uses correct CitOmni Db API: fetchRow, update, execute.
 * - insertIfMissing() is aligned with Db::execute(), which returns affected rows.
 *
 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On query failure.
 */
final class BruteForceRepository extends BaseRepository {
	
	/** @var string Table name for brute force counter rows. */
	private const TABLE = 'bruteforce_counters';


	/**
	 * findBySubject: Look up a single counter row by context and subject.
	 *
	 * @param string $context     Context name (for example 'default').
	 * @param string $subjectType 'identifier' or 'ip'.
	 * @param string $subjectHash SHA-256 hex hash of the normalized subject value.
	 *
	 * @return ?array Row with keys: id, window_start, attempt_count, blocked_until (string values from MySQLi - cast in caller). Null when no row exists.
	 */
	public function findBySubject(string $context, string $subjectType, string $subjectHash): ?array {
		return $this->app->db->fetchRow(
			'SELECT id, window_start, attempt_count, blocked_until'
			. ' FROM ' . self::TABLE
			. ' WHERE context = ? AND subject_type = ? AND subject_hash = ?'
			. ' LIMIT 1',
			[$context, $subjectType, $subjectHash]
		);
	}


	/**
	 * insertIfMissing: Insert a new counter row if it does not already exist.
	 *
	 * Uses INSERT IGNORE to avoid duplicate-key exceptions under concurrent first
	 * inserts. Returns true only when this call actually inserted the row.
	 *
	 * Notes:
	 * - Requires a unique index on (context, subject_type, subject_hash).
	 * - Aligned with Db::execute(), which returns affected row count.
	 *
	 * @param string $context     Context name.
	 * @param string $subjectType 'identifier' or 'ip'.
	 * @param string $subjectHash SHA-256 hex hash.
	 * @param int    $now         Current UNIX timestamp.
	 *
	 * @return bool True when a new row was inserted, false when it already existed.
	 */
	public function insertIfMissing(string $context, string $subjectType, string $subjectHash, int $now): bool {
		return $this->app->db->execute(
			'INSERT IGNORE INTO ' . self::TABLE
			. ' (context, subject_type, subject_hash, window_start, attempt_count, blocked_until, created_at, updated_at)'
			. ' VALUES (?, ?, ?, ?, 1, 0, ?, ?)',
			[$context, $subjectType, $subjectHash, $now, $now, $now]
		) > 0;
	}


	/**
	 * resetWindow: Reset a counter row to a fresh window with attempt_count = 1.
	 *
	 * Used when the rolling window has expired and a new attempt arrives.
	 * Clears any previous blocked_until.
	 *
	 * @param int $id  Row id.
	 * @param int $now Current UNIX timestamp.
	 *
	 * @return void
	 */
	public function resetWindow(int $id, int $now): void {
		$this->app->db->update(self::TABLE, [
			'window_start'  => $now,
			'attempt_count' => 1,
			'blocked_until' => 0,
			'updated_at'    => $now,
		], 'id = ?', [$id]);
	}


	/**
	 * increment: Increment attempt_count by 1 without blocking.
	 *
	 * Uses execute() with raw SQL because attempt_count = attempt_count + 1
	 * is an expression, not a plain value.
	 *
	 * @param int $id  Row id.
	 * @param int $now Current UNIX timestamp.
	 *
	 * @return void
	 */
	public function increment(int $id, int $now): void {
		$this->app->db->execute(
			'UPDATE ' . self::TABLE
			. ' SET attempt_count = attempt_count + 1, updated_at = ?'
			. ' WHERE id = ?',
			[$now, $id]
		);
	}


	/**
	 * incrementAndBlock: Increment attempt_count and set blocked_until.
	 *
	 * Called when the incremented count reaches the configured threshold.
	 *
	 * @param int $id           Row id.
	 * @param int $now          Current UNIX timestamp.
	 * @param int $blockedUntil UNIX timestamp until which the subject is blocked.
	 *
	 * @return void
	 */
	public function incrementAndBlock(int $id, int $now, int $blockedUntil): void {
		$this->app->db->execute(
			'UPDATE ' . self::TABLE
			. ' SET attempt_count = attempt_count + 1, blocked_until = ?, updated_at = ?'
			. ' WHERE id = ?',
			[$blockedUntil, $now, $id]
		);
	}


	/**
	 * deleteBySubject: Delete a single counter row by context and subject.
	 *
	 * @param string $context     Context name.
	 * @param string $subjectType 'identifier' or 'ip'.
	 * @param string $subjectHash SHA-256 hex hash.
	 *
	 * @return int Number of deleted rows (0 or 1).
	 */
	public function deleteBySubject(string $context, string $subjectType, string $subjectHash): int {
		return $this->app->db->execute(
			'DELETE FROM ' . self::TABLE
			. ' WHERE context = ? AND subject_type = ? AND subject_hash = ?',
			[$context, $subjectType, $subjectHash]
		);
	}


	/**
	 * deleteByContextOlderThan: Delete counter rows for a specific context
	 * where updated_at is older than the given cutoff.
	 *
	 * Used by prune() for per-context cleanup with per-context thresholds.
	 *
	 * @param string $context Context name.
	 * @param int    $cutoff  UNIX timestamp. Rows with updated_at < $cutoff are deleted.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByContextOlderThan(string $context, int $cutoff): int {
		return $this->app->db->execute(
			'DELETE FROM ' . self::TABLE
			. ' WHERE context = ? AND updated_at < ?',
			[$context, $cutoff]
		);
	}


	/**
	 * deleteOrphansOlderThan: Delete counter rows for contexts NOT in the given list,
	 * where updated_at is older than the given cutoff.
	 *
	 * Used by prune() to clean up rows belonging to contexts that have been
	 * removed from config. Uses a 30-day fallback threshold.
	 *
	 * @param string[] $configuredContexts Context names currently in config. May be empty.
	 * @param int      $cutoff             UNIX timestamp. Orphan rows with updated_at < $cutoff are deleted.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteOrphansOlderThan(array $configuredContexts, int $cutoff): int {
		if ($configuredContexts === []) {
			// No configured contexts at all - prune everything older than cutoff.
			return $this->app->db->execute(
				'DELETE FROM ' . self::TABLE . ' WHERE updated_at < ?',
				[$cutoff]
			);
		}

		$placeholders = \implode(', ', \array_fill(0, \count($configuredContexts), '?'));
		$params = \array_merge($configuredContexts, [$cutoff]);

		return $this->app->db->execute(
			'DELETE FROM ' . self::TABLE
			. ' WHERE context NOT IN (' . $placeholders . ') AND updated_at < ?',
			$params
		);
	}


}
