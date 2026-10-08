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

namespace CitOmni\Infrastructure\Tests\Txt;

use CitOmni\Infrastructure\Exception\TxtConfigException;
use CitOmni\Infrastructure\Service\Txt;
use CitOmni\Infrastructure\Tests\Support\App;
use CitOmni\Infrastructure\Tests\Support\LogRecorder;

use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\removeTree;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\tempDir;
use function CitOmni\Infrastructure\Tests\Support\thrown;
use function CitOmni\Infrastructure\Tests\Support\writePhpFile;

/*
 * Standalone suite for \CitOmni\Infrastructure\Service\Txt.
 *
 * The real service runs on the kernel doubles, without Composer. CITOMNI_APP_PATH
 * points at a temporary directory that holds the language files of the checks:
 * language/<lang>/... for the app layer and vendor/<vendor>/<package>/language/<lang>/...
 * for package layers. The directory is removed again afterwards.
 *
 * Usage:
 *   php tests/txt/run.php
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

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';

$root = tempDir('txt');
\define('CITOMNI_APP_PATH', $root);

require __DIR__ . '/../../src/Exception/TxtConfigException.php';
require __DIR__ . '/../../src/Service/Txt.php';


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/**
 * A Txt service for the given cfg, plus the log it writes to.
 *
 * @return array{0: Txt, 1: LogRecorder}
 */
function txt(array $cfg = ['locale' => ['language' => 'da']]): array {
	$app = new App($cfg);
	$log = new LogRecorder();
	$app->set('log', $log);
	return [new Txt($app), $log];
}


/** Write a language file below CITOMNI_APP_PATH that returns $payload. */
function languageFile(string $relativePath, mixed $payload): string {
	$path = \CITOMNI_APP_PATH . '/' . $relativePath;
	writePhpFile($path, "<?php\nreturn " . \var_export($payload, true) . ";\n");
	return $path;
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'locale.language is required' => static function (): void {
		thrown(TxtConfigException::class, static fn() => txt([]), 'no locale node');
		thrown(TxtConfigException::class, static fn() => txt(['locale' => ['timezone' => 'UTC']]), 'locale without language');
	},

	'locale.language must be "xx" or "xx_YY"' => static function (): void {
		foreach (['', 'dan', 'DA', 'da-DK', 'da_dk', 'da_DK_x', 'd'] as $language) {
			thrown(TxtConfigException::class, static fn() => txt(['locale' => ['language' => $language]]), 'language ' . export($language));
		}
		txt(['locale' => ['language' => 'da']]);
		txt(['locale' => ['language' => 'da_DK']]);
	},

	'the app layer reads language/<lang>/<file>.php' => static function (): void {
		languageFile('language/da/app_layer.php', ['greeting' => 'Hej']);
		languageFile('language/en/app_layer.php', ['greeting' => 'Hello']);
		[$txt] = txt();
		same('Hej', $txt->get('greeting', 'app_layer'), 'da');
		[$txt] = txt(['locale' => ['language' => 'en']]);
		same('Hello', $txt->get('greeting', 'app_layer', 'app'), 'en');
	},

	'a vendor layer reads vendor/<vendor>/<package>/language/<lang>/<file>.php' => static function (): void {
		languageFile('vendor/acme/shop/language/da/cart.php', ['empty' => 'Kurven er tom']);
		[$txt] = txt();
		same('Kurven er tom', $txt->get('empty', 'cart', 'acme/shop'), 'layer acme/shop');
		same('Kurven er tom', $txt->get('empty', 'cart', '/acme/shop/'), 'layer with surrounding slashes');
	},

	'file names may contain subdirectories' => static function (): void {
		languageFile('language/da/emails/welcome-mail.php', ['subject' => 'Velkommen']);
		[$txt] = txt();
		same('Velkommen', $txt->get('subject', 'emails/welcome-mail'), 'emails/welcome-mail');
	},

	'unsafe file names and malformed layers are rejected' => static function (): void {
		[$txt, $log] = txt();
		foreach (['', '../secret', 'a/../b', '/abs', 'x.php', 'a b', 'trailing/', '-dash', 'x\\y'] as $file) {
			thrown(\InvalidArgumentException::class, static fn() => $txt->get('k', $file), 'file ' . export($file));
		}
		foreach (['acme', 'acme/shop/extra', '../acme/shop', 'ac me/shop', 'acme/../shop'] as $layer) {
			thrown(\InvalidArgumentException::class, static fn() => $txt->get('k', 'cart', $layer), 'layer ' . export($layer));
		}
		same([], $log->records, 'log records');
	},

	'a missing or empty key returns the default with placeholders applied and logs txt.missing_key' => static function (): void {
		languageFile('language/da/defaults.php', ['empty' => '', 'null' => null, 'present' => 'x']);
		[$txt, $log] = txt();
		foreach (['absent', 'empty', 'null'] as $key) {
			same('Hej Bo', $txt->get($key, 'defaults', 'app', 'Hej %NAME%', ['name' => 'Bo']), "default for {$key}");
		}
		$records = $log->category('txt.missing_key');
		same(3, \count($records), 'txt.missing_key records');
		same('txt.jsonl', $records[0]['file'], 'log file');
		$context = $records[0]['context'];
		same(['absent', 'app', 'da', 'cli', CITOMNI_APP_PATH . '/language/da/defaults.php'], [$context['key'] ?? null, $context['layer'] ?? null, $context['language'] ?? null, $context['runtime'] ?? null, $context['file_path'] ?? null], 'context');
	},

	'scalar values are returned as strings' => static function (): void {
		languageFile('language/da/scalars.php', ['int' => 42, 'float' => 1.5, 'true' => true]);
		[$txt, $log] = txt();
		same(['42', '1.5', '1'], [$txt->get('int', 'scalars'), $txt->get('float', 'scalars'), $txt->get('true', 'scalars')], 'values');
		same([], $log->records, 'log records');
	},

	'a non-scalar value returns the default and logs txt.non_scalar_value' => static function (): void {
		languageFile('language/da/nested.php', ['list' => ['a', 'b']]);
		[$txt, $log] = txt();
		same('fallback', $txt->get('list', 'nested', 'app', 'fallback'), 'value');
		same('array', $log->category('txt.non_scalar_value')[0]['context']['value_type'] ?? null, 'logged value_type');
	},

	'placeholders are %UPPERCASE% tokens taken from the vars keys' => static function (): void {
		languageFile('language/da/vars.php', ['line' => 'Hej %NAME% fra %APP_NAME%, %name% og %MISSING%']);
		[$txt] = txt();
		same('Hej Bo fra CitOmni, %name% og %MISSING%', $txt->get('line', 'vars', 'app', '', ['name' => 'Bo', 'app_name' => 'CitOmni']), 'with vars');
		same('Hej %NAME% fra %APP_NAME%, %name% og %MISSING%', $txt->get('line', 'vars'), 'without vars');
		same('7 items', $txt->get('absent', 'vars', 'app', '%COUNT% items', ['count' => 7]), 'non-string var');
	},

	'a missing file returns defaults and is logged once as txt.missing_file' => static function (): void {
		[$txt, $log] = txt();
		same('a', $txt->get('k1', 'does_not_exist', 'app', 'a'), 'first lookup');
		same('b', $txt->get('k2', 'does_not_exist', 'app', 'b'), 'second lookup');
		$records = $log->category('txt.missing_file');
		same(1, \count($records), 'txt.missing_file records');
		same(['does_not_exist', 'k1'], [$records[0]['context']['file'] ?? null, $records[0]['context']['key'] ?? null], 'context');
	},

	'a file that does not return an array is logged once as txt.invalid_file_payload' => static function (): void {
		languageFile('language/da/broken.php', 'not an array');
		[$txt, $log] = txt();
		same('d1', $txt->get('k1', 'broken', 'app', 'd1'), 'first lookup');
		same('d2', $txt->get('k2', 'broken', 'app', 'd2'), 'second lookup');
		$records = $log->category('txt.invalid_file_payload');
		same(1, \count($records), 'txt.invalid_file_payload records');
		same('string', $records[0]['context']['payload_type'] ?? null, 'payload_type');
	},

	'a language file is loaded once per service instance' => static function (): void {
		$path = languageFile('language/da/cached.php', ['k' => 'first']);
		[$txt] = txt();
		same('first', $txt->get('k', 'cached'), 'first lookup');
		writePhpFile($path, "<?php\nreturn ['k' => 'second'];\n");
		same('first', $txt->get('k', 'cached'), 'same instance after the file changed');
		[$fresh] = txt();
		same('second', $fresh->get('k', 'cached'), 'new instance');
	},

	'lookups during an HTTP request log the request URI' => static function (): void {
		languageFile('language/da/http_lookup.php', ['present' => 'x']);
		[$txt, $log] = txt();
		$_SERVER['REQUEST_URI'] = '/da/kontakt.html';
		try {
			$txt->get('absent', 'http_lookup');
		} finally {
			unset($_SERVER['REQUEST_URI']);
		}
		$context = $log->category('txt.missing_key')[0]['context'] ?? [];
		same(['http', '/da/kontakt.html'], [$context['runtime'] ?? null, $context['request_uri'] ?? null], 'runtime and request_uri');
	},

];


$exitCode = 1;
try {
	$exitCode = runChecks($checks);
} finally {
	removeTree($root);
}
exit($exitCode);
