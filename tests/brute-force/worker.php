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

use CitOmni\Infrastructure\Service\BruteForce;
use CitOmni\Infrastructure\Service\Db;
use CitOmni\Infrastructure\Tests\Support\App;

use function CitOmni\Infrastructure\Tests\Support\dbCfg;
use function CitOmni\Infrastructure\Tests\Support\testSecrets;

/*
 * Worker for the multi-process check in database.php. Not a suite of its own.
 *
 * Opens its database connection, creates <readyFile> and waits for <gateFile>.
 * database.php opens the gate only when every worker is ready, so all of them
 * then record <count> failures for the same identifier and IP in the "race"
 * context through the real BruteForce service at the same time. The limits are
 * far above the total, so nothing gets blocked and every failure must be counted.
 *
 * The password is read from CITOMNI_TEST_PASSWORD in the inherited environment;
 * it is never passed on the command line.
 *
 * Usage (database.php builds the command line):
 *   php worker.php <database> <count> <readyFile> <gateFile>
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

[, $database, $count, $ready, $gate] = $argv + [null, '', '0', '', ''];

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/test-db.php';
require __DIR__ . '/../../src/Enum/TransactionIsolation.php';
require __DIR__ . '/../../src/Exception/DbException.php';
require __DIR__ . '/../../src/Exception/DbConnectException.php';
require __DIR__ . '/../../src/Exception/DbQueryException.php';
require __DIR__ . '/../../src/Exception/BruteForceException.php';
require __DIR__ . '/../../src/Exception/BruteForceConfigException.php';
require __DIR__ . '/../../src/Service/Db.php';
require __DIR__ . '/../../src/Repository/BruteForceRepository.php';
require __DIR__ . '/../../src/Service/BruteForce.php';

$app = new App([
	'db'       => dbCfg($database),
	'security' => ['bruteforce' => [
		'race' => ['max_identifier_attempts' => 100000, 'max_ip_attempts' => 100000, 'interval_minutes' => 60, 'retry_after_seconds' => 60],
	]],
]);
$app->set('secrets', testSecrets());
$app->set('db', new Db($app));
$bruteForce = new BruteForce($app);

// Connect before reporting ready, so all workers start recording at once.
$app->db->fetchValue('SELECT 1');
\touch($ready);

$deadline = \microtime(true) + 10;
while (!\is_file($gate)) {
	if (\microtime(true) > $deadline) {
		\fwrite(\STDERR, "Start gate timeout\n");
		exit(3);
	}
	\usleep(1_000);
}

for ($i = 0; $i < (int)$count; $i++) {
	$bruteForce->record('race', 'shared@example.com', '198.51.100.9');
}
