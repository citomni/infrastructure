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

namespace CitOmni\Infrastructure\Service;

use CitOmni\Infrastructure\Exception\BruteForceConfigException;
use CitOmni\Infrastructure\Exception\BruteForceSetupException;
use CitOmni\Infrastructure\Repository\BruteForceRepository;
use CitOmni\Infrastructure\Repository\DatabaseSchemaRepository;
use CitOmni\Kernel\Service\BaseService;

/**
 * BruteForce: Service that throttles repeated failures of an action per identifier and per IP address.
 *
 * Counts the failures of a protected action, such as a login, within a window per context
 * and subject, and blocks a subject that reaches the context's limit for a fixed time. A
 * subject is an identifier, such as an email address, or a client IP address; the two are
 * counted and blocked independently. What a block means for the response belongs to the
 * caller, and the SQL belongs to BruteForceRepository.
 *
 * Rules:
 * - Store a subject only as the SHA-256 hash of its normalized value.
 * - Normalize subjects the same way in every method, so lookups, counts and deletions agree.
 * - Treat every IP address alike; there is no allowlist.
 *
 * Typical usage:
 *   $status = $this->app->bruteForce->status('login', $email, $ip);
 *   if ($status['blocked']) {
 *       // Refuse the attempt; retry_after_seconds says for how long.
 *   }
 *   // After a failed attempt:
 *   $this->app->bruteForce->record('login', $email, $ip);
 *   // After a successful attempt:
 *   $this->app->bruteForce->clear('login', $email);
 */
class BruteForce extends BaseService {

	/** @var array<array-key,mixed> Cfg security.bruteforce: the settings of each context, keyed by context name. */
	private array $contexts = [];

	/** @var array<string,array{max_identifier_attempts:int,max_ip_attempts:int,interval_minutes:int,retry_after_seconds:int}> Checked limits by context name, filled on first use of a context. */
	private array $resolvedContexts = [];

	/** Repository of the counters. */
	private BruteForceRepository $repo;


	/**
	 * Read cfg security.bruteforce once.
	 *
	 * Behavior:
	 * - The limits of a context are checked on its first use, not here.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\BruteForceConfigException  When cfg security.bruteforce holds no context.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		// Package-owned cfg: Assumes the Registry baseline for security.bruteforce, so no isset() guard.
		$this->contexts = $this->app->cfg->security->bruteforce->toArray();

		if ($this->contexts === []) {
			throw new BruteForceConfigException(
				'Config node security.bruteforce is empty. Define at least one context.'
			);
		}

		$this->repo = new BruteForceRepository($this->app);
	}
	
	
	/**
	 * Fail when the counter table is not installed in the current database.
	 *
	 * Behavior:
	 * - Checks the schema only, not the cfg of any context.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\BruteForceSetupException  When the table does not exist; the exception names it and the schema file to run.
	 */
	public function assertStorageReady(): void {
		$schemaRepo = new DatabaseSchemaRepository($this->app);

		if (!$schemaRepo->tableExists(BruteForceRepository::TABLE)) {
			throw new BruteForceSetupException(
				'Required database table is missing: ' . BruteForceRepository::TABLE . '.',
				BruteForceRepository::TABLE,
				'Run citomni_bruteforce.sql on this environment before using brute force protection.'
			);
		}
	}


	/**
	 * Tell whether an identifier or an IP address is blocked in a context, without changing state.
	 *
	 * Call it before the protected action. Each given subject is checked on its own; the result
	 * is blocked when either one is.
	 *
	 * Behavior:
	 * - A subject is blocked until its block ends, also when its window has expired meanwhile.
	 * - Attempts count only within the current window: An expired window without a block
	 *   counts as 0 attempts.
	 * - reason is 'identifier', 'ip' or 'both' while blocked, and null otherwise. With both
	 *   blocked, blocked_until and retry_after_seconds follow the later end.
	 * - retry_after_seconds counts down to blocked_until, and is 0 when not blocked.
	 * - identifier_remaining and ip_remaining are the limits minus the attempts, never below 0.
	 * - An IP of 'unknown' or 'cli', in any letter case, is no IP subject. Without an
	 *   identifier, the result is not blocked, with 0 attempts.
	 *
	 * Typical usage:
	 *   $status = $this->app->bruteForce->status('api_auth', null, $ip);
	 *
	 * @param  string  $context  Context name, a key under cfg security.bruteforce; trimmed.
	 * @param  string|null  $identifier  Identifier such as an email address, or null for none; trimmed and lowercased.
	 * @param  string|null  $ip  Client IPv4 or IPv6 address, or null for none; trimmed and lowercased.
	 * @return array{blocked:bool,reason:'identifier'|'ip'|'both'|null,retry_after_seconds:int,blocked_until:int|null,identifier_attempts:int,ip_attempts:int,identifier_remaining:int,ip_remaining:int,max_identifier_attempts:int,max_ip_attempts:int,interval_minutes:int}  blocked_until is a UNIX timestamp; the max_* and interval_minutes keys are the context's limits.
	 * @throws \InvalidArgumentException  When the context is empty, the IP address is invalid, or neither subject is given.
	 * @throws \CitOmni\Infrastructure\Exception\BruteForceConfigException  When the context is not configured, its name is not valid UTF-8 or longer than BruteForceRepository::CONTEXT_MAX_LENGTH characters, or one of its limits is missing or below 1.
	 */
	public function status(string $context, ?string $identifier = null, ?string $ip = null): array {

		$context = $this->validateContext($context);
		$subjects = $this->normalizeSubjects($identifier, $ip);
		$cfg = $this->resolveContext($context);
		$now = \time();

		// Evaluate identifier dimension.
		$identAttempts = 0;
		$identBlockedUntil = 0;

		if ($subjects['identifier'] !== null) {
			$row = $this->repo->findBySubject(
				$context, 'identifier', $this->hash($subjects['identifier'])
			);

			if ($row !== null) {
				$storedBlock = (int)$row['blocked_until'];

				if ($storedBlock > $now) {
					// Actively blocked - report stored state.
					$identBlockedUntil = $storedBlock;
					$identAttempts = (int)$row['attempt_count'];
				} elseif ($this->isWindowActive((int)$row['window_start'], $cfg['interval_minutes'], $now)) {
					// Window active, not blocked - report current count.
					$identAttempts = (int)$row['attempt_count'];
				}
				// Else: window expired, no active block -> stale, treat as 0.
			}
		}

		// Evaluate IP dimension.
		$ipAttempts = 0;
		$ipBlockedUntil = 0;

		if ($subjects['ip'] !== null) {
			$row = $this->repo->findBySubject(
				$context, 'ip', $this->hash($subjects['ip'])
			);

			if ($row !== null) {
				$storedBlock = (int)$row['blocked_until'];

				if ($storedBlock > $now) {
					$ipBlockedUntil = $storedBlock;
					$ipAttempts = (int)$row['attempt_count'];
				} elseif ($this->isWindowActive((int)$row['window_start'], $cfg['interval_minutes'], $now)) {
					$ipAttempts = (int)$row['attempt_count'];
				}
			}
		}

		// Determine block verdict.
		$identBlocked = $identBlockedUntil > $now;
		$ipBlocked = $ipBlockedUntil > $now;

		if (!$identBlocked && !$ipBlocked) {
			return [
				'blocked'                 => false,
				'reason'                  => null,
				'retry_after_seconds'     => 0,
				'blocked_until'           => null,
				'identifier_attempts'     => $identAttempts,
				'ip_attempts'             => $ipAttempts,
				'identifier_remaining'    => \max(0, $cfg['max_identifier_attempts'] - $identAttempts),
				'ip_remaining'            => \max(0, $cfg['max_ip_attempts'] - $ipAttempts),
				'max_identifier_attempts' => $cfg['max_identifier_attempts'],
				'max_ip_attempts'         => $cfg['max_ip_attempts'],
				'interval_minutes'        => $cfg['interval_minutes'],
			];
		}

		// At least one dimension is blocked.
		if ($identBlocked && $ipBlocked) {
			$reason = 'both';
			$blockedUntil = \max($identBlockedUntil, $ipBlockedUntil);
		} elseif ($identBlocked) {
			$reason = 'identifier';
			$blockedUntil = $identBlockedUntil;
		} else {
			$reason = 'ip';
			$blockedUntil = $ipBlockedUntil;
		}

		return [
			'blocked'                 => true,
			'reason'                  => $reason,
			'retry_after_seconds'     => \max(0, $blockedUntil - $now),
			'blocked_until'           => $blockedUntil,
			'identifier_attempts'     => $identAttempts,
			'ip_attempts'             => $ipAttempts,
			'identifier_remaining'    => \max(0, $cfg['max_identifier_attempts'] - $identAttempts),
			'ip_remaining'            => \max(0, $cfg['max_ip_attempts'] - $ipAttempts),
			'max_identifier_attempts' => $cfg['max_identifier_attempts'],
			'max_ip_attempts'         => $cfg['max_ip_attempts'],
			'interval_minutes'        => $cfg['interval_minutes'],
		];
	}


	/**
	 * Count one failed attempt of an identifier and an IP address in a context.
	 *
	 * Call it after the protected action has failed; it needs no status() call before it. Each
	 * given subject is counted on its own, and a subject that reaches its limit is blocked for
	 * the context's retry_after_seconds.
	 *
	 * Behavior:
	 * - A blocked subject stays unchanged: Attempts during a block neither count nor extend it.
	 * - A subject without a counter, or whose window has expired, starts a new window with one
	 *   attempt. Otherwise the attempt is counted in the current window.
	 * - When the attempts reach the limit, the subject is blocked from now for
	 *   retry_after_seconds. With a limit of 1, the first attempt of a window blocks.
	 * - Concurrent failures of one subject create one counter and are all counted until the
	 *   block, which starts with the attempt that reaches the limit.
	 * - A failure concurrent with clear() of the same subject counts either before the clear,
	 *   which then removes it, or after it.
	 * - An IP of 'unknown' or 'cli', in any letter case, is no IP subject. Without an
	 *   identifier, nothing is counted.
	 *
	 * Typical usage:
	 *   $this->app->bruteForce->record('login', $email, $ip);
	 *
	 * @param  string  $context  Context name, a key under cfg security.bruteforce; trimmed.
	 * @param  string|null  $identifier  Identifier such as an email address, or null for none; trimmed and lowercased.
	 * @param  string|null  $ip  Client IPv4 or IPv6 address, or null for none; trimmed and lowercased.
	 * @return void
	 * @throws \InvalidArgumentException  When the context is empty, the IP address is invalid, or neither subject is given.
	 * @throws \CitOmni\Infrastructure\Exception\BruteForceConfigException  When the context is not configured, its name is not valid UTF-8 or longer than BruteForceRepository::CONTEXT_MAX_LENGTH characters, or one of its limits is missing or below 1.
	 */
	public function record(string $context, ?string $identifier = null, ?string $ip = null): void {

		$context = $this->validateContext($context);
		$subjects = $this->normalizeSubjects($identifier, $ip);
		$cfg = $this->resolveContext($context);
		$now = \time();

		if ($subjects['identifier'] !== null) {
			$this->recordSubject(
				$context,
				'identifier',
				$subjects['identifier'],
				$cfg['max_identifier_attempts'],
				$cfg['interval_minutes'],
				$cfg['retry_after_seconds'],
				$now
			);
		}

		if ($subjects['ip'] !== null) {
			$this->recordSubject(
				$context,
				'ip',
				$subjects['ip'],
				$cfg['max_ip_attempts'],
				$cfg['interval_minutes'],
				$cfg['retry_after_seconds'],
				$now
			);
		}
	}


	/**
	 * Delete the counter of an identifier, an IP address or both in a context.
	 *
	 * Call it after a successful attempt, usually with the identifier only, or from a flow that
	 * unblocks a subject on purpose. Identifier and IP address have separate counters, so each
	 * given subject is cleared on its own; no counter combines the two.
	 *
	 * Behavior:
	 * - Ends the block of each given subject, and its count.
	 * - Works for a context that is no longer configured.
	 * - An IP of 'unknown' or 'cli', in any letter case, is no IP subject. Without an
	 *   identifier, nothing is deleted.
	 *
	 * Notes:
	 * - Clearing an IP address resets it for all traffic from that address in the context,
	 *   such as everyone behind one NAT, proxy or mobile carrier. Pass $ip only to unblock that
	 *   address deliberately, such as from an admin tool, not after every successful attempt.
	 *
	 * Typical usage:
	 *   $this->app->bruteForce->clear('login', $email);
	 *
	 * @param  string  $context  Context name; trimmed.
	 * @param  string|null  $identifier  Identifier such as an email address, or null for none; trimmed and lowercased.
	 * @param  string|null  $ip  Client IPv4 or IPv6 address, or null for none; trimmed and lowercased.
	 * @return void
	 * @throws \InvalidArgumentException  When the context is empty, the IP address is invalid, or neither subject is given.
	 */
	public function clear(string $context, ?string $identifier = null, ?string $ip = null): void {

		$context = $this->validateContext($context);
		$subjects = $this->normalizeSubjects($identifier, $ip);

		if ($subjects['identifier'] !== null) {
			$this->repo->deleteBySubject(
				$context, 'identifier', $this->hash($subjects['identifier'])
			);
		}

		if ($subjects['ip'] !== null) {
			$this->repo->deleteBySubject(
				$context, 'ip', $this->hash($subjects['ip'])
			);
		}
	}


	/**
	 * Delete counters that have not changed for a long time, and return how many were deleted.
	 *
	 * Meant for a scheduled cleanup, such as a daily CLI command.
	 *
	 * Behavior:
	 * - Deletes the counters of each configured context that have not changed for its
	 *   prune_after_seconds, or for 604800 seconds (7 days) when that key is missing or below 1.
	 * - Deletes the counters of every other context after 2592000 seconds (30 days) without a
	 *   change. A context whose cfg value is not an array counts as not configured.
	 *
	 * Notes:
	 * - A prune_after_seconds below the context's retry_after_seconds lets prune() end a block
	 *   early.
	 *
	 * Typical usage:
	 *   $deleted = $this->app->bruteForce->prune();
	 *
	 * @return int  Number of deleted counters.
	 */
	public function prune(): int {

		$now = \time();
		$total = 0;
		$configuredContexts = [];

		foreach ($this->contexts as $context => $raw) {
			if (!\is_array($raw)) {
				continue;
			}

			$configuredContexts[] = (string)$context;

			$pruneAfter = (int)($raw['prune_after_seconds'] ?? 604800);
			if ($pruneAfter < 1) {
				$pruneAfter = 604800;
			}

			$total += $this->repo->deleteByContextOlderThan((string)$context, $now - $pruneAfter);
		}

		// Orphan sweep: rows for contexts no longer in config, older than 30 days.
		$total += $this->repo->deleteOrphansOlderThan($configuredContexts, $now - 2592000);

		return $total;

	}


	// ----------------------------------------------------------------
	// Internal helpers
	// ----------------------------------------------------------------

	/**
	 * Count one failed attempt of one subject.
	 *
	 * Writes only through statements that decide on the counter row itself, so concurrent
	 * attempts of the subject are counted exactly, also at the limit.
	 *
	 * @param  string  $context  Checked context name.
	 * @param  string  $subjectType  'identifier' or 'ip'.
	 * @param  string  $subjectValue  Normalized subject; hashed here.
	 * @param  int  $maxAttempts  Attempts at which the subject is blocked.
	 * @param  int  $intervalMinutes  Length of the window in minutes.
	 * @param  int  $retryAfterSeconds  Length of a block in seconds.
	 * @param  int  $now  Current UNIX timestamp.
	 * @return void
	 * @see \CitOmni\Infrastructure\Service\BruteForce::record()  The rules for a failed attempt.
	 */
	private function recordSubject(string $context, string $subjectType, string $subjectValue, int $maxAttempts, int $intervalMinutes, int $retryAfterSeconds, int $now): void {

		$subjectHash = $this->hash($subjectValue);
		// Must agree with isWindowActive(): A window that started after this moment is open.
		$windowStartAfter = $now - $intervalMinutes * 60;
		$blockedUntil = $now + $retryAfterSeconds;
		// The first attempt of a window reaches the limit only when the limit is 1.
		$firstAttemptBlockedUntil = $maxAttempts <= 1 ? $blockedUntil : 0;

		if ($this->repo->insertIfMissing($context, $subjectType, $subjectHash, $now, $firstAttemptBlockedUntil)) {
			return;
		}

		// No match needs no handling: The subject is blocked, or a concurrent clear() deleted the
		// counter after the insert above, which ends as if the clear had come after this attempt.
		$this->repo->countFailure(
			$context,
			$subjectType,
			$subjectHash,
			$now,
			$windowStartAfter,
			$maxAttempts,
			$blockedUntil,
			$firstAttemptBlockedUntil
		);
	}


	/**
	 * Return the checked limits of one context, checking them on its first use.
	 *
	 * @param  string  $context  Trimmed context name.
	 * @return array{max_identifier_attempts:int,max_ip_attempts:int,interval_minutes:int,retry_after_seconds:int}
	 * @throws \CitOmni\Infrastructure\Exception\BruteForceConfigException  When the context is not configured as an array, its name is not valid UTF-8 or longer than BruteForceRepository::CONTEXT_MAX_LENGTH characters, or one of the four limits is missing or below 1.
	 */
	private function resolveContext(string $context): array {

		if (isset($this->resolvedContexts[$context])) {
			return $this->resolvedContexts[$context];
		}

		if (!isset($this->contexts[$context]) || !\is_array($this->contexts[$context])) {
			throw new BruteForceConfigException(
				"Brute force context '{$context}' is not configured. "
				. 'Add it under security.bruteforce.'
			);
		}

		// Characters, the unit of CONTEXT_MAX_LENGTH; false for invalid UTF-8. A name that does not
		// fit would be stored altered, where no later lookup finds it.
		$nameLength = \preg_match_all('/./su', $context);

		if ($nameLength === false || $nameLength > BruteForceRepository::CONTEXT_MAX_LENGTH) {
			throw new BruteForceConfigException(
				"Brute force context '{$context}': the name must be valid UTF-8 of at most "
				. BruteForceRepository::CONTEXT_MAX_LENGTH . ' characters.'
			);
		}

		$raw = $this->contexts[$context];

		$maxIdent = (int)($raw['max_identifier_attempts'] ?? 0);
		$maxIp    = (int)($raw['max_ip_attempts'] ?? 0);
		$interval = (int)($raw['interval_minutes'] ?? 0);
		$retry    = (int)($raw['retry_after_seconds'] ?? 0);

		if ($maxIdent < 1 || $maxIp < 1) {
			throw new BruteForceConfigException(
				"Brute force context '{$context}': "
				. 'max_identifier_attempts and max_ip_attempts must be >= 1.'
			);
		}

		if ($interval < 1) {
			throw new BruteForceConfigException(
				"Brute force context '{$context}': interval_minutes must be >= 1."
			);
		}

		if ($retry < 1) {
			throw new BruteForceConfigException(
				"Brute force context '{$context}': retry_after_seconds must be >= 1."
			);
		}

		$resolved = [
			'max_identifier_attempts' => $maxIdent,
			'max_ip_attempts'         => $maxIp,
			'interval_minutes'        => $interval,
			'retry_after_seconds'     => $retry,
		];

		$this->resolvedContexts[$context] = $resolved;

		return $resolved;

	}


	/**
	 * Return a context name trimmed, rejecting an empty one.
	 *
	 * @param  string  $context  Context name as the caller passed it.
	 * @return string
	 * @throws \InvalidArgumentException  When the name is empty after trimming.
	 */
	private function validateContext(string $context): string {
		$context = \trim($context);

		if ($context === '') {
			throw new \InvalidArgumentException('Context cannot be empty.');
		}

		return $context;
	}


	/**
	 * Normalize and check the subjects of one call.
	 *
	 * Behavior:
	 * - An identifier is trimmed and lowercased, as UTF-8 when mbstring is available. '' after
	 *   trimming means no identifier.
	 * - An IP address is trimmed, lowercased so IPv6 hex digits compare alike, and checked.
	 *   'unknown' and 'cli' mean no IP subject but still count as given, so a call with only
	 *   such an IP returns two nulls instead of throwing.
	 *
	 * @param  string|null  $identifier  Identifier as the caller passed it.
	 * @param  string|null  $ip  IP address as the caller passed it.
	 * @return array{identifier:string|null,ip:string|null}  Normalized subjects; null for none.
	 * @throws \InvalidArgumentException  When the IP address is invalid, or neither subject is given.
	 */
	private function normalizeSubjects(?string $identifier, ?string $ip): array {

		$ident = null;
		$ipVal = null;

		if ($identifier !== null) {
			$identifier = \trim($identifier);

			if ($identifier !== '') {
				$ident = \function_exists('mb_strtolower')
					? \mb_strtolower($identifier, 'UTF-8')
					: \strtolower($identifier);
			}
		}

		if ($ip !== null) {
			$ip = \trim($ip);

			if ($ip !== '') {
				$ipVal = \strtolower($ip);

				// Treat transport sentinel values as "no IP dimension".
				if ($ipVal === 'unknown' || $ipVal === 'cli') {
					$ipVal = null;
				}
			}
		}

		if ($ipVal !== null && \filter_var($ipVal, \FILTER_VALIDATE_IP) === false) {
			throw new \InvalidArgumentException('Invalid IP address: ' . $ipVal);
		}

		if ($ident === null && ($ip === null || $ip === '')) {
			throw new \InvalidArgumentException('At least one of $identifier or $ip must be non-empty.');
		}

		return [
			'identifier' => $ident,
			'ip' => $ipVal,
		];
	}


	/**
	 * Hash a normalized subject for storage and lookup.
	 *
	 * @param  string  $value  Normalized subject.
	 * @return string  SHA-256 hex digest, 64 characters.
	 */
	private function hash(string $value): string {
		return \hash('sha256', $value);
	}


	/**
	 * Tell whether a window is still open.
	 *
	 * @param  int  $windowStart  UNIX timestamp of the window start.
	 * @param  int  $intervalMinutes  Length of the window in minutes.
	 * @param  int  $now  Current UNIX timestamp.
	 * @return bool
	 */
	private function isWindowActive(int $windowStart, int $intervalMinutes, int $now): bool {
		return ($windowStart + ($intervalMinutes * 60)) > $now;
	}

}
