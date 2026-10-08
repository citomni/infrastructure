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

namespace CitOmni\Infrastructure\Tests\Log;

use CitOmni\Infrastructure\Service\Log;
use CitOmni\Infrastructure\Tests\Support\App;

/*
 * Worker for the multi-process check in run.php. Not a suite of its own.
 *
 * Builds the real Log service with max_bytes 4096 and no limit on rotated
 * files, creates <readyFile>, and waits for <gateFile>. run.php opens the gate
 * only when every worker is ready, so all of them then write <count> records
 * "record <id>-<n>" to race.jsonl in <logDir> at the same time and rotate the
 * file many times while they compete for it.
 *
 * Usage (run.php builds the command line):
 *   php worker.php <logDir> <id> <count> <readyFile> <gateFile>
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

[, $logDir, $id, $count, $ready, $gate] = $argv + [null, '', '', '0', '', ''];

\define('CITOMNI_APP_PATH', \dirname($logDir));

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../../src/Exception/LogException.php';
require __DIR__ . '/../../src/Exception/LogConfigException.php';
require __DIR__ . '/../../src/Exception/LogDirectoryException.php';
require __DIR__ . '/../../src/Exception/LogFileException.php';
require __DIR__ . '/../../src/Exception/LogRotationException.php';
require __DIR__ . '/../../src/Exception/LogWriteException.php';
require __DIR__ . '/../../src/Service/Log.php';

$log = new Log(new App(['log' => ['path' => $logDir, 'max_bytes' => 4096, 'max_files' => null]]));

\touch($ready);

$deadline = \microtime(true) + 10;
while (!\is_file($gate)) {
	if (\microtime(true) > $deadline) {
		\fwrite(\STDERR, "Start gate timeout\n");
		exit(3);
	}
	\usleep(1_000);
}

$pad = \str_repeat('x', 100);
for ($i = 0; $i < (int)$count; $i++) {
	$log->write('race', 'race', "record {$id}-{$i}", ['pad' => $pad]);
}
