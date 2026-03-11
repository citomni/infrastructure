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
 * ValueFromSql: Strict SQL -> UI/form value formatter for MySQL/MariaDB result values.
 *
 * Converts raw SQL column values (typically strings/ints from mysqli/mysqlnd) into
 * locale-aware UI strings suitable for HTML output and form field population.
 *
 * Core rules:
 * - Fail fast on invalid or unexpected SQL values (no guessing, no silent coercion).
 * - Deterministic and cfg-strict locale policy for numbers:
 *   - decimal separator: cfg locale.format.decimal_separator (required, exactly 1 char)
 *   - thousand separator: cfg locale.format.thousand_separator (required, '' or exactly 1 char)
 *   - group thousands: cfg locale.format.group_thousands (required, bool)
 * - Deterministic and cfg-strict decimal policy:
 *   - default scale: cfg locale.format.decimal_scale (required, 0..18)
 *   - rounding mode: cfg locale.format.decimal_string_rounding (required, one of: "fail", "truncate", "half_up")
 *   - trim trailing zeros: cfg locale.format.decimal_trim_trailing_zeros (required, bool)
 *
 * HTML form helpers:
 * - date(): Returns a value suitable for <input type="date">:
 *   - "YYYY-MM-DD" (SQL DATE) validated and passed through.
 * - time(): Returns a value suitable for <input type="time">:
 *   - "HH:MM" or "HH:MM:SS" depending on cfg locale.format.time_include_seconds (required, bool).
 * - dateTimeLocal(): Returns a value suitable for <input type="datetime-local">:
 *   - "YYYY-MM-DDTHH:MM" or "YYYY-MM-DDTHH:MM:SS" depending on cfg locale.format.datetime_local_include_seconds (required, bool).
 *
 * Notes:
 * - SQL numeric input for decimals must be dot-decimal ('.') with optional leading sign.
 *   Thousand separators are not allowed in SQL input.
 * - BIGINT integers may arrive as strings; integer formatting works on digits as strings to avoid overflow.
 *
 * @throws ValueFromSqlException On invalid SQL values.
 * @throws ValueConfigurationException On missing or invalid locale configuration.
 * @throws ValueDefinitionException On invalid method arguments or unsupported formatting rules.
 */
final class ValueFromSql extends BaseService {

	private const ROUND_FAIL = 'fail';
	private const ROUND_TRUNCATE = 'truncate';
	private const ROUND_HALF_UP = 'half_up';

	private string $decimalSeparator = ',';
	private string $thousandSeparator = '.';
	private bool $groupThousands = true;

	private int $decimalScale = 2;
	private string $decimalStringRounding = self::ROUND_FAIL;
	private bool $decimalTrimTrailingZeros = false;

	private bool $timeIncludeSeconds = false;
	private bool $dateTimeLocalIncludeSeconds = false;

	protected function init(): void {

		// Require locale.format to exist (use isset() to avoid triggering Cfg::__get()).
		if (!isset($this->app->cfg->locale) || !isset($this->app->cfg->locale->format)) {
			throw new ValueConfigurationException('Missing cfg: locale.format is required by ValueFromSql.');
		}

		$format = $this->app->cfg->locale->format;

		// Required: Separators.
		if (!isset($format->decimal_separator)) {
			throw new ValueConfigurationException('Missing cfg: locale.format.decimal_separator is required by ValueFromSql.');
		}
		if (!isset($format->thousand_separator)) {
			throw new ValueConfigurationException("Missing cfg: locale.format.thousand_separator is required by ValueFromSql (use '' to disable).");
		}

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
		if (!isset($format->group_thousands)) {
			throw new ValueConfigurationException('Missing cfg: locale.format.group_thousands is required by ValueFromSql.');
		}
		$group = $format->group_thousands;
		if (!\is_bool($group)) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.group_thousands must be boolean.');
		}

		// Required: Decimal policy.
		if (!isset($format->decimal_scale)) {
			throw new ValueConfigurationException('Missing cfg: locale.format.decimal_scale is required by ValueFromSql.');
		}
		if (!isset($format->decimal_string_rounding)) {
			throw new ValueConfigurationException('Missing cfg: locale.format.decimal_string_rounding is required by ValueFromSql.');
		}
		if (!isset($format->decimal_trim_trailing_zeros)) {
			throw new ValueConfigurationException('Missing cfg: locale.format.decimal_trim_trailing_zeros is required by ValueFromSql.');
		}

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
		if (!isset($format->time_include_seconds)) {
			throw new ValueConfigurationException('Missing cfg: locale.format.time_include_seconds is required by ValueFromSql.');
		}
		if (!isset($format->datetime_local_include_seconds)) {
			throw new ValueConfigurationException('Missing cfg: locale.format.datetime_local_include_seconds is required by ValueFromSql.');
		}

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
	 * Format a SQL integer value as a locale-grouped display string.
	 *
	 * Accepts:
	 * - int
	 * - string: optional leading sign, then digits only (No separators)
	 *
	 * Behavior:
	 * - null -> null (unless required=true)
	 * - "" after trim -> null (unless required=true)
	 * - Leading zeros are stripped ("007" -> "7")
	 * - "-0" is normalized to "0"
	 * - Thousand grouping uses cfg locale.format.group_thousands and locale.format.thousand_separator
	 *
	 * @param mixed $value SQL value (int|string|null).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @param bool|null $groupThousands Override grouping (Default null = cfg).
	 * @return string|null UI formatted integer string or null.
	 *
	 * @throws ValueFromSqlException On invalid type/format or when required=true and empty.
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
	 * Format a SQL dot-decimal value as a locale-formatted UI decimal string.
	 *
	 * Input contract:
	 * - SQL decimals are dot-decimal strings: "1234.56" (optional leading sign).
	 * - No thousand separators are allowed in SQL input.
	 *
	 * Decimal policy is cfg-driven and strict:
	 * - scale default: cfg locale.format.decimal_scale
	 * - rounding mode: cfg locale.format.decimal_string_rounding ("fail"|"truncate"|"half_up")
	 * - trim zeros: cfg locale.format.decimal_trim_trailing_zeros
	 *   when enabled, output may drop trailing zeros and may remove the decimal separator entirely
	 *   (e.g. "1.234,50" -> "1.234,5" and "1.234,00" -> "1.234").
	 * - grouping: cfg locale.format.group_thousands
	 *
	 * Notes:
	 * - float inputs are always rounded HALF_UP (cfg rounding applies to string inputs only)
	 *
	 * @param mixed $value SQL value (string|int|float|null).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @param int|null $scale Override scale (Default null = cfg).
	 * @param bool|null $groupThousands Override grouping (Default null = cfg).
	 * @param bool|null $trimTrailingZeros Override trim policy (Default null = cfg).
	 * @param string|null $rounding Override rounding mode (Default null = cfg).
	 * @return string|null Locale-formatted decimal string or null.
	 *
	 * @throws ValueFromSqlException On invalid type, invalid SQL format, or policy violations.
	 * @throws ValueDefinitionException On invalid override arguments.
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
	 * Convert a SQL boolean-ish value to a PHP bool or null.
	 *
	 * Accepts:
	 * - null
	 * - bool
	 * - int: 0/1
	 * - string: "0"/"1"
	 *
	 * @param mixed $value SQL value.
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @return bool|null Bool or null.
	 *
	 * @throws ValueFromSqlException On invalid type/value or when required=true and empty.
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
	 * Validate and format a SQL DATE value for output.
	 *
	 * Default output is "YYYY-MM-DD", which is the format required by
	 * HTML <input type="date">. Use $format to request a different layout
	 * when the value is destined for display rather than a form field.
	 *
	 * Supported formats:
	 * - 'YYYY-MM-DD' -> "2026-02-26"  (default; HTML input type="date")
	 * - 'DD-MM-YYYY' -> "26-02-2026"  (European display)
	 * - 'DD/MM/YYYY' -> "26/02/2026"
	 * - 'MM/DD/YYYY' -> "02/26/2026"  (US display)
	 *
	 * Notes:
	 * - For display formats beyond the above (e.g. "26. februar 2026"), use the
	 *   template's $dt() helper or PHP's IntlDateFormatter instead.
	 *
	 * @param mixed $value SQL value (string|null).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @param string $format Output format token (Default 'YYYY-MM-DD').
	 * @return string|null Formatted date string or null.
	 *
	 * @throws ValueFromSqlException On non-string input, invalid SQL date,
	 *                               or when required=true and value is null/empty.
	 * @throws ValueDefinitionException On unsupported output format.
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
	 * Validate and format SQL TIME for HTML <input type="time">.
	 *
	 * Input accepts:
	 * - "HH:MM"
	 * - "HH:MM:SS"
	 *
	 * Output precision is cfg-driven by default (cfg locale.format.time_include_seconds),
	 * but may be overridden per call via $includeSeconds when different fields on the
	 * same page require different precision.
	 *
	 * - false -> "HH:MM"
	 * - true  -> "HH:MM:SS"
	 *
	 * @param mixed $value SQL value (string|null).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @param bool|null $includeSeconds Override cfg precision (Default null = use cfg).
	 * @return string|null HTML time string or null.
	 *
	 * @throws ValueFromSqlException On non-string input, invalid SQL time,
	 *                               or when required=true and value is null/empty.
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
	 * Validate and format SQL DATETIME for HTML <input type="datetime-local">.
	 *
	 * Input accepts:
	 * - "YYYY-MM-DD HH:MM"
	 * - "YYYY-MM-DD HH:MM:SS"
	 *
	 * Output precision is cfg-driven by default (cfg locale.format.datetime_local_include_seconds),
	 * but may be overridden per call via $includeSeconds when different fields on the
	 * same page require different precision.
	 *
	 * - false -> "YYYY-MM-DDTHH:MM"
	 * - true  -> "YYYY-MM-DDTHH:MM:SS"
	 *
	 * @param mixed $value SQL value (string|null).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @param bool|null $includeSeconds Override cfg precision (Default null = use cfg).
	 * @return string|null HTML datetime-local string or null.
	 *
	 * @throws ValueFromSqlException On non-string input, invalid SQL datetime,
	 *                               or when required=true and value is null/empty.
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
	 * Return a SQL text value as-is (null-safe).
	 *
	 * This method intentionally does NOT trim whitespace.
	 * A VARCHAR/TEXT column may contain leading/trailing whitespace by design,
	 * legacy data, imports, or manual SQL operations. ValueFromSql preserves
	 * the stored value exactly.
	 *
	 * Behavior:
	 * - null:
	 *   1) required=false -> null
	 *   2) required=true  -> throws
	 * - string:
	 *   - "" (empty string):
	 *     1) required=false -> null
	 *     2) required=true  -> throws
	 *   - "   " (whitespace-only) is considered a valid stored value and is returned as-is.
	 *
	 * Notes:
	 * - This is not symmetric with ValueToSql::text(), which may trim UI input.
	 *   If you want trimmed output for form fields, trim explicitly at the call site
	 *   or introduce a dedicated method (e.g. textTrimmed()) to make that policy explicit.
	 *
	 * @param mixed $value SQL value (string|null).
	 * @param bool $required Whether null/empty string is allowed (Default false).
	 * @return string|null Raw string or null.
	 *
	 * @throws ValueFromSqlException On non-string input, or when required=true and value is null/empty string.
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
	 * Decode SQL JSON (string) to array.
	 *
	 * @param mixed $value SQL value (string|null).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @return array|null Decoded array or null.
	 *
	 * @throws ValueFromSqlException On invalid JSON, invalid type, or when required=true and empty.
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
	 * Require a trimmed string or null.
	 *
	 * @param mixed $value SQL value.
	 * @param string $invalidKey Message key used when the value type is invalid.
	 * @return string|null Trimmed string or null.
	 *
	 * @throws ValueFromSqlException When $value is not a supported string/int source.
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
	 * Parse and validate SQL DATE "YYYY-MM-DD".
	 *
	 * @param string $s Trimmed date string.
	 * @return array{int,int,int} [y, m, d]
	 *
	 * @throws ValueFromSqlException On invalid SQL date format or invalid calendar date.
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
	 * Parse a SQL dot-decimal string into sign + digit parts.
	 *
	 * Accepted input:
	 * - "123"
	 * - "123.45"
	 * - "-123.45"
	 * - "+123.45" (normalized to no '+')
	 * - ".5" (normalized to "0.5")
	 *
	 * Rejected:
	 * - Thousand separators
	 * - Locale decimal separators
	 * - Scientific notation
	 *
	 * @param string $s Trimmed non-empty string.
	 * @return array{sign:string,int:string,frac:string} Parsed parts.
	 *
	 * @throws ValueFromSqlException On invalid SQL decimal format.
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
	 * @param string $sign '' or '-'.
	 * @param string $intDigits Digits-only integer part (>=1 char).
	 * @param string $fracDigits Digits-only fraction part (may be '').
	 * @param int $scale 0..18.
	 * @param string $rounding One of: "fail", "truncate", "half_up".
	 * @return array{string,string,string} [$intDigits, $fracDigits, $sign]
	 *
	 * @throws ValueFromSqlException On rounding-policy violations for SQL data.
	 */
	private function applyScalePolicy(string $sign, string $intDigits, string $fracDigits, int $scale, string $rounding): array {

		if ($scale === 0) {
			// Any fraction must be handled by rounding policy.
			if ($fracDigits === '' || \trim($fracDigits, '0') === '') {
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
	 * Add one to a digits-only integer string.
	 *
	 * @param string $digits Digits-only string (>=1 char).
	 * @return string Digits-only string, incremented by 1.
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
	 * Group digits with a thousand separator (digits-only input).
	 *
	 * @param string $digits Digits-only string.
	 * @param string $sep Thousand separator, exactly 1 char.
	 * @return string Grouped string.
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
	 * Parse a wall-clock SQL TIME string "HH:MM" or "HH:MM:SS".
	 *
	 * @param string $s Trimmed time string.
	 * @return array{int,int,int} [hh, mm, ss]
	 *
	 * @throws ValueFromSqlException On invalid SQL time format or invalid time value.
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
	 * Assemble locale-formatted decimal output from validated parts.
	 *
	 * Applies thousand grouping to the integer part and joins with the cfg
	 * decimal separator. Emits no decimal separator when $fracDigits is empty.
	 *
	 * @param string $sign '' or '-'.
	 * @param string $intDigits Digit-only integer part (no leading zeros unless "0").
	 * @param string $fracDigits Digit-only fractional part (may be '' when scale=0 or trimmed).
	 * @param bool $groupThousands Whether to apply cfg thousand grouping.
	 * @return string Locale-formatted decimal string.
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
