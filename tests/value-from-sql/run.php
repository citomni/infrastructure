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

namespace CitOmni\Infrastructure\Tests\ValueFromSql;

use CitOmni\Infrastructure\Boot\Registry;
use CitOmni\Infrastructure\Exception\ValueConfigurationException;
use CitOmni\Infrastructure\Exception\ValueDefinitionException;
use CitOmni\Infrastructure\Exception\ValueFromSqlException;
use CitOmni\Infrastructure\Service\ValueFromSql;
use CitOmni\Infrastructure\Tests\Support\App;

use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\mergeLastWins;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\thrown;

/*
 * Standalone suite for \CitOmni\Infrastructure\Service\ValueFromSql: SQL result
 * values formatted for UI output and form fields.
 *
 * The real service runs on the kernel doubles, without Composer, on the shipped
 * locale.format baseline (decimal ",", thousands ".", grouping on, scale 2,
 * rounding "fail", no trimming, no seconds) unless a check overrides it. No
 * files, no database.
 *
 * Usage:
 *   php tests/value-from-sql/run.php
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

// CFG_HTTP derives paths from CITOMNI_APP_PATH; this suite reads only locale.format.
\define('CITOMNI_APP_PATH', __DIR__);

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';
require __DIR__ . '/../../src/Boot/Registry.php';
require __DIR__ . '/../../src/Exception/ValueConfigurationException.php';
require __DIR__ . '/../../src/Exception/ValueDefinitionException.php';
require __DIR__ . '/../../src/Exception/ValueFromSqlException.php';
require __DIR__ . '/../../src/Service/ValueFromSql.php';


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/** A ValueFromSql service on the shipped locale.format baseline with $format overrides. */
function service(array $format = []): ValueFromSql {
	// An empty override would replace the whole node, as in the kernel merge.
	$cfg = $format === [] ? Registry::CFG_HTTP : mergeLastWins(Registry::CFG_HTTP, ['locale' => ['format' => $format]]);
	return new ValueFromSql(new App($cfg));
}


/**
 * Fail unless every input maps to its expected output.
 *
 * @param list<array{0: mixed, 1: mixed}> $cases  [input, expected] pairs.
 */
function maps(\Closure $format, array $cases, string $what): void {
	foreach ($cases as [$input, $expected]) {
		same($expected, $format($input), $what . ' ' . export($input));
	}
}


/**
 * Fail unless every input is rejected with ValueFromSqlException and the given message key.
 *
 * @param list<mixed> $inputs
 */
function rejects(\Closure $format, array $inputs, string $messageKey, string $what): void {
	foreach ($inputs as $input) {
		$e = thrown(ValueFromSqlException::class, static fn() => $format($input), $what . ' ' . export($input));
		same($messageKey, $e->getMessageKey(), $what . ' ' . export($input) . ' message key');
	}
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'init requires every locale.format policy key with a valid value' => static function (): void {
		$baseline = Registry::CFG_HTTP['locale']['format'];
		thrown(ValueConfigurationException::class, static fn() => new ValueFromSql(new App([])), 'no locale');
		foreach (\array_keys($baseline) as $key) {
			$format = $baseline;
			unset($format[$key]);
			thrown(ValueConfigurationException::class, static fn() => new ValueFromSql(new App(['locale' => ['format' => $format]])), "without {$key}");
		}
		$invalid = [
			'decimal_separator'              => ['', ',,'],
			'thousand_separator'             => [',', '..'],
			'group_thousands'                => ['yes', 1],
			'decimal_scale'                  => [-1, 19, '2'],
			'decimal_string_rounding'        => ['HALF_UP', 'round', ''],
			'decimal_trim_trailing_zeros'    => [0, 'false'],
			'time_include_seconds'           => [1, 'true'],
			'datetime_local_include_seconds' => [0, 'no'],
		];
		foreach ($invalid as $key => $values) {
			foreach ($values as $value) {
				thrown(ValueConfigurationException::class, static fn() => service([$key => $value]), "{$key} " . export($value));
			}
		}
	},

	'integer() groups digits as text, strips leading zeros and normalizes -0' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->integer($x), [
			['1234567', '1.234.567'], [1234, '1.234'], [-1234567, '-1.234.567'], ['123', '123'], ['007', '7'], ['+5', '5'], ['-0', '0'], [' 42 ', '42'],
			['18446744073709551615', '18.446.744.073.709.551.615'],
		], 'integer');
		same('1234567', $v->integer('1234567', groupThousands: false), 'grouping off per call');
		same('1234567', service(['thousand_separator' => ''])->integer('1234567'), 'empty thousand separator');
		same('1 234 567', service(['thousand_separator' => ' '])->integer('1234567'), 'space separator');
		same('1234567', service(['group_thousands' => false])->integer('1234567'), 'grouping off in cfg');
	},

	'integer() rejects separators, decimals and non-integer types' => static function (): void {
		$v = service();
		rejects(static fn($x) => $v->integer($x), ['1.234', '1,5', '12.0', '1e3', '-', '+', 'abc', '0x1A'], 'err_value_from_sql_integer_invalid', 'integer');
		rejects(static fn($x) => $v->integer($x), [1.0, true, [1], new \stdClass()], 'err_value_from_sql_integer_invalid_type', 'integer type');
	},

	'null and empty input is null unless required' => static function (): void {
		$v = service();
		$methods = [
			'integer'  => static fn($x, bool $r) => $v->integer($x, $r),
			'decimal'  => static fn($x, bool $r) => $v->decimal($x, $r),
			'boolean'  => static fn($x, bool $r) => $v->boolean($x, $r),
			'date'     => static fn($x, bool $r) => $v->date($x, $r),
			'time'     => static fn($x, bool $r) => $v->time($x, $r),
			'datetime' => static fn($x, bool $r) => $v->dateTimeLocal($x, $r),
			'text'     => static fn($x, bool $r) => $v->text($x, $r),
			'json'     => static fn($x, bool $r) => $v->json($x, $r),
		];
		foreach ($methods as $name => $format) {
			foreach ([null, ''] as $empty) {
				same(null, $format($empty, false), "{$name}(" . export($empty) . ')');
				$e = thrown(ValueFromSqlException::class, static fn() => $format($empty, true), "{$name}(" . export($empty) . ', required)');
				same("err_value_from_sql_{$name}_required", $e->getMessageKey(), "{$name} required key");
			}
		}
	},

	'decimal() formats SQL dot-decimals with the locale separators and pads to scale' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->decimal($x), [
			['1234.5', '1.234,50'], ['1234567.89', '1.234.567,89'], ['.5', '0,50'], ['+1.20', '1,20'], ['-0.00', '0,00'], ['007.10', '7,10'], [3, '3,00'], [-1234, '-1.234,00'], [' 1.5 ', '1,50'],
		], 'decimal');
		maps(static fn($x) => $v->decimal($x, scale: 0), [['12', '12'], ['12.000', '12'], ['-0.0', '0']], 'decimal scale 0');
		same('1234,50', $v->decimal('1234.5', groupThousands: false), 'grouping off per call');
		same('1,234.50', service(['decimal_separator' => '.', 'thousand_separator' => ','])->decimal('1234.5'), 'dot-decimal locale');
	},

	'decimal() applies the rounding modes fail, truncate and half_up to string input' => static function (): void {
		$v = service();
		$e = thrown(ValueFromSqlException::class, static fn() => $v->decimal('1.005'), 'fail');
		same(['err_value_from_sql_decimal_too_many_decimals', ['scale' => 2]], [$e->getMessageKey(), $e->getMessageParams()], 'fail key and params');
		maps(static fn($x) => $v->decimal($x, rounding: 'truncate'), [['1.009', '1,00'], ['-0.009', '0,00'], ['-1.999', '-1,99']], 'truncate');
		maps(static fn($x) => $v->decimal($x, rounding: 'half_up'), [
			['1.005', '1,01'], ['1.004', '1,00'], ['9.995', '10,00'], ['999.999', '1.000,00'], ['-1.005', '-1,01'], ['-0.004', '0,00'], ['-0.005', '-0,01'],
		], 'half_up');
		maps(static fn($x) => $v->decimal($x, scale: 0, rounding: 'half_up'), [['2.5', '3'], ['-2.5', '-3'], ['0.4', '0'], ['-0.4', '0'], ['99.5', '100']], 'half_up at scale 0');
		maps(static fn($x) => $v->decimal($x, scale: 0, rounding: 'truncate'), [['2.9', '2'], ['-0.9', '0']], 'truncate at scale 0');
		same('1,01', service(['decimal_string_rounding' => 'half_up'])->decimal('1.005'), 'rounding from cfg');
	},

	'decimal() with trim_trailing_zeros drops zeros and then the separator' => static function (): void {
		$v = service(['decimal_trim_trailing_zeros' => true]);
		maps(static fn($x) => $v->decimal($x), [['1234.50', '1.234,5'], ['1234.00', '1.234'], ['0.10', '0,1'], ['-0.00', '0']], 'trim');
		same('1.234,50', $v->decimal('1234.5', trimTrailingZeros: false), 'trimming off per call');
	},

	'decimal() rejects locale formats, scientific notation and non-finite floats' => static function (): void {
		$v = service();
		rejects(static fn($x) => $v->decimal($x), ['1,5', '1.234,5', '1e3', '1.2.3', '-', '+', '.', '1.', 'abc', \NAN, \INF], 'err_value_from_sql_decimal_invalid', 'decimal');
		rejects(static fn($x) => $v->decimal($x), [true, [1], new \stdClass()], 'err_value_from_sql_decimal_invalid_type', 'decimal type');
		thrown(ValueDefinitionException::class, static fn() => $v->decimal('1', scale: 19), 'scale 19');
		thrown(ValueDefinitionException::class, static fn() => $v->decimal('1', rounding: 'HALF_UP'), 'rounding override HALF_UP');
	},

	'decimal() rounds float input half-up to scale regardless of the rounding mode' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->decimal($x), [[1234.5, '1.234,50'], [0.125, '0,13'], [-0.001, '0,00']], 'float');
		maps(static fn($x) => $v->decimal($x, scale: 0), [[2.5, '3'], [-2.5, '-3'], [-0.4, '0']], 'float at scale 0');
	},

	'boolean() accepts 0 and 1 as int, string or bool' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->boolean($x), [['1', true], ['0', false], [' 1 ', true], [1, true], [0, false], [true, true], [false, false]], 'boolean');
		rejects(static fn($x) => $v->boolean($x), [2, -1, '2', 'true', 'yes', 'on'], 'err_value_from_sql_boolean_invalid', 'boolean');
		rejects(static fn($x) => $v->boolean($x), [1.0, [1], new \stdClass()], 'err_value_from_sql_boolean_invalid_type', 'boolean type');
	},

	'date() validates SQL DATE and formats it on request' => static function (): void {
		$v = service();
		same('2026-02-26', $v->date('2026-02-26'), 'default format');
		same(['26-02-2026', '26/02/2026', '02/26/2026'], [$v->date('2026-02-26', format: 'DD-MM-YYYY'), $v->date('2026-02-26', format: 'DD/MM/YYYY'), $v->date('2026-02-26', format: 'MM/DD/YYYY')], 'display formats');
		rejects(static fn($x) => $v->date($x), ['2026-02-30', '2023-02-29', '0000-00-00', '2026-00-10'], 'err_value_from_sql_date_invalid', 'impossible date');
		rejects(static fn($x) => $v->date($x), ['26-02-2026', '2026-2-26', '2026-02-26 10:00:00', '20260226'], 'err_value_from_sql_date_invalid_format', 'date format');
		rejects(static fn($x) => $v->date($x), [1.5, true], 'err_value_from_sql_date_invalid_type', 'date type');
		thrown(ValueDefinitionException::class, static fn() => $v->date(null, format: 'YYYY/MM/DD'), 'unsupported format, even for null');
	},

	'time() follows cfg time_include_seconds unless overridden per call' => static function (): void {
		$v = service();
		same(['14:30', '14:30:15', '14:30:00'], [$v->time('14:30:15'), $v->time('14:30:15', includeSeconds: true), $v->time('14:30', includeSeconds: true)], 'cfg false, then overrides');
		same('14:30:15', service(['time_include_seconds' => true])->time('14:30:15'), 'cfg true');
		rejects(static fn($x) => $v->time($x), ['24:00:00', '12:60', '12:00:60'], 'err_value_from_sql_time_invalid', 'time out of range');
		rejects(static fn($x) => $v->time($x), ['100:00:00', '-01:00:00', '9:05', '14:30:15.5'], 'err_value_from_sql_time_invalid_format', 'time format');
	},

	'dateTimeLocal() turns SQL DATETIME into datetime-local' => static function (): void {
		$v = service();
		same(['2026-02-26T14:30', '2026-02-26T14:30:15'], [$v->dateTimeLocal('2026-02-26 14:30:15'), $v->dateTimeLocal('2026-02-26 14:30:15', includeSeconds: true)], 'cfg false, then override');
		same('2026-02-26T14:30:00', service(['datetime_local_include_seconds' => true])->dateTimeLocal('2026-02-26 14:30'), 'cfg true');
		rejects(static fn($x) => $v->dateTimeLocal($x), ['2026-02-26T14:30:15', '2026-02-26', '2026-02-26_14:30'], 'err_value_from_sql_datetime_invalid_format', 'datetime format');
		rejects(static fn($x) => $v->dateTimeLocal($x), ['2026-02-30 10:00'], 'err_value_from_sql_date_invalid', 'impossible date');
		rejects(static fn($x) => $v->dateTimeLocal($x), ['2026-02-26 24:00'], 'err_value_from_sql_time_invalid', 'impossible time');
	},

	'text() returns stored text unchanged, whitespace included' => static function (): void {
		$v = service();
		same(['  padded  ', '   '], [$v->text('  padded  '), $v->text('   ')], 'values');
		rejects(static fn($x) => $v->text($x), [5, 1.5, true, ['a']], 'err_value_from_sql_text_invalid_type', 'text type');
	},

	'json() decodes JSON objects and arrays only' => static function (): void {
		$v = service();
		same([['a' => 1], [1, 2], []], [$v->json('{"a":1}'), $v->json(' [1,2] '), $v->json('{}')], 'decoded');
		rejects(static fn($x) => $v->json($x), ['"text"', '5', 'null', 'true', '{a:1}', '{"a":'], 'err_value_from_sql_json_invalid', 'json');
		rejects(static fn($x) => $v->json($x), [5, ['a' => 1]], 'err_value_from_sql_json_invalid_type', 'json type');
	},

	// Regression: withField() cloned the exception, and PHP exceptions cannot be cloned.
	'withField() returns a new exception with the field and the original as previous' => static function (): void {
		$cause = new \RuntimeException('cause');
		[$e, $thrownAt] = [new ValueFromSqlException('Too many decimals.', 'err_value_from_sql_decimal_too_many_decimals', ['scale' => 2], null, 7, $cause), __LINE__];
		[$copy, $calledAt] = [$e->withField(' price '), __LINE__];
		expect($copy !== $e, 'withField() returned the same instance');
		same(['price', 'Too many decimals.', 'err_value_from_sql_decimal_too_many_decimals', ['scale' => 2], 7], [$copy->getField(), $copy->getMessage(), $copy->getMessageKey(), $copy->getMessageParams(), $copy->getCode()], 'copy');
		same([__FILE__, $calledAt], [$copy->getFile(), $copy->getLine()], 'file and line of the copy');
		same($e, $copy->getPrevious(), 'previous of the copy');
		same([null, $cause, $thrownAt], [$e->getField(), $e->getPrevious(), $e->getLine()], 'original');
		same($e, $e->withField(" \t"), 'blank field');
	},

];

exit(runChecks($checks));
