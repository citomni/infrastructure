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
 * ships: service map, the cfg baseline and language files. It also pins that
 * the package contributes no routes, controllers or templates.
 *
 * Every service with package-owned cfg is constructed from Registry::CFG_COMMON on
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

// CFG_COMMON derives paths from CITOMNI_APP_PATH, and Log creates its directory below it.
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


/** Construct a mapped service on an App that holds the shipped baseline plus $cfg. */
function constructFromBaseline(string $id, array $cfg = []): object {
	$class = Registry::MAP_COMMON[$id];
	loadPackageClass($class);
	$app = new App(mergeLastWins(Registry::CFG_COMMON, $cfg));
	$app->set('log', new LogRecorder());
	return new $class($app);
}


/**
 * Collect the App cfg reads in package source code.
 *
 * Behavior:
 * - Follows ->cfg->... property chains, and chains through a local variable assigned
 *   from one in the same function ($c = $this->app->cfg->security->csrf), also when the
 *   assignment continues with ?? (the local then holds the cfg node or the fallback). A
 *   local assigned the cfg root ($cfg = $this->app->cfg) is followed too; the assignment
 *   itself is not a read. A local assigned anything else stops being followed.
 * - Records each read's dotted path, the file below src/, the line, and whether it uses
 *   array syntax on a Cfg node ($this->app->cfg['http'], $c['enabled']).
 * - Marks a read guarded when it is followed by ??, is an argument of isset() or empty(),
 *   or uses ?-> anywhere in its chain.
 *
 * Notes:
 * - Every ->cfg property counts as App cfg.
 * - A chain ends at a method call (->toArray()), at a dynamic property name and at array
 *   access. A named function starts a new scope; a closure body keeps the enclosing one.
 *   Variables are not followed across functions or through arrays, and a cfg node held
 *   in a property ($this->db = $this->app->cfg->db) is not followed at all.
 * - A ?? after a closing parenthesis (($cfg->port) ?? 1) is not seen, so that read counts
 *   as unguarded. ?: is no guard, since the read still throws on a missing key.
 *
 * @param  string  $srcDir  Absolute path to the package src/ directory.
 * @return list<array{file: string, line: int, path: string, guarded: bool, array: bool}>  One row per read.
 */
function scanCfgReads(string $srcDir): array {
	$reads = [];
	$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
	foreach ($files as $file) {
		if ($file->getExtension() !== 'php') {
			continue;
		}
		$relative = \str_replace('\\', '/', \substr($file->getPathname(), \strlen($srcDir) + 1));
		// TOKEN_PARSE tokenizes a method named after a keyword (list, print, default) as T_STRING, so it starts a scope.
		$tokens = \array_values(\array_filter(\token_get_all((string)\file_get_contents($file->getPathname()), \TOKEN_PARSE),
			static fn(mixed $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
		$is = static fn(int $i, int|string $kind, ?string $text = null): bool => isset($tokens[$i])
			&& (\is_array($tokens[$i]) ? $tokens[$i][0] === $kind && ($text === null || $tokens[$i][1] === $text) : $tokens[$i] === $kind);
		$locals = [];
		$reassigned = null;
		$realiased = false;
		for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
			// -- 1. Scope and reassignment ----------------------------------
			if ($is($i, \T_FUNCTION) && ($is($i + 1, \T_STRING) || ($is($i + 1, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) && $is($i + 2, \T_STRING)))) {
				$locals = [];
				$reassigned = null;
				continue;
			}
			if ($is($i, ';') && $reassigned !== null) {
				if (!$realiased) {
					unset($locals[$reassigned]);
				}
				$reassigned = null;
			}
			if ($is($i, \T_VARIABLE) && $is($i + 1, '=') && isset($locals[$tokens[$i][1]])) {
				$reassigned = $tokens[$i][1];
				$realiased = false;
				continue;
			}

			// -- 2. Start of a chain -----------------------------------------
			if ($is($i, \T_STRING, 'cfg') && ($is($i - 1, \T_OBJECT_OPERATOR) || $is($i - 1, \T_NULLSAFE_OBJECT_OPERATOR)) && !$is($i + 1, '(')) {
				$path = [];
				$next = $i + 1;
				$start = $i - 1;
				$guarded = $is($start, \T_NULLSAFE_OBJECT_OPERATOR);
				while ($start > 0 && ($is($start - 1, \T_VARIABLE) || $is($start - 1, \T_STRING) || $is($start - 1, \T_OBJECT_OPERATOR) || $is($start - 1, \T_NULLSAFE_OBJECT_OPERATOR))) {
					$guarded = $guarded || $is($start - 1, \T_NULLSAFE_OBJECT_OPERATOR);
					$start--;
				}
			} elseif ($is($i, \T_VARIABLE) && isset($locals[$tokens[$i][1]])
				&& ($is($i + 1, \T_OBJECT_OPERATOR) || $is($i + 1, \T_NULLSAFE_OBJECT_OPERATOR) || $is($i + 1, '['))) {
				$path = $locals[$tokens[$i][1]];
				$next = $i + 1;
				$start = $i;
				$guarded = false;
			} else {
				continue;
			}

			// -- 3. Follow the chain and classify the read -------------------
			while (($is($next, \T_OBJECT_OPERATOR) || $is($next, \T_NULLSAFE_OBJECT_OPERATOR)) && $is($next + 1, \T_STRING) && !$is($next + 2, '(')) {
				$guarded = $guarded || $is($next, \T_NULLSAFE_OBJECT_OPERATOR);
				$path[] = $tokens[$next + 1][1];
				$next += 2;
			}
			// The innermost open bracket around the chain decides whether it is an isset() or empty() argument.
			$depth = 0;
			for ($j = $start - 1; $j >= 0; $j--) {
				if ($is($j, ')') || $is($j, ']')) {
					$depth++;
				} elseif ($is($j, '(') || $is($j, '[')) {
					if ($depth === 0) {
						break;
					}
					$depth--;
				} elseif ($depth === 0 && ($is($j, ';') || $is($j, '{') || $is($j, '}'))) {
					$j = -1;
					break;
				}
			}
			$inGuardCall = $j > 0 && $is($j, '(') && ($is($j - 1, \T_ISSET) || $is($j - 1, \T_EMPTY));
			$guarded = $guarded || $is($next, \T_COALESCE) || $is($next, \T_NULLSAFE_OBJECT_OPERATOR) || $inGuardCall;
			$array = $is($next, '[');

			// -- 4. Record ----------------------------------------------------
			if (($is($next, ';') || $is($next, \T_COALESCE)) && $is($start - 1, '=') && $is($start - 2, \T_VARIABLE)) {
				$locals[$tokens[$start - 2][1]] = $path;
				$realiased = $realiased || $tokens[$start - 2][1] === $reassigned;
			}
			if ($path !== [] || $array) {
				$reads[] = ['file' => $relative, 'line' => (int)$tokens[$i][2], 'path' => \implode('.', $path), 'guarded' => $guarded, 'array' => $array];
			}
			$i = $next - 1;
		}
	}
	\usort($reads, static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
	return $reads;
}


/**
 * Whether the package owns a cfg path.
 *
 * Notes:
 * - A root in CFG_COMMON belongs to the package, except security and locale, which
 *   other packages and the app share. Below those two the package owns the keys
 *   CFG_COMMON ships there.
 *
 * @param  string  $path  Dotted cfg path.
 * @return bool
 */
function ownedPath(string $path): bool {
	$segments = \explode('.', $path);
	if (!\array_key_exists($segments[0], Registry::CFG_COMMON)) {
		return false;
	}
	if (!\in_array($segments[0], ['security', 'locale'], true) || \count($segments) === 1) {
		return true;
	}
	return \array_key_exists($segments[1], Registry::CFG_COMMON[$segments[0]]);
}


/** True when $path (dot-separated) names a key in $cfg. */
function cfgHasPath(array $cfg, string $path): bool {
	foreach (\explode('.', $path) as $key) {
		if (!\is_array($cfg) || !\array_key_exists($key, $cfg)) {
			return false;
		}
		$cfg = $cfg[$key];
	}
	return true;
}


/**
 * The cfg reads in src/, scanned once per run.
 *
 * @return list<array{file: string, line: int, path: string, guarded: bool, array: bool}>
 */
function cfgReads(): array {
	static $reads = null;
	return $reads ??= scanCfgReads(PACKAGE_ROOT . '/src');
}


/**
 * The reads that match $filter, each formatted as file:line path for a report.
 *
 * @param  callable(array{file: string, line: int, path: string, guarded: bool, array: bool}): bool  $filter
 * @return list<string>
 */
function readsWhere(callable $filter): array {
	return \array_map(
		static fn(array $read): string => $read['file'] . ':' . $read['line'] . ' ' . ($read['path'] !== '' ? $read['path'] : '(cfg root)'),
		\array_values(\array_filter(cfgReads(), $filter))
	);
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'CLI gets the same service map and cfg baseline as HTTP' => static function (): void {
		foreach (['MAP_HTTP', 'MAP_CLI', 'CFG_HTTP', 'CFG_CLI'] as $const) {
			expect(!\defined(Registry::class . '::' . $const), 'Registry declares mode-specific ' . $const);
		}
	},

	'every service id maps to a BaseService subclass under src/' => static function (): void {
		expect(Registry::MAP_COMMON !== [], 'MAP_COMMON is empty');
		foreach (Registry::MAP_COMMON as $id => $class) {
			loadPackageClass($class);
			expect(\is_subclass_of($class, BaseService::class), "{$id} => {$class} does not extend BaseService");
		}
	},

	// Pages that use the services (a contact form, a captcha route) belong to
	// the host app; a provider must not impose them.
	'the package contributes no routes, controllers or templates' => static function (): void {
		expect(!\defined(Registry::class . '::ROUTES_HTTP'), 'Registry declares ROUTES_HTTP');
		expect(!isset(Registry::CFG_COMMON['view']), 'CFG_COMMON registers a template layer');
		expect((\glob(PACKAGE_ROOT . '/src/Controller/*.php') ?: []) === [], 'src/Controller/ contains controllers');
		expect((\glob(PACKAGE_ROOT . '/templates/*/*.html') ?: []) === [], 'templates/ contains templates');
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
		$policy = Registry::CFG_COMMON['security']['bruteforce']['default'] ?? null;
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

	// Package rule: the package reads the cfg CFG_COMMON ships directly, so a missing
	// baseline key fails at the read instead of hiding behind a fallback.
	'the cfg scan finds known reads in src/' => static function (): void {
		$paths = \array_column(cfgReads(), 'path');
		foreach (['db.host', 'log.max_files', 'curl.verify_peer', 'mail.smtp.encryption', 'locale.format.decimal_scale', 'locale.language'] as $known) {
			expect(\in_array($known, $paths, true), "scan missed {$known}; the patterns are stale");
		}
	},

	'cfg is read with property syntax' => static function (): void {
		$array = readsWhere(static fn(array $read): bool => $read['array']);
		expect($array === [], 'array syntax, which the scan cannot follow: ' . \implode('; ', $array));
	},

	'package-owned cfg is read without fallbacks' => static function (): void {
		$guarded = readsWhere(static fn(array $read): bool => !$read['array'] && $read['guarded'] && ownedPath($read['path']));
		expect($guarded === [], \count($guarded) . ' guarded read(s): ' . \implode('; ', $guarded));
	},

	'every package-owned cfg path read in src/ exists in CFG_COMMON' => static function (): void {
		$missing = readsWhere(static fn(array $read): bool => !$read['array'] && ownedPath($read['path']) && !cfgHasPath(Registry::CFG_COMMON, $read['path']));
		expect($missing === [], 'missing from CFG_COMMON: ' . \implode('; ', $missing));
	},

	'cfg the package does not own is read with a fallback' => static function (): void {
		$direct = readsWhere(static fn(array $read): bool => !$read['array'] && !$read['guarded'] && !ownedPath($read['path']));
		expect($direct === [], \count($direct) . ' direct read(s): ' . \implode('; ', $direct));
	},

];


$exitCode = 1;
try {
	$exitCode = runChecks($checks);
} finally {
	removeTree($root);
}
exit($exitCode);
