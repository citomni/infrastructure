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

namespace CitOmni\Infrastructure\Tests\Support;

/*
 * Test database helpers for the database suites and their workers. Not a suite
 * of its own. Requires doubles.php.
 *
 * - Always root on 127.0.0.1:3306 over TCP. The password is read only from
 *   CITOMNI_TEST_PASSWORD; unset means no password. Application configuration is
 *   never loaded.
 * - The password reaches the Db service through MemorySecrets, in memory only. It
 *   is never written to disk, printed or passed on a command line; workers read
 *   it from the environment they inherit.
 */

/** Connect to the local test server as root; never load application configuration. */
function connect(string $database = ''): \mysqli {
	// Unset CITOMNI_TEST_PASSWORD means no password.
	$db = new \mysqli('127.0.0.1', 'root', (string)\getenv('CITOMNI_TEST_PASSWORD'), $database, 3306);
	$db->set_charset('utf8mb4');
	return $db;
}


/**
 * Create the run's database, named citomni_infrastructure_<suite>_test_<random>.
 *
 * @return string  Database name; generated here, never supplied from outside.
 */
function createTestDatabase(\mysqli $admin, string $suite): string {
	$database = 'citomni_infrastructure_' . $suite . '_test_' . \bin2hex(\random_bytes(6));
	$admin->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
	return $database;
}


/** Drop a database created by createTestDatabase(). Any other name is left alone. */
function dropTestDatabase(\mysqli $admin, string $database): void {
	if (\preg_match('/^citomni_infrastructure_[a-z_]+_test_[0-9a-f]{12}$/', $database) !== 1) {
		throw new \LogicException('Refusing to drop a database this suite did not create: ' . $database);
	}
	$admin->query('DROP DATABASE IF EXISTS `' . $database . '`');
}


/** Run an SQL script with one or more statements and discard every result. */
function importSql(\mysqli $connection, string $sql): void {
	$connection->multi_query($sql);
	do {
		$result = $connection->store_result();
		if ($result instanceof \mysqli_result) {
			$result->free();
		}
	} while ($connection->more_results() && $connection->next_result());
}


/**
 * cfg for the Db service: root on 127.0.0.1:3306 in the given database.
 *
 * @param  array<string, mixed>  $extra  Additional or replacing db keys.
 * @return array<string, mixed>  Value for the "db" cfg node.
 */
function dbCfg(string $database, array $extra = []): array {
	return $extra + [
		'host'    => '127.0.0.1',
		'user'    => 'root',
		'name'    => $database,
		'port'    => 3306,
		'charset' => 'utf8mb4',
	];
}


/** Secrets with CITOMNI_TEST_PASSWORD as db.password, held in memory only. */
function testSecrets(): MemorySecrets {
	return new MemorySecrets(['db.password' => (string)\getenv('CITOMNI_TEST_PASSWORD')]);
}
