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

namespace CitOmni\Infrastructure\Service;

use CitOmni\Infrastructure\Exception\ValueConfigurationException;
use CitOmni\Infrastructure\Exception\ValueDefinitionException;
use CitOmni\Infrastructure\Exception\ValueFromSqlException;
use CitOmni\Kernel\Service\BaseService;

/**
 * ValueFromSql: Service that formats values read from SQL for display and for form fields.
 *
 * Turns what mysqli returns for a MySQL or MariaDB column into the string that a page shows
 * or a form field takes: Numbers follow cfg locale.format, dates and times the formats that
 * HTML inputs accept. A stored value that it cannot read exactly is rejected rather than
 * guessed at, because it points at bad data. Reading the values belongs to the Repositories,
 * and escaping them for HTML belongs to the templates.
 *
 * Rules:
 * - Never guess or cast silently: A value that does not match exactly is rejected with
 *   ValueFromSqlException, whose message key names the reason.
 * - Treat a missing value as null, or as ValueFromSqlException when required is true.
 * - Report an invalid rule from the calling code as ValueDefinitionException, never as
 *   ValueFromSqlException.
 * - Format numbers digit by digit, so BIGINT and DECIMAL strings keep every digit.
 */
class ValueFromSql extends BaseService {

	/** Rounding mode that rejects nonzero digits beyond the scale. */
	private const ROUND_FAIL = 'fail';

	/** Rounding mode that drops the digits beyond the scale. */
	private const ROUND_TRUNCATE = 'truncate';

	/** Rounding mode that rounds half up at the scale. */
	private const ROUND_HALF_UP = 'half_up';

	/** Decimal separator of cfg locale.format.decimal_separator. */
	private string $decimalSeparator = ',';

	/** Thousand separator of cfg locale.format.thousand_separator, or '' for none. */
	private string $thousandSeparator = '.';

	/** Whether to group thousands by default; cfg locale.format.group_thousands. */
	private bool $groupThousands = true;

	/** Default number of decimals, 0-18; cfg locale.format.decimal_scale. */
	private int $decimalScale = 2;

	/** Default rounding mode, one of the ROUND_* values; cfg locale.format.decimal_string_rounding. */
	private string $decimalStringRounding = self::ROUND_FAIL;

	/** Whether to drop trailing zeros of the decimals by default; cfg locale.format.decimal_trim_trailing_zeros. */
	private bool $decimalTrimTrailingZeros = false;

	/** Whether time() includes seconds by default; cfg locale.format.time_include_seconds. */
	private bool $timeIncludeSeconds = false;

	/** Whether dateTimeLocal() includes seconds by default; cfg locale.format.datetime_local_include_seconds. */
	private bool $dateTimeLocalIncludeSeconds = false;

	/**
	 * Read and check cfg locale.format once.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\ValueConfigurationException  When decimal_separator is not one byte, thousand_separator is neither '' nor one byte or equals decimal_separator, decimal_scale is not an int from 0 to 18, decimal_string_rounding is not fail, truncate or half_up, or one of the four flags is not a bool.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		// Package-owned cfg: Assumes the Registry baseline for locale.format, so no isset() guard.
		$format = $this->app->cfg->locale->format;

		// Required: Separators.
		$dec = (string)$format->decimal_separator;
		$tho = (string)$format->thousand_separator;

		if ($dec === '' || \strlen($dec) !== 1) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.decimal_separator must be exactly 1 char.');
		}
		if ($tho !== '' && \strlen($tho) !== 1) {
			throw new ValueConfigurationException("Invalid cfg: locale.format.thousand_separator must be exactly 1 char or ''.");
		}
		if ($tho !== '' && $tho === $dec) {
			throw new ValueConfigurationException('Invalid cfg: thousand_separator must differ from decimal_separator.');
		}

		// Required: Grouping.
		$group = $format->group_thousands;
		if (!\is_bool($group)) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.group_thousands must be boolean.');
		}

		// Required: Decimal policy.
		$scale = $format->decimal_scale;
		if (!\is_int($scale) || $scale < 0 || $scale > 18) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.decimal_scale must be int in range 0..18.');
		}

		$round = (string)$format->decimal_string_rounding;
		if ($round !== self::ROUND_FAIL && $round !== self::ROUND_TRUNCATE && $round !== self::ROUND_HALF_UP) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.decimal_string_rounding must be one of: "fail", "truncate", "half_up".');
		}

		$trimZeros = $format->decimal_trim_trailing_zeros;
		if (!\is_bool($trimZeros)) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.decimal_trim_trailing_zeros must be boolean.');
		}

		// Required: HTML time/datetime-local policy.
		$timeSec = $format->time_include_seconds;
		if (!\is_bool($timeSec)) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.time_include_seconds must be boolean.');
		}

		$dtlSec = $format->datetime_local_include_seconds;
		if (!\is_bool($dtlSec)) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.datetime_local_include_seconds must be boolean.');
		}

		$this->decimalSeparator = $dec;
		$this->thousandSeparator = $tho;
		$this->groupThousands = $group;

		$this->decimalScale = $scale;
		$this->decimalStringRounding = $round;
		$this->decimalTrimTrailingZeros = $trimZeros;

		$this->timeIncludeSeconds = $timeSec;
		$this->dateTimeLocalIncludeSeconds = $dtlSec;
	}


	/**
	 * Format a stored integer for display, with thousands grouped.
	 *
	 * Behavior:
	 * - Accepts an int, or a string of digits with an optional sign, such as a BIGINT that
	 *   mysqli returns as a string. Rejects separators, any other character, and a bool, float,
	 *   array or object.
	 * - Drops leading zeros and a plus sign; -0 becomes 0.
	 * - Groups thousands by the thousand separator of cfg, unless grouping is off or the
	 *   separator is ''.
	 * - Returns null for null and for a string that is empty after trimming.
	 *
	 * Typical usage:
	 *   $visits = $this->app->valueFromSql->integer($row['visits']);
	 *
	 * @param  mixed  $value  Stored value: int, string or null.
	 * @param  bool  $required  Reject a missing value instead of returning null.
	 * @param  bool|null  $groupThousands  Group thousands; null for cfg locale.format.group_thousands.
	 * @return string|null  Such as 1.234.567.
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the value is rejected, or missing while required.
	 */
	public function integer(mixed $value, bool $required = false, ?bool $groupThousands = null): ?string {

		if ($value === null) {
			if ($required) {
				throw new ValueFromSqlException('Integer value is required.', 'err_value_from_sql_integer_required');
			}
			return null;
		}

		if (\is_bool($value) || \is_float($value) || \is_array($value) || \is_object($value)) {
			throw new ValueFromSqlException('Invalid integer type.', 'err_value_from_sql_integer_invalid_type');
		}

		$s = $this->requireTrimmedStringOrNull($value, 'err_value_from_sql_integer_invalid_type');

		if ($s === null) {
			if ($required) {
				throw new ValueFromSqlException('Integer value is required.', 'err_value_from_sql_integer_required');
			}
			return null;
		}

		$sign = '';
		$first = $s[0] ?? '';
		if ($first === '-' || $first === '+') {
			if ($first === '-') {
				$sign = '-';
			}
			$s = \substr($s, 1);
			if ($s === '') {
				throw new ValueFromSqlException('Invalid integer.', 'err_value_from_sql_integer_invalid');
			}
		}

		if (!\ctype_digit($s)) {
			throw new ValueFromSqlException('Invalid integer.', 'err_value_from_sql_integer_invalid');
		}

		$digits = \ltrim($s, '0');
		if ($digits === '') {
			$digits = '0';
		}

		if ($digits === '0') {
			$sign = '';
		}

		$useGrouping = ($groupThousands === null) ? $this->groupThousands : $groupThousands;
		if ($useGrouping && $this->thousandSeparator !== '' && \strlen($digits) > 3) {
			$digits = $this->groupThousandsDigits($digits, $this->thousandSeparator);
		}

		return $sign . $digits;
	}


	/**
	 * Format a stored decimal for display, with the separators and scale of cfg.
	 *
	 * Behavior:
	 * - Accepts a dot-decimal string with an optional sign, such as -1234.5 or .5, an int, or
	 *   a float. Rejects thousand separators, a comma decimal, exponent notation, and a bool,
	 *   array or object.
	 * - Pads the decimals with zeros to the scale. Zeros beyond the scale are dropped first, so
	 *   the rounding mode only meets nonzero digits beyond it: fail rejects them, truncate
	 *   drops them and half_up rounds. At scale 2, 1.500 gives 1,50, while fail rejects 1.505.
	 * - A float is rounded half up to the scale, whatever the rounding mode; prefer strings.
	 * - With trimming on, drops trailing zeros of the decimals, and the decimal separator when
	 *   none remain: 1.234,50 becomes 1.234,5 and 1.234,00 becomes 1.234.
	 * - Groups thousands as integer() does. Returns no plus sign and no negative zero.
	 * - Returns null for null and for a string that is empty after trimming.
	 *
	 * Typical usage:
	 *   $price = $this->app->valueFromSql->decimal($row['price']);
	 *
	 * @param  mixed  $value  Stored value: string, int, float or null.
	 * @param  bool  $required  Reject a missing value instead of returning null.
	 * @param  int|null  $scale  Decimals in the output, 0-18; null for cfg locale.format.decimal_scale.
	 * @param  bool|null  $groupThousands  Group thousands; null for cfg locale.format.group_thousands.
	 * @param  bool|null  $trimTrailingZeros  Drop trailing zeros of the decimals; null for cfg locale.format.decimal_trim_trailing_zeros.
	 * @param  string|null  $rounding  fail, truncate or half_up; null for cfg locale.format.decimal_string_rounding.
	 * @return string|null
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the value is rejected, the rounding mode fail meets nonzero digits beyond the scale, or the value is missing while required; a too many decimals failure carries scale.
	 * @throws \CitOmni\Infrastructure\Exception\ValueDefinitionException  When $scale is outside 0-18 or $rounding is not one of the modes.
	 */
	public function decimal(mixed $value, bool $required = false, ?int $scale = null, ?bool $groupThousands = null, ?bool $trimTrailingZeros = null, ?string $rounding = null): ?string {

		$useScale = ($scale === null) ? $this->decimalScale : $scale;
		if ($useScale < 0 || $useScale > 18) {
			throw new ValueDefinitionException('Invalid decimal scale: $scale must be in range 0..18.');
		}

		$useGrouping = ($groupThousands === null) ? $this->groupThousands : $groupThousands;
		$useTrimZeros = ($trimTrailingZeros === null) ? $this->decimalTrimTrailingZeros : $trimTrailingZeros;

		$useRounding = ($rounding === null) ? $this->decimalStringRounding : $rounding;
		if ($useRounding !== self::ROUND_FAIL && $useRounding !== self::ROUND_TRUNCATE && $useRounding !== self::ROUND_HALF_UP) {
			throw new ValueDefinitionException('Invalid decimal rounding mode override.');
		}

		if ($value === null) {
			if ($required) {
				throw new ValueFromSqlException('Decimal value is required.', 'err_value_from_sql_decimal_required');
			}
			return null;
		}

		if (\is_bool($value) || \is_array($value) || \is_object($value)) {
			throw new ValueFromSqlException('Invalid decimal type.', 'err_value_from_sql_decimal_invalid_type');
		}

		// Normalize to a dot-decimal string first.
		$s = '';
		if (\is_int($value)) {
			$s = (string)$value;
		} elseif (\is_float($value)) {
			if (!\is_finite($value)) {
				throw new ValueFromSqlException('Invalid decimal value.', 'err_value_from_sql_decimal_invalid');
			}
			$rounded = \round($value, $useScale, \PHP_ROUND_HALF_UP);
			$s = \number_format($rounded, $useScale, '.', '');

			if ($useScale > 0) {
				if ($s[0] === '-' && \strncmp($s, '-0.', 3) === 0 && \trim(\substr($s, 3), '0') === '') {
					$s = \substr($s, 1);
				}
			} else {
				if ($s === '-0') {
					$s = '0';
				}
			}
		} else {
			$s = \trim((string)$value);
			if ($s === '') {
				if ($required) {
					throw new ValueFromSqlException('Decimal value is required.', 'err_value_from_sql_decimal_required');
				}
				return null;
			}
		}

		// Validate + parse the SQL dot-decimal string.
		$parsed = $this->parseSqlDotDecimal($s);

		$sign = $parsed['sign'];
		$intDigits = $parsed['int'];
		$fracDigits = $parsed['frac'];

		// Apply rounding/scale policy deterministically (string-based, no float artifacts).
		[$intDigits, $fracDigits, $sign] = $this->applyScalePolicy($sign, $intDigits, $fracDigits, $useScale, $useRounding);

		// Apply trimming (cfg-driven).
		if ($useTrimZeros && $useScale > 0 && $fracDigits !== '') {
			$fracDigits = \rtrim($fracDigits, '0');
		}

		// Normalize negative zero after trimming.
		if ($sign === '-' && $intDigits === '0' && ($fracDigits === '' || \trim($fracDigits, '0') === '')) {
			$sign = '';
		}

		return $this->assembleDecimalParts($sign, $intDigits, $fracDigits, $useGrouping);
	}


	/**
	 * Convert a stored boolean to a PHP bool.
	 *
	 * Behavior:
	 * - Accepts true and false, the ints 1 and 0, and the strings 1 and 0. Rejects every other
	 *   value and type.
	 * - Returns null for null and for a string that is empty after trimming.
	 *
	 * Typical usage:
	 *   $active = $this->app->valueFromSql->boolean($row['active'], required: true);
	 *
	 * @param  mixed  $value  Stored value: bool, int, string or null; a string is trimmed.
	 * @param  bool  $required  Reject a missing value instead of returning null.
	 * @return bool|null
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the value is rejected, or missing while required.
	 */
	public function boolean(mixed $value, bool $required = false): ?bool {

		if ($value === null) {
			if ($required) {
				throw new ValueFromSqlException('Boolean value is required.', 'err_value_from_sql_boolean_required');
			}
			return null;
		}

		if (\is_bool($value)) {
			return $value;
		}

		if (\is_int($value)) {
			if ($value === 0) {
				return false;
			}
			if ($value === 1) {
				return true;
			}
			throw new ValueFromSqlException('Invalid boolean value.', 'err_value_from_sql_boolean_invalid');
		}

		if (\is_string($value)) {
			$s = \trim($value);
			if ($s === '') {
				if ($required) {
					throw new ValueFromSqlException('Boolean value is required.', 'err_value_from_sql_boolean_required');
				}
				return null;
			}
			if ($s === '0') {
				return false;
			}
			if ($s === '1') {
				return true;
			}
			throw new ValueFromSqlException('Invalid boolean value.', 'err_value_from_sql_boolean_invalid');
		}

		throw new ValueFromSqlException('Invalid boolean type.', 'err_value_from_sql_boolean_invalid_type');
	}


	/**
	 * Check a stored date and format it for a date field or for display.
	 *
	 * Behavior:
	 * - Accepts YYYY-MM-DD with a date that exists; anything else, a DATETIME included, is
	 *   rejected.
	 * - Returns YYYY-MM-DD, the value that input type="date" takes, by default, and
	 *   DD-MM-YYYY, DD/MM/YYYY or MM/DD/YYYY on request.
	 * - Returns null for null and for a string that is empty after trimming.
	 *
	 * Notes:
	 * - Other display formats, such as with month names, belong to the template or to
	 *   IntlDateFormatter.
	 *
	 * Typical usage:
	 *   $movedIn = $this->app->valueFromSql->date($row['moved_in']);
	 *
	 * @param  mixed  $value  Stored value: string or null; trimmed.
	 * @param  bool  $required  Reject a missing value instead of returning null.
	 * @param  string  $format  YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY or MM/DD/YYYY.
	 * @return string|null
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the value is not a string, not a date that exists, or missing while required.
	 * @throws \CitOmni\Infrastructure\Exception\ValueDefinitionException  When $format is not supported.
	 */
	public function date(mixed $value, bool $required = false, string $format = 'YYYY-MM-DD'): ?string {

		// Fail fast on unsupported format before any parsing work.
		if ($format !== 'YYYY-MM-DD' && $format !== 'DD-MM-YYYY' && $format !== 'DD/MM/YYYY' && $format !== 'MM/DD/YYYY') {
			throw new ValueDefinitionException('Unsupported date output format.');
		}

		$s = $this->requireTrimmedStringOrNull($value, 'err_value_from_sql_date_invalid_type');

		if ($s === null) {
			if ($required) {
				throw new ValueFromSqlException('Date value is required.', 'err_value_from_sql_date_required');
			}
			return null;
		}

		[$y, $m, $d] = $this->parseSqlDate($s);

		return match ($format) {
			'YYYY-MM-DD' => $s,
			'DD-MM-YYYY' => \sprintf('%02d-%02d-%04d', $d, $m, $y),
			'DD/MM/YYYY' => \sprintf('%02d/%02d/%04d', $d, $m, $y),
			'MM/DD/YYYY' => \sprintf('%02d/%02d/%04d', $m, $d, $y),
		};
	}


	/**
	 * Check a stored time of day and format it for input type="time".
	 *
	 * Behavior:
	 * - Accepts HH:MM or HH:MM:SS within 00:00:00 to 23:59:59. Rejects fractional seconds, and
	 *   a TIME value outside one day, such as 25:00:00 or a negative one.
	 * - Returns HH:MM:SS when seconds are included, and HH:MM otherwise.
	 * - Returns null for null and for a string that is empty after trimming.
	 *
	 * Typical usage:
	 *   $startTime = $this->app->valueFromSql->time($row['start_time']);
	 *
	 * @param  mixed  $value  Stored value: string or null; trimmed.
	 * @param  bool  $required  Reject a missing value instead of returning null.
	 * @param  bool|null  $includeSeconds  Include seconds; null for cfg locale.format.time_include_seconds.
	 * @return string|null
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the value is not a string, not a time of day, or missing while required.
	 */
	public function time(mixed $value, bool $required = false, ?bool $includeSeconds = null): ?string {

		$s = $this->requireTrimmedStringOrNull($value, 'err_value_from_sql_time_invalid_type');

		if ($s === null) {
			if ($required) {
				throw new ValueFromSqlException('Time value is required.', 'err_value_from_sql_time_required');
			}
			return null;
		}

		[$hh, $mm, $ss] = $this->parseSqlWallClockTime($s);

		$useIncludeSeconds = ($includeSeconds !== null) ? $includeSeconds : $this->timeIncludeSeconds;

		if ($useIncludeSeconds) {
			return \sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
		}

		return \sprintf('%02d:%02d', $hh, $mm);
	}


	/**
	 * Check a stored date and time and format it for input type="datetime-local".
	 *
	 * Behavior:
	 * - Accepts YYYY-MM-DD HH:MM or YYYY-MM-DD HH:MM:SS with a date that exists and a time of
	 *   day; rejects fractional seconds.
	 * - Returns YYYY-MM-DDTHH:MM:SS when seconds are included, and YYYY-MM-DDTHH:MM otherwise.
	 * - Returns null for null and for a string that is empty after trimming.
	 *
	 * Typical usage:
	 *   $startsAt = $this->app->valueFromSql->dateTimeLocal($row['starts_at']);
	 *
	 * @param  mixed  $value  Stored value: string or null; trimmed.
	 * @param  bool  $required  Reject a missing value instead of returning null.
	 * @param  bool|null  $includeSeconds  Include seconds; null for cfg locale.format.datetime_local_include_seconds.
	 * @return string|null
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the value is not a string, not a date and time that exist, or missing while required.
	 */
	public function dateTimeLocal(mixed $value, bool $required = false, ?bool $includeSeconds = null): ?string {

		$s = $this->requireTrimmedStringOrNull($value, 'err_value_from_sql_datetime_invalid_type');

		if ($s === null) {
			if ($required) {
				throw new ValueFromSqlException('Datetime value is required.', 'err_value_from_sql_datetime_required');
			}
			return null;
		}

		// Minimum length: "YYYY-MM-DD HH:MM" = 16 chars. Space must be at position 10.
		if (\strlen($s) < 16 || $s[10] !== ' ') {
			throw new ValueFromSqlException('Invalid SQL datetime format.', 'err_value_from_sql_datetime_invalid_format');
		}

		[$y, $m, $d] = $this->parseSqlDate(\substr($s, 0, 10));
		[$hh, $mm, $ss] = $this->parseSqlWallClockTime(\substr($s, 11));

		$useIncludeSeconds = ($includeSeconds !== null) ? $includeSeconds : $this->dateTimeLocalIncludeSeconds;

		if ($useIncludeSeconds) {
			return \sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $y, $m, $d, $hh, $mm, $ss);
		}

		return \sprintf('%04d-%02d-%02dT%02d:%02d', $y, $m, $d, $hh, $mm);
	}


	/**
	 * Return a stored text unchanged.
	 *
	 * Behavior:
	 * - Never trims: Leading and trailing whitespace may belong to the stored value, so a
	 *   string of only whitespace is returned as it is.
	 * - Returns null for null and for ''.
	 *
	 * Notes:
	 * - ValueToSql::text() trims by default, so the two are not symmetric. Trim at the call
	 *   site when a form field needs it.
	 *
	 * Typical usage:
	 *   $title = $this->app->valueFromSql->text($row['title'], required: true);
	 *
	 * @param  mixed  $value  Stored value: string or null.
	 * @param  bool  $required  Reject null and '' instead of returning null.
	 * @return string|null
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the value is not a string, or null or '' while required.
	 */
	public function text(mixed $value, bool $required = false): ?string {

		if ($value === null) {
			if ($required) {
				throw new ValueFromSqlException('Text value is required.', 'err_value_from_sql_text_required');
			}
			return null;
		}

		if (!\is_string($value)) {
			throw new ValueFromSqlException('Invalid text type.', 'err_value_from_sql_text_invalid_type');
		}

		if ($value === '') {
			if ($required) {
				throw new ValueFromSqlException('Text value is required.', 'err_value_from_sql_text_required');
			}
			return null;
		}

		return $value;
	}


	/**
	 * Decode a stored JSON array or object.
	 *
	 * Behavior:
	 * - Decodes objects to associative arrays. Rejects JSON that is no array or object, such as
	 *   a number, a string or null.
	 * - Returns null for null and for a string that is empty after trimming.
	 *
	 * Typical usage:
	 *   $settings = $this->app->valueFromSql->json($row['settings']) ?? [];
	 *
	 * @param  mixed  $value  Stored value: string or null.
	 * @param  bool  $required  Reject a missing value instead of returning null.
	 * @return array<array-key,mixed>|null
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the value is not a string, not a JSON array or object, or missing while required.
	 */
	public function json(mixed $value, bool $required = false): ?array {

		if ($value === null) {
			if ($required) {
				throw new ValueFromSqlException('JSON value is required.', 'err_value_from_sql_json_required');
			}
			return null;
		}

		if (!\is_string($value)) {
			throw new ValueFromSqlException('Invalid JSON type.', 'err_value_from_sql_json_invalid_type');
		}

		$s = \trim($value);
		if ($s === '') {
			if ($required) {
				throw new ValueFromSqlException('JSON value is required.', 'err_value_from_sql_json_required');
			}
			return null;
		}

		try {
			$decoded = \json_decode($s, true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new ValueFromSqlException('Invalid JSON.', 'err_value_from_sql_json_invalid');
		}

		if (!\is_array($decoded)) {
			throw new ValueFromSqlException('Invalid JSON.', 'err_value_from_sql_json_invalid');
		}

		return $decoded;
	}


	/**
	 * Return a stored value as a trimmed string, or null when it is missing.
	 *
	 * Behavior:
	 * - Accepts an int as its digits, because mysqlnd may return a numeric column as int.
	 *
	 * @param  mixed  $value  Stored value.
	 * @param  string  $invalidKey  Message key when the type is neither null, int nor string.
	 * @return string|null  Null for null and for a string that is empty after trimming.
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the type is neither null, int nor string.
	 */
	private function requireTrimmedStringOrNull(mixed $value, string $invalidKey): ?string {

		if ($value === null) {
			return null;
		}

		// mysqlnd may return ints for numeric columns. Accept int here to keep callers uniform.
		if (\is_int($value)) {
			$s = (string)$value;
			return ($s === '') ? null : $s;
		}

		if (!\is_string($value)) {
			throw new ValueFromSqlException('Invalid input type.', $invalidKey);
		}

		$s = \trim($value);
		return ($s === '') ? null : $s;
	}


	/**
	 * Parse a SQL date YYYY-MM-DD with a date that exists.
	 *
	 * @param  string  $s  Trimmed date.
	 * @return array{0:int,1:int,2:int}  Year, month and day.
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the format is invalid or the date does not exist.
	 */
	private function parseSqlDate(string $s): array {

		$matches = [];
		if (\preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $matches) !== 1) {
			throw new ValueFromSqlException('Invalid SQL date format.', 'err_value_from_sql_date_invalid_format');
		}

		$y = (int)$matches[1];
		$m = (int)$matches[2];
		$d = (int)$matches[3];

		if (!\checkdate($m, $d, $y)) {
			throw new ValueFromSqlException('Invalid date.', 'err_value_from_sql_date_invalid');
		}

		return [$y, $m, $d];
	}


	/**
	 * Parse a SQL dot-decimal string into sign, integer digits and decimals.
	 *
	 * Behavior:
	 * - Accepts 123, 123.45, -123.45, +123.45 and .5. Drops a plus sign and the leading zeros
	 *   of the integer part, and turns a missing integer part into 0.
	 * - Rejects thousand separators, a comma decimal and exponent notation.
	 * - A negative value that is zero loses its sign.
	 *
	 * @param  string  $s  Trimmed, non-empty input.
	 * @return array{sign:string,int:string,frac:string}  sign is '' or '-'; frac may be ''.
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the format is invalid.
	 */
	private function parseSqlDotDecimal(string $s): array {

		$sign = '';
		$first = $s[0] ?? '';
		if ($first === '-' || $first === '+') {
			if ($first === '-') {
				$sign = '-';
			}
			$s = \substr($s, 1);
			if ($s === '') {
				throw new ValueFromSqlException('Invalid decimal value.', 'err_value_from_sql_decimal_invalid');
			}
		}

		// Accept: "123", "123.45", ".45" (leading dot normalized below). Reject: scientific, locale separators, thousand separators.
		if (\preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)$/', $s) !== 1) {
			throw new ValueFromSqlException('Invalid decimal value.', 'err_value_from_sql_decimal_invalid');
		}

		if ($s[0] === '.') {
			$s = '0' . $s;
		}

		$dotPos = \strpos($s, '.');
		if ($dotPos === false) {
			$intRaw = $s;
			$fracRaw = '';
		} else {
			$intRaw = \substr($s, 0, $dotPos);
			$fracRaw = \substr($s, $dotPos + 1);
		}

		$intDigits = \ltrim($intRaw, '0');
		if ($intDigits === '') {
			$intDigits = '0';
		}

		$fracDigits = $fracRaw;

		// Normalize "-0" early if it is clearly zero-like already.
		if ($sign === '-' && $intDigits === '0' && ($fracDigits === '' || \trim($fracDigits, '0') === '')) {
			$sign = '';
		}

		return [
			'sign' => $sign,
			'int' => $intDigits,
			'frac' => $fracDigits,
		];
	}


	/**
	 * Apply scale and rounding policy to parsed decimal parts.
	 *
	 * Behavior:
	 * - Zeros beyond the scale are dropped first; they carry no value.
	 * - Remaining digits beyond the scale follow the rounding mode: "fail" throws, "truncate"
	 *   cuts them off, "half_up" rounds on the first of them.
	 * - A shorter fraction is padded with zeros, and negative zero becomes zero.
	 *
	 * @param  string  $sign  '' or '-'.
	 * @param  string  $intDigits  Integer digits, at least one.
	 * @param  string  $fracDigits  Decimal digits; may be ''.
	 * @param  int  $scale  0-18.
	 * @param  string  $rounding  fail, truncate or half_up.
	 * @return array{0:string,1:string,2:string}  Integer digits, decimals and sign.
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the mode is fail and nonzero digits remain beyond the scale.
	 */
	private function applyScalePolicy(string $sign, string $intDigits, string $fracDigits, int $scale, string $rounding): array {

		// Drop zeros beyond the scale, so "fail" only fails when a digit other than zero would be lost.
		if (\strlen($fracDigits) > $scale) {
			$fracDigits = \substr($fracDigits, 0, $scale) . \rtrim(\substr($fracDigits, $scale), '0');
		}

		if ($scale === 0) {
			// Any remaining fraction must be handled by rounding policy.
			if ($fracDigits === '') {
				return [$intDigits, '', ($intDigits === '0') ? '' : $sign];
			}

			if ($rounding === self::ROUND_FAIL) {
				throw new ValueFromSqlException('Too many decimals.', 'err_value_from_sql_decimal_too_many_decimals', ['scale' => $scale]);
			}

			if ($rounding === self::ROUND_TRUNCATE) {
				return [$intDigits, '', ($intDigits === '0') ? '' : $sign];
			}

			// HALF_UP at scale 0: Look at first fractional digit only.
			$first = \ord($fracDigits[0]) - 48;
			if ($first >= 5) {
				$intDigits = $this->addOneToDigits($intDigits);
			}

			// Normalize negative zero: "-0" must become "0".
			if ($intDigits === '0') {
				$sign = '';
			}

			return [$intDigits, '', $sign];
		}

		$fracLen = \strlen($fracDigits);

		if ($fracLen === $scale) {
			// Exact scale: no change.
			return [$intDigits, $fracDigits, ($sign === '-' && $intDigits === '0' && \trim($fracDigits, '0') === '') ? '' : $sign];
		}

		if ($fracLen < $scale) {
			// Pad.
			$fracDigits = \str_pad($fracDigits, $scale, '0', \STR_PAD_RIGHT);
			if ($sign === '-' && $intDigits === '0' && \trim($fracDigits, '0') === '') {
				$sign = '';
			}
			return [$intDigits, $fracDigits, $sign];
		}

		// fracLen > scale: Too many digits.
		if ($rounding === self::ROUND_FAIL) {
			throw new ValueFromSqlException('Too many decimals.', 'err_value_from_sql_decimal_too_many_decimals', ['scale' => $scale]);
		}

		if ($rounding === self::ROUND_TRUNCATE) {
			$fracDigits = \substr($fracDigits, 0, $scale);
			if ($sign === '-' && $intDigits === '0' && \trim($fracDigits, '0') === '') {
				$sign = '';
			}
			return [$intDigits, $fracDigits, $sign];
		}

		// HALF_UP rounding (string-based):
		// Keep first $scale digits, look at the next digit to decide rounding.
		$keep = \substr($fracDigits, 0, $scale);
		$nextDigit = \ord($fracDigits[$scale]) - 48;

		if ($nextDigit < 5) {
			$fracDigits = $keep;
			if ($sign === '-' && $intDigits === '0' && \trim($fracDigits, '0') === '') {
				$sign = '';
			}
			return [$intDigits, $fracDigits, $sign];
		}

		// Round up: Add 1 to the kept fractional digits with carry into integer.
		$carry = 1;
		$buf = $keep;
		for ($i = $scale - 1; $i >= 0; $i--) {
			$d = \ord($buf[$i]) - 48 + $carry;
			if ($d >= 10) {
				$buf[$i] = '0';
				$carry = 1;
			} else {
				$buf[$i] = \chr(48 + $d);
				$carry = 0;
				break;
			}
		}

		if ($carry === 1) {
			$intDigits = $this->addOneToDigits($intDigits);
			$buf = \str_repeat('0', $scale);
		}

		$fracDigits = $buf;

		if ($sign === '-' && $intDigits === '0' && \trim($fracDigits, '0') === '') {
			$sign = '';
		}

		return [$intDigits, $fracDigits, $sign];
	}


	/**
	 * Add one to a string of digits.
	 *
	 * @param  string  $digits  Digits only, at least one.
	 * @return string  Digits only, without leading zeros.
	 */
	private function addOneToDigits(string $digits): string {

		$len = \strlen($digits);
		$carry = 1;

		for ($i = $len - 1; $i >= 0; $i--) {
			$d = \ord($digits[$i]) - 48 + $carry;
			if ($d >= 10) {
				$digits[$i] = '0';
				$carry = 1;
			} else {
				$digits[$i] = \chr(48 + $d);
				$carry = 0;
				break;
			}
		}

		if ($carry === 1) {
			$digits = '1' . $digits;
		}

		// Normalize leading zeros (should never happen, but keep it sane).
		$digits = \ltrim($digits, '0');
		return ($digits === '') ? '0' : $digits;
	}


	/**
	 * Group a string of digits by three from the right.
	 *
	 * @param  string  $digits  Digits only.
	 * @param  string  $sep  Thousand separator, one byte.
	 * @return string
	 */
	private function groupThousandsDigits(string $digits, string $sep): string {

		$len = \strlen($digits);
		if ($len <= 3) {
			return $digits;
		}

		$firstGroupLen = $len % 3;
		if ($firstGroupLen === 0) {
			$firstGroupLen = 3;
		}

		$out = \substr($digits, 0, $firstGroupLen);
		for ($i = $firstGroupLen; $i < $len; $i += 3) {
			$out .= $sep . \substr($digits, $i, 3);
		}

		return $out;
	}


	/**
	 * Parse a time of day HH:MM or HH:MM:SS.
	 *
	 * @param  string  $s  Trimmed time.
	 * @return array{0:int,1:int,2:int}  Hours, minutes and seconds; seconds are 0 when missing.
	 * @throws \CitOmni\Infrastructure\Exception\ValueFromSqlException  When the format is invalid or the time is outside 00:00:00 to 23:59:59.
	 */
	private function parseSqlWallClockTime(string $s): array {

		$matches = [];
		if (\preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $matches) !== 1) {
			throw new ValueFromSqlException('Invalid SQL time format.', 'err_value_from_sql_time_invalid_format');
		}

		$hh = (int)$matches[1];
		$mm = (int)$matches[2];
		$ssRaw = $matches[3] ?? '';
		$ss = ($ssRaw !== '') ? (int)$ssRaw : 0;

		if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59 || $ss < 0 || $ss > 59) {
			throw new ValueFromSqlException('Invalid time.', 'err_value_from_sql_time_invalid');
		}

		return [$hh, $mm, $ss];
	}


	/**
	 * Join checked decimal parts with the separators of cfg.
	 *
	 * Behavior:
	 * - Groups the integer part when $groupThousands is true and the thousand separator is not
	 *   ''. Adds no decimal separator when there are no decimals.
	 *
	 * @param  string  $sign  '' or '-'.
	 * @param  string  $intDigits  Integer digits without leading zeros, or 0.
	 * @param  string  $fracDigits  Decimal digits; may be ''.
	 * @param  bool  $groupThousands  Group the integer part.
	 * @return string
	 */
	private function assembleDecimalParts(string $sign, string $intDigits, string $fracDigits, bool $groupThousands): string {

		if ($groupThousands && $this->thousandSeparator !== '') {
			$intDigits = $this->groupThousandsDigits($intDigits, $this->thousandSeparator);
		}

		if ($fracDigits === '') {
			return $sign . $intDigits;
		}

		return $sign . $intDigits . $this->decimalSeparator . $fracDigits;
	}

}
