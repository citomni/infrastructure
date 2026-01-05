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
 * FormatNumber: Strict UI <-> DB number formatter for decimal strings.
 *
 * Converts user-facing "continental/ISO" formats (e.g. "1.234,56" or "1 234,56")
 * into DB-ready dot-decimal strings (e.g. "1234.56"), and vice versa.
 *
 * Behavior:
 * - No floats: All inputs/outputs are strings suitable for DECIMAL columns.
 * - Fail fast: Invalid or ambiguous formats throw \InvalidArgumentException.
 * - Strict parsing: No auto-detection; no tolerant whitespace; no rounding.
 * - Empty input handling:
 *   1) toDb(): Empty -> null (useful for optional fields / NULL columns).
 *   2) fromDb(): Empty/null -> '' (useful for rendering optional form fields).
 *
 * Notes:
 * - UI parsing accepts only comma as decimal separator.
 * - DB parsing accepts only dot as decimal separator.
 * - Thousand separator for UI input can be '.' OR ' ' (single ASCII space), not both.
 *
 * Typical usage:
 *   $db = $this->app->formatNumber->toDb($raw, 14, 2);
 *   $ui = $this->app->formatNumber->fromDb($dbValue, 2, self::UI_THOUSANDS_DOT, self::UI_DECIMAL_COMMA);
 */
final class FormatNumber extends BaseService {
	public const UI_THOUSANDS_DOT = '.';
	public const UI_THOUSANDS_SPACE = ' ';
	public const UI_DECIMAL_COMMA = ',';

	public const DB_DECIMAL_DOT = '.';

	/**
	 * One-time init hook. Present for symmetry; no config/options needed.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Intentionally empty. This service is pure and stateless.
	}

	/**
	 * toDb: Convert UI number string to DB dot-decimal string.
	 *
	 * Behavior:
	 * - Accepts:
	 *   1) "1234,56"
	 *   2) "1.234,56"
	 *   3) "1 234,56"
	 *   4) "1234" / "1.234" / "1 234"
	 * - Rejects:
	 *   - Dot-decimal UI input like "1234.56" (ambiguous with thousands).
	 *   - Mixed thousands separators ('.' and ' ').
	 *   - More fractional digits than $scale (no rounding).
	 * - Pads fractional part with trailing zeros up to $scale.
	 * - Enforces DECIMAL($precision,$scale) digit limits.
	 * - Empty input (null/''/whitespace) returns null.
	 *
	 * @param string|null $raw Raw UI value from request (nullable).
	 * @param int $precision Total digits (DECIMAL precision), must be >= 1.
	 * @param int $scale Fractional digits (DECIMAL scale), must be >= 0 and <= $precision.
	 * @return string|null DB-ready string or null when input is empty.
	 *
	 * @throws \InvalidArgumentException On invalid/ambiguous input.
	 */
	public function toDb(?string $raw, int $precision, int $scale): ?string {
		$raw = $raw === null ? '' : \trim($raw);
		if ($raw === '') {
			return null;
		}

		$this->assertPrecisionScale($precision, $scale);

		$sign = '';
		$first = $raw[0] ?? '';
		if ($first === '-' || $first === '+') {
			$sign = $first === '-' ? '-' : '';
			$raw = \substr($raw, 1);
			$raw = \trim($raw);
			if ($raw === '') {
				throw new \InvalidArgumentException($this->app->txt->get('err_format_number_sign_without_digits', 'format_number', 'citomni/infrastructure', 'Invalid number: Sign without digits.'));
			}
		}

		// Strict character whitelist: Digits, comma, dot, ASCII space only.
		// This intentionally rejects NBSP and other unicode spaces.
		if (\preg_match('/[^0-9,\. ]/', $raw) === 1) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_unsupported_chars', 'format_number', 'citomni/infrastructure', 'Invalid number: Unsupported characters.'));
		}

		// Decimal separator must be comma for UI.
		$commaCount = \substr_count($raw, self::UI_DECIMAL_COMMA);
		if ($commaCount > 1) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_multiple_decimal_separators', 'format_number', 'citomni/infrastructure', 'Invalid number: Multiple decimal separators.'));
		}

		// Reject dot-decimal UI input (e.g. "1234.56") by policy.
		// If there is a dot and also digits after it but no comma, this could be decimal or thousands.
		// We choose to fail fast rather than guess.
		if ($commaCount === 0 && \strpos($raw, self::UI_THOUSANDS_DOT) !== false) {
			// Reject any dot-decimal shape "digits.digits" in UI.
			// Dots are allowed only as strict thousands grouping when no comma is present.
			if (\preg_match('/^\d+\.\d+$/', $raw) === 1) {
				throw new \InvalidArgumentException($this->app->txt->get('err_format_number_dot_decimal_not_supported', 'format_number', 'citomni/infrastructure', 'Invalid number: Dot-decimal UI input is not supported. Use comma as decimal separator.'));
			}
		}

		$parts = $commaCount === 1 ? \explode(self::UI_DECIMAL_COMMA, $raw, 2) : [$raw, ''];
		$intPartRaw = $parts[0];
		$fracRaw = $parts[1];

		if ($scale === 0 && $commaCount === 1) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_decimals_not_allowed_scale0', 'format_number', 'citomni/infrastructure', 'Invalid number: Decimals are not allowed for scale=0.'));
		}

		// Allow ",50" style input (treat missing integer part as "0").
		// Still strict: Only allowed when a comma is present.
		if ($intPartRaw === '' && $commaCount === 1) {
			$intPartRaw = '0';
		}


		$this->assertThousandsGrouping($intPartRaw);


		$intDigits = $this->stripThousands($intPartRaw);
		if ($intDigits === '') {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_missing_integer_digits', 'format_number', 'citomni/infrastructure', 'Invalid number: Missing integer digits.'));
		}

		// Normalize leading zeros, but keep at least one digit.
		$intDigits = \ltrim($intDigits, '0');
		if ($intDigits === '') {
			$intDigits = '0';
		}

		if ($fracRaw !== '' && \preg_match('/^\d+$/', $fracRaw) !== 1) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_fraction_must_be_digits', 'format_number', 'citomni/infrastructure', 'Invalid number: Fractional part must be digits only.'));
		}

		$fracLen = $fracRaw === '' ? 0 : \strlen($fracRaw);
		if ($fracLen > $scale) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_too_many_fraction_digits', 'format_number', 'citomni/infrastructure', 'Invalid number: Too many fractional digits for scale=%SCALE%.', ['scale' => (string)$scale]));
		}

		$maxIntDigits = $precision - $scale;
		if (\strlen($intDigits) > $maxIntDigits) {
			throw new \InvalidArgumentException(
				$this->app->txt->get(
					'err_format_number_too_many_integer_digits',
					'format_number', 'citomni/infrastructure',
					'Invalid number: Too many integer digits for DECIMAL(%PRECISION%,%SCALE%).',
					['precision' => (string)$precision, 'scale' => (string)$scale]
				)
			);
		}

		$out = $sign . $intDigits;

		if ($scale > 0) {
			$fracPadded = $fracRaw;
			if ($fracLen < $scale) {
				$fracPadded .= \str_repeat('0', $scale - $fracLen);
			}
			$out .= self::DB_DECIMAL_DOT . $fracPadded;
		}

		return $out;
	}

	/**
	 * fromDb: Convert DB dot-decimal string to UI formatted string.
	 *
	 * Behavior:
	 * - Accepts DB strings like: "1234.56", "-0.50", "10", "10.0".
	 * - Rejects:
	 *   - Thousands separators in DB input.
	 *   - Comma decimals in DB input.
	 *   - Non-digit characters (besides leading sign and one dot).
	 * - Pads fractional part with zeros up to $scale for display.
	 * - Inserts thousands separators as explicitly requested by caller.
	 * - Empty/null input returns ''.
	 * - If $scale > 0, always renders exactly $scale fractional digits (pads with zeros).
	 *
	 * @param string|null $db Raw DB value (nullable).
	 * @param int $scale Desired UI scale (fraction digits) >= 0.
	 * @param string $thousandsSep Thousands separator for UI output ('' | '.' | ' ').
	 * @param string $decimalSep Decimal separator for UI output (usually ',').
	 * @return string UI formatted value, or '' when input is empty.
	 *
	 * @throws \InvalidArgumentException On invalid DB input or invalid separators.
	 */
	public function fromDb(?string $db, int $scale = 2, string $thousandsSep = self::UI_THOUSANDS_DOT, string $decimalSep = self::UI_DECIMAL_COMMA): string {
		$db = $db === null ? '' : \trim($db);
		if ($db === '') {
			return '';
		}

		if ($scale < 0) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_invalid_scale_fromdb', 'format_number', 'citomni/infrastructure', 'Invalid scale: Must be >= 0.'));
		}

		$this->assertUiSeparators($thousandsSep, $decimalSep);

		$sign = '';
		$first = $db[0] ?? '';
		if ($first === '-' || $first === '+') {
			$sign = $first === '-' ? '-' : '';
			$db = \substr($db, 1);
			if ($db === '') {
				throw new \InvalidArgumentException($this->app->txt->get('err_format_number_db_sign_without_digits', 'format_number', 'citomni/infrastructure', 'Invalid DB number: Sign without digits.'));
			}
		}

		// Strict DB format: digits, optional single dot, digits.
		if (\preg_match('/^\d+(\.\d+)?$/', $db) !== 1) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_db_invalid_format', 'format_number', 'citomni/infrastructure', 'Invalid DB number: Expected dot-decimal without thousands separators.'));
		}

		$parts = \explode(self::DB_DECIMAL_DOT, $db, 2);

		$intDigits = $parts[0];
		$intDigits = \ltrim($intDigits, '0');
		if ($intDigits === '') {
			$intDigits = '0';
		}

		$fracRaw = $parts[1] ?? '';

		$fracLen = $fracRaw === '' ? 0 : \strlen($fracRaw);
		if ($fracLen > $scale) {
			throw new \InvalidArgumentException(
				$this->app->txt->get(
					'err_format_number_db_too_many_fraction_digits',
					'format_number', 'citomni/infrastructure',
					'Invalid DB number: Too many fractional digits for requested scale=%SCALE%.',
					['scale' => (string)$scale]
				)
			);
		}

		$fracPadded = $fracRaw;
		if ($scale > 0 && $fracLen < $scale) {
			$fracPadded .= \str_repeat('0', $scale - $fracLen);
		}

		$intFormatted = $this->addThousands($intDigits, $thousandsSep);

		if ($scale === 0) {
			return $sign . $intFormatted;
		}

		return $sign . $intFormatted . $decimalSep . $fracPadded;
	}

	/**
	 * Validate DECIMAL precision/scale constraints.
	 *
	 * @param int $precision Total digits >= 1.
	 * @param int $scale Fraction digits >= 0 and <= precision.
	 * @return void
	 */
	private function assertPrecisionScale(int $precision, int $scale): void {
		if ($precision < 1) {
			throw new \InvalidArgumentException(
				$this->app->txt->get('err_format_number_invalid_precision', 'format_number', 'citomni/infrastructure', 'Invalid precision: Must be >= 1.')
			);
		}
		if ($scale < 0 || $scale > $precision) {
			throw new \InvalidArgumentException(
				$this->app->txt->get('err_format_number_invalid_scale', 'format_number', 'citomni/infrastructure', 'Invalid scale: Must be between 0 and precision.')
			);
		}
	}

	/**
	 * Ensure UI separators are explicitly supported.
	 *
	 * @param string $thousandsSep Allowed: '', '.', ' '.
	 * @param string $decimalSep Allowed: ',' or '.' (caller-chosen UI).
	 * @return void
	 */
	private function assertUiSeparators(string $thousandsSep, string $decimalSep): void {
		if ($thousandsSep !== '' && $thousandsSep !== self::UI_THOUSANDS_DOT && $thousandsSep !== self::UI_THOUSANDS_SPACE) {
			throw new \InvalidArgumentException(
				$this->app->txt->get('err_format_number_invalid_thousands_sep', 'format_number', 'citomni/infrastructure', 'Invalid thousands separator: Must be "", ".", or " ".')
			);
		}
		if ($decimalSep !== self::UI_DECIMAL_COMMA && $decimalSep !== self::DB_DECIMAL_DOT) {
			throw new \InvalidArgumentException(
				$this->app->txt->get('err_format_number_invalid_decimal_sep', 'format_number', 'citomni/infrastructure', 'Invalid decimal separator: Must be "," or ".".')
			);
		}
		if ($thousandsSep !== '' && $thousandsSep === $decimalSep) {
			throw new \InvalidArgumentException(
				$this->app->txt->get('err_format_number_invalid_separators_same', 'format_number', 'citomni/infrastructure', 'Invalid separators: Thousands and decimal separators must differ.')
			);
		}
	}

	/**
	 * Validate thousands grouping for UI integer part.
	 *
	 * Rules:
	 * - May contain only digits plus '.' OR digits plus ' ' (not both), or digits only.
	 * - If separator is present, grouping must be 1-3 digits then (sep + 3 digits) repeated.
	 *
	 * @param string $intPartRaw Integer part as typed (no sign, no decimals).
	 * @return void
	 */
	private function assertThousandsGrouping(string $intPartRaw): void {
		if ($intPartRaw === '') {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_missing_integer_part', 'format_number', 'citomni/infrastructure', 'Invalid number: Missing integer part.'));
		}

		$hasDot = \strpos($intPartRaw, self::UI_THOUSANDS_DOT) !== false;
		$hasSpace = \strpos($intPartRaw, self::UI_THOUSANDS_SPACE) !== false;

		if ($hasDot && $hasSpace) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_mixed_thousands_seps', 'format_number', 'citomni/infrastructure', 'Invalid number: Mixed thousands separators are not supported.'));
		}

		if ($hasDot) {
			if (\preg_match('/^\d{1,3}(\.\d{3})*$/', $intPartRaw) !== 1) {
				throw new \InvalidArgumentException($this->app->txt->get('err_format_number_dot_grouping_malformed', 'format_number', 'citomni/infrastructure', 'Invalid number: Thousands grouping with "." is malformed.'));
			}
			return;
		}

		if ($hasSpace) {
			if (\preg_match('/^\d{1,3}( \d{3})*$/', $intPartRaw) !== 1) {
				throw new \InvalidArgumentException($this->app->txt->get('err_format_number_space_grouping_malformed', 'format_number', 'citomni/infrastructure', 'Invalid number: Thousands grouping with space is malformed.'));
			}
			return;
		}

		if (\preg_match('/^\d+$/', $intPartRaw) !== 1) {
			throw new \InvalidArgumentException($this->app->txt->get('err_format_number_integer_must_be_digits', 'format_number', 'citomni/infrastructure', 'Invalid number: Integer part must be digits.'));
		}
	}

	/**
	 * Strip thousands separators from a validated UI integer part.
	 *
	 * @param string $intPartRaw Validated integer part.
	 * @return string Digits only.
	 */
	private function stripThousands(string $intPartRaw): string {
		// Note: Input is already validated for grouping and char set.
		return \str_replace([self::UI_THOUSANDS_DOT, self::UI_THOUSANDS_SPACE], '', $intPartRaw);
	}

	/**
	 * Add thousands separators to a DB integer digit string.
	 *
	 * @param string $digits Digits only, non-empty.
	 * @param string $sep '', '.' or ' '.
	 * @return string Formatted integer part.
	 */
	private function addThousands(string $digits, string $sep): string {
		if ($sep === '') {
			return $digits;
		}

		$len = \strlen($digits);
		if ($len <= 3) {
			return $digits;
		}

		// Build from the end to avoid allocations from regex.
		$out = '';
		$i = $len;

		while ($i > 0) {
			$take = 3;
			if ($i - $take < 0) {
				$take = $i;
			}
			$chunk = \substr($digits, $i - $take, $take);
			$out = $chunk . ($out === '' ? '' : $sep . $out);

			$i -= $take;
		}

		return $out;
	}
}
