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

namespace CitOmni\Infrastructure\Tests\BruteForce;

use CitOmni\Infrastructure\Exception\BruteForceConfigException;
use CitOmni\Infrastructure\Exception\BruteForceSetupException;
use CitOmni\Infrastructure\Repository\DatabaseSchemaRepository;
use CitOmni\Infrastructure\Service\BruteForce;
use CitOmni\Infrastructure\Service\Db;
use CitOmni\Infrastructure\Tests\Support\App;

use function CitOmni\Infrastructure\Tests\Support\childReport;
use function CitOmni\Infrastructure\Tests\Support\connect;
use function CitOmni\Infrastructure\Tests\Support\createTestDatabase;
use function CitOmni\Infrastructure\Tests\Support\dbCfg;
use function CitOmni\Infrastructure\Tests\Support\dropTestDatabase;
use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\importSql;
use function CitOmni\Infrastructure\Tests\Support\removeTree;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\startPhp;
use function CitOmni\Infrastructure\Tests\Support\tempDir;
use function CitOmni\Infrastructure\Tests\Support\testSecrets;
use function CitOmni\Infrastructure\Tests\Support\thrown;
use function CitOmni\Infrastructure\Tests\Support\waitForFiles;

/*
 * Database suite for \CitOmni\Infrastructure\Service\BruteForce with its
 * repositories against a real MySQL/MariaDB server and the shipped schema in
 * sql/citomni_bruteforce.sql.
 *
 * The real BruteForce, BruteForceRepository, DatabaseSchemaRepository and Db
 * run on the kernel doubles, without Composer. The run creates one database,
 * citomni_infrastructure_brute_force_test_<random>, imports the schema before
 * every check, and drops only that database in finally. Time is simulated by
 * moving the stored timestamps back.
 *
 * Usage:
 *   php tests/brute-force/database.php
 *
 * Notes:
 * - Connects as root to 127.0.0.1:3306 over TCP. The password is read only from
 *   CITOMNI_TEST_PASSWORD; unset means no password. Workers inherit it through
 *   the environment.
 * - The multi-process check runs only with CITOMNI_TEST_PARALLEL=1. It starts
 *   worker.php several times at once.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);

// Fail fast on every diagnostic; like the production ErrorHandler, leave
// diagnostics silenced with @ to PHP.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';
require __DIR__ . '/../support/test-db.php';
require __DIR__ . '/../../src/Enum/TransactionIsolation.php';
require __DIR__ . '/../../src/Exception/DbException.php';
require __DIR__ . '/../../src/Exception/DbConnectException.php';
require __DIR__ . '/../../src/Exception/DbQueryException.php';
require __DIR__ . '/../../src/Exception/BruteForceException.php';
require __DIR__ . '/../../src/Exception/BruteForceConfigException.php';
require __DIR__ . '/../../src/Exception/BruteForceSetupException.php';
require __DIR__ . '/../../src/Service/Db.php';
require __DIR__ . '/../../src/Repository/BruteForceRepository.php';
require __DIR__ . '/../../src/Repository/DatabaseSchemaRepository.php';
require __DIR__ . '/../../src/Service/BruteForce.php';

/** The shipped schema; the path must exist. */
const SCHEMA = __DIR__ . '/../../sql/citomni_bruteforce.sql';

/** Contexts for the checks. "api" has no prune_after_seconds and uses the 7-day default. */
const CONTEXTS = [
	'login' => ['max_identifier_attempts' => 3, 'max_ip_attempts' => 5, 'interval_minutes' => 15, 'retry_after_seconds' => 900, 'prune_after_seconds' => 3600],
	'api'   => ['max_identifier_attempts' => 4, 'max_ip_attempts' => 4, 'interval_minutes' => 10, 'retry_after_seconds' => 60],
];

/** Workers in the multi-process check. */
const PARALLEL_WORKERS = 4;

/** Failures each worker records. */
const PARALLEL_RECORDS = 25;


/**
 * The run's admin connection and database.
 */
final class Env {

	public static \mysqli $admin;

	public static string $database = '';
}


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/** An App with a Db service on the test database and the given brute-force contexts. */
function app(array $contexts = CONTEXTS): App {
	$app = new App(['db' => dbCfg(Env::$database), 'security' => ['bruteforce' => $contexts]]);
	$app->set('secrets', testSecrets());
	$app->set('db', new Db($app));
	return $app;
}


/** A BruteForce service on the test database. */
function bruteForce(array $contexts = CONTEXTS): BruteForce {
	return new BruteForce(app($contexts));
}


/**
 * Every counter row as text, ordered by id.
 *
 * @return list<array<string, string>>
 */
function rows(): array {
	return Env::$admin->query('SELECT * FROM bruteforce_counters ORDER BY id')->fetch_all(\MYSQLI_ASSOC);
}


/** The counter row of one subject with native integer columns, or null. */
function row(string $context, string $type, string $normalizedValue): ?array {
	$stmt = Env::$admin->prepare('SELECT * FROM bruteforce_counters WHERE context = ? AND subject_type = ? AND subject_hash = ?');
	$hash = \hash('sha256', $normalizedValue);
	$stmt->bind_param('sss', $context, $type, $hash);
	$stmt->execute();
	return $stmt->get_result()->fetch_assoc();
}


/** Move window_start, updated_at and an active block of a subject back by $seconds, as if that time had passed. */
function age(string $context, string $type, string $normalizedValue, int $seconds): void {
	$stmt = Env::$admin->prepare(
		'UPDATE bruteforce_counters SET window_start = window_start - ?, updated_at = updated_at - ?, blocked_until = IF(blocked_until > 0, blocked_until - ?, 0)'
		. ' WHERE context = ? AND subject_type = ? AND subject_hash = ?'
	);
	$hash = \hash('sha256', $normalizedValue);
	$stmt->bind_param('iiisss', $seconds, $seconds, $seconds, $context, $type, $hash);
	$stmt->execute();
}


/** Move only window_start and updated_at of a subject back by $seconds; blocked_until stays. */
function ageWindow(string $context, string $type, string $normalizedValue, int $seconds): void {
	$stmt = Env::$admin->prepare(
		'UPDATE bruteforce_counters SET window_start = window_start - ?, updated_at = updated_at - ?'
		. ' WHERE context = ? AND subject_type = ? AND subject_hash = ?'
	);
	$hash = \hash('sha256', $normalizedValue);
	$stmt->bind_param('iisss', $seconds, $seconds, $context, $type, $hash);
	$stmt->execute();
}


/** Insert a counter row directly, for prune() checks. */
function seed(string $context, string $subject, int $updatedAt): void {
	$stmt = Env::$admin->prepare(
		'INSERT INTO bruteforce_counters (context, subject_type, subject_hash, window_start, attempt_count, blocked_until, created_at, updated_at)'
		. " VALUES (?, 'identifier', ?, ?, 1, 0, ?, ?)"
	);
	$hash = \hash('sha256', $subject);
	$stmt->bind_param('ssiii', $context, $hash, $updatedAt, $updatedAt, $updatedAt);
	$stmt->execute();
}


/** The subset of a status() result named by $keys. */
function pick(array $status, string ...$keys): array {
	return \array_intersect_key($status, \array_flip($keys));
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'assertStorageReady() fails with a setup exception until citomni_bruteforce.sql is imported' => static function (): void {
		Env::$admin->query('DROP TABLE bruteforce_counters');
		$bf = bruteForce();
		$e = thrown(BruteForceSetupException::class, static fn() => $bf->assertStorageReady(), 'without the table');
		same('bruteforce_counters', $e->getMissingTable(), 'missing table');
		expect(\str_contains((string)$e->getHint(), 'citomni_bruteforce.sql'), 'hint does not name the schema file: ' . export($e->getHint()));
		importSql(Env::$admin, (string)\file_get_contents(SCHEMA));
		$bf->assertStorageReady();
		$schema = new DatabaseSchemaRepository(app());
		same([true, false], [$schema->tableExists('bruteforce_counters'), $schema->tableExists('bruteforce_counters_old')], 'tableExists()');
		thrown(\InvalidArgumentException::class, static fn() => $schema->tableExists('x` OR 1=1 -- '), 'unsafe table name');
	},

	'the identifier is blocked when its failures reach max_identifier_attempts' => static function (): void {
		$bf = bruteForce();
		$keys = ['blocked', 'reason', 'identifier_attempts', 'identifier_remaining', 'max_identifier_attempts', 'max_ip_attempts', 'interval_minutes'];
		same(['blocked' => false, 'reason' => null, 'identifier_attempts' => 0, 'identifier_remaining' => 3, 'max_identifier_attempts' => 3, 'max_ip_attempts' => 5, 'interval_minutes' => 15], pick($bf->status('login', 'user@example.com'), ...$keys), 'before any failure');
		$bf->record('login', 'user@example.com');
		$bf->record('login', 'user@example.com');
		same(['blocked' => false, 'identifier_attempts' => 2, 'identifier_remaining' => 1], pick($bf->status('login', 'user@example.com'), 'blocked', 'identifier_attempts', 'identifier_remaining'), 'after two failures');
		$before = \time();
		$bf->record('login', 'user@example.com');
		$status = $bf->status('login', 'user@example.com');
		same(['blocked' => true, 'reason' => 'identifier', 'identifier_attempts' => 3, 'identifier_remaining' => 0], pick($status, 'blocked', 'reason', 'identifier_attempts', 'identifier_remaining'), 'after three failures');
		expect($status['retry_after_seconds'] >= 898 && $status['retry_after_seconds'] <= 900, 'retry_after_seconds: ' . $status['retry_after_seconds']);
		expect($status['blocked_until'] >= $before + 900 && $status['blocked_until'] <= \time() + 900, 'blocked_until: ' . $status['blocked_until']);
	},

	'identifiers are trimmed and lower-cased, and only their SHA-256 hashes are stored' => static function (): void {
		$bf = bruteForce();
		$bf->record('login', ' USER@Example.COM ');
		$bf->record('login', 'user@example.com');
		$rows = rows();
		same(1, \count($rows), 'rows');
		same(['login', 'identifier', \hash('sha256', 'user@example.com'), '2'], [$rows[0]['context'], $rows[0]['subject_type'], $rows[0]['subject_hash'], $rows[0]['attempt_count']], 'row');
		expect(!\str_contains(\strtolower(\json_encode($rows)), 'example'), 'plain identifier stored: ' . export($rows));
		same(2, $bf->status('login', "\tUser@example.com\n")['identifier_attempts'], 'lookup with other case and whitespace');
	},

	'IP addresses are throttled on their own, and reason is "both" when both are blocked' => static function (): void {
		$bf = bruteForce();
		for ($i = 0; $i < 5; $i++) {
			$bf->record('login', null, '203.0.113.7');
		}
		same(['blocked' => true, 'reason' => 'ip', 'ip_attempts' => 5, 'ip_remaining' => 0], pick($bf->status('login', null, '203.0.113.7'), 'blocked', 'reason', 'ip_attempts', 'ip_remaining'), 'IP only');
		same(['blocked' => true, 'reason' => 'ip', 'identifier_attempts' => 0], pick($bf->status('login', 'other@example.com', '203.0.113.7'), 'blocked', 'reason', 'identifier_attempts'), 'clean identifier behind a blocked IP');
		same(false, $bf->status('login', 'other@example.com', '203.0.113.8')['blocked'], 'another IP');
		for ($i = 0; $i < 3; $i++) {
			$bf->record('login', 'other@example.com', '203.0.113.7');
		}
		$status = $bf->status('login', 'other@example.com', '203.0.113.7');
		same(['blocked' => true, 'reason' => 'both', 'identifier_attempts' => 3, 'ip_attempts' => 5], pick($status, 'blocked', 'reason', 'identifier_attempts', 'ip_attempts'), 'both blocked');
		$latest = \max(row('login', 'ip', '203.0.113.7')['blocked_until'], row('login', 'identifier', 'other@example.com')['blocked_until']);
		same($latest, $status['blocked_until'], 'blocked_until is the later of the two blocks');
	},

	'IPv6 addresses are lower-cased before hashing' => static function (): void {
		$bf = bruteForce();
		$bf->record('login', null, '2001:DB8::1');
		$bf->record('login', null, '2001:db8::1');
		same(1, \count(rows()), 'rows');
		same(2, row('login', 'ip', '2001:db8::1')['attempt_count'] ?? null, 'attempt_count');
	},

	'the transport sentinels "unknown" and "cli" give no IP subject' => static function (): void {
		$bf = bruteForce();
		$bf->record('login', null, 'CLI');
		$bf->record('login', null, ' unknown ');
		same([], rows(), 'rows after IP-only records with sentinels');
		same(['blocked' => false, 'ip_attempts' => 0], pick($bf->status('login', null, 'Unknown'), 'blocked', 'ip_attempts'), 'status with only a sentinel');
		$bf->record('login', 'user@example.com', 'cli');
		same(['identifier'], \array_column(rows(), 'subject_type'), 'subjects recorded with a sentinel IP');
		$bf->clear('login', null, 'cli');
		same(1, \count(rows()), 'rows after clearing with only a sentinel');
	},

	'invalid input is rejected before any lookup' => static function (): void {
		$bf = bruteForce();
		foreach (['status', 'record', 'clear'] as $method) {
			foreach (['', '  '] as $context) {
				thrown(\InvalidArgumentException::class, static fn() => $bf->{$method}($context, 'user@example.com'), "{$method}() with context " . export($context));
			}
			foreach ([[null, null], ['', '  '], ['  ', null]] as [$identifier, $ip]) {
				thrown(\InvalidArgumentException::class, static fn() => $bf->{$method}('login', $identifier, $ip), "{$method}() without subjects " . export([$identifier, $ip]));
			}
			foreach (['999.1.1.1', 'localhost', '10.0.0.0/8', '1.2.3'] as $ip) {
				thrown(\InvalidArgumentException::class, static fn() => $bf->{$method}('login', 'user@example.com', $ip), "{$method}() with IP " . export($ip));
			}
		}
		thrown(BruteForceConfigException::class, static fn() => $bf->status('unknown_context', 'user@example.com'), 'status() for an unconfigured context');
		thrown(BruteForceConfigException::class, static fn() => $bf->record('unknown_context', 'user@example.com'), 'record() for an unconfigured context');
		$bf->clear('unknown_context', 'user@example.com');
		same([], rows(), 'rows');
	},

	'contexts are validated on first use and the config node is required' => static function (): void {
		$valid = CONTEXTS['login'];
		$broken = [
			'zero_identifier' => ['max_identifier_attempts' => 0] + $valid,
			'zero_ip'         => ['max_ip_attempts' => 0] + $valid,
			'zero_interval'   => ['interval_minutes' => 0] + $valid,
			'zero_retry'      => ['retry_after_seconds' => 0] + $valid,
			'missing_keys'    => ['prune_after_seconds' => 60],
			'scalar'          => 5,
		];
		$bf = bruteForce($broken + ['ok' => $valid]);
		foreach (\array_keys($broken) as $context) {
			thrown(BruteForceConfigException::class, static fn() => $bf->status($context, 'user@example.com'), "context {$context}");
		}
		same(false, $bf->status('ok', 'user@example.com')['blocked'], 'valid context next to broken ones');
		thrown(BruteForceConfigException::class, static fn() => new BruteForce(new App(['security' => []])), 'no security.bruteforce');
		thrown(BruteForceConfigException::class, static fn() => new BruteForce(new App(['security' => ['bruteforce' => []]])), 'empty security.bruteforce');
	},

	'a blocked subject is not counted further' => static function (): void {
		$bf = bruteForce();
		for ($i = 0; $i < 3; $i++) {
			$bf->record('login', 'user@example.com');
		}
		$blocked = row('login', 'identifier', 'user@example.com');
		$bf->record('login', 'user@example.com');
		$bf->record('login', 'user@example.com');
		same($blocked, row('login', 'identifier', 'user@example.com'), 'row after more failures');
	},

	'an expired window is ignored by status() and restarted by the next failure' => static function (): void {
		$bf = bruteForce();
		$bf->record('login', 'user@example.com');
		$bf->record('login', 'user@example.com');
		age('login', 'identifier', 'user@example.com', 16 * 60);
		$stale = row('login', 'identifier', 'user@example.com');
		same(['blocked' => false, 'identifier_attempts' => 0, 'identifier_remaining' => 3], pick($bf->status('login', 'user@example.com'), 'blocked', 'identifier_attempts', 'identifier_remaining'), 'status for a stale window');
		same($stale, row('login', 'identifier', 'user@example.com'), 'row after status()');
		$before = \time();
		$bf->record('login', 'user@example.com');
		$row = row('login', 'identifier', 'user@example.com');
		same([1, 0], [$row['attempt_count'], $row['blocked_until']], 'attempt_count and blocked_until after the restart');
		expect($row['window_start'] >= $before, 'window_start was not restarted: ' . $row['window_start']);
	},

	'an active block outlives its window, and an expired block allows a fresh window' => static function (): void {
		$bf = bruteForce();
		for ($i = 0; $i < 3; $i++) {
			$bf->record('login', 'user@example.com');
		}
		ageWindow('login', 'identifier', 'user@example.com', 20 * 60);
		same(true, $bf->status('login', 'user@example.com')['blocked'], 'block with an expired window');
		age('login', 'identifier', 'user@example.com', 15 * 60);
		same(['blocked' => false, 'identifier_attempts' => 0], pick($bf->status('login', 'user@example.com'), 'blocked', 'identifier_attempts'), 'after the block expired');
		$bf->record('login', 'user@example.com');
		$row = row('login', 'identifier', 'user@example.com');
		same([1, 0], [$row['attempt_count'], $row['blocked_until']], 'attempt_count and blocked_until');
	},

	'clear() removes only the requested buckets, also for unconfigured contexts' => static function (): void {
		$bf = bruteForce();
		$bf->record('login', 'user@example.com', '198.51.100.1');
		$bf->record('api', 'user@example.com');
		$bf->clear('login', 'user@example.com');
		same([['login', 'ip'], ['api', 'identifier']], \array_map(static fn(array $r): array => [$r['context'], $r['subject_type']], rows()), 'after clearing the login identifier');
		$bf->clear('login', null, '198.51.100.1');
		same([['api', 'identifier']], \array_map(static fn(array $r): array => [$r['context'], $r['subject_type']], rows()), 'after clearing the login IP');
		seed('removed_context', 'user@example.com', \time());
		$bf->clear('removed_context', ' USER@example.com');
		same(1, \count(rows()), 'rows after clearing an unconfigured context');
	},

	'prune() applies prune_after_seconds per context and removes orphans after 30 days' => static function (): void {
		$now = \time();
		seed('login', 'old', $now - 7200);
		seed('login', 'recent', $now - 60);
		seed('api', 'old', $now - 8 * 86400);
		seed('api', 'recent', $now - 6 * 86400);
		seed('removed_context', 'old', $now - 31 * 86400);
		seed('removed_context', 'recent', $now - 29 * 86400);
		same(3, bruteForce()->prune(), 'deleted rows');
		$left = \array_map(static fn(array $r): string => $r['context'] . ':' . $r['subject_hash'], rows());
		\sort($left);
		$expected = ['api:' . \hash('sha256', 'recent'), 'login:' . \hash('sha256', 'recent'), 'removed_context:' . \hash('sha256', 'recent')];
		\sort($expected);
		same($expected, $left, 'rows left');
	},

	'status() never writes' => static function (): void {
		$bf = bruteForce();
		for ($i = 0; $i < 3; $i++) {
			$bf->record('login', 'blocked@example.com', '198.51.100.2');
		}
		$bf->record('login', 'stale@example.com');
		age('login', 'identifier', 'stale@example.com', 3600);
		$before = rows();
		foreach (['blocked@example.com', 'stale@example.com', 'unknown@example.com'] as $identifier) {
			$bf->status('login', $identifier, '198.51.100.2');
			$bf->status('login', $identifier);
		}
		same($before, rows(), 'rows');
	},

];

// Multi-process checks; they run only with CITOMNI_TEST_PARALLEL=1.
$parallelChecks = [

	'concurrent failures create one bucket per subject and lose no increments' => static function (): void {
		$dir   = tempDir('brute_force');
		$gate  = $dir . '/go';
		$procs = [];
		$ready = [];
		try {
			for ($w = 0; $w < PARALLEL_WORKERS; $w++) {
				$ready[$w] = $dir . "/ready{$w}";
				$procs[$w] = startPhp([__DIR__ . '/worker.php', Env::$database, (string)PARALLEL_RECORDS, $ready[$w], $gate], $dir . "/worker{$w}.out", $dir . "/worker{$w}.err");
			}

			// Open the start gate only when every worker is connected and ready.
			$allReady = waitForFiles($ready, 10.0);
			\touch($gate);

			$exits = [];
			foreach ($procs as $w => $proc) {
				$exits[$w] = \proc_close($proc);
			}
			foreach ($exits as $w => $exit) {
				same(0, $exit, "worker {$w} exit code (" . childReport($exit, $dir . "/worker{$w}.out", $dir . "/worker{$w}.err") . ')');
			}
			expect($allReady, 'not every worker was ready within 10 seconds');
		} finally {
			removeTree($dir);
		}

		$expected = PARALLEL_WORKERS * PARALLEL_RECORDS;
		same(2, \count(rows()), 'rows');
		$identifier = row('race', 'identifier', 'shared@example.com') ?? [];
		same([$expected, 0], [$identifier['attempt_count'] ?? null, $identifier['blocked_until'] ?? null], 'identifier attempt_count and blocked_until');
		same($expected, row('race', 'ip', '198.51.100.9')['attempt_count'] ?? null, 'IP attempt_count');
	},

];


Env::$admin = connect();
Env::$database = createTestDatabase(Env::$admin, 'brute_force');
$exitCode = 1;
try {
	Env::$admin->select_db(Env::$database);
	// The schema drops and recreates the table, so every check starts from an
	// empty table, also after a check that dropped it.
	$exitCode = runChecks($checks, $parallelChecks, static function (): void {
		importSql(Env::$admin, (string)\file_get_contents(SCHEMA));
	});
} finally {
	dropTestDatabase(Env::$admin, Env::$database);
}
exit($exitCode);
