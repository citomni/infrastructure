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

use CitOmni\Infrastructure\Boot\Registry;
use CitOmni\Infrastructure\Service\Log;
use CitOmni\Infrastructure\Tests\Support\App;

use function CitOmni\Infrastructure\Tests\Support\mergeLastWins;

/*
 * Child process for the normalization check in run.php. Not a suite of its own.
 *
 * An extension cannot be unloaded at runtime, so run.php starts the probe with
 * -d disable_functions to take mbstring or iconv away. The probe writes five
 * entries to probe.jsonl in <logDir> through the real Log service. A recursive
 * object in the context makes the first encode fail, so the first three entries
 * are encoded after normalization. INF fails the normalized encode too, so the
 * last two become fallback lines.
 *
 * Usage (run.php builds the command line):
 *   php probe.php <logDir>
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

[, $logDir] = $argv + [null, ''];

\define('CITOMNI_APP_PATH', \dirname($logDir));

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../../src/Exception/LogException.php';
require __DIR__ . '/../../src/Exception/LogConfigException.php';
require __DIR__ . '/../../src/Exception/LogDirectoryException.php';
require __DIR__ . '/../../src/Exception/LogFileException.php';
require __DIR__ . '/../../src/Exception/LogRotationException.php';
require __DIR__ . '/../../src/Exception/LogWriteException.php';
require __DIR__ . '/../../src/Service/Log.php';
require __DIR__ . '/../../src/Boot/Registry.php';

$log = new Log(new App(['log' => mergeLastWins(Registry::CFG_COMMON['log'], ['path' => $logDir])]));

$cycle = new \stdClass();
$cycle->self = $cycle;

$log->write('probe', 'valid.æøå', 'Søren æøå € 😀', ['cycle' => $cycle, 'nøgle' => 'værdi']);
$log->write('probe', 'cp1252', "S\xF8ren \xE6\xF8\xE5 \x80", ['cycle' => $cycle, "n\xF8gle" => "v\xE6rdi"]);
$log->write('probe', 'undefined', "a\x81b\x8Dc\x8Fd\x90e\x9Df", ['cycle' => $cycle]);
$log->write('probe', 'fallback.æøå', 'message', ['inf' => \INF]);
$log->write('probe', "fallback.\xE6\xF8\xE5", 'message', ['inf' => \INF]);
