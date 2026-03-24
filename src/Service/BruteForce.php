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
use CitOmni\Infrastructure\Repository\BruteForceRepository;
use CitOmni\Kernel\Service\BaseService;

/**
 * BruteForce: Transport-agnostic brute force protection with per-context config.
 *
 * Provides a reusable API for tracking, evaluating, and clearing failed attempts
 * per identifier (e.g. email) and/or client IP. Each context is independently
 * configured via the `security.bruteforce` config node.
 * Subject values are normalized (trimmed, lowercased) and SHA-256 hashed
 * before persistence to ensure consistent lookups and minimize data exposure.
 *
 * Behavior:
 * - Uses bucketed counters (one row per context + subject type + subject hash)
 *   instead of per-attempt event logging. This keeps row count bounded and
 *   queries predictable regardless of traffic volume.
 * - Contexts are defined entirely in config - no hardcoded action types.
 * - Both identifier-based and IP-based throttling are evaluated independently.
 *   A request is blocked if either dimension has an active blocked_until timestamp.
 * - All IPs are treated equally - no known-IP relaxation.
 * - SQL is fully delegated to BruteForceRepository; this service contains zero SQL.
 * - status() is read-only and never modifies state.
 * - record() is write-only and does not evaluate block status.
 * - clear() removes the explicitly requested bucket(s) for the given context.
 * - First inserts use INSERT IGNORE for race-safe bucket creation under
 *   concurrent requests, followed by a normal read/update flow.
 * - Identifiers are normalized (trim + mb_strtolower) and IPs are normalized
 *   (trim + strtolower) before hashing, so callers do not need to normalize.
 *
 * Notes:
 * - Requires citomni/infrastructure (Db service) to be available.
 * - The `bruteforce` config node must exist and contain at least one context.
 * - Config keys (login, 2fa, etc.) are arbitrary - any string works as a context.
 * - Config is cached as a plain array in init(); no Cfg magic on hot paths.
 * - Designed for low overhead: bounded rows, cheap lookups, no runtime reflection.
 *
 * Typical usage:
 *   // Before attempting action:
 *   $s = $this->app->bruteForce->status('login', $email, $ip);
 *   if ($s['blocked']) { /* respond with $s['retry_after_seconds'] *​/ }
 *
 *   // After failed attempt:
 *   $this->app->bruteForce->record('login', $email, $ip);
 *
 *   // After successful attempt (identifier only - see NAT note on clear()):
 *   $this->app->bruteForce->clear('login', $email);
 *
 * Required config (example):
 *   'security' => [
 *       'bruteforce' => [
 *           'default' => [
 *               'max_identifier_attempts' => 5,
 *               'max_ip_attempts'         => 25,
 *               'interval_minutes'        => 15,
 *               'retry_after_seconds'     => 900,
 *               'prune_after_seconds'     => 604800, // optional, default: 7 days
 *           ],
 *       ],
 *   ]
 *
 * @throws BruteForceConfigException When the bruteforce config node is missing or invalid.
 * @throws \InvalidArgumentException On invalid input (empty context, no subjects, etc.).
 */
final class BruteForce extends BaseService {

	/** @var array<string, array<string, mixed>> Raw context configs keyed by context name. */
	private array $contexts = [];

	/** @var array<string, array{max_identifier_attempts: int, max_ip_attempts: int, interval_minutes: int, retry_after_seconds: int}> Validated context configs, cached after first resolve. */
	private array $resolvedContexts = [];

	/** @var BruteForceRepository Persistence layer for counter rows. */
	private BruteForceRepository $repo;


	/**
	 * One-time initialization. Reads and caches the security.bruteforce config
	 * node as a plain array and instantiates the repository.
	 *
	 * Behavior:
	 * - Fails fast if the `security.bruteforce` config node is missing or empty.
	 * - Per-context validation is deferred to first access (resolveContext).
	 *
	 * @return void
	 */
	protected function init(): void {
		if (!isset($this->app->cfg->security) || !isset($this->app->cfg->security->bruteforce)) {
			throw new BruteForceConfigException(
				'Missing required config node: security.bruteforce. '
				. 'Define at least one context (for example "default") under security.bruteforce.'
			);
		}

		$this->contexts = $this->app->cfg->security->bruteforce->toArray();

		if ($this->contexts === []) {
			throw new BruteForceConfigException(
				'Config node security.bruteforce is empty. Define at least one context.'
			);
		}

		$this->repo = new BruteForceRepository($this->app);
	}


	/**
	 * status: Evaluate the current block state for an identifier and/or IP in a given context.
	 *
	 * Read-only - does not modify any state. Returns an associative array
	 * describing whether the request should be blocked, why, how long the
	 * block lasts, and how many attempts remain per dimension.
	 *
	 * Behavior:
	 * - At least one of $identifier or $ip must be non-null and non-empty.
	 * - If $identifier is null, only IP-based throttling is evaluated (and vice versa).
	 * - A subject is blocked when blocked_until > now, regardless of whether the
	 *   rolling window has technically expired.
	 * - If the rolling window has expired and there is no active block, the counter
	 *   is treated as zero (stale data is ignored without a write).
	 * - If both dimensions are blocked, reason is 'both' and retry_after_seconds
	 *   reflects the longer remaining cooldown.
	 * - retry_after_seconds is a dynamic countdown: blocked_until - now.
	 * - Inputs are normalized before lookup (trim + lowercase).
	 *
	 * Typical usage:
	 *   Called before attempting the protected action.
	 *
	 * Examples:
	 *
	 *   $s = $this->app->bruteForce->status('login', $email, $ip);
	 *   if ($s['blocked']) {
	 *       // $s['retry_after_seconds'] for Retry-After header
	 *       // $s['identifier_remaining'] for "X attempts left" message
	 *   }
	 *
	 *   // IP-only (e.g. API auth without username):
	 *   $s = $this->app->bruteForce->status('api_auth', null, $ip);
	 *
	 * Failure:
	 * - BruteForceConfigException if the context is not configured.
	 * - \InvalidArgumentException if $context is empty or both subjects are null/empty.
	 *
	 * @param string  $context    Context name matching a key in security.bruteforce config.
	 * @param ?string $identifier User identifier (email, username, etc.). Null for IP-only checks.
	 * @param ?string $ip         Client IP address (IPv4 or IPv6). Null for identifier-only checks.
	 *
	 * @return array{blocked: bool, reason: ?string, retry_after_seconds: int, blocked_until: ?int, identifier_attempts: int, ip_attempts: int, identifier_remaining: int, ip_remaining: int, max_identifier_attempts: int, max_ip_attempts: int, interval_minutes: int}
	 *
	 * @throws BruteForceConfigException When the context is not configured.
	 * @throws \InvalidArgumentException On empty $context, invalid $ip, or no subjects provided.
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
	 * record: Register a failed attempt for the given context and subject(s).
	 *
	 * Write-only - does not evaluate or return block status. Call this after a
	 * failed action (wrong password, invalid 2FA code, etc.). Callers will
	 * typically check status() first, but record() does not require a prior
	 * status() call to function correctly.
	 *
	 * Behavior:
	 * - At least one of $identifier or $ip must be non-null and non-empty.
	 * - Inputs are normalized before recording (trim + lowercase).
	 * - If a subject is currently blocked (blocked_until > now), the call is
	 *   silently ignored for that subject - no counter modification occurs.
	 * - If the rolling window has expired (and no active block), the counter is
	 *   reset to 1 and a fresh window is started.
	 * - If the rolling window is active, the counter is incremented. When the
	 *   incremented count reaches the configured maximum, blocked_until is set
	 *   to now + retry_after_seconds.
	 * - First-ever inserts use ON DUPLICATE KEY UPDATE to handle concurrent
	 *   requests racing to create the same counter row.
	 *
	 * Typical usage:
	 *   Called after the protected action has failed.
	 *
	 * Examples:
	 *
	 *   $this->app->bruteForce->record('login', $email, $ip);
	 *
	 *   // IP-only:
	 *   $this->app->bruteForce->record('api_auth', null, $ip);
	 *
	 * Failure:
	 * - BruteForceConfigException if the context is not configured.
	 * - \InvalidArgumentException if $context is empty, $ip is invalid, or no subjects.
	 *
	 * @param string  $context    Context name matching a key in bruteforce config.
	 * @param ?string $identifier User identifier. Null to skip identifier recording.
	 * @param ?string $ip         Client IP. Null to skip IP recording.
	 *
	 * @return void
	 *
	 * @throws BruteForceConfigException When the context is not configured.
	 * @throws \InvalidArgumentException On empty $context, invalid $ip, or no subjects provided.
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
	 * clear: Remove explicitly requested brute force bucket(s) for a context.
	 *
	 * Call after a successful action or from an explicit unblock flow. At least one
	 * of $identifier or $ip must be non-null and non-empty.
	 *
	 * Behavior:
	 * - This service tracks identifier and IP as two independent bucket types.
	 * - If only $identifier is provided, only the identifier bucket is removed.
	 * - If only $ip is provided, only the IP bucket is removed.
	 * - If both are provided, both independent buckets are removed.
	 * - This is NOT a combined triple-match on (context + identifier + ip), because
	 *   the underlying data model does not store a separate combined bucket.
	 * - Inputs are normalized before deletion (trim + lowercase normalization).
	 * - Does not require the context to exist in config (allows cleanup of removed contexts).
	 *
	 * Operational guidance:
	 * - Normal success flow should typically clear only the identifier bucket:
	 *   $this->app->bruteForce->clear('login', $email);
	 * - This matches the practical account-centric lockout model used by common
	 *   authentication guidance and avoids implicitly trusting IP as identity.
	 * - Clearing the IP bucket should be explicit, because it affects all activity
	 *   aggregated into that IP bucket for the context.
	 *
	 * NAT / shared-IP warning:
	 * - Clearing the IP bucket on success resets the counter for all traffic that
	 *   shares that IP in this context (e.g. shared NAT, corporate proxy, mobile carrier).
	 * - Therefore, passing $ip in normal success flows is usually NOT recommended.
	 * - Pass $ip only when you deliberately want to clear the IP bucket, such as
	 *   from an admin unblock tool or a diagnostic workflow.
	 *
	 * Typical usage:
	 *   Called after the protected action has succeeded, usually with identifier only.
	 *
	 * Examples:
	 *
	 *   // Recommended normal success flow:
	 *   $this->app->bruteForce->clear('login', $email);
	 *
	 *   // Explicit IP unblock:
	 *   $this->app->bruteForce->clear('login', null, $ip);
	 *
	 *   // Explicit full cleanup of both tracked dimensions:
	 *   $this->app->bruteForce->clear('login', $email, $ip);
	 *
	 * Failure:
	 * - \InvalidArgumentException if $context is empty, $ip is invalid, or no subjects.
	 *
	 * @param string  $context    Context name.
	 * @param ?string $identifier User identifier. Null skips identifier bucket removal.
	 * @param ?string $ip         Client IP. Null skips IP bucket removal.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException On empty $context, invalid $ip, or no subjects provided.
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
	 * prune: Delete old counter rows based on per-context prune_after_seconds config.
	 *
	 * Intended for periodic cleanup via a CLI command or scheduled task.
	 * Iterates all configured contexts and removes rows where updated_at is
	 * older than the context's prune_after_seconds (default: 604800 = 7 days).
	 * After per-context cleanup, performs an orphan sweep: rows belonging to
	 * contexts no longer present in security.bruteforce are pruned at a 30-day threshold.
	 *
	 * Typical usage:
	 *   Called from a CLI command on a cron schedule (e.g. daily).
	 *
	 * Examples:
	 *
	 *   $deleted = $this->app->bruteForce->prune();
	 *
	 * @return int Total number of deleted rows (across all contexts + orphans).
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






	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * Record a failed attempt for a single subject dimension.
	 *
	 * Uses a race-safe insert-then-read flow:
	 * - insertIfMissing() creates the bucket with attempt_count = 1 when absent
	 * - concurrent duplicate creates are ignored without error
	 * - when the row already exists, normal block/window/update logic applies
	 *
	 * @param string $context           Context name.
	 * @param string $subjectType       'identifier' or 'ip'.
	 * @param string $subjectValue      Normalized subject value (will be hashed).
	 * @param int    $maxAttempts       Configured maximum attempts for this dimension.
	 * @param int    $intervalMinutes   Rolling window length in minutes.
	 * @param int    $retryAfterSeconds Block duration in seconds.
	 * @param int    $now               Current UNIX timestamp.
	 *
	 * @return void
	 */
	private function recordSubject(string $context, string $subjectType, string $subjectValue, int $maxAttempts, int $intervalMinutes, int $retryAfterSeconds, int $now): void {

		$subjectHash = $this->hash($subjectValue);

		if ($this->repo->insertIfMissing($context, $subjectType, $subjectHash, $now)) {
			return;
		}

		$row = $this->repo->findBySubject($context, $subjectType, $subjectHash);
		if ($row === null) {
			throw new \RuntimeException(
				'Failed to load brute force counter row after insertIfMissing() reported existing row.'
			);
		}

		// Subject is actively blocked - do not modify state.
		if (((int)$row['blocked_until']) > $now) {
			return;
		}

		// Rolling window expired - reset to fresh window with count = 1.
		if (!$this->isWindowActive((int)$row['window_start'], $intervalMinutes, $now)) {
			$this->repo->resetWindow((int)$row['id'], $now);
			return;
		}

		// Window active - increment and check threshold.
		$newCount = (int)$row['attempt_count'] + 1;

		if ($newCount >= $maxAttempts) {
			$this->repo->incrementAndBlock((int)$row['id'], $now, $now + $retryAfterSeconds);
			return;
		}

		$this->repo->increment((int)$row['id'], $now);
	}


	/**
	 * Resolve and validate the config for a given context. Results are cached
	 * after first validation to avoid repeated parsing.
	 *
	 * @param string $context Context name (pre-trimmed by validateContext).
	 *
	 * @return array{max_identifier_attempts: int, max_ip_attempts: int, interval_minutes: int, retry_after_seconds: int}
	 *
	 * @throws BruteForceConfigException If the context is missing or has invalid values.
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
	 * Validate that the context name is non-empty and return trimmed value.
	 *
	 * @param string $context Context name.
	 *
	 * @return string Trimmed context name.
	 *
	 * @throws \InvalidArgumentException If empty after trimming.
	 */
	private function validateContext(string $context): string {
		$context = \trim($context);

		if ($context === '') {
			throw new \InvalidArgumentException('Context cannot be empty.');
		}

		return $context;
	}


	/**
	 * Normalize and validate subject inputs.
	 *
	 * At least one of identifier or ip must be non-null and non-empty.
	 * Identifiers are trimmed and lowercased for consistent hashing regardless
	 * of caller casing. UTF-8 is used explicitly for deterministic multibyte
	 * case folding when mbstring is available. IPs are trimmed and lowercased
	 * for IPv6 hex consistency, then validated with filter_var.
	 *
	 * @param ?string $identifier Identifier value.
	 * @param ?string $ip         IP address.
	 *
	 * @return array{identifier: ?string, ip: ?string} Normalized values or null.
	 *
	 * @throws \InvalidArgumentException On invalid IP or no subjects.
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

		if ($ident === null && $ipVal === null) {
			throw new \InvalidArgumentException('At least one of $identifier or $ip must be non-empty.');
		}

		return [
			'identifier' => $ident,
			'ip' => $ipVal,
		];
	}


	/**
	 * Hash a normalized subject value for storage and lookup.
	 *
	 * @param string $value Normalized subject value.
	 *
	 * @return string 64-character SHA-256 hex digest.
	 */
	private function hash(string $value): string {
		return \hash('sha256', $value);
	}


	/**
	 * Check whether a rolling window is still active.
	 *
	 * @param int $windowStart     Window start timestamp.
	 * @param int $intervalMinutes Window length in minutes.
	 * @param int $now             Current UNIX timestamp.
	 *
	 * @return bool True if the window has not yet expired.
	 */
	private function isWindowActive(int $windowStart, int $intervalMinutes, int $now): bool {
		return ($windowStart + ($intervalMinutes * 60)) > $now;
	}

}
