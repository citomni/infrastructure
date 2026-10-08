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
 * Assertions, temporary directories, child processes, and the check runner for
 * the suites. Not a suite of its own.
 *
 * - expect(), same() and thrown() fail the current check with a CheckFailed.
 * - runChecks() prints "PASS <name>" on stdout, "FAIL <name> - <message>" on
 *   stderr and "SKIP <name>: <reason>" for multi-process checks without
 *   CITOMNI_TEST_PARALLEL=1, then the totals line, and returns the exit code.
 * - tempDir() creates citomni_infrastructure_<suite>_test_<random> under the
 *   system temp directory; removeTree() removes only such directories.
 * - startPhp() and runPhp() run children with the same PHP binary and php.ini as
 *   the calling suite, like tests/run.php: the loaded file, the default lookup,
 *   or -n. Children inherit the environment.
 * - waitForFiles() and childReport() serve the multi-process checks: workers
 *   signal readiness through files, and failures quote a child's exit code and
 *   both of its outputs (PHP without a php.ini prints fatal errors on stdout).
 * - freePort() and waitForPort() serve the fixture servers of the curl and
 *   mailer suites.
 */

/** Prefix of every directory the suites create; removeTree() refuses anything else. */
const TEMP_PREFIX = 'citomni_infrastructure_';


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
}


// ----------------------------------------------------------------
// Assertions and formatting
// ----------------------------------------------------------------

/** Fail the current check with $message unless $condition holds. */
function expect(bool $condition, string $message): void {
	if (!$condition) {
		throw new CheckFailed($message);
	}
}


/** Fail the current check unless $actual is identical to $expected. */
function same(mixed $expected, mixed $actual, string $what): void {
	if ($expected !== $actual) {
		throw new CheckFailed($what . ': expected ' . export($expected) . ', got ' . export($actual));
	}
}


/**
 * Run $action and return the throwable it throws.
 *
 * Fails the current check when $action returns normally or throws something that
 * is not an instance of $class. A CheckFailed from inside $action passes through.
 *
 * @param  class-string<\Throwable>  $class   Expected class (or parent class).
 * @param  \Closure                  $action  Code that must throw.
 * @param  string                    $what    Subject for the failure message.
 * @return \Throwable  The caught throwable.
 */
function thrown(string $class, \Closure $action, string $what): \Throwable {
	try {
		$action();
	} catch (CheckFailed $e) {
		throw $e;
	} catch (\Throwable $e) {
		if (!$e instanceof $class) {
			throw new CheckFailed($what . ': expected ' . $class . ', got ' . $e::class . ': ' . squash($e->getMessage()));
		}
		return $e;
	}
	throw new CheckFailed($what . ': expected ' . $class . ', nothing was thrown');
}


/** One-line, bounded rendering of a value for failure messages. */
function export(mixed $value): string {
	$json = \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION);
	return squash($json === false ? \var_export($value, true) : $json);
}


/** Collapse line breaks and cap the length, so a message stays on one line. */
function squash(string $text): string {
	$text = \trim((string)\preg_replace('/\s*\R\s*/', ' | ', $text));
	return \strlen($text) > 400 ? \substr($text, 0, 400) . '...' : $text;
}


// ----------------------------------------------------------------
// Files and processes
// ----------------------------------------------------------------

/** Create an empty, uniquely named directory under the system temp directory. */
function tempDir(string $suite): string {
	$dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . TEMP_PREFIX . $suite . '_test_' . \bin2hex(\random_bytes(6));
	\mkdir($dir, 0700, true);
	return $dir;
}


/** Remove a directory created by tempDir(), including everything below it. Any other path is left alone. */
function removeTree(string $dir): void {
	if (!\str_starts_with(\basename($dir), TEMP_PREFIX) || !\is_dir($dir)) {
		return;
	}

	$items = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($items as $item) {
		if ($item->isDir() && !$item->isLink()) {
			\rmdir($item->getPathname());
		} else {
			\unlink($item->getPathname());
		}
	}
	\rmdir($dir);
}


/**
 * Write a PHP file, creating its directory, and drop any OPcache copy of it.
 *
 * Checks rewrite files that the code under test includes again; with
 * opcache.enable_cli on, a stale compiled copy would hide the new content.
 */
function writePhpFile(string $path, string $source): void {
	if (!\is_dir(\dirname($path))) {
		\mkdir(\dirname($path), 0700, true);
	}
	\file_put_contents($path, $source);
	if (\function_exists('opcache_invalidate')) {
		\opcache_invalidate($path, true);
	}
}


/**
 * Decode a JSON Lines file.
 *
 * @return list<mixed>  One decoded value per non-empty line; empty when the file does not exist.
 */
function readJsonLines(string $file): array {
	if (!\is_file($file)) {
		return [];
	}
	$values = [];
	foreach (\file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $i => $line) {
		try {
			$values[] = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new CheckFailed(\basename($file) . ' line ' . ($i + 1) . ' is not JSON (' . $e->getMessage() . '): ' . squash($line));
		}
	}
	return $values;
}


/**
 * The command prefix for a PHP child process: this binary and the same php.ini.
 *
 * @return list<string>
 */
function phpCommand(): array {
	return match (true) {
		\php_ini_loaded_file() !== false   => [\PHP_BINARY, '-c', \php_ini_loaded_file()],
		\php_ini_scanned_files() !== false => [\PHP_BINARY],
		default                            => [\PHP_BINARY, '-n'],
	};
}


/**
 * Start a PHP child process without waiting. Stdout and stderr go to $out and $err.
 *
 * The child inherits this process's environment, so CITOMNI_TEST_PASSWORD reaches
 * it without appearing on the command line.
 *
 * @param  list<string>  $args  Script path and its arguments.
 * @return resource  Process handle for proc_close().
 */
function startPhp(array $args, string $out, string $err) {
	$proc = \proc_open([...phpCommand(), ...$args], [
		0 => ['pipe', 'r'],
		1 => ['file', $out, 'w'],
		2 => ['file', $err, 'w'],
	], $pipes);
	if (!\is_resource($proc)) {
		throw new \RuntimeException('Cannot start PHP child process: ' . \implode(' ', $args));
	}
	\fclose($pipes[0]);
	return $proc;
}


/**
 * Run a PHP child process to completion.
 *
 * Output goes through files in a temporary directory, not pipes, so a child that
 * writes a lot to stderr cannot block on a full pipe while stdout is read.
 *
 * @param  list<string>  $args  Script path and its arguments.
 * @return array{exit: int, stdout: string, stderr: string}
 */
function runPhp(array $args): array {
	$dir = tempDir('child');
	try {
		$exit = \proc_close(startPhp($args, $dir . '/out', $dir . '/err'));
		return [
			'exit'   => $exit,
			'stdout' => (string)\file_get_contents($dir . '/out'),
			'stderr' => (string)\file_get_contents($dir . '/err'),
		];
	} finally {
		removeTree($dir);
	}
}


/**
 * Wait until every file in $files exists.
 *
 * @param  list<string>  $files
 * @return bool  False when $seconds passed first.
 */
function waitForFiles(array $files, float $seconds): bool {
	$deadline = \microtime(true) + $seconds;
	do {
		\clearstatcache();
		$missing = \array_filter($files, static fn(string $file): bool => !\is_file($file));
		if ($missing === []) {
			return true;
		}
		\usleep(5_000);
	} while (\microtime(true) < $deadline);
	return false;
}


/** Exit code and output of a finished child, for failure messages. */
function childReport(int $exit, string $out, string $err): string {
	return 'exit ' . $exit
		. '; stdout: ' . squash(\is_file($out) ? (string)\file_get_contents($out) : '')
		. '; stderr: ' . squash(\is_file($err) ? (string)\file_get_contents($err) : '');
}


/** Reserve a free local TCP port and return it; the socket is closed again. */
function freePort(): int {
	$socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if ($socket === false) {
		throw new \RuntimeException('Cannot reserve a local port: ' . $errstr);
	}
	$name = (string)\stream_socket_get_name($socket, false);
	\fclose($socket);
	return (int)\substr($name, (int)\strrpos($name, ':') + 1);
}


/** Wait until a local TCP port accepts connections, or fail after $seconds. */
function waitForPort(int $port, float $seconds = 5.0): void {
	$deadline = \microtime(true) + $seconds;
	while (($probe = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1)) === false) {
		if (\microtime(true) >= $deadline) {
			throw new \RuntimeException("Nothing listens on 127.0.0.1:{$port}: {$errstr}");
		}
		\usleep(10_000);
	}
	\fclose($probe);
}


// ----------------------------------------------------------------
// Runner
// ----------------------------------------------------------------

/**
 * Run named checks and print the results and the totals line.
 *
 * Behavior:
 * - Each check runs in order; a CheckFailed is reported with its message, any
 *   other throwable with its class and message.
 * - Multi-process checks run only with CITOMNI_TEST_PARALLEL=1; otherwise they
 *   are reported as SKIP and counted as skipped.
 * - $beforeEach runs before every check that runs.
 *
 * @param  array<string, \Closure>  $checks          Checks by name.
 * @param  array<string, \Closure>  $parallelChecks  Multi-process checks by name.
 * @param  \Closure|null            $beforeEach      Reset hook.
 * @return int  Exit code: 0 when no check failed, otherwise 1.
 */
function runChecks(array $checks, array $parallelChecks = [], ?\Closure $beforeEach = null): int {
	$passed  = 0;
	$failed  = 0;
	$skipped = 0;

	$run = static function (string $name, \Closure $check) use (&$passed, &$failed, $beforeEach): void {
		try {
			if ($beforeEach !== null) {
				$beforeEach();
			}
			$check();
			$passed++;
			\fwrite(\STDOUT, "PASS {$name}\n");
		} catch (CheckFailed $e) {
			$failed++;
			\fwrite(\STDERR, "FAIL {$name} - {$e->getMessage()}\n");
		} catch (\Throwable $e) {
			$failed++;
			\fwrite(\STDERR, "FAIL {$name} - " . $e::class . ': ' . squash($e->getMessage()) . "\n");
		}
	};

	foreach ($checks as $name => $check) {
		$run($name, $check);
	}

	foreach ($parallelChecks as $name => $check) {
		if (\getenv('CITOMNI_TEST_PARALLEL') === '1') {
			$run($name, $check);
		} else {
			$skipped++;
			\fwrite(\STDOUT, "SKIP {$name}: set CITOMNI_TEST_PARALLEL=1 to run multi-process checks\n");
		}
	}

	\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
	return $failed === 0 ? 0 : 1;
}
