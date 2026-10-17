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
 * FormatNumber: Service that converts decimal numbers between a comma-decimal UI format and SQL dot-decimal strings.
 *
 * Works on strings only, never floats, so a DECIMAL value keeps every digit. UI input takes a
 * comma as decimal separator and database values a dot, and a value that does not fit is
 * rejected instead of guessed at or rounded. The wording of the error messages belongs to
 * the package's format_number language file.
 *
 * Rules:
 * - Never round, and never pass a value through a float.
 * - Reject a value with \InvalidArgumentException and a message from the txt service.
 *
 * @deprecated  To be removed. The valueToSql and valueFromSql services convert decimals by cfg locale.format instead.
 */
class FormatNumber extends BaseService {

	/** Thousands separator '.' in UI values. */
	public const UI_THOUSANDS_DOT = '.';

	/** Thousands separator ' ', a plain ASCII space, in UI values. */
	public const UI_THOUSANDS_SPACE = ' ';

	/** Decimal separator ',' in UI values. */
	public const UI_DECIMAL_COMMA = ',';

	/** Decimal separator '.' in database values. */
	public const DB_DECIMAL_DOT = '.';

	/**
	 * Do nothing; the service needs no setup.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		// Intentionally empty. This service is pure and stateless.
	}

	/**
	 * Convert a UI number to a database dot-decimal string with exactly $scale decimals.
	 *
	 * Behavior:
	 * - Returns null for null, '' and whitespace.
	 * - Accepts an optional sign, then digits with an optional comma and decimals, with the
	 *   thousands grouped by '.' or by ' ' in groups of three: 1234,56, 1.234,56, 1 234,56,
	 *   1.234 and ,5 are valid.
	 * - Rejects a dot decimal such as 1234.56, mixed or malformed grouping, any other character
	 *   (non-breaking spaces included), more decimals than $scale, a comma at scale 0, and more
	 *   integer digits than $precision - $scale.
	 * - Pads the decimals with zeros to $scale, drops leading zeros of the integer part and
	 *   drops a plus sign.
	 *
	 * Notes:
	 * - The messages come from the format_number language file of this package, in the active
	 *   language.
	 *
	 * Typical usage:
	 *   $price = $this->app->formatNumber->toDb($raw, 14, 2);
	 *
	 * @param  string|null  $raw  UI value; trimmed.
	 * @param  int  $precision  Total digits of the DECIMAL column, >= 1.
	 * @param  int  $scale  Decimals of the DECIMAL column, 0 to $precision.
	 * @return string|null  Such as 1234.56; null for empty input.
	 * @throws \InvalidArgumentException  When the value is rejected, or $precision or $scale is out of range.
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
		// If there is a dot and no comma, the input is ambiguous: It could be decimal or thousands grouping.
		// We still choose to fail fast on dot-decimal shapes, but must allow *valid* thousands grouping.
		// Therefore:
		//   - Accept strict thousands grouping (e.g. "300.000", "1.234.567").
		//   - Reject plain "digits.digits" forms that are not valid grouping, as they imply dot-decimal UI input.
		if ($commaCount === 0 && \strpos($raw, self::UI_THOUSANDS_DOT) !== false) {
			$isPlainDotDecimal = (\preg_match('/^\d+\.\d+$/', $raw) === 1);
			$isValidDotGrouping = (\preg_match('/^\d{1,3}(\.\d{3})*$/', $raw) === 1);

			// Reject ambiguous dot-decimal UI input, unless it is valid thousands grouping.
			if ($isPlainDotDecimal && !$isValidDotGrouping) {
				throw new \InvalidArgumentException(
					$this->app->txt->get('err_format_number_dot_decimal_not_supported', 'format_number', 'citomni/infrastructure', 'Invalid number: Dot-decimal UI input is not supported. Use comma as decimal separator.')
				);
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
	 * Format a database dot-decimal value for the UI with exactly $scale decimals.
	 *
	 * Behavior:
	 * - Returns '' for null, '' and whitespace.
	 * - Accepts an optional sign, then digits with an optional dot and decimals, such as
	 *   1234.56, -0.50 or 10. Rejects thousands separators, a comma decimal, any other
	 *   character and more decimals than $scale.
	 * - Pads the decimals with zeros to $scale and groups the integer part by $thousandsSep.
	 *   Drops leading zeros of the integer part and a plus sign; scale 0 has no decimal
	 *   separator.
	 *
	 * Notes:
	 * - The messages come from the format_number language file of this package, in the active
	 *   language.
	 *
	 * Typical usage:
	 *   $shown = $this->app->formatNumber->fromDb($row['price'], 2);
	 *
	 * @param  string|null  $db  Database value; trimmed.
	 * @param  int  $scale  Decimals in the output, >= 0.
	 * @param  string  $thousandsSep  '', '.' or ' '.
	 * @param  string  $decimalSep  ',' or '.', different from $thousandsSep.
	 * @return string  Such as 1.234,56; '' for empty input.
	 * @throws \InvalidArgumentException  When the value is rejected, $scale is negative or a separator is not supported.
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
	 * Reject a DECIMAL precision or scale out of range.
	 *
	 * @param  int  $precision  Total digits; must be >= 1.
	 * @param  int  $scale  Decimals; must be 0 to $precision.
	 * @return void
	 * @throws \InvalidArgumentException  When either is out of range.
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
	 * Reject unsupported UI output separators.
	 *
	 * @param  string  $thousandsSep  Must be '', '.' or ' '.
	 * @param  string  $decimalSep  Must be ',' or '.', and differ from a non-empty $thousandsSep.
	 * @return void
	 * @throws \InvalidArgumentException  When a separator is not supported or both are the same.
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
	 * Reject a UI integer part with malformed or mixed thousands grouping.
	 *
	 * Behavior:
	 * - Accepts digits only, or a first group of one to three digits followed by groups of
	 *   three, separated by '.' or by ' ', not both.
	 *
	 * @param  string  $intPartRaw  Integer part as typed, without sign and decimals.
	 * @return void
	 * @throws \InvalidArgumentException  When the part is empty or not accepted.
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
	 * Remove the thousands separators from a checked UI integer part.
	 *
	 * @param  string  $intPartRaw  Integer part that assertThousandsGrouping() accepted.
	 * @return string  Digits only.
	 */
	private function stripThousands(string $intPartRaw): string {
		// Note: Input is already validated for grouping and char set.
		return \str_replace([self::UI_THOUSANDS_DOT, self::UI_THOUSANDS_SPACE], '', $intPartRaw);
	}

	/**
	 * Group a digit string by three from the right.
	 *
	 * @param  string  $digits  Digits only, non-empty.
	 * @param  string  $sep  '', '.' or ' '; '' leaves the digits ungrouped.
	 * @return string
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
