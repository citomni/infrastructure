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
 * BruteForceRepository: Repository for brute force counters in bruteforce_counters.
 *
 * Owns the SQL for the counters: one row per context, subject type and subject hash, which
 * the table's unique key enforces. When a failure counts, when a subject is blocked and how
 * a subject is hashed belong to the BruteForce service.
 *
 * Rules:
 * - Work with the UNIX timestamps that the caller passes; never read the clock.
 * - Open no transaction. A caller that needs one owns it.
 */
class BruteForceRepository extends BaseRepository {

	/** Name of the counter table. */
	public const TABLE = 'bruteforce_counters';


	/**
	 * Find the counter of one subject in one context.
	 *
	 * @param  string  $context  Context name.
	 * @param  string  $subjectType  'identifier' or 'ip'.
	 * @param  string  $subjectHash  SHA-256 hex digest of the normalized subject.
	 * @return array{id:mixed,window_start:mixed,attempt_count:mixed,blocked_until:mixed}|null  Uncast column values, which the caller casts; null when the subject has no counter.
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
	 * Create the counter of one subject with its first failure, unless the counter exists.
	 *
	 * Behavior:
	 * - A new counter starts its window at $now with one attempt and no block.
	 * - An existing counter stays unchanged, also when a concurrent call created it first; no
	 *   duplicate key error escapes.
	 *
	 * Notes:
	 * - INSERT IGNORE turns other row errors into warnings as well, so a context longer than
	 *   its column is stored truncated rather than rejected.
	 *
	 * @param  string  $context  Context name.
	 * @param  string  $subjectType  'identifier' or 'ip'.
	 * @param  string  $subjectHash  SHA-256 hex digest of the normalized subject.
	 * @param  int  $now  Current UNIX timestamp.
	 * @return bool  True when this call created the counter.
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
	 * Start a new window on one counter with one attempt.
	 *
	 * Behavior:
	 * - Sets the window start to $now and the attempt count to 1, and lifts any block.
	 *
	 * @param  int  $id  Counter id.
	 * @param  int  $now  Current UNIX timestamp.
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
	 * Count one more attempt on one counter without blocking it.
	 *
	 * Behavior:
	 * - The database adds the attempt, so concurrent calls are all counted.
	 *
	 * @param  int  $id  Counter id.
	 * @param  int  $now  Current UNIX timestamp.
	 * @return void
	 */
	public function increment(int $id, int $now): void {
		// An expression rather than a value, so execute() instead of update(), which binds values.
		$this->app->db->execute(
			'UPDATE ' . self::TABLE
			. ' SET attempt_count = attempt_count + 1, updated_at = ?'
			. ' WHERE id = ?',
			[$now, $id]
		);
	}


	/**
	 * Count one more attempt on one counter and block its subject until a given time.
	 *
	 * Behavior:
	 * - The database adds the attempt, so concurrent calls are all counted.
	 *
	 * @param  int  $id  Counter id.
	 * @param  int  $now  Current UNIX timestamp.
	 * @param  int  $blockedUntil  UNIX timestamp at which the block ends.
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
	 * Delete the counter of one subject in one context.
	 *
	 * @param  string  $context  Context name.
	 * @param  string  $subjectType  'identifier' or 'ip'.
	 * @param  string  $subjectHash  SHA-256 hex digest of the normalized subject.
	 * @return int  Number of deleted counters: 0 or 1.
	 */
	public function deleteBySubject(string $context, string $subjectType, string $subjectHash): int {
		return $this->app->db->execute(
			'DELETE FROM ' . self::TABLE
			. ' WHERE context = ? AND subject_type = ? AND subject_hash = ?',
			[$context, $subjectType, $subjectHash]
		);
	}


	/**
	 * Delete the counters of one context that have not changed since a cutoff.
	 *
	 * @param  string  $context  Context name.
	 * @param  int  $cutoff  UNIX timestamp; counters last changed before it are deleted.
	 * @return int  Number of deleted counters.
	 */
	public function deleteByContextOlderThan(string $context, int $cutoff): int {
		return $this->app->db->execute(
			'DELETE FROM ' . self::TABLE
			. ' WHERE context = ? AND updated_at < ?',
			[$context, $cutoff]
		);
	}


	/**
	 * Delete the counters outside a list of contexts that have not changed since a cutoff.
	 *
	 * Behavior:
	 * - With an empty list, every counter that has not changed since the cutoff is deleted.
	 *
	 * @param  list<string>  $configuredContexts  Context names whose counters are kept; may be empty.
	 * @param  int  $cutoff  UNIX timestamp; counters last changed before it are deleted.
	 * @return int  Number of deleted counters.
	 */
	public function deleteOrphansOlderThan(array $configuredContexts, int $cutoff): int {
		if ($configuredContexts === []) {
			// NOT IN () is invalid SQL, so an empty list deletes by age alone.
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
