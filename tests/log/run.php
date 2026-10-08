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

use CitOmni\Infrastructure\Exception\LogConfigException;
use CitOmni\Infrastructure\Exception\LogDirectoryException;
use CitOmni\Infrastructure\Exception\LogFileException;
use CitOmni\Infrastructure\Service\Log;
use CitOmni\Infrastructure\Tests\Support\App;

use function CitOmni\Infrastructure\Tests\Support\childReport;
use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\readJsonLines;
use function CitOmni\Infrastructure\Tests\Support\removeTree;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\startPhp;
use function CitOmni\Infrastructure\Tests\Support\tempDir;
use function CitOmni\Infrastructure\Tests\Support\thrown;
use function CitOmni\Infrastructure\Tests\Support\waitForFiles;

/*
 * Standalone suite for \CitOmni\Infrastructure\Service\Log.
 *
 * The real service runs on the kernel doubles, without Composer, and writes into
 * its own directory per check below one temporary directory, which is removed
 * again afterwards. CITOMNI_APP_PATH points at that temporary directory.
 *
 * Usage:
 *   php tests/log/run.php
 *
 * The multi-process check runs only with CITOMNI_TEST_PARALLEL=1. It starts
 * worker.php several times at once.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; like the production ErrorHandler, leave
// diagnostics silenced with @ to PHP. Log relies on @ for fopen(), mkdir() and rename().
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';

$root = tempDir('log');
\define('CITOMNI_APP_PATH', $root);

require __DIR__ . '/../../src/Exception/LogException.php';
require __DIR__ . '/../../src/Exception/LogConfigException.php';
require __DIR__ . '/../../src/Exception/LogDirectoryException.php';
require __DIR__ . '/../../src/Exception/LogFileException.php';
require __DIR__ . '/../../src/Exception/LogRotationException.php';
require __DIR__ . '/../../src/Exception/LogWriteException.php';
require __DIR__ . '/../../src/Service/Log.php';

/** Workers in the multi-process check. */
const PARALLEL_WORKERS = 4;

/** Records each worker writes. */
const PARALLEL_RECORDS = 150;

/** Name of a rotated file in the "app" family: app_<Ymd>_<His>_<pid>[_<n>].jsonl. */
const ROTATED_APP = '/^app_\d{8}_\d{6}_\d+(?:_\d+)?\.jsonl$/';


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/** A new, empty directory below the suite's temporary directory. */
function freshDir(): string {
	static $n = 0;
	$dir = \CITOMNI_APP_PATH . '/case' . ++$n;
	\mkdir($dir);
	return $dir;
}


/** A Log service on the given "log" cfg node; no node at all when $logCfg is null. */
function logService(?array $logCfg): Log {
	return new Log(new App($logCfg === null ? [] : ['log' => $logCfg]));
}


/**
 * File names in $dir, sorted.
 *
 * @return list<string>
 */
function files(string $dir): array {
	$names = \array_values(\array_diff(\scandir($dir), ['.', '..']));
	\sort($names);
	return $names;
}


/**
 * Rotated files of the "app" family in $dir, sorted.
 *
 * @return list<string>
 */
function rotated(string $dir): array {
	return \array_values(\preg_grep(ROTATED_APP, files($dir)));
}


/**
 * Messages of every record in the given files, in file order.
 *
 * @param  list<string>  $paths
 * @return list<mixed>
 */
function messages(array $paths): array {
	$messages = [];
	foreach ($paths as $path) {
		foreach (readJsonLines($path) as $record) {
			$messages[] = $record['message'] ?? null;
		}
	}
	return $messages;
}


/**
 * Messages of every record in the rotated "app" files in $dir, sorted.
 *
 * @return list<mixed>
 */
function rotatedMessages(string $dir): array {
	$messages = messages(\array_map(static fn(string $file): string => $dir . '/' . $file, rotated($dir)));
	\sort($messages);
	return $messages;
}


/**
 * Create files in $dir that hold their own name as the message of one record,
 * all with the modification time $time.
 *
 * @param  list<string>  $files
 */
function seedFiles(string $dir, array $files, int $time): void {
	foreach ($files as $file) {
		\file_put_contents($dir . '/' . $file, \json_encode(['message' => $file]) . "\n");
		\touch($dir . '/' . $file, $time);
	}
}


/** Return right after the clock has entered a new second. */
function waitForNextSecond(): void {
	$second = \time();
	while (\time() === $second) {
		\usleep(1_000);
	}
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'init creates the configured directory and default_file becomes a .jsonl file' => static function (): void {
		$dir = freshDir() . '/nested/logs';
		$log = logService(['path' => $dir, 'default_file' => 'app.log']);
		expect(\is_dir($dir), 'directory was not created');
		$log->write(null, 'cat', 'first');
		$log->write('', 'cat', 'second');
		same(['app.jsonl', 'app.jsonl.lock'], files($dir), 'files');
		same(['first', 'second'], messages([$dir . '/app.jsonl']), 'messages');
	},

	'without a log cfg node the logger writes citomni_app.jsonl under CITOMNI_APP_PATH/var/logs' => static function (): void {
		logService(null)->write(null, 'cat', 'default');
		same(['default'], messages([\CITOMNI_APP_PATH . '/var/logs/citomni_app.jsonl']), 'messages');
	},

	'each entry is one JSON line: timestamp, category, message, then context only when non-empty' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir]);
		$log->write('entries', 'a.cat', 'plain');
		$log->write('entries', 'b.cat', ['k' => 'v'], ['id' => 7]);
		$raw = (string)\file_get_contents($dir . '/entries.jsonl');
		same(2, \substr_count($raw, "\n"), 'line feeds');
		expect(\str_ends_with($raw, "\n") && !\str_contains($raw, "\r"), 'lines must end with a single LF: ' . export($raw));
		[$first, $second] = readJsonLines($dir . '/entries.jsonl');
		same(['timestamp', 'category', 'message'], \array_keys($first), 'keys without context');
		same(['timestamp', 'category', 'message', 'context'], \array_keys($second), 'keys with context');
		same(['a.cat', 'plain', 'b.cat', ['k' => 'v'], ['id' => 7]], [$first['category'], $first['message'], $second['category'], $second['message'], $second['context']], 'values');
		expect(\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string)$first['timestamp']) === 1, 'timestamp is not DATE_ATOM: ' . export($first['timestamp']));
	},

	'unicode and slashes are written unescaped, invalid UTF-8 is substituted' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir]);
		$log->write('utf8', 'cat', 'æøå / 😀');
		$log->write('utf8', 'cat', "bad \xB1 byte", ["k\xB1" => "v\xB1"]);
		$raw = (string)\file_get_contents($dir . '/utf8.jsonl');
		expect(\str_contains($raw, '"æøå / 😀"'), 'unicode or slash was escaped: ' . export($raw));
		$records = readJsonLines($dir . '/utf8.jsonl');
		same("bad \u{FFFD} byte", $records[1]['message'], 'substituted message');
		same(["k\u{FFFD}" => "v\u{FFFD}"], $records[1]['context'], 'substituted context');
	},

	'objects are encoded as JSON, and entries JSON cannot encode keep timestamp and category' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir]);
		$log->write('objects', 'plain', (object)['a' => 1]);
		$log->write('objects', 'serializable', new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['custom' => true];
			}
		});
		$deep = 'leaf';
		for ($i = 0; $i < 600; $i++) {
			$deep = [$deep];
		}
		$log->write('objects', 'deep', $deep);
		$log->write('objects', 'infinite', ['x' => \INF]);
		$records = readJsonLines($dir . '/objects.jsonl');
		same(4, \count($records), 'records');
		same(['a' => 1], $records[0]['message'], 'stdClass');
		same(['custom' => true], $records[1]['message'], 'JsonSerializable');
		$leaf   = $records[2]['message'];
		$levels = 0;
		while (\is_array($leaf)) {
			$leaf = $leaf[0];
			$levels++;
		}
		same('[max-normalize-depth-exceeded]', $leaf, 'leaf of the deep message (' . $levels . ' levels kept)');
		same('infinite', $records[3]['category'], 'fallback category');
		expect(\str_starts_with((string)$records[3]['message'], 'Log fallback: JSON encode failed'), 'fallback message: ' . export($records[3]['message']));
	},

	'flat file names are normalized to .jsonl' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir]);
		foreach (['app', 'app.log', 'audit.jsonl', 'v1.2.log', 'A-b_c', 'x.'] as $name) {
			$log->write($name, 'cat', $name);
		}
		same(['A-b_c.jsonl', 'app.jsonl', 'audit.jsonl', 'v1.2.jsonl', 'x.jsonl'], \array_values(\preg_grep('/\.jsonl$/', files($dir))), 'files');
		same(['app', 'app.log'], messages([$dir . '/app.jsonl']), 'app.jsonl');
	},

	'unsafe file names are rejected without creating files' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir]);
		foreach (['../x', 'sub/x', 'sub\\x', '.env', '.log', '...', '.', 'x y', 'æ.log', "x\0y", 'x:y'] as $name) {
			thrown(LogFileException::class, static fn() => $log->write($name, 'cat', 'm'), 'file name ' . export($name));
		}
		same([], files($dir), 'files');
		expect(!\is_file(\dirname($dir) . '/x.jsonl'), 'a file was written outside the log directory');
	},

	'invalid cfg values are rejected at init' => static function (): void {
		$dir = freshDir();
		$cases = [
			[['path' => ''], LogConfigException::class],
			[['path' => 5], LogConfigException::class],
			[['path' => $dir, 'default_file' => ''], LogConfigException::class],
			[['path' => $dir, 'default_file' => '../app.log'], LogFileException::class],
			[['path' => $dir, 'max_bytes' => '2000000'], LogConfigException::class],
			[['path' => $dir, 'max_bytes' => 1023], LogConfigException::class],
			[['path' => $dir, 'max_files' => 0], LogConfigException::class],
			[['path' => $dir, 'max_files' => '10'], LogConfigException::class],
		];
		foreach ($cases as [$cfg, $class]) {
			thrown($class, static fn() => logService($cfg), 'cfg ' . export($cfg));
		}
		logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => null]);
	},

	'setDir() rejects a missing directory unless autoCreate is set' => static function (): void {
		$log = logService(['path' => freshDir()]);
		$missing = freshDir() . '/missing';
		thrown(LogDirectoryException::class, static fn() => $log->setDir($missing), 'missing directory');
		expect(!\is_dir($missing), 'directory was created without autoCreate');
		$log->setDir($missing, true);
		$log->write('moved', 'cat', 'here');
		same(['here'], messages([$missing . '/moved.jsonl']), 'messages after setDir()');
	},

	'the write that reaches max_bytes rotates the file to <name>_<date>_<time>_<pid>.jsonl' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => null]);
		$pad = \str_repeat('x', 300);
		for ($i = 1; $i <= 3; $i++) {
			$log->write('app', 'cat', "record {$i}", ['pad' => $pad]);
		}
		expect(!\is_file($dir . '/app.jsonl'), 'the live file was not rotated after crossing max_bytes');
		$rotated = rotated($dir);
		same(1, \count($rotated), 'rotated files (' . export(files($dir)) . ')');
		expect(\str_contains($rotated[0], '_' . \getmypid()), 'rotated name lacks the pid: ' . $rotated[0]);
		same(['record 1', 'record 2', 'record 3'], messages([$dir . '/' . $rotated[0]]), 'rotated records');
		$log->write('app', 'cat', 'record 4');
		same(['record 4'], messages([$dir . '/app.jsonl']), 'new live file');
	},

	'a file already at max_bytes is rotated before the next append' => static function (): void {
		$dir = freshDir();
		\file_put_contents($dir . '/app.jsonl', \str_repeat('{"old":true}' . "\n", 100));
		logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => null])->write('app', 'cat', 'new');
		same(['new'], messages([$dir . '/app.jsonl']), 'live file');
		same(1, \count(rotated($dir)), 'rotated files');
	},

	// Pruning orders rotated files by modification time. Rotations normally lie
	// seconds apart; instead of sleeping, the check ages the rotated files by ten
	// seconds after every write.
	'rotation prunes the oldest rotated files down to max_files' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => 2]);
		$pad = \str_repeat('x', 1100);
		for ($i = 1; $i <= 5; $i++) {
			$log->write('app', 'cat', "record {$i}", ['pad' => $pad]);
			foreach (rotated($dir) as $file) {
				\clearstatcache(true, $dir . '/' . $file);
				\touch($dir . '/' . $file, \filemtime($dir . '/' . $file) - 10);
			}
		}
		same(2, \count(rotated($dir)), 'rotated files (' . export(files($dir)) . ')');
		same(['record 4', 'record 5'], rotatedMessages($dir), 'records kept');
	},

	// Regression: rotations within one second share a modification time, and a
	// rotation reused the name that pruning had just freed, so pruning deleted
	// the newest file first. The writes start right after a new second begins
	// and take a few milliseconds; every second write rotates.
	'rotations within one second prune the oldest files and keep the newest' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => 3]);
		$pad = \str_repeat('x', 600);
		waitForNextSecond();
		for ($i = 0; $i < 12; $i++) {
			$log->write('app', 'cat', \sprintf('record %02d', $i), ['pad' => $pad]);
		}
		same(3, \count(rotated($dir)), 'rotated files (' . export(files($dir)) . ')');
		same(['record 06', 'record 07', 'record 08', 'record 09', 'record 10', 'record 11'], rotatedMessages($dir), 'records kept');
	},

	// Regression: the names carried local time, so a process in another time zone
	// numbered its rotations of the same second from 0 again and reused names
	// that pruning had freed. One process that switches time zones between writes
	// stands in for several processes; every write rotates.
	'rotations from processes in different time zones keep the newest files' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => 3]);
		$pad = \str_repeat('x', 1100);
		$zone = \date_default_timezone_get();
		try {
			waitForNextSecond();
			for ($i = 0; $i < 8; $i++) {
				\date_default_timezone_set($i % 2 === 0 ? 'UTC' : 'Europe/Copenhagen');
				$log->write('app', 'cat', "record {$i}", ['pad' => $pad]);
			}
		} finally {
			\date_default_timezone_set($zone);
		}
		same(['record 5', 'record 6', 'record 7'], rotatedMessages($dir), 'records kept');
	},

	// Regression: a rotation took the first free name for its own process id, so
	// rotations of different processes in one second were not numbered in order.
	'a rotation is numbered past the rotations of other processes in the same second' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => null]);
		waitForNextSecond();
		$timestamp = \gmdate('Ymd_His');
		seedFiles($dir, ["app_{$timestamp}_999999_5.jsonl"], \time());
		$log->write('app', 'cat', 'record', ['pad' => \str_repeat('x', 1100)]);
		$expected = ["app_{$timestamp}_999999_5.jsonl", "app_{$timestamp}_" . \getmypid() . '_6.jsonl'];
		\sort($expected);
		same($expected, rotated($dir), 'rotated files');
	},

	// Regression: equal modification times fell back to plain name order, which
	// also puts _10 before _2.
	'pruning orders equal modification times by the timestamp and sequence number in the name' => static function (): void {
		$dir = freshDir();
		seedFiles($dir, ['app_20200101_000000_7.jsonl', 'app_20200101_000000_7_2.jsonl', 'app_20200101_000000_7_10.jsonl', 'app_20200101_000001_7.jsonl'], \time() - 3600);
		logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => 3])->write('app', 'cat', 'newest', ['pad' => \str_repeat('x', 1100)]);
		same(3, \count(rotated($dir)), 'rotated files (' . export(files($dir)) . ')');
		same(['app_20200101_000000_7_10.jsonl', 'app_20200101_000001_7.jsonl', 'newest'], rotatedMessages($dir), 'records kept');
	},

	// A name can disagree with the order of the rotations, e.g. local-time names
	// across the end of daylight saving time: 02:59:59, then 02:00:00 an hour
	// later. The modification time decides.
	'pruning orders by modification time before the name' => static function (): void {
		$dir = freshDir();
		seedFiles($dir, ['app_20201025_025959_7.jsonl'], \time() - 20);
		seedFiles($dir, ['app_20201025_020000_7.jsonl'], \time() - 10);
		logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => 2])->write('app', 'cat', 'newest', ['pad' => \str_repeat('x', 1100)]);
		same(['app_20201025_020000_7.jsonl', 'newest'], rotatedMessages($dir), 'records kept');
	},

	// Regression: pruning matched every app_*.jsonl file, so it also deleted the
	// files of logs named like app_failed.
	'pruning deletes only rotated files of the log file being written' => static function (): void {
		$dir = freshDir();
		$others = ['app_failed.jsonl', 'app_failed_20200101_000000_7.jsonl', 'app_2.jsonl'];
		seedFiles($dir, $others, \time() - 3600);
		$log = logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => 1]);
		$pad = \str_repeat('x', 1100);
		for ($i = 1; $i <= 3; $i++) {
			$log->write('app', 'cat', "record {$i}", ['pad' => $pad]);
		}
		same([], \array_values(\array_diff($others, files($dir))), 'other files deleted');
		same(['record 3'], rotatedMessages($dir), 'app records kept');
	},

	'max_files null keeps every rotated file' => static function (): void {
		$dir = freshDir();
		$log = logService(['path' => $dir, 'max_bytes' => 1024, 'max_files' => null]);
		$pad = \str_repeat('x', 1100);
		for ($i = 1; $i <= 5; $i++) {
			$log->write('app', 'cat', "record {$i}", ['pad' => $pad]);
		}
		same(5, \count(rotated($dir)), 'rotated files');
	},

];

// Multi-process checks; they run only with CITOMNI_TEST_PARALLEL=1.
$parallelChecks = [

	'concurrent writers lose no records across rotations' => static function (): void {
		$dir   = freshDir();
		$gate  = $dir . '/go';
		$procs = [];
		$ready = [];
		for ($w = 0; $w < PARALLEL_WORKERS; $w++) {
			$ready[$w] = $dir . "/ready{$w}";
			$procs[$w] = startPhp([__DIR__ . '/worker.php', $dir . '/logs', (string)$w, (string)PARALLEL_RECORDS, $ready[$w], $gate], $dir . "/worker{$w}.out", $dir . "/worker{$w}.err");
		}

		// Open the start gate only when every worker is ready to write.
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

		$logFiles = \glob($dir . '/logs/*.jsonl') ?: [];
		$messages = messages($logFiles);
		$expected = [];
		for ($w = 0; $w < PARALLEL_WORKERS; $w++) {
			for ($i = 0; $i < PARALLEL_RECORDS; $i++) {
				$expected[] = "record {$w}-{$i}";
			}
		}
		$missing    = \array_diff($expected, $messages);
		$duplicates = \count($messages) - \count(\array_unique($messages));
		same([0, 0], [\count($missing), $duplicates], 'missing and duplicated records (first missing: ' . export(\array_slice(\array_values($missing), 0, 3)) . ')');
		expect(\count($logFiles) >= 10, 'expected at least 10 files after rotation, got ' . \count($logFiles));
	},

];


$exitCode = 1;
try {
	$exitCode = runChecks($checks, $parallelChecks);
} finally {
	removeTree($root);
}
exit($exitCode);
