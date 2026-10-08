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

use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\removeTree;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\runPhp;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\squash;
use function CitOmni\Infrastructure\Tests\Support\tempDir;
use function CitOmni\Infrastructure\Tests\Support\thrown;
use function CitOmni\Infrastructure\Tests\Support\writePhpFile;

/*
 * Standalone suite for \CitOmni\Infrastructure\Service\Secrets.
 *
 * The real service runs on the kernel doubles, without Composer, with
 * CITOMNI_ENVIRONMENT set to "dev". Every check gets its own application root
 * below one temporary directory, which is removed again afterwards. The secret
 * values are fixed test strings.
 *
 * CITOMNI_ENVIRONMENT is a constant, so the checks for "stage", "prod", an
 * unsupported value and a missing constant run probe.php in a child process.
 *
 * Usage:
 *   php tests/secrets/run.php
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

\define('CITOMNI_ENVIRONMENT', 'dev');

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';
require __DIR__ . '/../../src/Service/Secrets.php';

$root = tempDir('secrets');

/** Package root, for the shipped secret file stubs. */
const PACKAGE_ROOT = __DIR__ . '/../..';


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/** A new application root without a var/secrets directory. */
function appRoot(): string {
	global $root;
	static $n = 0;
	$dir = $root . '/app' . ++$n;
	\mkdir($dir);
	return $dir;
}


/** Write var/secrets/app.secret.<env>.php below $appRoot, returning $payload (or $source verbatim). */
function secretFile(string $appRoot, string $environment, mixed $payload, ?string $source = null): string {
	$path = $appRoot . '/var/secrets/app.secret.' . $environment . '.php';
	writePhpFile($path, $source ?? "<?php\nreturn " . \var_export($payload, true) . ";\n");
	return $path;
}


/** A Secrets service for $appRoot in this process (CITOMNI_ENVIRONMENT "dev"). */
function secrets(string $appRoot): Secrets {
	return new Secrets(new App([], $appRoot));
}


/**
 * Look up $key through probe.php with the given environment ("-" leaves it undefined).
 *
 * @return array<string, mixed>  The probe's JSON result.
 */
function probe(string $environment, string $appRoot, string $key): array {
	$r = runPhp([__DIR__ . '/probe.php', $environment, $appRoot, $key]);
	$result = \json_decode(\trim($r['stdout']), true);
	expect($r['exit'] === 0 && \is_array($result), 'probe failed: exit ' . $r['exit'] . '; stdout: ' . squash($r['stdout']) . '; stderr: ' . squash($r['stderr']));
	return $result;
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'a missing secret file is an empty store' => static function (): void {
		$secrets = secrets(appRoot());
		same(false, $secrets->has('db.password'), 'has()');
		$e = thrown(\OutOfBoundsException::class, static fn() => $secrets->get('db.password'), 'get()');
		same("Unknown secret: 'db.password'", $e->getMessage(), 'message');
	},

	'dev reads var/secrets/app.secret.dev.php' => static function (): void {
		$appRoot = appRoot();
		secretFile($appRoot, 'dev', ['db.password' => 'dev-db', 'mail.smtp.password' => 'dev-smtp']);
		secretFile($appRoot, 'prod', ['db.password' => 'prod-db']);
		$secrets = secrets($appRoot);
		same([true, 'dev-db', 'dev-smtp'], [$secrets->has('db.password'), $secrets->get('db.password'), $secrets->get('mail.smtp.password')], 'values');
	},

	'keys are exact: case-sensitive, and empty or padded keys are rejected before any file is read' => static function (): void {
		$appRoot = appRoot();
		secretFile($appRoot, 'dev', null, "<?php\nreturn 'not an array';\n");
		$secrets = secrets($appRoot);
		foreach (['', ' db.password', "db.password\n", "\tx"] as $key) {
			thrown(\InvalidArgumentException::class, static fn() => $secrets->has($key), 'has(' . export($key) . ')');
			thrown(\InvalidArgumentException::class, static fn() => $secrets->get($key), 'get(' . export($key) . ')');
		}

		$valid = appRoot();
		secretFile($valid, 'dev', ['db.password' => 'x']);
		same([true, false], [secrets($valid)->has('db.password'), secrets($valid)->has('DB.password')], 'exact and differently cased key');
	},

	'an empty string is a valid secret value' => static function (): void {
		$appRoot = appRoot();
		secretFile($appRoot, 'dev', ['db.password' => '']);
		same([true, ''], [secrets($appRoot)->has('db.password'), secrets($appRoot)->get('db.password')], 'has() and get()');
	},

	'a secret file must return a flat map of string keys to string values' => static function (): void {
		$payloads = ['not an array', [1 => 'x'], ['' => 'x'], [' padded' => 'x'], ['padded ' => 'x'], ['k' => 5], ['k' => null], ['k' => ['nested' => 'x']], ['k' => true]];
		foreach ($payloads as $payload) {
			$appRoot = appRoot();
			secretFile($appRoot, 'dev', $payload);
			thrown(\UnexpectedValueException::class, static fn() => secrets($appRoot)->has('k'), 'payload ' . export($payload));
		}
	},

	'a directory at the secret path is rejected' => static function (): void {
		$appRoot = appRoot();
		\mkdir($appRoot . '/var/secrets/app.secret.dev.php', 0700, true);
		$e = thrown(\UnexpectedValueException::class, static fn() => secrets($appRoot)->has('k'), 'directory');
		expect(\str_contains($e->getMessage(), 'not a regular file'), 'message: ' . $e->getMessage());
	},

	'the file is read on first use and once per instance' => static function (): void {
		$appRoot = appRoot();
		$path = secretFile($appRoot, 'dev', null, "<?php\nreturn 'broken';\n");
		$secrets = secrets($appRoot);
		writePhpFile($path, "<?php\nreturn ['k' => 'first'];\n");
		same('first', $secrets->get('k'), 'first read after construction');
		writePhpFile($path, "<?php\nreturn ['k' => 'second'];\n");
		same('first', $secrets->get('k'), 'same instance after the file changed');
		same('second', secrets($appRoot)->get('k'), 'new instance');
	},

	'debug output contains neither keys nor values' => static function (): void {
		$appRoot = appRoot();
		secretFile($appRoot, 'dev', ['service.token' => 'sekret-value-123']);
		$secrets = secrets($appRoot);
		$secrets->get('service.token');
		\ob_start();
		\var_dump($secrets);
		$dump = (string)\ob_get_clean() . \print_r($secrets, true);
		expect(!\str_contains($dump, 'sekret-value-123') && !\str_contains($dump, 'service.token'), 'debug output leaks the store: ' . squash($dump));
		same(['loaded' => true], $secrets->__debugInfo(), 'debug info');
	},

	'the shipped secret file stubs load as empty stores' => static function (): void {
		$appRoot = appRoot();
		foreach (['dev', 'stage', 'prod'] as $environment) {
			$stub = PACKAGE_ROOT . '/install/scaffold/var/secrets/app.secret.' . $environment . '.php.stub';
			expect(\is_file($stub), 'missing stub ' . \basename($stub));
			secretFile($appRoot, $environment, null, (string)\file_get_contents($stub));
		}
		same(false, secrets($appRoot)->has('db.password'), 'dev stub');
		same(['has' => false, 'value' => null], probe('stage', $appRoot, 'db.password'), 'stage stub');
		same(['has' => false, 'value' => null], probe('prod', $appRoot, 'db.password'), 'prod stub');
	},

	'stage and prod read only their own file, without falling back to dev' => static function (): void {
		$appRoot = appRoot();
		secretFile($appRoot, 'dev', ['db.password' => 'dev-db']);
		secretFile($appRoot, 'stage', ['db.password' => 'stage-db']);
		same(['has' => true, 'value' => 'stage-db'], probe('stage', $appRoot, 'db.password'), 'stage');
		same(['has' => false, 'value' => null], probe('prod', $appRoot, 'db.password'), 'prod without a prod file');
	},

	'an unsupported or missing CITOMNI_ENVIRONMENT is a RuntimeException' => static function (): void {
		$appRoot = appRoot();
		secretFile($appRoot, 'dev', ['db.password' => 'dev-db']);
		$unsupported = probe('test', $appRoot, 'db.password');
		same('RuntimeException', $unsupported['error'] ?? null, 'error class for "test"');
		expect(\str_contains((string)($unsupported['message'] ?? ''), "'test'"), 'message: ' . export($unsupported));
		$missing = probe('-', $appRoot, 'db.password');
		same(['error' => 'RuntimeException', 'message' => 'CITOMNI_ENVIRONMENT is required to resolve application secrets.'], $missing, 'without the constant');
	},

];


$exitCode = 1;
try {
	$exitCode = runChecks($checks);
} finally {
	removeTree($root);
}
exit($exitCode);
