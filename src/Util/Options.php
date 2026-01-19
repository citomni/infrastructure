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

namespace CitOmni\Infrastructure\Util;

final class Options {

	private function __construct() {}

	/**
	 * Get a string option or default.
	 *
	 * @param array $opt Options array.
	 * @param string $key Option key.
	 * @param string $default Default value if key is missing.
	 * @return string The option value.
	 */
	public static function string(array $opt, string $key, string $default = ''): string {
		if (!\array_key_exists($key, $opt)) {
			return $default;
		}
		$v = $opt[$key];
		if (!\is_string($v)) {
			$type = \gettype($v);
			throw new \InvalidArgumentException("Option '{$key}' must be a string, got {$type}.");
		}
		return $v;
	}

	/**
	 * Get a string option or null (or default).
	 *
	 * Behavior:
	 * - If the key is missing: Returns $default.
	 * - If the value is null: Returns null.
	 * - Otherwise: Requires a string and returns it.
	 *
	 * @param array $opt Options array.
	 * @param string $key Option key.
	 * @param string|null $default Default value if key is missing.
	 * @return string|null The option value (string or null).
	 */
	public static function stringOrNull(array $opt, string $key, ?string $default = null): ?string {
		if (!\array_key_exists($key, $opt)) {
			return $default;
		}
		$v = $opt[$key];
		if ($v === null) {
			return null;
		}
		if (!\is_string($v)) {
			$type = \gettype($v);
			throw new \InvalidArgumentException("Option '{$key}' must be a string or null, got {$type}.");
		}
		return $v;
	}

	/**
	 * Get an int option or default with optional bounds.
	 *
	 * @param array $opt Options array.
	 * @param string $key Option key.
	 * @param int $default Default value if key is missing.
	 * @param int|null $min Minimum allowed value (inclusive) or null.
	 * @param int|null $max Maximum allowed value (inclusive) or null.
	 * @return int The option value.
	 */
	public static function int(array $opt, string $key, int $default = 0, ?int $min = null, ?int $max = null): int {
		if (!\array_key_exists($key, $opt)) {
			return $default;
		}
		$v = $opt[$key];
		if (!\is_int($v)) {
			$type = \gettype($v);
			throw new \InvalidArgumentException("Option '{$key}' must be an integer, got {$type}.");
		}
		if ($min !== null && $v < $min) {
			throw new \InvalidArgumentException("Option '{$key}' must be >= {$min}.");
		}
		if ($max !== null && $v > $max) {
			throw new \InvalidArgumentException("Option '{$key}' must be <= {$max}.");
		}
		return $v;
	}
	
	/**
	 * Get an int option or null (or default) with optional bounds.
	 *
	 * Behavior:
	 * - If the key is missing: Returns $default.
	 * - If the value is null: Returns null.
	 * - Otherwise: Requires an int and returns it.
	 * - If bounds are provided, enforces them on non-null values.
	 *
	 * @param array $opt Options array.
	 * @param string $key Option key.
	 * @param int|null $default Default value if key is missing.
	 * @param int|null $min Minimum allowed value (inclusive) or null.
	 * @param int|null $max Maximum allowed value (inclusive) or null.
	 * @return int|null The option value (int or null).
	 */
	public static function intOrNull(array $opt, string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int {
		if (!\array_key_exists($key, $opt)) {
			return $default;
		}
		$v = $opt[$key];
		if ($v === null) {
			return null;
		}
		if (!\is_int($v)) {
			$type = \gettype($v);
			throw new \InvalidArgumentException("Option '{$key}' must be an integer or null, got {$type}.");
		}
		if ($min !== null && $v < $min) {
			throw new \InvalidArgumentException("Option '{$key}' must be >= {$min}.");
		}
		if ($max !== null && $v > $max) {
			throw new \InvalidArgumentException("Option '{$key}' must be <= {$max}.");
		}
		return $v;
	}

	/**
	 * Get a bool option or default.
	 *
	 * @param array $opt Options array.
	 * @param string $key Option key.
	 * @param bool $default Default value if key is missing.
	 * @return bool The option value.
	 */
	public static function bool(array $opt, string $key, bool $default = false): bool {
		if (!\array_key_exists($key, $opt)) {
			return $default;
		}
		$v = $opt[$key];
		if (!\is_bool($v)) {
			$type = \gettype($v);
			throw new \InvalidArgumentException("Option '{$key}' must be a boolean, got {$type}.");
		}
		return $v;
	}
	
	/**
	 * Get an array option or default.
	 *
	 * @param array $opt Options array.
	 * @param string $key Option key.
	 * @param array $default Default value if key is missing.
	 * @return array The option value.
	 */
	public static function arr(array $opt, string $key, array $default = []): array {
		if (!\array_key_exists($key, $opt)) {
			return $default;
		}
		$v = $opt[$key];
		if (!\is_array($v)) {
			$type = \gettype($v);
			throw new \InvalidArgumentException("Option '{$key}' must be an array, got {$type}.");
		}
		return $v;
	}

	/**
	 * Enforce that no unknown keys exist in the options array.
	 *
	 * @param array $opt Options array.
	 * @param array $allowedKeys Allowed keys as either:
	 *   - list style: ['a', 'b']
	 *   - map style:  ['a' => <any>, 'b' => <any>] (keys matter, values are ignored)
	 * @return void
	 */
	public static function assertNoUnknownKeys(array $opt, array $allowedKeys): void {
		if ($opt === []) {
			return;
		}
		if ($allowedKeys === []) {
			throw new \InvalidArgumentException('assertNoUnknownKeys() requires a non-empty allowed keys list/map.');
		}
		$allowed = \array_is_list($allowedKeys)
			? \array_fill_keys($allowedKeys, true)
			: $allowedKeys;

		foreach ($opt as $key => $_) {
			if (!\array_key_exists($key, $allowed)) {
				throw new \InvalidArgumentException("Unknown option '{$key}'.");
			}
		}
	}

	/**
	 * Get an enum string option or default.
	 *
	 * @param array $opt Options array.
	 * @param string $key Option key.
	 * @param string $default Default value if key is missing.
	 * @param array $allowed Allowed string values.
	 * @return string The chosen value.
	 */
	public static function enumString(array $opt, string $key, string $default, array $allowed): string {
		if ($allowed === []) {
			throw new \InvalidArgumentException("enumString() requires a non-empty allowed list.");
		}
		$v = self::string($opt, $key, $default);
		if (!\in_array($v, $allowed, true)) {
			$allowedList = \implode(', ', $allowed);
			throw new \InvalidArgumentException("Option '{$key}' must be one of: {$allowedList}.");
		}
		return $v;
	}

	/**
	 * Get an enum string option or default using map membership.
	 *
	 * Behavior:
	 * - Reads the value as a strict string (or uses $default if missing).
	 * - Requires that the chosen value exists as a key in $allowedMap.
	 *
	 * Notes:
	 * - $allowedMap must be an associative map where keys are allowed values.
	 *   Values are ignored (membership is key-based).
	 *
	 * @param array $opt Options array.
	 * @param string $key Option key.
	 * @param string $default Default value if key is missing.
	 * @param array $allowedMap Allowed values as keys.
	 * @return string The chosen value.
	 */
	public static function enumStringMap(array $opt, string $key, string $default, array $allowedMap): string {
		if ($allowedMap === []) {
			throw new \InvalidArgumentException("enumStringMap() requires a non-empty allowed map.");
		}
		$v = self::string($opt, $key, $default);
		if (!\array_key_exists($v, $allowedMap)) {
			$allowedKeys = \array_keys($allowedMap);
			\sort($allowedKeys);
			$allowedList = \implode(', ', $allowedKeys);

			throw new \InvalidArgumentException("Option '{$key}' must be one of: {$allowedList}.");
		}
		return $v;
	}

}
