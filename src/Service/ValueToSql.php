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
use CitOmni\Infrastructure\Exception\ValueToSqlException;
use CitOmni\Kernel\Service\BaseService;

/**
 * ValueToSql: Service that converts form input into values for SQL parameter binding.
 *
 * Turns what a form posts into the exact value a MySQL or MariaDB column takes, and rejects
 * what it would have to guess at: Numbers follow the separators of cfg locale.format, dates
 * and times the formats that HTML inputs post. Binding the values belongs to the Repositories
 * and the db service; which fields are required and what is valid in the domain belongs to
 * the caller.
 *
 * Rules:
 * - Never guess, round a string or cast silently: Input that does not match exactly is
 *   rejected with ValueToSqlException, whose message key names the reason.
 * - Treat an empty value as missing: null, or ValueToSqlException when required is true.
 * - Report an invalid rule from the calling code, such as reversed bounds, as
 *   ValueDefinitionException, never as ValueToSqlException.
 * - Treat dates and times as local wall-clock values, without time zone conversion.
 */
class ValueToSql extends BaseService {

	/** Decimal separator of cfg locale.format.decimal_separator. */
	private string $decimalSeparator = ',';

	/** Thousand separator of cfg locale.format.thousand_separator, or '' for none. */
	private string $thousandSeparator = '.';

	/**
	 * Read and check the separators of cfg locale.format once.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\ValueConfigurationException  When decimal_separator is not one byte, thousand_separator is neither '' nor one byte, or both are the same.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		// Package-owned cfg: Assumes the Registry baseline for locale.format, so no isset() guard.
		$format = $this->app->cfg->locale->format;

		$dec = (string)$format->decimal_separator;
		$tho = (string)$format->thousand_separator;

		// Decimal separator must be a single non-empty char.
		if ($dec === '' || \strlen($dec) !== 1) {
			throw new ValueConfigurationException('Invalid cfg: locale.format.decimal_separator must be exactly 1 char.');
		}

		// Thousand separator can be '' or a single char.
		if ($tho !== '' && \strlen($tho) !== 1) {
			throw new ValueConfigurationException("Invalid cfg: locale.format.thousand_separator must be exactly 1 char or ''.");
		}

		// Must not be the same when thousand is enabled.
		if ($tho !== '' && $tho === $dec) {
			throw new ValueConfigurationException('Invalid cfg: thousand_separator must differ from decimal_separator.');
		}

		$this->decimalSeparator = $dec;
		$this->thousandSeparator = $tho;
	}


	/**
	 * Convert form input to an integer.
	 *
	 * Behavior:
	 * - Accepts an int, or a string of digits with an optional sign and the thousand separator
	 *   of cfg in groups of three: 1.234 when the thousand separator is '.', 1 234 when it is
	 *   ' ', and no separator when it is ''.
	 * - Rejects the decimal separator anywhere, so 123,00 is no integer; any other character
	 *   or grouping; and a float, bool, array or object.
	 * - Rejects a value beyond the int range of the platform as out of range instead of
	 *   overflowing; PHP_INT_MIN itself is accepted.
	 * - Rejects a negative value when $allowNegative is false, and a value outside $min to $max.
	 * - When the thousand separator is a space, a non-breaking or narrow non-breaking space
	 *   counts as a space, and a run of spaces as one.
	 *
	 * Typical usage:
	 *   $year = $this->app->valueToSql->integer($post['year'], required: true, min: 1900, max: 2100);
	 *
	 * @param  mixed  $value  Form input: string, int or null; a string is trimmed.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @param  int  $min  Smallest accepted value.
	 * @param  int  $max  Largest accepted value.
	 * @param  bool  $allowNegative  Accept values below 0.
	 * @return int|null  Null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is rejected; an out of range failure carries min and max.
	 * @throws \CitOmni\Infrastructure\Exception\ValueDefinitionException  When $min is greater than $max.
	 */
	public function integer(mixed $value, bool $required = false, int $min = \PHP_INT_MIN, int $max = \PHP_INT_MAX, bool $allowNegative = true): ?int {
		
		// Reject non-scalar types and types that are not legitimate integer sources.
		// Float is rejected: A float is not an integer; the caller must round/cast explicitly.
		// Bool is rejected: Form input should not arrive here as a native PHP boolean.
		if (\is_float($value) || \is_bool($value) || \is_array($value) || \is_object($value)) {
			throw new ValueToSqlException('Invalid integer input.', 'err_value_to_sql_integer_invalid');
		}

		// Range sanity: User may pass reversed bounds.
		if ($min > $max) {
			throw new ValueDefinitionException('Invalid integer range: $min must not be greater than $max.');
		}

		// Fast-path for native int (e.g. controller-side calculations).
		if (\is_int($value)) {
			if (!$allowNegative && $value < 0) {
				throw new ValueToSqlException('Negative values are not allowed.', 'err_value_to_sql_integer_negative_not_allowed');
			}
			if ($value < $min || $value > $max) {
				throw new ValueToSqlException('Integer out of range.', 'err_value_to_sql_integer_out_of_range',	['min' => $min, 'max' => $max]);
			}
			return $value;
		}

		$s = $this->requireStringOrNull($value, 'err_value_to_sql_integer_invalid');
		if ($s === null) {
			if ($required) {
				throw new ValueToSqlException('Integer value is required.','err_value_to_sql_integer_required');
			}
			return null;
		}

		$decSep = $this->decimalSeparator;
		$thoSep = $this->thousandSeparator;

		// Reject decimal separator strictly
		if (\strpos($s, $decSep) !== false) {
			throw new ValueToSqlException('Invalid integer input.', 'err_value_to_sql_integer_invalid');
		}

		$negative = false;
		$first = $s[0] ?? '';
		if ($first === '-' || $first === '+') {
			if ($first === '-') {
				if (!$allowNegative) {
					throw new ValueToSqlException('Negative values are not allowed.','err_value_to_sql_integer_negative_not_allowed');
				}
				$negative = true;
			}
			$s = \substr($s, 1);
			if ($s === '') {
				throw new ValueToSqlException('Invalid integer input.', 'err_value_to_sql_integer_invalid');
			}
		}

		// Strip/validate thousand separator strictly per cfg.
		// This must reject:
		// - wrong grouping
		// - separators when thoSep is ''
		// - any non-digit char
		$digits = $this->stripAndValidateThousands($s, $thoSep, 'err_value_to_sql_integer_invalid');
		if ($digits === '') {
			throw new ValueToSqlException('Invalid integer input.', 'err_value_to_sql_integer_invalid');
		}

		// Strip leading zeros (keep at least one).
		$digits = \ltrim($digits, '0');
		if ($digits === '') {
			$digits = '0';
		}

		$len = \strlen($digits);

		// Quick digit count cutoffs (works for both 32/64-bit).
		// 64-bit max is 19 digits, 32-bit max is 10 digits.
		$maxDigits = (\PHP_INT_SIZE === 8) ? 19 : 10;
		if ($len > $maxDigits) {
			throw new ValueToSqlException('Integer out of range.','err_value_to_sql_integer_out_of_range',['min' => $min, 'max' => $max]);
		}

		if (!$negative) {
			$val = 0;

			$cut = \intdiv(\PHP_INT_MAX, 10);
			$cutDigit = \PHP_INT_MAX % 10;

			for ($i = 0; $i < $len; $i++) {
				$digit = \ord($digits[$i]) - 48;
				if ($digit < 0 || $digit > 9) {
					throw new ValueToSqlException('Invalid integer input.', 'err_value_to_sql_integer_invalid');
				}
				if ($val > $cut || ($val === $cut && $digit > $cutDigit)) {
					throw new ValueToSqlException('Integer out of range.','err_value_to_sql_integer_out_of_range',['min' => $min, 'max' => $max]);
				}
				$val = $val * 10 + $digit;
			}
		} else {
			// Build negative directly to allow PHP_INT_MIN.
			$val = 0;

			$cut = \intdiv(\PHP_INT_MIN, 10);         // negative (e.g. -922...580)
			$cutDigit = -(\PHP_INT_MIN % 10);         // positive (e.g. 8)

			for ($i = 0; $i < $len; $i++) {
				$digit = \ord($digits[$i]) - 48;
				if ($digit < 0 || $digit > 9) {
					throw new ValueToSqlException('Invalid integer input.', 'err_value_to_sql_integer_invalid');
				}

				// Before: val = val * 10 - digit
				// Overflow if:
				// - val < cut, or
				// - val == cut and digit > cutDigit
				if ($val < $cut || ($val === $cut && $digit > $cutDigit)) {
					throw new ValueToSqlException('Integer out of range.','err_value_to_sql_integer_out_of_range',['min' => $min, 'max' => $max]);
				}

				$val = $val * 10 - $digit;
			}
		}

		if ($val < $min || $val > $max) {
			throw new ValueToSqlException('Integer out of range.','err_value_to_sql_integer_out_of_range',['min' => $min, 'max' => $max]);
		}

		return $val;
	}


	/**
	 * Convert form input to a dot-decimal string with exactly $scale decimals, for a DECIMAL column.
	 *
	 * Behavior:
	 * - Accepts a string with an optional sign, an integer part with the thousand separator of
	 *   cfg in groups of three, and decimals after the decimal separator of cfg, which occurs
	 *   at most once. With ',' as decimal and '.' as thousand separator, 1.234,5 gives 1234.50
	 *   and ,5 gives 0.50 at scale 2, while 1234.5 is rejected.
	 * - A trailing decimal separator without decimals counts as an integer.
	 * - Pads the decimals with zeros to $scale and rejects more decimals than $scale, zeros
	 *   included: 12,00 is rejected at scale 0. A string is never rounded.
	 * - An int is padded to $scale. A float is rounded half up to $scale, and NAN and INF are
	 *   rejected; a float is approximate, so prefer strings.
	 * - Returns no plus sign and no negative zero.
	 * - Rejects a bool, array or object, a minus sign when $allowNegative is false, and any
	 *   other separator or character. When the thousand separator is a space, a non-breaking or
	 *   narrow non-breaking space counts as a space, and a run of spaces as one.
	 *
	 * Typical usage:
	 *   $price = $this->app->valueToSql->decimal($post['price'], required: true);
	 *
	 * @param  mixed  $value  Form input: string, int, float or null; a string is trimmed.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @param  int  $scale  Decimals of the column, 0-18.
	 * @param  bool  $allowNegative  Accept values below 0.
	 * @return string|null  Such as 1234.50, or 25 at scale 0; null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is rejected; a too many decimals failure carries scale.
	 * @throws \CitOmni\Infrastructure\Exception\ValueDefinitionException  When $scale is outside 0-18.
	 */
	public function decimal(mixed $value, bool $required = false, int $scale = 2, bool $allowNegative = true): ?string {
		
		if ($scale < 0 || $scale > 18) {
			throw new ValueDefinitionException('Invalid decimal scale: $scale must be in range 0..18.');
		}		
		
		// Explicitly reject arrays/objects early (do not treat as "missing").
		if (\is_array($value) || \is_object($value) || \is_bool($value)) {
			throw new ValueToSqlException('Invalid decimal type.','err_value_to_sql_decimal_invalid_type');
		}
		
		// Numeric fast-path: Supports controller-side calculations deterministically.
		if (\is_int($value)) {
			if (!$allowNegative && $value < 0) {
				throw new ValueToSqlException('Negative values are not allowed.','err_value_to_sql_decimal_negative_not_allowed');
			}

			// int -> string, then reuse existing scale logic by synthesizing normalized form.
			$norm = (string)$value;

			// Normalize negative zero (int can't really be -0, but keep symmetry).
			if ($norm === '-0') {
				$norm = '0';
			}

			// Apply scale padding.
			if ($scale > 0) {
				return $norm . '.' . \str_repeat('0', $scale);
			}
			return $norm;
		}

		if (\is_float($value)) {
			if (!\is_finite($value)) {
				throw new ValueToSqlException('Invalid decimal.','err_value_to_sql_decimal_invalid');
			}
			if (!$allowNegative && $value < 0) {
				throw new ValueToSqlException('Negative values are not allowed.','err_value_to_sql_decimal_negative_not_allowed');
			}

			// Round explicitly to scale (float is approximate; policy must be explicit).
			$rounded = \round($value, $scale, \PHP_ROUND_HALF_UP);

			$out = \number_format($rounded, $scale, '.', ''); // no scientific notation

			// Normalize "-0.00" to "0.00" (and "-0" to "0" when scale=0).
			if ($scale > 0) {
				if ($out[0] === '-' && \strncmp($out, '-0.', 3) === 0 && \trim(\substr($out, 3), '0') === '') {
					$out = \substr($out, 1);
				}
			} else {
				if ($out === '-0') {
					$out = '0';
				}
			}

			return $out;
		}
		
		$s = $this->requireStringOrNull($value, 'err_value_to_sql_decimal_invalid');
		if ($s === null) {
			if ($required) {
				throw new ValueToSqlException('Decimal value is required.','err_value_to_sql_decimal_required');
			}
			return null;
		}

		$norm = $this->normalizeDecimalString($s, $allowNegative);

		// Split into sign, integer, fraction.
		$sign = '';
		if ($norm[0] === '-') {
			$sign = '-';
			$norm = \substr($norm, 1);
		}

		$dotPos = \strpos($norm, '.');
		if ($dotPos === false) {
			$intPart = $norm;
			$fracPart = '';
		} else {
			$intPart = \substr($norm, 0, $dotPos);
			$fracPart = \substr($norm, $dotPos + 1);
		}

		if ($intPart === '') {
			$intPart = '0';
		}

		// Enforce scale without rounding.
		$fracLen = \strlen($fracPart);
		if ($fracLen > $scale) {
			throw new ValueToSqlException('Too many decimals.','err_value_to_sql_decimal_too_many_decimals',['scale' => $scale]);
		}

		if ($scale > 0) {
			if ($fracLen < $scale) {
				$fracPart .= \str_repeat('0', $scale - $fracLen);
			}

			// Normalize "-0.00" to "0.00" (negative zero is rarely useful in storage).
			if ($sign === '-' && $intPart === '0' && \trim($fracPart, '0') === '') {
				$sign = '';
			}

			return $sign . $intPart . '.' . $fracPart;
		}

		// scale=0 -> integer-like decimal. Any fraction digit, also the zeros in "12,00",
		// was rejected above as too_many_decimals.
		if ($sign === '-' && $intPart === '0') {
			$sign = '';
		}
		return $sign . $intPart;
		
	}


	/**
	 * Convert form input to 1 or 0 for a boolean column, such as TINYINT(1).
	 *
	 * Behavior:
	 * - Accepts true and false, the ints 1 and 0, and the strings 1, true, yes and on, or 0,
	 *   false, no and off, in any letter case.
	 * - Rejects every other value, and a float, array or object.
	 *
	 * Typical usage:
	 *   $active = $this->app->valueToSql->boolean($post['active'], required: true);
	 *
	 * @param  mixed  $value  Form input: string, bool, int or null; a string is trimmed.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @return int|null  1 or 0; null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is rejected.
	 */
	public function boolean(mixed $value, bool $required = false): ?int {
		
		if (\is_float($value) || \is_array($value) || \is_object($value)) {
			throw new ValueToSqlException('Invalid boolean type.','err_value_to_sql_boolean_invalid_type');
		}
		
		// Fast-path for real booleans.
		if (\is_bool($value)) {
			return $value ? 1 : 0;
		}

		// Fast-path for integer 0/1.
		if (\is_int($value)) {
			if ($value === 0 || $value === 1) {
				return $value;
			}
			throw new ValueToSqlException('Invalid boolean value.','err_value_to_sql_boolean_invalid');
		}

		$s = $this->requireStringOrNull($value, 'err_value_to_sql_boolean_invalid');

		if ($s === null) {
			if ($required) {
				throw new ValueToSqlException('Boolean value is required.','err_value_to_sql_boolean_required');
			}
			return null;
		}

		$lc = \strtolower($s);

		// Accepted truthy values.
		if ($lc === '1' || $lc === 'true' || $lc === 'yes' || $lc === 'on') {
			return 1;
		}

		// Accepted falsy values.
		if ($lc === '0' || $lc === 'false' || $lc === 'no' || $lc === 'off') {
			return 0;
		}

		throw new ValueToSqlException('Invalid boolean value.','err_value_to_sql_boolean_invalid');
	}


	/**
	 * Convert form input to a date in the form YYYY-MM-DD, for a DATE column.
	 *
	 * Behavior:
	 * - Accepts YYYY-MM-DD, as input type="date" posts it, and DD-MM-YYYY or DD/MM/YYYY with
	 *   the same separator twice.
	 * - Rejects a date that does not exist, such as 2026-02-30.
	 *
	 * Typical usage:
	 *   $birthDate = $this->app->valueToSql->date($post['birth_date'], required: true);
	 *
	 * @param  mixed  $value  Form input: string or null; trimmed.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @return string|null  Null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is not a string, is rejected, or is empty while required.
	 */
	public function date(mixed $value, bool $required = false): ?string {
		
		$s = $this->requireStringOrNull($value, 'err_value_to_sql_date_invalid');
		
		if ($s === null) {
			if ($required) {
				throw new ValueToSqlException('Date value is required.','err_value_to_sql_date_required');
			}
			return null;
		}

		$y = 0; $m = 0; $d = 0;

		// "YYYY-MM-DD"
		if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
			$y = (int)\substr($s, 0, 4);
			$m = (int)\substr($s, 5, 2);
			$d = (int)\substr($s, 8, 2);

		// "DD-MM-YYYY" or "DD/MM/YYYY" (same separator required)
		} elseif (\preg_match('/^\d{2}([\-\/])\d{2}\1\d{4}$/', $s) === 1) {
			$d = (int)\substr($s, 0, 2);
			$m = (int)\substr($s, 3, 2);
			$y = (int)\substr($s, 6, 4);

		} else {
			throw new ValueToSqlException('Invalid date format.','err_value_to_sql_date_invalid_format');
		}

		if (!\checkdate($m, $d, $y)) {
			throw new ValueToSqlException('Invalid date.','err_value_to_sql_date_invalid');
		}

		return \sprintf('%04d-%02d-%02d', $y, $m, $d);
	}


	/**
	 * Convert form input to a time of day in the form HH:MM:SS, for a TIME column.
	 *
	 * Behavior:
	 * - Accepts HH:MM, as input type="time" posts it by default, and HH:MM:SS; missing seconds
	 *   become 00.
	 * - Rejects an hour above 23 and a minute or second above 59.
	 *
	 * Typical usage:
	 *   $startTime = $this->app->valueToSql->time($post['start_time'], required: true);
	 *
	 * @param  mixed  $value  Form input: string or null; trimmed.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @return string|null  Null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is not a string, is rejected, or is empty while required.
	 */
	public function time(mixed $value, bool $required = false): ?string {
		
		$s = $this->requireStringOrNull($value, 'err_value_to_sql_time_invalid');
		
		if ($s === null) {
			if ($required) {
				throw new ValueToSqlException('Time value is required.','err_value_to_sql_time_required');
			}
			return null;
		}

		// Accept strictly:
		// - "HH:MM"
		// - "HH:MM:SS"
		if (\preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $s) !== 1) {
			throw new ValueToSqlException('Invalid time format.','err_value_to_sql_time_invalid_format');
		}

		$hh = (int)\substr($s, 0, 2);
		$mm = (int)\substr($s, 3, 2);
		$ss = (\strlen($s) === 8) ? (int)\substr($s, 6, 2) : 0;

		if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59 || $ss < 0 || $ss > 59) {
			throw new ValueToSqlException('Invalid time.','err_value_to_sql_time_invalid');
		}

		return \sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
	}


	/**
	 * Convert form input to a date and time in the form YYYY-MM-DD HH:MM:SS, for a DATETIME column.
	 *
	 * Behavior:
	 * - Accepts YYYY-MM-DDTHH:MM, as input type="datetime-local" posts it by default, with or
	 *   without seconds, and with a space instead of the T; missing seconds become 00. A run of
	 *   whitespace counts as one space.
	 * - Rejects a date that does not exist, and a time outside 00:00:00 to 23:59:59.
	 *
	 * Typical usage:
	 *   $startsAt = $this->app->valueToSql->dateTime($post['starts_at'], required: true);
	 *
	 * @param  mixed  $value  Form input: string or null; trimmed.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @return string|null  Null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is not a string, is rejected, or is empty while required.
	 */
	public function dateTime(mixed $value, bool $required = false): ?string {
		
		$s = $this->requireStringOrNull($value, 'err_value_to_sql_datetime_invalid');
		
		if ($s === null) {
			if ($required) {
				throw new ValueToSqlException('Datetime value is required.','err_value_to_sql_datetime_required');
			}
			return null;
		}

		// HTML datetime-local typically posts: "YYYY-MM-DDTHH:MM" (seconds optional via step).
		// Normalize the separator to a space and collapse any repeated whitespace.
		$s = \str_replace('T', ' ', $s);
		$s = \preg_replace('/\s+/', ' ', $s) ?? $s;

		// Expect exactly:
		// - "YYYY-MM-DD HH:MM"
		// - "YYYY-MM-DD HH:MM:SS"
		$matches = [];
		if (\preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $matches) !== 1) {
			throw new ValueToSqlException('Invalid datetime format.','err_value_to_sql_datetime_invalid_format');
		}

		$y = (int)$matches[1];
		$m = (int)$matches[2];
		$d = (int)$matches[3];
		$hh = (int)$matches[4];
		$mm = (int)$matches[5];
		
		$ssRaw = $matches[6] ?? '';
		$ss = ($ssRaw !== '') ? (int)$ssRaw : 0;		

		if (!\checkdate($m, $d, $y) || $hh < 0 || $hh > 23 || $mm < 0 || $mm > 59 || $ss < 0 || $ss > 59) {
			throw new ValueToSqlException('Invalid datetime.','err_value_to_sql_datetime_invalid');
		}

		return \sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $hh, $mm, $ss);
	}


	/**
	 * Check form text for a text column.
	 *
	 * Behavior:
	 * - Accepts a string only. An int, float, bool, array or object is rejected rather than
	 *   cast, so true never reaches a text column as '1'.
	 * - Returns null for '', after trimming when $trim is true.
	 *
	 * Notes:
	 * - $maxLen counts bytes. A VARCHAR(n) counts characters, so a limit of n bytes is stricter
	 *   for multibyte text.
	 *
	 * Typical usage:
	 *   $title = $this->app->valueToSql->text($post['title'], required: true, maxLen: 200);
	 *
	 * @param  mixed  $value  Form input: string or null.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @param  int  $maxLen  Maximum length in bytes, >= 0; 0 for no limit.
	 * @param  bool  $trim  Trim the text first.
	 * @return string|null  Null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is not a string, is empty while required, or is longer than $maxLen; a too long failure carries max.
	 * @throws \CitOmni\Infrastructure\Exception\ValueDefinitionException  When $maxLen is below 0 and the value is not empty.
	 */
	public function text(mixed $value, bool $required = false, int $maxLen = 0, bool $trim = true): ?string {
		if ($value === null) {
			if ($required) {
				throw new ValueToSqlException('Text value is required.','err_value_to_sql_text_required');
			}
			return null;
		}
		if (!\is_string($value)) {
			throw new ValueToSqlException('Invalid text type.','err_value_to_sql_text_invalid_type');
		}

		$s = $value;

		if ($trim) {
			$s = \trim($s);
		}

		if ($s === '') {
			if ($required) {
				throw new ValueToSqlException('Text value is required.','err_value_to_sql_text_required');
			}
			return null;
		}

		if ($maxLen < 0) {
			throw new ValueDefinitionException('Invalid text maxLen: $maxLen must be 0 or greater.');
		}

		if ($maxLen > 0 && \strlen($s) > $maxLen) {
			throw new ValueToSqlException('Text is too long.','err_value_to_sql_text_too_long',['max' => $maxLen]);
		}

		return $s;
	}


	/**
	 * Check form input against a list of allowed strings, such as the values of an ENUM column.
	 *
	 * Behavior:
	 * - Returns the trimmed input when it equals an allowed value exactly, letter case
	 *   included.
	 * - Accepts a string only; any other type is rejected rather than cast.
	 *
	 * Typical usage:
	 *   $status = $this->app->valueToSql->enum($post['status'], ['draft', 'published'], required: true);
	 *
	 * @param  mixed  $value  Form input: string or null; trimmed.
	 * @param  list<string>  $allowed  Allowed values; not empty.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @return string|null  Null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is not a string, is empty while required, or is not allowed.
	 * @throws \CitOmni\Infrastructure\Exception\ValueDefinitionException  When $allowed is empty or holds a value that is not a string, and the value is not empty.
	 */
	public function enum(mixed $value, array $allowed, bool $required = false): ?string {

		if ($value === null) {
			if ($required) {
				throw new ValueToSqlException('Enum value is required.','err_value_to_sql_enum_required');
			}
			return null;
		}
		if (!\is_string($value)) {
			throw new ValueToSqlException('Invalid enum type.','err_value_to_sql_enum_invalid_type');
		}

		$s = \trim($value);
		if ($s === '') {
			if ($required) {
				throw new ValueToSqlException('Enum value is required.','err_value_to_sql_enum_required');
			}
			return null;
		}

		// Fail fast if $allowed is misconfigured (developer contract).
		if ($allowed === []) {
			throw new ValueDefinitionException('Invalid enum definition: $allowed must not be empty.');
		}
		
		foreach ($allowed as $a) {
			if (!\is_string($a)) {
				throw new ValueDefinitionException('Invalid enum definition: all $allowed values must be strings.');
			}
		}

		if (\in_array($s, $allowed, true)) {
			return $s;
		}

		throw new ValueToSqlException('Invalid enum value.','err_value_to_sql_enum_invalid');

	}


	/**
	 * Convert form input to a JSON string, for a JSON column.
	 *
	 * Behavior:
	 * - A string must be well-formed JSON and is returned trimmed, otherwise unchanged.
	 * - An array or object is encoded, with Unicode and slashes unescaped.
	 * - Rejects every other type.
	 *
	 * Typical usage:
	 *   $settings = $this->app->valueToSql->json($post['settings']);
	 *
	 * @param  mixed  $value  JSON string, array, object or null.
	 * @param  bool  $required  Reject an empty value instead of returning null.
	 * @return string|null  Null for an empty value.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When a string is not valid JSON, an array or object cannot be encoded, the type is not accepted, or the value is empty while required.
	 */
	public function json(mixed $value, bool $required = false): ?string {
		if ($value === null) {
			if ($required) {
				throw new ValueToSqlException('JSON value is required.','err_value_to_sql_json_required');
			}
			return null;
		}

		if (\is_string($value)) {
			$s = \trim($value);
			if ($s === '') {
				if ($required) {
					throw new ValueToSqlException('JSON value is required.','err_value_to_sql_json_required');
				}
				return null;
			}

			// Validate that it is well-formed JSON.
			try {
				\json_decode($s, true, 512, \JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				throw new ValueToSqlException('Invalid JSON.','err_value_to_sql_json_invalid');
			}
			return $s;
		}

		if (!\is_array($value) && !\is_object($value)) {
			throw new ValueToSqlException('Invalid JSON type.','err_value_to_sql_json_invalid_type');
		}

		try {
			$s = \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new ValueToSqlException('JSON encoding failed.','err_value_to_sql_json_encode_failed');
		}

		return $s;
	}


	/**
	 * Return a string trimmed, or null for null and for a string that is empty after trimming.
	 *
	 * @param  mixed  $value  Form input.
	 * @param  string  $invalidKey  Message key when the value is neither null nor a string.
	 * @return string|null
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the value is neither null nor a string; it is never cast.
	 */
	private function requireStringOrNull(mixed $value, string $invalidKey): ?string {
		if ($value === null) {
			return null;
		}
		if (!\is_string($value)) {
			throw new ValueToSqlException('Invalid input type.', $invalidKey);
		}
		$s = \trim($value);
		return ($s === '') ? null : $s;
	}


	/**
	 * Convert a decimal string with the separators of cfg to a dot-decimal string.
	 *
	 * Behavior:
	 * - Accepts an optional sign, an integer part with the thousand separator in groups of
	 *   three, and decimals after the decimal separator, which occurs at most once; the
	 *   decimals hold digits only. An empty integer part becomes 0.
	 * - Returns digits with an optional minus and an optional dot with the decimals as typed;
	 *   never a plus sign.
	 *
	 * @param  string  $s  Non-empty, trimmed input.
	 * @param  bool  $allowNegative  Accept a minus sign.
	 * @return string  Such as 1234, 1234.56 or -0.5.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the format is invalid, or a minus sign is not allowed.
	 */
	private function normalizeDecimalString(string $s, bool $allowNegative): string {
		$decSep = $this->decimalSeparator;
		$thoSep = $this->thousandSeparator;

		$sign = '';
		$first = $s[0];
		if ($first === '-' || $first === '+') {
			// Note: Leading '+' is accepted but never emitted in normalized output.
			$sign = $first;
			$s = \substr($s, 1);
			if ($s === '') {
				throw new ValueToSqlException('Invalid decimal.','err_value_to_sql_decimal_invalid');
			}
		}

		if ($sign === '-' && !$allowNegative) {
			throw new ValueToSqlException('Negative values are not allowed.','err_value_to_sql_decimal_negative_not_allowed');
		}

		// Split on decimal separator (cfg). Must appear at most once.
		$decPos = \strpos($s, $decSep);
		if ($decPos === false) {
			$intRaw = $s;
			$fracRaw = '';
		} else {
			if (\strpos($s, $decSep, $decPos + 1) !== false) {
				throw new ValueToSqlException('Invalid decimal.','err_value_to_sql_decimal_invalid');
			}
			$intRaw = \substr($s, 0, $decPos);
			$fracRaw = \substr($s, $decPos + 1);
		}

		if ($intRaw === '' && $fracRaw === '') {
			throw new ValueToSqlException('Invalid decimal.','err_value_to_sql_decimal_invalid');
		}

		// Fraction: must be digits only (or empty). Thousand separator is never allowed here.
		if ($fracRaw !== '') {
			if ($thoSep !== '' && \strpos($fracRaw, $thoSep) !== false) {
				throw new ValueToSqlException('Invalid decimal.','err_value_to_sql_decimal_invalid');
			}
			if (!\ctype_digit($fracRaw)) {
				throw new ValueToSqlException('Invalid decimal.','err_value_to_sql_decimal_invalid');
			}
		}

		// Integer part: validate and remove thousand separator if enabled.
		$intDigits = $this->stripAndValidateThousands($intRaw, $thoSep, 'err_value_to_sql_decimal_invalid');

		// Normalize empty integer part like ",50" -> "0.50" ONLY if decimal separator exists and intRaw is empty.
		if ($intDigits === '') {
			$intDigits = '0';
		}

		$out = $intDigits;
		if ($fracRaw !== '') {
			$out .= '.' . $fracRaw; // SQL dot-decimal
		}

		return ($sign === '-') ? '-' . $out : $out;
	}


	/**
	 * Remove the thousand separators from an integer part, rejecting a malformed one.
	 *
	 * Behavior:
	 * - Without a thousand separator, accepts digits only.
	 * - With one, accepts digits only, or a first group of one to three digits followed by
	 *   groups of exactly three.
	 * - When the separator is a space, a non-breaking or narrow non-breaking space counts as a
	 *   space, and a run of spaces as one.
	 *
	 * @param  string  $raw  Integer part as typed; may be ''.
	 * @param  string  $thoSep  Thousand separator, or '' for none.
	 * @param  string  $errKey  Message key of the exception.
	 * @return string  Digits only; '' for ''.
	 * @throws \CitOmni\Infrastructure\Exception\ValueToSqlException  When the part is malformed.
	 */
	private function stripAndValidateThousands(string $raw, string $thoSep, string $errKey): string {
		if ($raw === '') {
			return '';
		}

		if ($thoSep === '') {
			if (!\ctype_digit($raw)) {
				throw new ValueToSqlException('Invalid numeric format.',$errKey);
			}
			return $raw;
		}

		// No empty groups, only digits in each group.
		if ($thoSep === ' ') {
			// Normalize NBSP and thin space to regular space for human-copied numbers.
			$raw = \str_replace("\xC2\xA0", ' ', $raw);
			$raw = \str_replace("\xE2\x80\xAF", ' ', $raw);

			// Collapse repeated spaces to a single space.
			$raw = \preg_replace('/ +/', ' ', $raw) ?? $raw;
		}
		$parts = \explode($thoSep, $raw);
		$cnt = \count($parts);

		if ($cnt === 1) {
			if (!\ctype_digit($raw)) {
				throw new ValueToSqlException('Invalid numeric format.',$errKey);
			}
			return $raw;
		}

		for ($i = 0; $i < $cnt; $i++) {
			$p = $parts[$i];
			if ($p === '' || !\ctype_digit($p)) {
				throw new ValueToSqlException('Invalid numeric format.',$errKey);
			}
		}

		// First group 1-3 digits, rest exactly 3 digits.
		$firstLen = \strlen($parts[0]);
		if ($firstLen < 1 || $firstLen > 3) {
			throw new ValueToSqlException('Invalid numeric format.',$errKey);
		}
		for ($i = 1; $i < $cnt; $i++) {
			if (\strlen($parts[$i]) !== 3) {
				throw new ValueToSqlException('Invalid numeric format.',$errKey);
			}
		}

		return \implode('', $parts);
	}

}