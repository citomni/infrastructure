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

namespace CitOmni\Infrastructure\Tests\ValueToSql;

use CitOmni\Infrastructure\Boot\Registry;
use CitOmni\Infrastructure\Exception\ValueConfigurationException;
use CitOmni\Infrastructure\Exception\ValueDefinitionException;
use CitOmni\Infrastructure\Exception\ValueToSqlException;
use CitOmni\Infrastructure\Service\ValueToSql;
use CitOmni\Infrastructure\Tests\Support\App;

use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\mergeLastWins;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\thrown;

/*
 * Standalone suite for \CitOmni\Infrastructure\Service\ValueToSql: UI/form input
 * normalized for SQL parameter binding.
 *
 * The real service runs on the kernel doubles, without Composer, on the shipped
 * locale.format baseline (decimal ",", thousands ".") unless a check sets other
 * separators. No files, no database.
 *
 * Usage:
 *   php tests/value-to-sql/run.php
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
require __DIR__ . '/../../src/Exception/ValueToSqlException.php';
require __DIR__ . '/../../src/Service/ValueToSql.php';


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/** A ValueToSql service on the shipped locale.format baseline with $format overrides. */
function service(array $format = []): ValueToSql {
	// An empty override would replace the whole node, as in the kernel merge.
	$cfg = $format === [] ? Registry::CFG_HTTP : mergeLastWins(Registry::CFG_HTTP, ['locale' => ['format' => $format]]);
	return new ValueToSql(new App($cfg));
}


/** A ValueToSql service on a locale node holding exactly $format. */
function serviceWithFormat(array $format): ValueToSql {
	return new ValueToSql(new App(['locale' => ['format' => $format]]));
}


/**
 * Fail unless every input maps to its expected output.
 *
 * @param list<array{0: mixed, 1: mixed}> $cases  [input, expected] pairs.
 */
function maps(\Closure $normalize, array $cases, string $what): void {
	foreach ($cases as [$input, $expected]) {
		same($expected, $normalize($input), $what . ' ' . export($input));
	}
}


/**
 * Fail unless every input is rejected with ValueToSqlException and the given message key.
 *
 * @param list<mixed> $inputs
 */
function rejects(\Closure $normalize, array $inputs, string $messageKey, string $what): void {
	foreach ($inputs as $input) {
		$e = thrown(ValueToSqlException::class, static fn() => $normalize($input), $what . ' ' . export($input));
		same($messageKey, $e->getMessageKey(), $what . ' ' . export($input) . ' message key');
	}
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'init requires locale.format with one decimal separator and a different or empty thousand separator' => static function (): void {
		$valid = ['decimal_separator' => ',', 'thousand_separator' => '.'];
		thrown(ValueConfigurationException::class, static fn() => new ValueToSql(new App([])), 'no locale');
		thrown(ValueConfigurationException::class, static fn() => new ValueToSql(new App(['locale' => ['language' => 'da']])), 'no locale.format');
		foreach ([
			'no decimal_separator'          => ['thousand_separator' => '.'],
			'no thousand_separator'         => ['decimal_separator' => ','],
			'empty decimal_separator'       => ['decimal_separator' => ''] + $valid,
			'two-char decimal_separator'    => ['decimal_separator' => ',,'] + $valid,
			'two-char thousand_separator'   => ['thousand_separator' => '..'] + $valid,
			'equal separators'              => ['thousand_separator' => ','] + $valid,
		] as $what => $format) {
			thrown(ValueConfigurationException::class, static fn() => serviceWithFormat($format), $what);
		}
		serviceWithFormat(['decimal_separator' => '.', 'thousand_separator' => '']);
	},

	'integer() accepts an optional sign and strict thousand grouping' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->integer($x), [
			['1234', 1234], ['1.234', 1234], ['-1.234.567', -1234567], ['+42', 42], ['007', 7], ['  12  ', 12], ['0', 0], ['-0', 0],
			[1234, 1234], [-5, -5],
			['9223372036854775807', \PHP_INT_MAX], ['-9.223.372.036.854.775.808', \PHP_INT_MIN],
		], 'integer');
	},

	'integer() rejects decimals, wrong grouping, other characters and non-string types' => static function (): void {
		$v = service();
		rejects(static fn($x) => $v->integer($x), ['1,0', '1,', '12.34', '1.2345', '1234.567', '.123', '1..234', '1 234', '1e3', '0x10', '+', '-', '--1', '1-', 'abc', 1.0, true, ['1'], new \stdClass()], 'err_value_to_sql_integer_invalid', 'integer');
		$space = service(['thousand_separator' => ' ']);
		same(1234567, $space->integer('1 234 567'), 'space grouping');
		rejects(static fn($x) => $space->integer($x), ['1.234'], 'err_value_to_sql_integer_invalid', 'dot with space grouping');
		$none = service(['thousand_separator' => '']);
		rejects(static fn($x) => $none->integer($x), ['1.234', '1 234'], 'err_value_to_sql_integer_invalid', 'grouping when disabled');
	},

	'integer() enforces min, max, allowNegative and the 64-bit range' => static function (): void {
		$v = service();
		rejects(static fn($x) => $v->integer($x), ['9223372036854775808', '-9223372036854775809', '99999999999999999999'], 'err_value_to_sql_integer_out_of_range', 'beyond 64 bits');
		$e = thrown(ValueToSqlException::class, static fn() => $v->integer('2101', min: 1900, max: 2100), 'above max');
		same(['err_value_to_sql_integer_out_of_range', ['min' => 1900, 'max' => 2100]], [$e->getMessageKey(), $e->getMessageParams()], 'key and params');
		rejects(static fn($x) => $v->integer($x, min: 1900, max: 2100), ['1899', 1899, 2101], 'err_value_to_sql_integer_out_of_range', 'range');
		same(2100, $v->integer('2.100', min: 1900, max: 2100), 'at max');
		rejects(static fn($x) => $v->integer($x, allowNegative: false), ['-1', -1], 'err_value_to_sql_integer_negative_not_allowed', 'negative');
		thrown(ValueDefinitionException::class, static fn() => $v->integer('5', min: 10, max: 1), 'reversed bounds');
	},

	'empty input is null unless required, for every method' => static function (): void {
		$v = service();
		$methods = [
			'integer'  => static fn($x, bool $r) => $v->integer($x, $r),
			'decimal'  => static fn($x, bool $r) => $v->decimal($x, $r),
			'boolean'  => static fn($x, bool $r) => $v->boolean($x, $r),
			'date'     => static fn($x, bool $r) => $v->date($x, $r),
			'time'     => static fn($x, bool $r) => $v->time($x, $r),
			'datetime' => static fn($x, bool $r) => $v->dateTime($x, $r),
			'text'     => static fn($x, bool $r) => $v->text($x, $r),
			'enum'     => static fn($x, bool $r) => $v->enum($x, ['a'], $r),
			'json'     => static fn($x, bool $r) => $v->json($x, $r),
		];
		foreach ($methods as $name => $normalize) {
			foreach ([null, '', "  \t "] as $empty) {
				same(null, $normalize($empty, false), "{$name}(" . export($empty) . ')');
				$e = thrown(ValueToSqlException::class, static fn() => $normalize($empty, true), "{$name}(" . export($empty) . ', required)');
				same("err_value_to_sql_{$name}_required", $e->getMessageKey(), "{$name} required key");
			}
		}
	},

	'decimal() normalizes to dot-decimal and pads to scale without rounding' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->decimal($x), [
			['1.234,5', '1234.50'], ['1234,56', '1234.56'], [',5', '0.50'], ['123,', '123.00'], ['+1,5', '1.50'], ['-1.234,5', '-1234.50'],
			['-0,00', '0.00'], ['0', '0.00'],
		], 'decimal');
		maps(static fn($x) => $v->decimal($x, scale: 0), [['12', '12'], ['1.234', '1234'], ['-0', '0']], 'decimal scale 0');
		maps(static fn($x) => $v->decimal($x, scale: 4), [['1,5', '1.5000']], 'decimal scale 4');
		$e = thrown(ValueToSqlException::class, static fn() => $v->decimal('1,234'), 'three decimals at scale 2');
		same(['err_value_to_sql_decimal_too_many_decimals', ['scale' => 2]], [$e->getMessageKey(), $e->getMessageParams()], 'key and params');
		// The scale limits the written fraction digits, zeros included: scale 2 does
		// not take "1,500", and scale 0 takes neither "12,5" nor "12,00".
		foreach ([['1,500', 2], ['12,5', 0], ['12,00', 0]] as [$input, $scale]) {
			$e = thrown(ValueToSqlException::class, static fn() => $v->decimal($input, scale: $scale), export($input) . " at scale {$scale}");
			same(['err_value_to_sql_decimal_too_many_decimals', ['scale' => $scale]], [$e->getMessageKey(), $e->getMessageParams()], 'key and params for ' . export($input));
		}
	},

	'decimal() accepts only the configured separators' => static function (): void {
		$v = service();
		rejects(static fn($x) => $v->decimal($x), ['1234.5', '1234.567,8', '1,2,3', '1.23,4', '1,2.3', '12.34.567,8', '1 234,5', '1e3', ',', '+', '-', 'abc', '١٢'], 'err_value_to_sql_decimal_invalid', 'decimal');
		$dot = service(['decimal_separator' => '.', 'thousand_separator' => ',']);
		maps(static fn($x) => $dot->decimal($x), [['1,234.5', '1234.50'], ['.5', '0.50']], 'dot-decimal cfg');
		rejects(static fn($x) => $dot->decimal($x), ['1.234,5'], 'err_value_to_sql_decimal_invalid', 'comma decimal with dot cfg');
	},

	'decimal() with space grouping also accepts no-break spaces' => static function (): void {
		$v = service(['thousand_separator' => ' ']);
		maps(static fn($x) => $v->decimal($x), [["1\u{00A0}234,5", '1234.50'], ["1\u{202F}234", '1234.00'], ['1 234 567,89', '1234567.89']], 'space grouping');
		rejects(static fn($x) => $v->decimal($x), ['1 23,5', '12 345 6,7'], 'err_value_to_sql_decimal_invalid', 'wrong space grouping');
	},

	'decimal() takes int and float input, rounding floats half-up to scale' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->decimal($x), [[5, '5.00'], [-5, '-5.00'], [1234.5, '1234.50'], [0.125, '0.13'], [-0.001, '0.00'], [1.0E15, '1000000000000000.00']], 'decimal');
		maps(static fn($x) => $v->decimal($x, scale: 0), [[2.5, '3'], [-2.5, '-3'], [-0.4, '0'], [7, '7']], 'decimal scale 0');
		rejects(static fn($x) => $v->decimal($x), [\NAN, \INF, -\INF], 'err_value_to_sql_decimal_invalid', 'non-finite float');
		rejects(static fn($x) => $v->decimal($x, allowNegative: false), [-1, -0.5, '-1,5'], 'err_value_to_sql_decimal_negative_not_allowed', 'negative');
		rejects(static fn($x) => $v->decimal($x), [true, [1], new \stdClass()], 'err_value_to_sql_decimal_invalid_type', 'type');
		thrown(ValueDefinitionException::class, static fn() => $v->decimal('1', scale: 19), 'scale 19');
		thrown(ValueDefinitionException::class, static fn() => $v->decimal('1', scale: -1), 'scale -1');
	},

	'boolean() accepts the documented tokens case-insensitively and returns 0 or 1' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->boolean($x), [
			[true, 1], [false, 0], [1, 1], [0, 0], ['1', 1], ['0', 0], ['TRUE', 1], ['False', 0], [' yes ', 1], ['No', 0], ['ON', 1], ['off', 0],
		], 'boolean');
		rejects(static fn($x) => $v->boolean($x), ['y', 'n', '2', 'enabled', 2, -1], 'err_value_to_sql_boolean_invalid', 'boolean');
		rejects(static fn($x) => $v->boolean($x), [1.0, [1], new \stdClass()], 'err_value_to_sql_boolean_invalid_type', 'boolean type');
	},

	'date() accepts YYYY-MM-DD, DD-MM-YYYY and DD/MM/YYYY and checks the calendar' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->date($x), [['2024-02-29', '2024-02-29'], ['29-02-2024', '2024-02-29'], ['01/12/2026', '2026-12-01'], [' 2026-01-31 ', '2026-01-31']], 'date');
		rejects(static fn($x) => $v->date($x), ['2023-02-29', '2026-13-01', '2026-04-31', '00-01-2026', '0000-00-00'], 'err_value_to_sql_date_invalid', 'impossible date');
		rejects(static fn($x) => $v->date($x), ['12-03/2026', '2026-2-1', '2026/02/01', '20260201', '2026-02-01 10:00', '1.2.2026'], 'err_value_to_sql_date_invalid_format', 'date format');
		rejects(static fn($x) => $v->date($x), [20260201, 1.5, true], 'err_value_to_sql_date_invalid', 'date type');
	},

	'time() accepts HH:MM and HH:MM:SS from 00:00:00 to 23:59:59' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->time($x), [['09:05', '09:05:00'], ['23:59:59', '23:59:59'], ['00:00', '00:00:00']], 'time');
		rejects(static fn($x) => $v->time($x), ['24:00', '12:60', '12:00:60'], 'err_value_to_sql_time_invalid', 'time out of range');
		rejects(static fn($x) => $v->time($x), ['9:05', '09:05:5', '0905', '09:05:00.5', '09.05'], 'err_value_to_sql_time_invalid_format', 'time format');
	},

	'dateTime() accepts datetime-local and SQL input and normalizes to YYYY-MM-DD HH:MM:SS' => static function (): void {
		$v = service();
		maps(static fn($x) => $v->dateTime($x), [
			['2026-02-26T14:30', '2026-02-26 14:30:00'], ['2026-02-26T14:30:15', '2026-02-26 14:30:15'], ['2026-02-26 14:30', '2026-02-26 14:30:00'], ["2026-02-26 \t 14:30", '2026-02-26 14:30:00'],
		], 'dateTime');
		rejects(static fn($x) => $v->dateTime($x), ['2026-02-30T10:00', '2026-02-26T24:00', '2026-02-26T10:60'], 'err_value_to_sql_datetime_invalid', 'impossible datetime');
		rejects(static fn($x) => $v->dateTime($x), ['2026-02-26', '26-02-2026 10:00', '2026-02-26T10', '2026-02-26T10:00Z', '2026-02-26T10:00+01:00'], 'err_value_to_sql_datetime_invalid_format', 'datetime format');
	},

	'text() takes strings only, trims by default and limits length in bytes' => static function (): void {
		$v = service();
		same('a b', $v->text('  a b  '), 'trimmed');
		same('  a  ', $v->text('  a  ', trim: false), 'untrimmed');
		same('æøå', $v->text('æøå', maxLen: 6), 'six bytes at maxLen 6');
		$e = thrown(ValueToSqlException::class, static fn() => $v->text('æøå', maxLen: 5), 'six bytes at maxLen 5');
		same(['err_value_to_sql_text_too_long', ['max' => 5]], [$e->getMessageKey(), $e->getMessageParams()], 'key and params');
		rejects(static fn($x) => $v->text($x), [5, 1.5, true, ['a']], 'err_value_to_sql_text_invalid_type', 'text type');
		thrown(ValueDefinitionException::class, static fn() => $v->text('a', maxLen: -1), 'negative maxLen');
	},

	'enum() matches the trimmed input exactly against a list of strings' => static function (): void {
		$v = service();
		same('draft', $v->enum(' draft ', ['draft', 'published']), 'trimmed match');
		rejects(static fn($x) => $v->enum($x, ['draft', 'published']), ['Draft', 'draf', 'publish ed'], 'err_value_to_sql_enum_invalid', 'enum');
		rejects(static fn($x) => $v->enum($x, ['1', '2']), [1, true], 'err_value_to_sql_enum_invalid_type', 'enum type');
		thrown(ValueDefinitionException::class, static fn() => $v->enum('a', []), 'empty allowed list');
		thrown(ValueDefinitionException::class, static fn() => $v->enum('1', [1, 2]), 'non-string allowed values');
	},

	'json() validates JSON strings and encodes arrays and objects' => static function (): void {
		$v = service();
		same('{"a":1}', $v->json('  {"a":1}  '), 'valid JSON string, trimmed');
		same('"text"', $v->json('"text"'), 'JSON scalar string');
		same('{"å":"/x"}', $v->json(['å' => '/x']), 'array, unescaped unicode and slashes');
		same('{"a":1}', $v->json((object)['a' => 1]), 'object');
		rejects(static fn($x) => $v->json($x), ['{a:1}', '{"a":1', "{'a':1}"], 'err_value_to_sql_json_invalid', 'invalid JSON');
		rejects(static fn($x) => $v->json($x), [5, 1.5, true], 'err_value_to_sql_json_invalid_type', 'json type');
		rejects(static fn($x) => $v->json($x), [['x' => \INF]], 'err_value_to_sql_json_encode_failed', 'unencodable array');
	},

	'ValueToSqlException carries the message key, params and an optional field' => static function (): void {
		$e = new ValueToSqlException('Too long.', 'err_value_to_sql_text_too_long', ['max' => 5]);
		same([false, null, 'err_value_to_sql_text_too_long', ['max' => 5], 'Too long.'], [$e->hasField(), $e->getField(), $e->getMessageKey(), $e->getMessageParams(), $e->getMessage()], 'without field');
		$withField = new ValueToSqlException('Too long.', 'err_value_to_sql_text_too_long', [], 'title');
		same([true, 'title'], [$withField->hasField(), $withField->getField()], 'with field');
		same(false, (new ValueToSqlException('x', 'k', [], ''))->hasField(), 'empty field');
	},

	// Regression: withField() cloned the exception, and PHP exceptions cannot be cloned.
	'withField() returns a new exception with the field and the original as previous' => static function (): void {
		$cause = new \RuntimeException('cause');
		[$e, $thrownAt] = [new ValueToSqlException('Too long.', 'err_value_to_sql_text_too_long', ['max' => 5], null, 7, $cause), __LINE__];
		[$copy, $calledAt] = [$e->withField(' title '), __LINE__];
		expect($copy !== $e, 'withField() returned the same instance');
		same(['title', 'Too long.', 'err_value_to_sql_text_too_long', ['max' => 5], 7], [$copy->getField(), $copy->getMessage(), $copy->getMessageKey(), $copy->getMessageParams(), $copy->getCode()], 'copy');
		same([__FILE__, $calledAt], [$copy->getFile(), $copy->getLine()], 'file and line of the copy');
		same($e, $copy->getPrevious(), 'previous of the copy');
		same([null, $cause, $thrownAt], [$e->getField(), $e->getPrevious(), $e->getLine()], 'original');
		same($e, $e->withField(" \t"), 'blank field');
	},

];

exit(runChecks($checks));
