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

namespace CitOmni\Infrastructure\Tests\Db;

use CitOmni\Infrastructure\Enum\TransactionIsolation;
use CitOmni\Infrastructure\Exception\DbConnectException;
use CitOmni\Infrastructure\Exception\DbQueryException;
use CitOmni\Infrastructure\Service\Db;
use CitOmni\Infrastructure\Tests\Support\App;
use CitOmni\Infrastructure\Tests\Support\MemorySecrets;

use function CitOmni\Infrastructure\Tests\Support\connect;
use function CitOmni\Infrastructure\Tests\Support\createTestDatabase;
use function CitOmni\Infrastructure\Tests\Support\dbCfg;
use function CitOmni\Infrastructure\Tests\Support\dropTestDatabase;
use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\testSecrets;
use function CitOmni\Infrastructure\Tests\Support\thrown;

/*
 * Database suite for \CitOmni\Infrastructure\Service\Db against a real
 * MySQL/MariaDB server: queries and binding, batches, transactions and isolation,
 * the statement cache, session settings, connection recovery and error codes.
 *
 * The real service runs on the kernel doubles, without Composer. The run creates
 * one database, citomni_infrastructure_db_test_<random>, and drops only that
 * database in finally. Each check creates the tables it uses.
 *
 * Usage:
 *   php tests/db/database.php
 *
 * Notes:
 * - Connects as root to 127.0.0.1:3306 over TCP. The password is read only from
 *   CITOMNI_TEST_PASSWORD; unset means no password.
 * - The deadlock and lock-wait checks hold row locks from a second connection.
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
require __DIR__ . '/../../src/Service/Db.php';


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

/** A Db service on the test database with $extra db cfg; the password comes from CITOMNI_TEST_PASSWORD. */
function db(array $extra = []): Db {
	$app = new App(['db' => dbCfg(Env::$database, $extra)]);
	$app->set('secrets', testSecrets());
	return new Db($app);
}


/** Drop and create a table in the test database. */
function fresh(string $table, string $columns): void {
	Env::$admin->query('DROP TABLE IF EXISTS `' . $table . '`');
	Env::$admin->query('CREATE TABLE `' . $table . '` (' . $columns . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}


/** Row count of a table, read through the admin connection. */
function rowCount(string $table): int {
	return (int)Env::$admin->query('SELECT COUNT(*) FROM `' . $table . '`')->fetch_row()[0];
}


/** Com_stmt_prepare of the Db service's own session. */
function prepares(Db $db): int {
	$result = $db->queryRaw("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'");
	$row = $result->fetch_row();
	$result->free();
	return (int)$row[1];
}


/** Fail unless $action throws DbQueryException whose message contains $messagePart. */
function queryError(\Closure $action, string $messagePart, string $what): DbQueryException {
	$e = thrown(DbQueryException::class, $action, $what);
	expect(\str_contains($e->getMessage(), $messagePart), $what . ': message lacks ' . export($messagePart) . ': ' . $e->getMessage());
	return $e;
}


/** @return list<array<string, mixed>> */
function batchRows(int $count): array {
	$rows = [];
	for ($i = 0; $i < $count; $i++) {
		$rows[] = ['name' => 'row' . $i, 'n' => $i];
	}
	return $rows;
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'insert, fetch, exists, count, update and delete work on a real table' => static function (): void {
		fresh('items', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, qty INT NOT NULL, price DECIMAL(10,2) NULL, active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uniq_name (name)');
		$db = db();
		same(1, $db->insert('items', ['name' => 'apple', 'qty' => 3, 'price' => '1.25']), 'first insert id');
		same(2, $db->insert(Env::$database . '.items', ['name' => 'pear', 'qty' => 5, 'price' => null, 'active' => false]), 'second insert id, schema-qualified');
		same(['id' => 1, 'name' => 'apple', 'qty' => 3, 'price' => '1.25', 'active' => 1], $db->fetchRow('SELECT * FROM items WHERE id = ?', [1]), 'fetchRow with native integer types');
		same(null, $db->fetchRow('SELECT * FROM items WHERE id = ?', [99]), 'fetchRow without a row');
		same([['name' => 'apple', 'price' => '1.25'], ['name' => 'pear', 'price' => null]], $db->fetchAll('SELECT name, price FROM items ORDER BY id'), 'fetchAll');
		same([], $db->fetchAll('SELECT * FROM items WHERE id > ?', [10]), 'fetchAll without rows');
		same(['apple', null], [$db->fetchValue('SELECT name FROM items WHERE qty = ?', [3]), $db->fetchValue('SELECT name FROM items WHERE id = ?', [99])], 'fetchValue');
		same([true, false], [$db->exists('items', 'name = ?', ['pear']), $db->exists('items', 'name = ?', ['plum'])], 'exists');
		same(2, $db->countRows('SELECT id FROM items'), 'countRows');
		same(1, $db->update('items', ['qty' => 4], 'name = ?', ['apple']), 'update');
		same(0, $db->update('items', ['qty' => 4], 'name = ?', ['apple']), 'update that changes nothing');
		same(1, $db->delete('items', 'id = ?', [2]), 'delete');
		same(1, rowCount('items'), 'rows left');
	},

	'parameters bind by PHP type: int, float, bool as 0/1, null as SQL NULL and strings verbatim' => static function (): void {
		$db = db();
		$text = "O'Brien \\ \"quoted\" æøå 😀 \0 end";
		same([1, 0, 1], [$db->fetchValue('SELECT ? IS NULL', [null]), $db->fetchValue('SELECT ? IS NULL', ['']), $db->fetchValue('SELECT ? = 1', [true])], 'null, empty string, true');
		same([42, 1.5, 0, $text, '0123'], [$db->fetchValue('SELECT ?', [42]), $db->fetchValue('SELECT ?', [1.5]), $db->fetchValue('SELECT ?', [false]), $db->fetchValue('SELECT ?', [$text]), $db->fetchValue('SELECT ?', ['0123'])], 'int, float, false, string, digit string');
	},

	'a failed statement raises DbQueryException with its SQL and parameter types and adds no values' => static function (): void {
		fresh('items', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, qty INT NOT NULL');
		$db = db(['sql_mode' => 'STRICT_ALL_TABLES']);
		$db->insert('items', ['name' => 'apple', 'qty' => 1]);
		$e = queryError(static fn() => $db->execute('UPDATE items SET name = ? WHERE qty = ? AND name <> ?', [null, 1, 'value-not-for-logs']), 'Params: [null, int, string]', 'NOT NULL violation');
		same(1048, $e->getCode(), 'error code');
		expect(\str_contains($e->getMessage(), 'SQL: UPDATE items SET name = ? WHERE qty = ? AND name <> ?'), 'message lacks the SQL: ' . $e->getMessage());
		expect(!\str_contains($e->getMessage(), 'value-not-for-logs'), 'message contains a parameter value: ' . $e->getMessage());
		expect($e->getPrevious() instanceof \mysqli_sql_exception, 'previous is not the mysqli exception');
		queryError(static fn() => $db->fetchValue('SELECT nope FROM items'), 'SQL: SELECT nope FROM items', 'unknown column at prepare');
	},

	'a duplicate key surfaces as DbQueryException::isDuplicateEntry()' => static function (): void {
		fresh('items', 'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(64) NOT NULL, UNIQUE KEY uniq_email (email)');
		$db = db();
		$db->insert('items', ['email' => 'a@example.invalid']);
		$e = thrown(DbQueryException::class, static fn() => $db->insert('items', ['email' => 'a@example.invalid']), 'duplicate insert');
		same([1062, true, false], [$e->getCode(), $e->isDuplicateEntry(), $e->isDeadlock()], 'code, duplicate, deadlock');
	},

	'insertBatch() sends up to 1000 rows as one multi-row INSERT' => static function (): void {
		fresh('batch', 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) NOT NULL UNIQUE, n INT NULL');
		$db = db();
		same(3, $db->insertBatch('batch', [['name' => 'a', 'n' => 1], ['name' => 'b', 'n' => null], ['name' => 'c', 'n' => 3]]), 'three rows');
		$before = prepares($db);
		$db->countQueries(true);
		same(1000, $db->insertBatch('batch', batchRows(1000)), '1000 rows');
		same([1, 1], [$db->countQueries(), prepares($db) - $before], 'statements counted and prepared');
		same(1003, rowCount('batch'), 'rows');
		same(null, $db->fetchValue('SELECT n FROM batch WHERE name = ?', ['b']), 'NULL value');
	},

	'insertBatch() above 1000 rows inserts in chunks in its own transaction and rolls back as a whole' => static function (): void {
		fresh('batch', 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) NOT NULL UNIQUE, n INT NULL');
		$db = db();
		same(1001, $db->insertBatch('batch', batchRows(1001)), '1001 rows');
		same(1001, rowCount('batch'), 'rows');

		Env::$admin->query('TRUNCATE TABLE batch');
		$rows = batchRows(1200);
		$rows[1100]['name'] = 'row5';
		$e = thrown(DbQueryException::class, static fn() => $db->insertBatch('batch', $rows), 'duplicate in the second chunk');
		same(true, $e->isDuplicateEntry(), 'duplicate entry');
		same(0, rowCount('batch'), 'rows after the failed batch');
		same(false, $db->__debugInfo()['inTransaction'], 'transaction left open');
	},

	'the chunked insertBatch() path refuses to start inside an active transaction' => static function (): void {
		fresh('batch', 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) NOT NULL UNIQUE, n INT NULL');
		$db = db();
		$db->beginTransaction();
		try {
			queryError(static fn() => $db->insertBatch('batch', batchRows(1001)), 'cannot start a transaction while one is already active', '1001 rows');
			same(2, $db->insertBatch('batch', batchRows(2)), 'small batch inside the transaction');
		} finally {
			$db->rollback();
		}
		same(0, rowCount('batch'), 'rows after rollback');
	},

	'transaction() commits the callback result and rethrows a callback exception after rolling back' => static function (): void {
		fresh('tx', 'id INT PRIMARY KEY');
		$db = db();
		same('done', $db->transaction(static function (Db $tx): string {
			$tx->insert('tx', ['id' => 1]);
			return 'done';
		}), 'result');
		same(1, rowCount('tx'), 'rows after commit');

		$failure = new \DomainException('stop');
		$caught = thrown(\DomainException::class, static fn() => $db->transaction(static function (Db $tx) use ($failure): void {
			$tx->insert('tx', ['id' => 2]);
			throw $failure;
		}), 'failing callback');
		same(true, $caught === $failure, 'the original exception is rethrown');
		same(1, rowCount('tx'), 'rows after rollback');
		same(7, $db->easyTransaction(static fn(Db $tx): int => 7), 'easyTransaction()');
		same(false, $db->__debugInfo()['inTransaction'], 'transaction left open');
	},

	'nested transactions are rejected and leave the outer transaction intact' => static function (): void {
		fresh('tx', 'id INT PRIMARY KEY');
		$db = db();
		$db->beginTransaction();
		queryError(static fn() => $db->beginTransaction(), 'Nested transactions are not supported', 'beginTransaction()');
		queryError(static fn() => $db->transaction(static fn() => null), 'Nested transactions are not supported', 'transaction()');
		$db->insert('tx', ['id' => 10]);
		$db->commit();
		same(1, rowCount('tx'), 'rows after commit');
	},

	'an isolation level applies to that one transaction only' => static function (): void {
		fresh('iso', 'id INT PRIMARY KEY');
		$db = db();
		$db->queryRaw('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		$writer = connect(Env::$database);
		$writer->begin_transaction();
		$writer->query('INSERT INTO iso (id) VALUES (1)');
		try {
			$count = static fn(Db $tx): int => (int)$tx->fetchValue('SELECT COUNT(*) FROM iso');
			same(1, $db->transaction($count, TransactionIsolation::ReadUncommitted), 'READ UNCOMMITTED sees the uncommitted row');
			same(0, $db->transaction($count), 'next transaction at the session default');
			same(0, $db->transaction($count, TransactionIsolation::ReadCommitted), 'READ COMMITTED');
			same(1, $db->transaction($count, TransactionIsolation::ReadUncommitted), 'READ UNCOMMITTED again');
			same(0, $count($db), 'outside a transaction');
		} finally {
			$writer->rollback();
			$writer->close();
		}
	},

	'the statement cache prepares each SQL once and evicts first in, first out' => static function (): void {
		$db = db(['statement_cache_limit' => 2]);
		$db->queryRaw('DO 0');
		$before = prepares($db);
		foreach (['SELECT 1', 'SELECT 2', 'SELECT 1', 'SELECT 3', 'SELECT 1'] as $sql) {
			$db->fetchValue($sql);
		}
		same(4, prepares($db) - $before, 'prepares for A, B, A, C, A with two slots');
		same(2, $db->__debugInfo()['statementCacheCount'], 'cached statements');
		$db->setStatementCacheLimit(1);
		same(1, $db->__debugInfo()['statementCacheCount'], 'after shrinking to one slot');
		$db->setStatementCacheLimit(0);
		same(0, $db->__debugInfo()['statementCacheCount'], 'after disabling');
		$before = prepares($db);
		foreach ([1, 2, 3] as $n) {
			same($n, $db->fetchValue('SELECT ?', [$n]), 'uncached select');
		}
		same(3, prepares($db) - $before, 'prepares without cache');
	},

	'a result from select() stays readable when the cached statement runs again' => static function (): void {
		$db = db();
		$first = $db->select('SELECT ? AS v', [1]);
		$second = $db->select('SELECT ? AS v', [2]);
		same([['v' => 1], ['v' => 2]], [$first->fetch_assoc(), $second->fetch_assoc()], 'rows');
		$first->free();
		$second->free();
		same(3, $db->countRows($db->select('SELECT 1 UNION SELECT 2 UNION SELECT 3')), 'countRows() on a result');
	},

	'selectNoMysqlnd() streams the same rows as fetchAll()' => static function (): void {
		fresh('stream', 'id INT PRIMARY KEY, label VARCHAR(10) NULL');
		Env::$admin->query("INSERT INTO stream VALUES (1, 'a'), (2, NULL), (3, 'c')");
		$db = db();
		$sql = 'SELECT id, label FROM stream WHERE id >= ? ORDER BY id';
		$rows = [];
		foreach ($db->selectNoMysqlnd($sql, [1]) as $row) {
			$rows[] = \array_map(static fn(mixed $value): mixed => $value, $row);
		}
		same($db->fetchAll($sql, [1]), $rows, 'rows');
		same([], \iterator_to_array($db->selectNoMysqlnd($sql, [9])), 'no rows');
	},

	'queryRaw() returns a result for reads and true for statements without one' => static function (): void {
		$db = db();
		$result = $db->queryRaw('SELECT 7 AS x');
		expect($result instanceof \mysqli_result, 'no mysqli_result');
		same(['x' => '7'], $result->fetch_assoc(), 'text protocol row');
		$result->free();
		same(true, $db->queryRaw('DO 1'), 'DO');
		queryError(static fn() => $db->queryRaw('SELEC 1'), 'SQL: SELEC 1', 'syntax error');
	},

	'queryRawMulti() returns results in order and leaves the connection usable after a failure' => static function (): void {
		$db = db();
		$results = $db->queryRawMulti('SELECT 1 AS a; DO 1; SELECT 2 AS b');
		same(3, \count($results), 'results');
		same([['a' => '1'], true, ['b' => '2']], [$results[0]->fetch_assoc(), $results[1], $results[2]->fetch_assoc()], 'results in order');
		$results[0]->free();
		$results[2]->free();
		queryError(static fn() => $db->queryRawMulti('SELECT 1; SELECT nope FROM dual; SELECT 3'), 'nope', 'failing second statement');
		same(42, $db->fetchValue('SELECT 42'), 'connection after the failure');
	},

	'sql_mode and time_zone from cfg, or the PHP time zone offset, apply to the session' => static function (): void {
		$db = db(['sql_mode' => 'ANSI_QUOTES', 'timezone' => '+05:30']);
		same(['ANSI_QUOTES', '+05:30'], [$db->fetchValue('SELECT @@SESSION.sql_mode'), $db->fetchValue('SELECT @@SESSION.time_zone')], 'sql_mode and time_zone');
		$previous = \date_default_timezone_get();
		\date_default_timezone_set('Asia/Kathmandu');
		try {
			same('+05:45', db()->fetchValue('SELECT @@SESSION.time_zone'), 'offset of Asia/Kathmandu');
		} finally {
			\date_default_timezone_set($previous);
		}
	},

	'a failing session setting leaves no half-open connection' => static function (): void {
		$db = db(['sql_mode' => 'NOT_A_MODE']);
		thrown(DbConnectException::class, static fn() => $db->fetchValue('SELECT 1'), 'invalid sql_mode');
		same(false, $db->__debugInfo()['hasConnection'], 'connection kept after the failure');
		thrown(DbConnectException::class, static fn() => $db->fetchValue('SELECT 1'), 'second attempt');
		thrown(DbConnectException::class, static fn() => db(['timezone' => 'Mars/Olympus_Mons'])->fetchValue('SELECT 1'), 'invalid time zone');
	},

	'wrong credentials fail as DbConnectException without the password in the message' => static function (): void {
		$password = 'wrong-' . \bin2hex(\random_bytes(6));
		$app = new App(['db' => dbCfg(Env::$database)]);
		$app->set('secrets', new MemorySecrets(['db.password' => $password]));
		$e = thrown(DbConnectException::class, static fn() => (new Db($app))->fetchValue('SELECT 1'), 'wrong password');
		expect(!\str_contains($e->getMessage(), $password), 'message contains the password');
	},

	'checkConnection(), ensureConnection() and reconnect() recover a killed connection' => static function (): void {
		$db = db();
		$first = (int)$db->fetchValue('SELECT CONNECTION_ID()');
		$db->ensureConnection();
		same($first, (int)$db->fetchValue('SELECT CONNECTION_ID()'), 'ensureConnection() keeps a healthy connection');
		Env::$admin->query('KILL ' . $first);
		same(false, $db->checkConnection(), 'checkConnection() after KILL');
		$db->ensureConnection();
		$second = (int)$db->fetchValue('SELECT CONNECTION_ID()');
		expect($second !== $first, 'ensureConnection() did not reconnect');
		$db->reconnect();
		$third = (int)$db->fetchValue('SELECT CONNECTION_ID()');
		expect($third !== $second, 'reconnect() did not open a new connection');
		same(true, $db->checkConnection(), 'checkConnection() after reconnect()');
	},

	'reconnect() is refused inside a transaction' => static function (): void {
		$db = db();
		$db->beginTransaction();
		try {
			queryError(static fn() => $db->reconnect(), 'Refusing to reconnect inside an active transaction', 'reconnect()');
		} finally {
			$db->rollback();
		}
	},

	'lastInsertId(), affectedRows() and countQueries() report the latest work' => static function (): void {
		fresh('counter', 'id INT AUTO_INCREMENT PRIMARY KEY, v INT NOT NULL');
		$db = db();
		$db->countQueries(true);
		$db->insert('counter', ['v' => 1]);
		$db->insert('counter', ['v' => 1]);
		$db->insert('counter', ['v' => 1]);
		same(3, $db->lastInsertId(), 'lastInsertId()');
		same(3, $db->execute('UPDATE counter SET v = v + 1'), 'execute() affected rows');
		same(3, $db->affectedRows(), 'affectedRows()');
		same([4, 0], [$db->countQueries(true), $db->countQueries()], 'countQueries() before and after reset');
	},

	'a real InnoDB deadlock surfaces as DbQueryException::isDeadlock()' => static function (): void {
		fresh('locks', 'id INT PRIMARY KEY, v INT NOT NULL');
		fresh('weight', 'id INT AUTO_INCREMENT PRIMARY KEY, v INT NOT NULL');
		Env::$admin->query('INSERT INTO locks VALUES (1, 0), (2, 0)');
		$db = db();
		$db->execute('SET SESSION innodb_lock_wait_timeout = 5');
		$other = connect(Env::$database);
		$db->beginTransaction();
		try {
			$db->execute('UPDATE locks SET v = v + 1 WHERE id = 1');

			// The other transaction modifies more rows, so InnoDB picks the Db side as the
			// victim, whichever of the two requests closes the cycle.
			$other->begin_transaction();
			$other->query('INSERT INTO weight (v) VALUES (1), (2), (3), (4), (5), (6), (7), (8), (9), (10)');
			$other->query('UPDATE locks SET v = v + 1 WHERE id = 2');
			$other->query('UPDATE locks SET v = v + 1 WHERE id = 1', \MYSQLI_ASYNC);

			$e = thrown(DbQueryException::class, static fn() => $db->execute('UPDATE locks SET v = v + 1 WHERE id = 2'), 'closing the lock cycle');
			same([1213, true, false], [$e->getCode(), $e->isDeadlock(), $e->isDuplicateEntry()], 'code, deadlock, duplicate');
		} finally {
			$db->rollback();
			$links = $errors = $rejected = [$other];
			if (\mysqli::poll($links, $errors, $rejected, 5) > 0) {
				$other->reap_async_query();
			}
			$other->rollback();
			$other->close();
		}
	},

	'a lock wait timeout is not reported as a deadlock' => static function (): void {
		fresh('locks', 'id INT PRIMARY KEY, v INT NOT NULL');
		Env::$admin->query('INSERT INTO locks VALUES (1, 0)');
		$db = db();
		$other = connect(Env::$database);
		$other->begin_transaction();
		$other->query('UPDATE locks SET v = v + 1 WHERE id = 1');
		try {
			$db->execute('SET SESSION innodb_lock_wait_timeout = 1');
			$e = thrown(DbQueryException::class, static fn() => $db->execute('UPDATE locks SET v = v + 1 WHERE id = 1'), 'waiting for a held row');
			same([1205, false], [$e->getCode(), $e->isDeadlock()], 'code, deadlock');
		} finally {
			$other->rollback();
			$other->close();
		}
	},

];


Env::$admin = connect();
Env::$database = createTestDatabase(Env::$admin, 'db');
$exitCode = 1;
try {
	Env::$admin->select_db(Env::$database);
	$exitCode = runChecks($checks);
} finally {
	dropTestDatabase(Env::$admin, Env::$database);
}
exit($exitCode);
