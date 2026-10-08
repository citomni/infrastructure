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

namespace CitOmni\Infrastructure\Tests\Secrets;

use CitOmni\Infrastructure\Service\Secrets;
use CitOmni\Infrastructure\Tests\Support\App;

/*
 * Child process for run.php. Not a suite of its own.
 *
 * CITOMNI_ENVIRONMENT is a constant, so every environment needs its own
 * process. The probe defines it (or leaves it undefined for "-"), looks up one
 * key through the real Secrets service and prints the outcome as one JSON
 * object: {"has": bool, "value": string|null} or {"error": class, "message": string}.
 *
 * Usage (run.php builds the command line):
 *   php probe.php <environment|-> <appRoot> <key>
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

[, $environment, $appRoot, $key] = $argv + [null, '-', '', ''];

if ($environment !== '-') {
	\define('CITOMNI_ENVIRONMENT', $environment);
}

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../../src/Service/Secrets.php';

$secrets = new Secrets(new App([], $appRoot));

try {
	$has = $secrets->has($key);
	echo \json_encode(['has' => $has, 'value' => $has ? $secrets->get($key) : null]), "\n";
} catch (\Throwable $e) {
	echo \json_encode(['error' => $e::class, 'message' => $e->getMessage()]), "\n";
}
