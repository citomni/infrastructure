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

use CitOmni\Kernel\Service\BaseService;

/**
 * ValueToSql: Strict UI/form -> SQL value normalizer for MySQL/MariaDB parameter binding.
 *
 * Converts common UI input formats into SQL-friendly scalar strings/values intended
 * for prepared statement binding (Not a query builder. Not a SQL dialect abstraction layer.)
 *
 * Core rules:
 * - Fail fast on invalid inputs (no guessing, no silent coercion).
 * - Deterministic and cfg-strict for number formats:
 *   - decimal separator: cfg locale.format.decimal_separator (required, exactly 1 char)
 *   - thousand separator: cfg locale.format.thousand_separator (optional: '' or exactly 1 char)
 *   - If thousand separator is enabled, it must differ from the decimal separator.
 * - Whitespace policy:
 *   - Leading/trailing whitespace is trimmed.
 *   - Internal whitespace is NOT removed unless it is configured as thousand separator.
 *
 * Required semantics (default required=false across methods):
 * - Empty values return null when required=false.
 * - Empty values throw when required=true.
 *
 * Numeric parsing (cfg-strict, no heuristics):
 * - integer():
 *   - Accepts: optional leading '+'/'-', digits, and the configured thousand separator (if enabled),
 *     with strict 3-digit grouping (first group 1-3 digits, subsequent groups exactly 3 digits).
 *   - Rejects: any occurrence of the configured decimal separator, any other characters,
 *     any wrong/empty grouping, or separators when thousand separator is disabled ('').
 *   - Parses with overflow-safe digit-by-digit checks.
 * - decimal():
 *   - Accepts: optional leading '+'/'-', integer part with optional configured thousand separators
 *     (same strict 3-digit grouping), and an optional fractional part separated by the configured
 *     decimal separator (must appear at most once).
 *   - If the decimal separator is present but no fractional digits are provided (e.g. "123,"), it is treated as an integer value.
 *   - Rejects: thousand separators in the fractional part, any non-digit characters beyond the
 *     allowed separators, wrong/empty grouping, or alternative separator characters.
 *   - Normalizes output to SQL dot-decimal form ('.') and enforces scale without rounding.
 *
 * Date/time parsing:
 * - date(): accepts HTML type="date" format "YYYY-MM-DD" and also "DD-MM-YYYY"/"DD/MM/YYYY" (strict).
 * - time(): accepts "HH:MM" and "HH:MM:SS" (strict).
 * - dateTime(): accepts HTML datetime-local "YYYY-MM-DDTHH:MM[:SS]" and "YYYY-MM-DD HH:MM[:SS]" (strict).
 *   All are treated as local wall-clock values (no timezone conversion).
 *
 * Typical usage:
 *   $price = $this->app->valueToSql->decimal($post['price'], required: true); // "1234.50"
 *   $year  = $this->app->valueToSql->integer($post['year'], required: true, min: 1900, max: 2100); // 2026
 *   $date  = $this->app->valueToSql->date($post['moved_in']); // "2026-02-26" or null
 *
 * @throws \InvalidArgumentException On invalid input values.
 * @throws \RuntimeException On invalid locale separator configuration.
 */
final class ValueToSql extends BaseService {

	private string $decimalSeparator = ',';
	private string $thousandSeparator = '.';

	protected function init(): void {
		
		// Require locale.format to exist (use isset() to avoid triggering Cfg::__get()).
		if (!isset($this->app->cfg->locale) || !isset($this->app->cfg->locale->format)) {
			throw new \RuntimeException('Missing cfg: locale.format is required by ValueToSql.');
		}

		$format = $this->app->cfg->locale->format;

		// Both keys must be set explicitly. No defaults: the caller owns locale policy.
		if (!isset($format->decimal_separator)) {
			throw new \RuntimeException('Missing cfg: locale.format.decimal_separator is required by ValueToSql.');
		}
		if (!isset($format->thousand_separator)) {
			throw new \RuntimeException("Missing cfg: locale.format.thousand_separator is required by ValueToSql (use '' to disable grouping).");
		}

		$dec = (string)$format->decimal_separator;
		$tho = (string)$format->thousand_separator;

		// Decimal separator must be a single non-empty char.
		if ($dec === '' || \strlen($dec) !== 1) {
			throw new \RuntimeException('Invalid cfg: locale.format.decimal_separator must be exactly 1 char.');
		}

		// Thousand separator can be '' or a single char.
		if ($tho !== '' && \strlen($tho) !== 1) {
			throw new \RuntimeException("Invalid cfg: locale.format.thousand_separator must be exactly 1 char or ''.");
		}

		// Must not be the same when thousand is enabled.
		if ($tho !== '' && $tho === $dec) {
			throw new \RuntimeException('Invalid cfg: thousand_separator must differ from decimal_separator.');
		}

		$this->decimalSeparator = $dec;
		$this->thousandSeparator = $tho;
	}


	/**
	 * Normalize a UI integer to a PHP int suitable for SQL parameter binding.
	 *
	 * This method is cfg-strict:
	 * - Uses cfg-defined thousand separator (may be '' which disables thousand separators).
	 * - Rejects any occurrence of the cfg-defined decimal separator (No "123,00" tolerance).
	 * - No guessing, no tolerance for alternative separators.
	 *
	 * Behavior:
	 * - Empty input:
	 *   - required=false -> null
	 *   - required=true  -> throws
	 * - Accepted inputs:
	 *   - "1.234" only when cfg thousand_separator is "."
	 *   - "1 234" only when cfg thousand_separator is " "
	 *   - No thousand separators when cfg thousand_separator is ""
	 *   - Optional leading '+'/'-' (a leading '+' is accepted but has no effect on output - the result is an int)
	 * - Rejected inputs:
	 *   - Any occurrence of cfg decimal_separator (no "123,00" tolerance)
	 *   - Any non-digit chars (after removing valid thousand separators)
	 *   - Mixed or incorrectly grouped thousand separators
	 *
	 * Notes:
	 * - Parsing is performed digit-by-digit with overflow checks.
	 * - This method does not accept "integer disguised as decimal" (e.g. "123,00").
	 *
	 * Typical usage:
	 *   $year  = $this->app->valueToSql->integer($post['year'], required: true, min: 1900, max: 2100);
	 *   $count = $this->app->valueToSql->integer($post['count']);
	 *
	 * @param mixed $value UI input value (Typically string; may be null).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @param int $min Minimum allowed value (Default PHP_INT_MIN).
	 * @param int $max Maximum allowed value (Default PHP_INT_MAX).
	 * @param bool $allowNegative Whether negative values are allowed (Default true).
	 * @return int|null Parsed integer or null.
	 *
	 * @throws \InvalidArgumentException On invalid format or out-of-range value.
	 */
	public function integer(mixed $value, bool $required = false, int $min = \PHP_INT_MIN, int $max = \PHP_INT_MAX, bool $allowNegative = true): ?int {
		
		// Reject non-scalar types and types that are not legitimate integer sources.
		// Float is rejected: A float is not an integer; the caller must round/cast explicitly.
		// Bool is rejected: A form value, not a PHP boolean.
		if (\is_float($value) || \is_bool($value) || \is_array($value) || \is_object($value)) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_invalid_type','value_to_sql','citomni/infrastructure','Invalid integer type.'));
		}

		// Range sanity: User may pass reversed bounds.
		if ($min > $max) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_invalid_range','value_to_sql','citomni/infrastructure','Invalid integer range (%MIN%..%MAX%).',['min' => $min, 'max' => $max]));
		}

		// Fast-path for native int (e.g. controller-side calculations).
		if (\is_int($value)) {
			if (!$allowNegative && $value < 0) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_negative_not_allowed','value_to_sql','citomni/infrastructure','Negative values are not allowed.'));
			}
			if ($value < $min || $value > $max) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_out_of_range','value_to_sql','citomni/infrastructure','Integer out of range (%MIN%..%MAX%).',['min' => $min, 'max' => $max]));
			}
			return $value;
		}

		$s = $this->requireStringOrNull($value);
		if ($s === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_required','value_to_sql','citomni/infrastructure','Value is required.'));
			}
			return null;
		}

		$decSep = $this->decimalSeparator;
		$thoSep = $this->thousandSeparator;

		// Reject decimal separator strictly
		if (\strpos($s, $decSep) !== false) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_invalid','value_to_sql','citomni/infrastructure','Invalid integer.'));
		}

		$negative = false;
		$first = $s[0] ?? '';
		if ($first === '-' || $first === '+') {
			if ($first === '-') {
				if (!$allowNegative) {
					throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_negative_not_allowed','value_to_sql','citomni/infrastructure','Negative values are not allowed.'));
				}
				$negative = true;
			}
			$s = \substr($s, 1);
			if ($s === '') {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_invalid','value_to_sql','citomni/infrastructure','Invalid integer.'));
			}
		}

		// Strip/validate thousand separator strictly per cfg.
		// This must reject:
		// - wrong grouping
		// - separators when thoSep is ''
		// - any non-digit char
		$digits = $this->stripAndValidateThousands($s, $thoSep, 'err_value_to_sql_integer_invalid');
		if ($digits === '') {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_invalid','value_to_sql','citomni/infrastructure','Invalid integer.'));
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
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_out_of_range','value_to_sql','citomni/infrastructure','Integer out of range (%MIN%..%MAX%).',['min' => $min, 'max' => $max]));
		}

		if (!$negative) {
			$val = 0;

			$cut = \intdiv(\PHP_INT_MAX, 10);
			$cutDigit = \PHP_INT_MAX % 10;

			for ($i = 0; $i < $len; $i++) {
				$digit = \ord($digits[$i]) - 48;
				if ($digit < 0 || $digit > 9) {
					throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_invalid','value_to_sql','citomni/infrastructure','Invalid integer.'));
				}
				if ($val > $cut || ($val === $cut && $digit > $cutDigit)) {
					throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_out_of_range','value_to_sql','citomni/infrastructure','Integer out of range (%MIN%..%MAX%).',['min' => $min, 'max' => $max]));
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
					throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_invalid','value_to_sql','citomni/infrastructure','Invalid integer.'));
				}

				// Before: val = val * 10 - digit
				// Overflow if:
				// - val < cut, or
				// - val == cut and digit > cutDigit
				if ($val < $cut || ($val === $cut && $digit > $cutDigit)) {
					throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_out_of_range','value_to_sql','citomni/infrastructure','Integer out of range (%MIN%..%MAX%).',['min' => $min, 'max' => $max]));
				}

				$val = $val * 10 - $digit;
			}
		}

		if ($val < $min || $val > $max) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_integer_out_of_range','value_to_sql','citomni/infrastructure','Integer out of range (%MIN%..%MAX%).',['min' => $min, 'max' => $max]));
		}

		return $val;
	}


	/**
	 * Normalize a UI decimal string to a SQL dot-decimal string (DECIMAL-safe).
	 *
	 * Locale behavior is 100% cfg-strict:
	 * - Decimal separator: $this->decimalSeparator (cfg locale.format.decimal_separator, exactly 1 char).
	 * - Thousand separator: $this->thousandSeparator (cfg locale.format.thousand_separator, '' or exactly 1 char).
	 * - No heuristics and no alternative separators are accepted.
	 *
	 * Behavior:
	 * - Empty input:
	 *   1) required=false -> null
	 *   2) required=true  -> throws
	 * - Leading/trailing whitespace is trimmed.
	 * - Optional leading '+' or '-' is allowed (negative depends on $allowNegative).
	 *   (a leading '+' is accepted but not preserved - so output never includes '+')
	 * - Thousand separator (if enabled) is only allowed in the integer part and must use strict 3-digit grouping:
	 *   1) First group: 1-3 digits
	 *   2) Subsequent groups: exactly 3 digits
	 * - Decimal separator (cfg) may appear at most once.
	 * - If the decimal separator is present but no fractional digits are provided (e.g. "123,"), the value is treated as an integer ("123") and then scale-padding is applied.
	 * - Fractional part must be digits only and must not contain thousand separators.
	 *
	 * Output:
	 * - Always normalized to SQL dot-decimal form using '.' as decimal separator.
	 * - Enforces $scale without rounding:
	 *   - If fewer than $scale fraction digits are provided, pads with trailing zeros.
	 *   - If more than $scale fraction digits are provided, throws.
	 * - scale=0 returns an integer-like string (no dot).
	 *
	 * Examples (cfg-dependent):
	 * - If cfg decimal_separator=',' and thousand_separator='.':
	 *   - "1.234,5"  -> "1234.50" (scale=2)
	 *   - ",5"       -> "0.50"    (scale=2)
	 *   - "1234.5"   -> throws ('.' is not the cfg decimal separator)
	 * - If cfg decimal_separator='.' and thousand_separator=',':
	 *   - "1,234.5"  -> "1234.50" (scale=2)
	 *   - "1.234,5"  -> throws (',' is not the cfg decimal separator)
	 *
	 * Notes:
	 * - This method is intended for SQL parameter binding to DECIMAL/NUMERIC columns.
	 * - For string inputs, this method does not apply any rounding policy (fail fast instead).
	 * - For numeric inputs (int/float), values are rounded to $scale using HALF_UP to avoid float artifacts.
	 * - Remember that float is approximate!
	 *
	 * Typical usage:
	 *   $price = $this->app->valueToSql->decimal($post['price'], required: true); // e.g. "1234.50"
	 *   $vat   = $this->app->valueToSql->decimal($post['vat'], scale: 0);        // e.g. "25"
	 *
	 * @param mixed $value UI input value (typically string; may be null).
	 * @param bool $required Whether empty input is allowed (default false).
	 * @param int $scale Fractional digits to enforce (default 2, range 0..18).
	 * @param bool $allowNegative Whether negative values are allowed (default true).
	 * @return string|null Normalized SQL decimal string or null if empty and not required.
	 *
	 * @throws \InvalidArgumentException On invalid format, invalid scale, disallowed negative values,
	 *                                  or when required=true and input is empty.
	 */
	public function decimal(mixed $value, bool $required = false, int $scale = 2, bool $allowNegative = true): ?string {
		
		if ($scale < 0 || $scale > 18) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_scale_range','value_to_sql','citomni/infrastructure','Invalid decimal scale (%SCALE%).',['scale' => $scale]));
		}		
		
		// Explicitly reject arrays/objects early (do not treat as "missing").
		if (\is_array($value) || \is_object($value) || \is_bool($value)) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_invalid_type','value_to_sql','citomni/infrastructure','Invalid decimal type.'));
		}
		
		// Numeric fast-path: Supports controller-side calculations deterministically.
		if (\is_int($value)) {
			if (!$allowNegative && $value < 0) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_negative_not_allowed','value_to_sql','citomni/infrastructure','Negative values are not allowed.'));
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
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_invalid','value_to_sql','citomni/infrastructure','Invalid decimal.'));
			}
			if (!$allowNegative && $value < 0) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_negative_not_allowed','value_to_sql','citomni/infrastructure','Negative values are not allowed.'));
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
		
		$s = $this->requireStringOrNull($value);
		if ($s === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_required','value_to_sql','citomni/infrastructure','Value is required.'));
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
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_too_many_decimals','value_to_sql','citomni/infrastructure','Too many decimals (max %SCALE%).',['scale' => $scale]));
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

		// scale=0 -> integer-like decimal
		if ($fracPart !== '') {
			// Allow "0", "00", "000" etc. when scale is 0 (no fractional value).
			if (\trim($fracPart, '0') !== '') {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_scale_zero_has_fraction','value_to_sql','citomni/infrastructure','Fraction not allowed when scale is 0.'));
			}
		}
		if ($sign === '-' && $intPart === '0') {
			$sign = '';
		}
		return $sign . $intPart;
		
	}


	/**
	 * Normalize a UI boolean value to an integer (0/1) for SQL binding.
	 *
	 * Intended for TINYINT(1), BIT(1) or similar boolean-like columns.
	 * Always returns 1 or 0 (never true/false).
	 *
	 * Behavior:
	 * - Empty input:
	 *   1) required=false -> null
	 *   2) required=true  -> throws
	 * - Accepts (case-insensitive for strings):
	 *   - true/false (bool)
	 *   - 1/0 (int)
	 *   - "1"/"0"
	 *   - "true"/"false"
	 *   - "yes"/"no"
	 *   - "on"/"off"
	 * - Any other value -> throws.
	 *
	 * Notes:
	 * - String comparison is strict after trimming.
	 * - No implicit casting beyond explicitly supported values.
	 * - Designed for deterministic behavior (no FILTER_VALIDATE_BOOLEAN heuristics).
	 *
	 * Typical usage:
	 *   $active = $this->app->valueToSql->boolean($post['active'], required: true);
	 *
	 * @param mixed $value UI input value.
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @return int|null 1 or 0, or null if empty and not required.
	 *
	 * @throws \InvalidArgumentException On invalid value.
	 */
	public function boolean(mixed $value, bool $required = false): ?int {
		
		if (\is_float($value) || \is_array($value) || \is_object($value)) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_boolean_invalid_type','value_to_sql','citomni/infrastructure','Invalid boolean type.'));
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
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_boolean_invalid','value_to_sql','citomni/infrastructure','Invalid boolean value.'));
		}

		$s = $this->requireStringOrNull($value);

		if ($s === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_boolean_required','value_to_sql','citomni/infrastructure','Value is required.'));
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

		throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_boolean_invalid','value_to_sql','citomni/infrastructure','Invalid boolean value.'));
	}


	/**
	 * Normalize a UI date to "YYYY-MM-DD".
	 *
	 * Accepts:
	 * - "YYYY-MM-DD" (HTML input type="date")
	 * - "DD-MM-YYYY"
	 * - "DD/MM/YYYY"
	 *
	 * Behavior:
	 * - Empty input:
	 *   - required=false -> null
	 *   - required=true  -> throws
	 * - Validates:
	 *   - Date must be a real calendar date (checkdate).
	 * - Output is always normalized to "YYYY-MM-DD".
	 *
	 * Notes:
	 * - This method does not accept mixed separators (e.g. "12-03/2026").
	 * - Intended for SQL DATE binding.
	 *
	 * Typical usage:
	 *   $birthDate = $this->app->valueToSql->date($post['birth_date'], required: true);
	 *
	 * @param mixed $value UI input.
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @return string|null SQL date string ("YYYY-MM-DD") or null.
	 *
	 * @throws \InvalidArgumentException On invalid date or when required and empty.
	 */
	public function date(mixed $value, bool $required = false): ?string {
		
		$s = $this->requireStringOrNull($value);		
		
		if ($s === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_date_required','value_to_sql','citomni/infrastructure','Value is required.'));
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
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_date_invalid_format','value_to_sql','citomni/infrastructure','Invalid date format.'));
		}

		if (!\checkdate($m, $d, $y)) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_date_invalid','value_to_sql','citomni/infrastructure','Invalid date.'));
		}

		return \sprintf('%04d-%02d-%02d', $y, $m, $d);
	}


	/**
	 * Normalize a UI time value to "HH:MM:SS".
	 *
	 * Accepts:
	 * - "HH:MM" (HTML input type="time", default step)
	 * - "HH:MM:SS" (when step allows seconds)
	 *
	 * Behavior:
	 * - Empty input:
	 *   - required=false -> null
	 *   - required=true  -> throws
	 * - Validates:
	 *   - 00 <= HH <= 23
	 *   - 00 <= MM <= 59
	 *   - 00 <= SS <= 59
	 * - Output always includes seconds (pads with ":00" when missing).
	 *
	 * Notes:
	 * - This method treats input as a local wall-clock time (no timezone).
	 * - Intended for SQL TIME binding.
	 *
	 * Typical usage:
	 *   $startTime = $this->app->valueToSql->time($post['start_time'], required: true);
	 *
	 * @param mixed $value UI input.
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @return string|null SQL time string ("HH:MM:SS") or null.
	 *
	 * @throws \InvalidArgumentException On invalid time or when required and empty.
	 */
	public function time(mixed $value, bool $required = false): ?string {
		
		$s = $this->requireStringOrNull($value);
		
		if ($s === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_time_required','value_to_sql','citomni/infrastructure','Value is required.'));
			}
			return null;
		}

		// Accept strictly:
		// - "HH:MM"
		// - "HH:MM:SS"
		if (\preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $s) !== 1) {
			throw new \InvalidArgumentException(
				$this->app->txt->get('err_value_to_sql_time_invalid', 'value_to_sql', 'citomni/infrastructure', 'Invalid time format.')
			);
		}

		$hh = (int)\substr($s, 0, 2);
		$mm = (int)\substr($s, 3, 2);
		$ss = (\strlen($s) === 8) ? (int)\substr($s, 6, 2) : 0;

		if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59 || $ss < 0 || $ss > 59) {
			throw new \InvalidArgumentException(
				$this->app->txt->get('err_value_to_sql_time_invalid', 'value_to_sql', 'citomni/infrastructure', 'Invalid time.')
			);
		}

		return \sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
	}


	/**
	 * Normalize a UI datetime to "YYYY-MM-DD HH:MM:SS".
	 *
	 * Accepts:
	 * - "YYYY-MM-DDTHH:MM" (HTML datetime-local)
	 * - "YYYY-MM-DDTHH:MM:SS"
	 * - "YYYY-MM-DD HH:MM"
	 * - "YYYY-MM-DD HH:MM:SS"
	 *
	 * Behavior:
	 * - Empty input:
	 *   - required=false -> null
	 *   - required=true  -> throws
	 * - Normalizes:
	 *   - Replaces "T" with a single space (HTML datetime-local).
	 *   - Collapses any whitespace between date/time into a single space.
	 * - Validates:
	 *   - Date must be a real calendar date (checkdate).
	 *   - Time must be within 00:00:00..23:59:59.
	 * - Output always includes seconds (pads with ":00" when missing).
	 *
	 * Notes:
	 * - This method treats input as a local wall-clock value (no timezone).
	 * - Intended for SQL DATETIME binding (not TIMESTAMP/UTC conversion).
	 *
	 * Typical usage:
	 *   $startsAt = $this->app->valueToSql->dateTime($post['starts_at'], required: true);
	 *
	 * @param mixed $value UI input.
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @return string|null SQL datetime string ("YYYY-MM-DD HH:MM:SS") or null.
	 *
	 * @throws \InvalidArgumentException On invalid datetime or when required and empty.
	 */
	public function dateTime(mixed $value, bool $required = false): ?string {
		
		$s = $this->requireStringOrNull($value);
		
		if ($s === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_datetime_required','value_to_sql','citomni/infrastructure','Value is required.'));
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
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_datetime_invalid','value_to_sql','citomni/infrastructure','Invalid datetime format.'));
		}

		$y = (int)$matches[1];
		$m = (int)$matches[2];
		$d = (int)$matches[3];
		$hh = (int)$matches[4];
		$mm = (int)$matches[5];
		
		$ssRaw = $matches[6] ?? '';
		$ss = ($ssRaw !== '') ? (int)$ssRaw : 0;		

		if (!\checkdate($m, $d, $y) || $hh < 0 || $hh > 23 || $mm < 0 || $mm > 59 || $ss < 0 || $ss > 59) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_datetime_invalid','value_to_sql','citomni/infrastructure','Invalid datetime.'));
		}

		return \sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $hh, $mm, $ss);
	}


	/**
	 * Normalize UI text for SQL binding (string input only).
	 *
	 * Behavior:
	 * - Type rules:
	 *   - Accepts: string
	 *   - Accepts: null only when required=false
	 *   - Rejects: int/float/bool/array/object (no implicit casting)
	 * - Empty input:
	 *   1) required=false -> null (null or "" after optional trim)
	 *   2) required=true  -> throws
	 * - Optional trim (default true).
	 * - Optional max length (0 disables length check).
	 *
	 * Notes:
	 * - This method is intentionally strict to prevent accidental "scalar-to-string" casts
	 *   (e.g. true -> "1") leaking into persisted text columns.
	 * - If you need numeric formatting, normalize with integer()/decimal() and then decide
	 *   explicitly whether you want to store that result as text.
	 *
	 * Typical usage:
	 *   $title = $this->app->valueToSql->text($post['title'], required: true, maxLen: 200);
	 *   $note  = $this->app->valueToSql->text($post['note']);
	 *
	 * @param mixed $value UI input (string or null).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @param int $maxLen Maximum allowed length in bytes (0 disables).
	 * @param bool $trim Whether to trim whitespace (Default true).
	 * @return string|null Normalized string or null.
	 *
	 * @throws \InvalidArgumentException On invalid type, when required and empty, or on max length violation.
	 */
	public function text(mixed $value, bool $required = false, int $maxLen = 0, bool $trim = true): ?string {
		if ($value === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_text_required','value_to_sql','citomni/infrastructure','Value is required.'));
			}
			return null;
		}
		if (!\is_string($value)) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_text_invalid_type','value_to_sql','citomni/infrastructure','Invalid text type.'));
		}

		$s = $value;

		if ($trim) {
			$s = \trim($s);
		}

		if ($s === '') {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_text_required','value_to_sql','citomni/infrastructure','Value is required.'));
			}
			return null;
		}

		if ($maxLen > 0 && \strlen($s) > $maxLen) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_text_too_long','value_to_sql','citomni/infrastructure','Text is too long (max %MAX%).',['max' => $maxLen]));
		}

		return $s;
	}


	/**
	 * Validate a UI value against an allowed set (ENUM-like) and return a string.
	 *
	 * Behavior:
	 * - Type rules:
	 *   - Accepts: string
	 *   - Accepts: null only when required=false
	 *   - Rejects: int/float/bool/array/object (no implicit casting)
	 * - Empty input:
	 *   1) required=false -> null (null or "" after trim)
	 *   2) required=true  -> throws
	 * - Allowed contract:
	 *   - $allowed must be a list of strings (strict, no implicit casting).
	 *   - Matching is performed as an exact string match after trimming UI input.
	 * - Output:
	 *   - Returns the matched string (same as UI value) when allowed.
	 *
	 * Notes:
	 * - This method is intentionally strict and deterministic.
	 * - If you need "case-insensitive" or "int enum" behavior, add a separate method
	 *   (do not smuggle heuristics into this one).
	 *
	 * Typical usage:
	 *   $status = $this->app->valueToSql->enum($post['status'], ['draft','published'], required: true);
	 *
	 * @param mixed $value UI input (string or null).
	 * @param string[] $allowed Allowed values (strict match).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @return string|null Allowed string or null.
	 *
	 * @throws \InvalidArgumentException On invalid type, invalid value, or invalid $allowed contract.
	 */
	public function enum(mixed $value, array $allowed, bool $required = false): ?string {
		if ($value === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_enum_required','value_to_sql','citomni/infrastructure','Value is required.'));
			}
			return null;
		}
		if (!\is_string($value)) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_enum_invalid_type','value_to_sql','citomni/infrastructure','Invalid enum type.'));
		}

		$s = \trim($value);
		if ($s === '') {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_enum_required','value_to_sql','citomni/infrastructure','Value is required.'));
			}
			return null;
		}

		// Fail fast if $allowed is misconfigured (developer contract).
		foreach ($allowed as $a) {
			if (!\is_string($a)) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_enum_allowed_invalid_type','value_to_sql','citomni/infrastructure','Invalid enum allowed list type.'));
			}
		}

		if (\in_array($s, $allowed, true)) {
			return $s;
		}

		throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_enum_invalid','value_to_sql','citomni/infrastructure','Invalid value.'));

	}


	/**
	 * Normalize a UI value to a JSON string for SQL binding.
	 *
	 * @param mixed $value UI input (array/object/string).
	 * @param bool $required Whether empty input is allowed (Default false).
	 * @return string|null JSON string or null.
	 *
	 * @throws \InvalidArgumentException On invalid JSON or unsupported input.
	 */
	public function json(mixed $value, bool $required = false): ?string {
		if ($value === null) {
			if ($required) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_json_required','value_to_sql','citomni/infrastructure','Value is required.'));
			}
			return null;
		}

		if (\is_string($value)) {
			$s = \trim($value);
			if ($s === '') {
				if ($required) {
					throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_json_required','value_to_sql','citomni/infrastructure','Value is required.'));
				}
				return null;
			}

			// Validate that it is well-formed JSON.
			try {
				\json_decode($s, true, 512, \JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_json_invalid','value_to_sql','citomni/infrastructure','Invalid JSON.'));
			}
			return $s;
		}

		if (!\is_array($value) && !\is_object($value)) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_json_invalid_type','value_to_sql','citomni/infrastructure','Invalid JSON type.'));
		}

		try {
			$s = \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_json_encode_failed','value_to_sql','citomni/infrastructure','JSON encoding failed.'));
		}

		return $s;
	}


	/**
	 * Require string or null and return a trimmed string or null.
	 *
	 * This helper enforces a strict type contract for UI inputs where only
	 * textual values are meaningful (e.g. date, time, datetime).
	 *
	 * Behavior:
	 * - null -> null
	 * - string:
	 *   1) trim()
	 *   2) '' after trim -> null
	 *   3) otherwise -> trimmed string
	 * - non-string (int, float, bool, array, object) -> throws
	 *
	 * Guarantees:
	 * - No implicit scalar-to-string casting.
	 * - No silent coercion of unexpected types.
	 * - Whitespace-only input is treated as "missing" (null).
	 *
	 * Notes:
	 * - Use this in methods where non-string input is a programmer error
	 *   and must fail fast.
	 * - Differs from soft string helpers that may treat non-string input
	 *   as null without throwing.
	 *
	 * @param mixed $value UI input value.
	 * @return string|null Trimmed string or null if empty.
	 *
	 * @throws \InvalidArgumentException When $value is not a string and not null.
	 */
	private function requireStringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!\is_string($value)) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_invalid_type','value_to_sql','citomni/infrastructure','Value must be a string.'));
		}
		$s = \trim($value);
		return ($s === '') ? null : $s;
	}


	/**
	 * Normalize a UI decimal string to a SQL dot-decimal numeric string.
	 *
	 * Rules:
	 * - Policy is derived from cfg:
	 *   - decimal separator: $this->decimalSeparator (single char, required)
	 *   - thousand separator: $this->thousandSeparator (single char or '', optional)
	 * - No guessing:
	 *   - Only the configured separators are accepted.
	 *   - Any other separator characters are rejected.
	 * - Whitespace:
	 *   - Leading/trailing whitespace is trimmed.
	 *   - Internal whitespace is not removed unless it is the configured thousand separator.
	 * - Decimal separator:
	 *   - May appear at most once.
	 * - Thousand separator:
	 *   - If configured as '' -> not allowed at all.
	 *   - Otherwise -> allowed only in the integer part and only with strict 3-digit grouping
	 *     (first group 1-3 digits, subsequent groups exactly 3 digits).
	 *
	 * Output:
	 * - Digits only, optional leading '-' sign, optional fractional part separated by '.'.
	 * - Examples: "1234", "1234.56", "-1234.56"
	 *
	 * @param string $s Non-empty trimmed string (UI input).
	 * @param bool $allowNegative Whether negative values are allowed.
	 * @return string Normalized SQL decimal string using '.' as decimal separator.
	 *
	 * @throws \InvalidArgumentException On invalid format or disallowed value.
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
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_invalid','value_to_sql','citomni/infrastructure','Invalid decimal.'));
			}
		}

		if ($sign === '-' && !$allowNegative) {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_negative_not_allowed','value_to_sql','citomni/infrastructure','Negative values are not allowed.'));
		}

		// Split on decimal separator (cfg). Must appear at most once.
		$decPos = \strpos($s, $decSep);
		if ($decPos === false) {
			$intRaw = $s;
			$fracRaw = '';
		} else {
			if (\strpos($s, $decSep, $decPos + 1) !== false) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_invalid','value_to_sql','citomni/infrastructure','Invalid decimal.'));
			}
			$intRaw = \substr($s, 0, $decPos);
			$fracRaw = \substr($s, $decPos + 1);
		}

		if ($intRaw === '' && $fracRaw === '') {
			throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_invalid','value_to_sql','citomni/infrastructure','Invalid decimal.'));
		}

		// Fraction: must be digits only (or empty). Thousand separator is never allowed here.
		if ($fracRaw !== '') {
			if ($thoSep !== '' && \strpos($fracRaw, $thoSep) !== false) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_invalid','value_to_sql','citomni/infrastructure','Invalid decimal.'));
			}
			if (!\ctype_digit($fracRaw)) {
				throw new \InvalidArgumentException($this->app->txt->get('err_value_to_sql_decimal_invalid','value_to_sql','citomni/infrastructure','Invalid decimal.'));
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
	 * Strip thousand separators strictly and return digits-only.
	 *
	 * Rules:
	 * - If $thoSep === '': input must be digits only (no separators).
	 * - If $thoSep !== '': allow proper 3-digit grouping in the integer part:
	 *   - First group: 1-3 digits
	 *   - Subsequent groups: exactly 3 digits
	 *
	 * @param string $raw Integer part as typed (may be empty).
	 * @param string $thoSep Thousand separator ('' or single char).
	 * @param string $errKey Txt key used for exceptions.
	 * @return string Digits-only integer part.
	 */
	private function stripAndValidateThousands(string $raw, string $thoSep, string $errKey): string {
		if ($raw === '') {
			return '';
		}

		if ($thoSep === '') {
			if (!\ctype_digit($raw)) {
				throw new \InvalidArgumentException($this->app->txt->get($errKey,'value_to_sql','citomni/infrastructure','Invalid format.'));
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
				throw new \InvalidArgumentException($this->app->txt->get($errKey,'value_to_sql','citomni/infrastructure','Invalid format.'));
			}
			return $raw;
		}

		for ($i = 0; $i < $cnt; $i++) {
			$p = $parts[$i];
			if ($p === '' || !\ctype_digit($p)) {
				throw new \InvalidArgumentException($this->app->txt->get($errKey,'value_to_sql','citomni/infrastructure','Invalid format.'));
			}
		}

		// First group 1-3 digits, rest exactly 3 digits.
		$firstLen = \strlen($parts[0]);
		if ($firstLen < 1 || $firstLen > 3) {
			throw new \InvalidArgumentException($this->app->txt->get($errKey,'value_to_sql','citomni/infrastructure','Invalid format.'));
		}
		for ($i = 1; $i < $cnt; $i++) {
			if (\strlen($parts[$i]) !== 3) {
				throw new \InvalidArgumentException($this->app->txt->get($errKey,'value_to_sql','citomni/infrastructure','Invalid format.'));
			}
		}

		return \implode('', $parts);
	}

}