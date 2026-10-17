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
 * the table's unique key enforces, and the statements that count a failure on a row. The
 * limits, the lengths of window and block, and how a subject is hashed belong to the
 * BruteForce service.
 *
 * Rules:
 * - Work with the UNIX timestamps that the caller passes; never read the clock.
 * - Open no transaction. A caller that needs one owns it.
 * - Change a counter in one statement that checks its conditions on the row itself, never on
 *   values that an earlier statement read.
 */
class BruteForceRepository extends BaseRepository {

	/** Name of the counter table. */
	public const TABLE = 'bruteforce_counters';

	/** Maximum context length in characters. It must equal the length of bruteforce_counters.context. */
	public const CONTEXT_MAX_LENGTH = 64;


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
	 * - A new counter starts its window at $now with one attempt, blocked until $blockedUntil.
	 * - An existing counter stays unchanged, also when a concurrent call created it first; no
	 *   duplicate key error escapes.
	 *
	 * Notes:
	 * - INSERT IGNORE turns other row errors into warnings as well, so a context longer than
	 *   CONTEXT_MAX_LENGTH characters is stored truncated rather than rejected.
	 *
	 * @param  string  $context  Context name.
	 * @param  string  $subjectType  'identifier' or 'ip'.
	 * @param  string  $subjectHash  SHA-256 hex digest of the normalized subject.
	 * @param  int  $now  Current UNIX timestamp.
	 * @param  int  $blockedUntil  UNIX timestamp at which the block of a new counter ends; 0 for no block.
	 * @return bool  True when this call created the counter.
	 */
	public function insertIfMissing(string $context, string $subjectType, string $subjectHash, int $now, int $blockedUntil): bool {
		return $this->app->db->execute(
			'INSERT IGNORE INTO ' . self::TABLE
			. ' (context, subject_type, subject_hash, window_start, attempt_count, blocked_until, created_at, updated_at)'
			. ' VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
			[$context, $subjectType, $subjectHash, $now, $blockedUntil, $now, $now]
		) > 0;
	}


	/**
	 * Count one more failure on the existing counter of one subject, unless the subject is blocked.
	 *
	 * The statement decides on the row as it is under its row lock, so concurrent calls for one
	 * subject are counted one after another, and the block starts with the call that reaches
	 * the limit.
	 *
	 * Behavior:
	 * - A counter blocked beyond $now stays unchanged.
	 * - A counter whose window started after $windowStartAfter gets one more attempt. When the
	 *   attempts reach $maxAttempts, it is blocked until $blockedUntil.
	 * - Any other counter starts a new window at $now with one attempt, blocked until
	 *   $restartBlockedUntil.
	 *
	 * @param  string  $context  Context name.
	 * @param  string  $subjectType  'identifier' or 'ip'.
	 * @param  string  $subjectHash  SHA-256 hex digest of the normalized subject.
	 * @param  int  $now  Current UNIX timestamp.
	 * @param  int  $windowStartAfter  UNIX timestamp; a window that started after it is still open.
	 * @param  int  $maxAttempts  Attempts at which the subject is blocked.
	 * @param  int  $blockedUntil  UNIX timestamp at which the block ends when this count reaches the limit.
	 * @param  int  $restartBlockedUntil  UNIX timestamp at which the block of a new window ends; 0 for no block.
	 * @return bool  True when the failure was counted; false when the subject has no counter or is blocked.
	 */
	public function countFailure(string $context, string $subjectType, string $subjectHash, int $now, int $windowStartAfter, int $maxAttempts, int $blockedUntil, int $restartBlockedUntil): bool {
		// Each assignment reads only its own column and columns assigned after it, so every
		// expression sees the row as it was before this statement, whether the server assigns
		// left to right or, with MariaDB's SIMULTANEOUS_ASSIGNMENT, all at once.
		return $this->app->db->execute(
			'UPDATE ' . self::TABLE
			. ' SET blocked_until = IF(window_start > ?, IF(attempt_count + 1 >= ?, ?, blocked_until), ?),'
			. ' attempt_count = IF(window_start > ?, attempt_count + 1, 1),'
			. ' window_start = IF(window_start > ?, window_start, ?),'
			. ' updated_at = ?'
			. ' WHERE context = ? AND subject_type = ? AND subject_hash = ? AND blocked_until <= ?',
			[
				$windowStartAfter, $maxAttempts, $blockedUntil, $restartBlockedUntil,
				$windowStartAfter,
				$windowStartAfter, $now,
				$now,
				$context, $subjectType, $subjectHash, $now,
			]
		) > 0;
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
