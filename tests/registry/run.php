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

namespace CitOmni\Infrastructure\Tests\Registry;

use CitOmni\Infrastructure\Boot\Registry;
use CitOmni\Infrastructure\Exception\CurlExecException;
use CitOmni\Infrastructure\Tests\Support\App;
use CitOmni\Infrastructure\Tests\Support\LogRecorder;
use CitOmni\Kernel\Controller\BaseController;
use CitOmni\Kernel\Service\BaseService;

use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\mergeLastWins;
use function CitOmni\Infrastructure\Tests\Support\removeTree;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\tempDir;
use function CitOmni\Infrastructure\Tests\Support\thrown;

/*
 * Standalone suite for the package wiring in Boot\Registry and the content it
 * ships: service map, routes, the cfg baseline, language files and templates.
 *
 * Every service with package-owned cfg is constructed from Registry::CFG_HTTP on
 * the kernel doubles, without Composer. CITOMNI_APP_PATH points at a temporary
 * directory that is removed again afterwards.
 *
 * Usage:
 *   php tests/registry/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; like the production ErrorHandler, leave
// diagnostics silenced with @ to PHP.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

const PACKAGE_ROOT = __DIR__ . '/../..';

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';

// CFG_HTTP derives paths from CITOMNI_APP_PATH, and Log creates its directory below it.
$root = tempDir('registry');
\define('CITOMNI_APP_PATH', $root);

require PACKAGE_ROOT . '/src/Boot/Registry.php';

foreach ([
	'Enum/TransactionIsolation',
	'Exception/BruteForceException', 'Exception/BruteForceConfigException', 'Exception/BruteForceSetupException',
	'Exception/CurlException', 'Exception/CurlConfigException', 'Exception/CurlExecException',
	'Exception/DbException', 'Exception/DbConnectException', 'Exception/DbQueryException',
	'Exception/LogException', 'Exception/LogConfigException', 'Exception/LogDirectoryException',
	'Exception/LogFileException', 'Exception/LogRotationException', 'Exception/LogWriteException',
	'Exception/TxtConfigException',
	'Exception/ValueConfigurationException', 'Exception/ValueDefinitionException',
	'Exception/ValueFromSqlException', 'Exception/ValueToSqlException',
	'Repository/BruteForceRepository', 'Repository/DatabaseSchemaRepository',
] as $file) {
	require PACKAGE_ROOT . '/src/' . $file . '.php';
}


/**
 * Source file of a package class under the PSR-4 mapping CitOmni\Infrastructure\ => src/.
 */
function sourceFile(string $class): string {
	$prefix = 'CitOmni\\Infrastructure\\';
	if (!\str_starts_with($class, $prefix)) {
		return '';
	}
	return PACKAGE_ROOT . '/src/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';
}


/** Load a package class from its PSR-4 file, failing the check when the file is missing. */
function loadPackageClass(string $class): void {
	if (\class_exists($class, false)) {
		return;
	}
	$file = sourceFile($class);
	expect($file !== '' && \is_file($file), $class . ' has no source file under src/');
	require $file;
	expect(\class_exists($class, false), $file . ' does not declare ' . $class);
}


/** Construct a mapped service on an App that holds the shipped HTTP baseline plus $cfg. */
function constructFromBaseline(string $id, array $cfg = []): object {
	$class = Registry::MAP_HTTP[$id];
	loadPackageClass($class);
	$app = new App(mergeLastWins(Registry::CFG_HTTP, $cfg));
	$app->set('log', new LogRecorder());
	return new $class($app);
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'CLI gets the same service map and cfg baseline as HTTP' => static function (): void {
		same(Registry::MAP_HTTP, Registry::MAP_CLI, 'MAP_CLI');
		same(Registry::CFG_HTTP, Registry::CFG_CLI, 'CFG_CLI');
	},

	'every service id maps to a BaseService subclass under src/' => static function (): void {
		expect(Registry::MAP_HTTP !== [], 'MAP_HTTP is empty');
		foreach (Registry::MAP_HTTP as $id => $class) {
			loadPackageClass($class);
			expect(\is_subclass_of($class, BaseService::class), "{$id} => {$class} does not extend BaseService");
		}
	},

	'every HTTP route names a public action on a package controller' => static function (): void {
		expect(Registry::ROUTES_HTTP !== [], 'ROUTES_HTTP is empty');
		foreach (Registry::ROUTES_HTTP as $path => $route) {
			$class = (string)($route['controller'] ?? '');
			loadPackageClass($class);
			expect(\is_subclass_of($class, BaseController::class), "{$path}: {$class} does not extend BaseController");
			$action = (string)($route['action'] ?? '');
			expect(\method_exists($class, $action) && (new \ReflectionMethod($class, $action))->isPublic(), "{$path}: {$class}::{$action}() is not a public method");
			$methods = $route['methods'] ?? [];
			expect(\is_array($methods) && $methods !== [] && \array_diff($methods, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']) === [], "{$path}: invalid methods " . \json_encode($methods));
		}
	},

	'log constructs from the shipped cfg baseline, creates var/logs and writes citomni_app.jsonl' => static function (): void {
		$log = constructFromBaseline('log');
		expect(\is_dir(\CITOMNI_APP_PATH . '/var/logs'), 'var/logs was not created');
		$log->write(null, 'registry', 'baseline');
		expect(\is_file(\CITOMNI_APP_PATH . '/var/logs/citomni_app.jsonl'), 'default_file "citomni_app.log" was not written as citomni_app.jsonl');
	},

	'secrets constructs from the shipped cfg baseline without reading a file' => static function (): void {
		same(['loaded' => false], constructFromBaseline('secrets')->__debugInfo(), 'debug info');
	},

	// Curl validates its cfg defaults per request, so only a request proves the
	// baseline valid. An unsupported scheme stops inside libcurl before any IO.
	'curl accepts the shipped cfg baseline as request defaults' => static function (): void {
		$curl = constructFromBaseline('curl');
		$e    = thrown(CurlExecException::class, static fn() => $curl->execute(['url' => 'nope://localhost/']), 'a request with an unsupported scheme');
		same(\CURLE_UNSUPPORTED_PROTOCOL, $e->getCurlErrno(), 'curl errno');
	},

	'db constructs from the shipped cfg baseline without connecting' => static function (): void {
		$info = constructFromBaseline('db')->__debugInfo();
		same(
			['localhost', 'citomni', 'citomni', 'utf8mb4', 3306, 'db.password', false],
			[$info['cfgHost'], $info['cfgUser'], $info['cfgName'], $info['cfgCharset'], $info['cfgPort'], $info['cfgPasswordSecret'], $info['hasConnection']],
			'host, user, name, charset, port, password secret, connected'
		);
	},

	'valueToSql and valueFromSql accept the shipped locale.format policy' => static function (): void {
		constructFromBaseline('valueToSql');
		constructFromBaseline('valueFromSql');
	},

	'bruteForce constructs from the shipped cfg baseline with a valid default context' => static function (): void {
		$policy = Registry::CFG_HTTP['security']['bruteforce']['default'] ?? null;
		expect(\is_array($policy), 'security.bruteforce.default is missing');
		foreach (['max_identifier_attempts', 'max_ip_attempts', 'interval_minutes', 'retry_after_seconds', 'prune_after_seconds'] as $key) {
			expect(\is_int($policy[$key] ?? null) && $policy[$key] >= 1, "security.bruteforce.default.{$key} must be an integer >= 1");
		}
		constructFromBaseline('bruteForce');
	},

	'formatNumber constructs from the shipped cfg baseline' => static function (): void {
		constructFromBaseline('formatNumber');
	},

	// No CitOmni baseline declares locale.language; the application supplies it.
	'txt constructs from the shipped cfg baseline plus locale.language' => static function (): void {
		constructFromBaseline('txt', ['locale' => ['language' => 'da']]);
	},

	'shipped language files return flat maps of non-empty strings' => static function (): void {
		$files = \glob(PACKAGE_ROOT . '/language/*/*.php') ?: [];
		expect($files !== [], 'no language files found');
		foreach ($files as $file) {
			$data = require $file;
			$name = \basename(\dirname($file)) . '/' . \basename($file);
			expect(\is_array($data) && $data !== [], "{$name} does not return a non-empty array");
			foreach ($data as $key => $value) {
				expect(\is_string($key) && $key !== '', "{$name} has a non-string key");
				expect(\is_string($value) && $value !== '', "{$name}: {$key} is not a non-empty string");
			}
		}
	},

	'every $txt() key the templates read from citomni/infrastructure exists in every language' => static function (): void {
		$languages = \glob(PACKAGE_ROOT . '/language/*', \GLOB_ONLYDIR) ?: [];
		$found     = 0;
		foreach (\glob(PACKAGE_ROOT . '/templates/*/*.html') ?: [] as $template) {
			\preg_match_all('/\$txt\(\s*([\'"])([^\'"]+)\1\s*,\s*([\'"])([^\'"]+)\3\s*,\s*([\'"])citomni\/infrastructure\5/', (string)\file_get_contents($template), $calls, \PREG_SET_ORDER);
			foreach ($calls as $call) {
				[$key, $file] = [$call[2], $call[4]];
				$found++;
				foreach ($languages as $languageDir) {
					$path = $languageDir . '/' . $file . '.php';
					expect(\is_file($path), \basename($template) . ": language/" . \basename($languageDir) . "/{$file}.php is missing");
					$data = require $path;
					expect(\is_string($data[$key] ?? null), \basename($template) . ": {$key} is missing from language/" . \basename($languageDir) . "/{$file}.php");
				}
			}
		}
		expect($found > 0, 'no $txt() calls found in templates/; the pattern is stale');
	},

];


$exitCode = 1;
try {
	$exitCode = runChecks($checks);
} finally {
	removeTree($root);
}
exit($exitCode);
