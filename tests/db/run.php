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

use CitOmni\Infrastructure\Exception\DbConnectException;
use CitOmni\Infrastructure\Exception\DbQueryException;
use CitOmni\Infrastructure\Service\Db;
use CitOmni\Infrastructure\Tests\Support\App;
use CitOmni\Infrastructure\Tests\Support\MemorySecrets;

use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\freePort;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\squash;
use function CitOmni\Infrastructure\Tests\Support\thrown;

/*
 * Standalone suite for \CitOmni\Infrastructure\Service\Db without a database
 * server: configuration, laziness and everything that must fail before a
 * connection is opened.
 *
 * The real service runs on the kernel doubles, without Composer. Unless a check
 * registers one, the App has no secrets service, so an accidental attempt to
 * connect fails with DbConnectException ("DB secret ... is missing or invalid")
 * instead of the exception the check expects. One check connects to a closed
 * local port on purpose. tests/db/database.php covers the behavior against a
 * real server.
 *
 * Usage:
 *   php tests/db/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';
require __DIR__ . '/../../src/Enum/TransactionIsolation.php';
require __DIR__ . '/../../src/Exception/DbException.php';
require __DIR__ . '/../../src/Exception/DbConnectException.php';
require __DIR__ . '/../../src/Exception/DbQueryException.php';
require __DIR__ . '/../../src/Service/Db.php';

/** A db cfg node that passes validation. */
const VALID = ['host' => '127.0.0.1', 'user' => 'app', 'name' => 'appdb'];


/** A Db service on the given db cfg node and service options; the App has no secrets service. */
function db(array $dbCfg = VALID, array $options = []): Db {
	return new Db(new App(['db' => $dbCfg]), $options);
}


/** Fail unless $action throws DbQueryException whose message contains $messagePart. */
function queryError(\Closure $action, string $messagePart, string $what): void {
	$e = thrown(DbQueryException::class, $action, $what);
	expect(\str_contains($e->getMessage(), $messagePart), $what . ': message lacks ' . export($messagePart) . ': ' . $e->getMessage());
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'host, user and name are required' => static function (): void {
		foreach (['host', 'user', 'name'] as $key) {
			$cfg = VALID;
			unset($cfg[$key]);
			$e = thrown(DbConnectException::class, static fn() => db($cfg), "without {$key}");
			same('DB config: "' . $key . '" is required.', $e->getMessage(), 'message');
			$cfg[$key] = '   ';
			thrown(DbConnectException::class, static fn() => db($cfg), "blank {$key}");
		}
	},

	'the legacy pass key is rejected instead of ignored' => static function (): void {
		$e = thrown(DbConnectException::class, static fn() => db(VALID + ['pass' => 'committed-password']), 'cfg pass');
		expect(\str_contains($e->getMessage(), 'password_secret') && !\str_contains($e->getMessage(), 'committed-password'), 'message: ' . $e->getMessage());
		thrown(DbConnectException::class, static fn() => db(VALID, ['pass' => 'x']), 'option pass');
	},

	'port, connect_timeout and statement_cache_limit take integers or digit strings in range' => static function (): void {
		$invalid = [
			'port'                  => [0, 65536, -1, '33a', '', 3306.0, null, '+3306'],
			'connect_timeout'       => [0, -1, '1.5', 1.5, null],
			'statement_cache_limit' => [-1, '-1', 'ten', 1.0, null],
		];
		foreach ($invalid as $key => $values) {
			foreach ($values as $value) {
				thrown(DbConnectException::class, static fn() => db(VALID + [$key => $value]), "{$key} " . export($value));
			}
		}
		$info = db(VALID + ['port' => '3307', 'connect_timeout' => '2', 'statement_cache_limit' => '0'])->__debugInfo();
		same([3307, 2, 0], [$info['cfgPort'], $info['cfgConnectTimeout'], $info['statementCacheLimit']], 'digit strings');
		$defaults = db()->__debugInfo();
		same([3306, 5, 128, 'utf8mb4', 'db.password', null, null, null], [$defaults['cfgPort'], $defaults['cfgConnectTimeout'], $defaults['statementCacheLimit'], $defaults['cfgCharset'], $defaults['cfgPasswordSecret'], $defaults['cfgSocket'], $defaults['cfgSqlMode'], $defaults['cfgTimezone']], 'defaults');
	},

	'password_secret must be a non-empty string' => static function (): void {
		foreach ([5, '', '   ', null, ['db.password']] as $value) {
			thrown(DbConnectException::class, static fn() => db(VALID + ['password_secret' => $value]), 'password_secret ' . export($value));
		}
		same('reporting.db.password', db(VALID + ['password_secret' => ' reporting.db.password '])->__debugInfo()['cfgPasswordSecret'], 'trimmed key');
	},

	'service options win over cfg' => static function (): void {
		$info = db(VALID, ['host' => 'replica.local', 'name' => 'reporting'])->__debugInfo();
		same(['replica.local', 'app', 'reporting'], [$info['cfgHost'], $info['cfgUser'], $info['cfgName']], 'host, user, name');
	},

	'construction opens no connection and reads no secret' => static function (): void {
		$app = new App(['db' => VALID]);
		$secrets = new MemorySecrets(['db.password' => 'never-read']);
		$app->set('secrets', $secrets);
		$db = new Db($app);
		same([], $secrets->reads, 'secret reads');
		same([false, false, 0, 0, null, 0, 0], [$db->__debugInfo()['hasConnection'], $db->checkConnection(), $db->lastInsertId(), $db->affectedRows(), $db->getLastError(), $db->getLastErrorCode(), $db->countQueries()], 'state before the first query');
	},

	// The secrets stub keeps the password in a public property without __debugInfo(),
	// so the password shows up in any dump that reaches the App.
	'debug output carries no password, also after a connection attempt' => static function (): void {
		$app = new App(['db' => VALID + ['port' => freePort(), 'connect_timeout' => 1]]);
		$app->set('secrets', new class {
			public array $values = ['db.password' => 'debug-secret-value'];

			public function get(string $key): string {
				return $this->values[$key];
			}
		});
		$db = new Db($app);
		thrown(DbConnectException::class, static fn() => $db->fetchValue('SELECT 1'), 'connecting to a closed port');
		\ob_start();
		\var_dump($db);
		$dump = (string)\ob_get_clean() . \print_r($db, true);
		expect(!\str_contains($dump, 'debug-secret-value'), 'debug output leaks the password: ' . squash($dump));
		same([], \array_values(\preg_grep('/pass(word)?$/i', \array_keys($db->__debugInfo()))), 'password-like debug keys');
	},

	'a missing password secret fails as DbConnectException without connecting' => static function (): void {
		$app = new App(['db' => VALID + ['password_secret' => 'custom.key']]);
		$secrets = new MemorySecrets();
		$app->set('secrets', $secrets);
		$e = thrown(DbConnectException::class, static fn() => (new Db($app))->fetchValue('SELECT 1'), 'first query');
		same('DB secret "custom.key" is missing or invalid.', $e->getMessage(), 'message');
		expect($e->getPrevious() instanceof \OutOfBoundsException, 'previous: ' . export($e->getPrevious()?->getMessage()));
		same(['custom.key'], $secrets->reads, 'secret reads');
	},

	'empty SQL and empty WHERE clauses fail before connecting' => static function (): void {
		$db = db();
		queryError(static fn() => $db->select("  \n"), 'select(): SQL must not be empty', 'select');
		queryError(static fn() => $db->fetchRow(''), 'select(): SQL must not be empty', 'fetchRow');
		queryError(static fn() => $db->execute(''), 'execute(): SQL must not be empty', 'execute');
		queryError(static fn() => $db->executeMany(' ', [[1]]), 'executeMany(): SQL must not be empty', 'executeMany');
		queryError(static fn() => $db->queryRaw(''), 'queryRaw(): SQL must not be empty', 'queryRaw');
		queryError(static fn() => \iterator_to_array($db->selectNoMysqlnd('')), 'selectNoMysqlnd(): SQL must not be empty', 'selectNoMysqlnd');
		queryError(static fn() => $db->exists('t', ' '), 'exists(): WHERE clause must not be empty', 'exists');
		queryError(static fn() => $db->update('t', ['a' => 1], ''), 'update(): WHERE clause must not be empty', 'update');
		queryError(static fn() => $db->delete('t', "\t"), 'delete(): WHERE clause must not be empty', 'delete');
		queryError(static fn() => $db->insert('t', []), 'insert(): data array must not be empty', 'insert');
		queryError(static fn() => $db->update('t', [], 'id = 1'), 'update(): data array must not be empty', 'update without data');
		same([], $db->queryRawMulti('  '), 'queryRawMulti with blank SQL');
		same(0, $db->executeMany('INSERT INTO t VALUES (?)', []), 'executeMany without parameter sets');
		same(0, $db->countQueries(), 'queries counted');
	},

	'table and column identifiers are validated before any SQL is sent' => static function (): void {
		$db = db();
		foreach (['users; DROP TABLE users', 'a-b', 'a b', 'db..t', '.t', 't.', '`t`', 'tæ'] as $table) {
			queryError(static fn() => $db->insert($table, ['a' => 1]), 'Invalid SQL identifier segment', 'table ' . export($table));
			queryError(static fn() => $db->delete($table, 'id = 1'), 'Invalid SQL identifier segment', 'delete from ' . export($table));
		}
		foreach (['a`b', 'a b', 'a.b', '', '1; --'] as $column) {
			queryError(static fn() => $db->insert('t', [$column => 1]), 'Invalid SQL identifier', 'column ' . export($column));
			queryError(static fn() => $db->update('t', [$column => 1], 'id = 1'), 'Invalid SQL identifier', 'update column ' . export($column));
		}
	},

	'insertBatch() validates every row against the first row before connecting' => static function (): void {
		$db = db();
		queryError(static fn() => $db->insertBatch('t', []), 'rows array must not be empty', 'no rows');
		queryError(static fn() => $db->insertBatch('t', [[]]), 'first row must be a non-empty', 'empty first row');
		queryError(static fn() => $db->insertBatch('t', [['a' => 1], 'x']), 'row #1 is not an array', 'scalar row');
		queryError(static fn() => $db->insertBatch('t', [['a' => 1], ['b' => 2]]), 'row #1 has a different column set', 'other column');
		queryError(static fn() => $db->insertBatch('t', [['a' => 1, 'b' => 2], ['a' => 1]]), 'row #1 has a different column set', 'missing column');
		queryError(static fn() => $db->insertBatch('t', [['a' => 1], ['a' => 1, 'b' => 2]]), 'row #1 has a different column set', 'extra column');
		queryError(static fn() => $db->insertBatch('t', [['a`' => 1]]), 'Invalid SQL identifier', 'column name');
	},

	'commit() and rollback() without a connection fail' => static function (): void {
		$db = db();
		queryError(static fn() => $db->commit(), 'No active database connection', 'commit');
		queryError(static fn() => $db->rollback(), 'No active database connection', 'rollback');
	},

	'DbQueryException classifies duplicate entries and deadlocks by error code' => static function (): void {
		$cases = [1062 => [true, false], 1213 => [false, true], 1205 => [false, false], 0 => [false, false]];
		foreach ($cases as $code => $expected) {
			$e = new DbQueryException('x', $code);
			same($expected, [$e->isDuplicateEntry(), $e->isDeadlock()], "code {$code}");
		}
	},

];

exit(runChecks($checks));
