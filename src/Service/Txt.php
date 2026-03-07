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

use CitOmni\Infrastructure\Exception\TxtConfigException;
use CitOmni\Kernel\Service\BaseService;

/**
 * Resolves translated text strings from application or vendor language files.
 *
 * This service is a native CitOmni replacement for the former LiteTxt-backed
 * wrapper. The public API remains unchanged for migration safety, while the
 * internal implementation now performs file lookup, payload caching, and
 * structured logging natively.
 *
 * Behavior:
 * - Validates and caches the active language once during initialization
 * - Resolves language files from either the app layer or a vendor/package layer
 * - Caches loaded language payloads in-memory by absolute file path
 * - Logs operational content issues and falls back to default behavior
 *
 * Compatibility:
 * - Caller-visible lookup semantics are preserved
 * - Missing, invalid, or unusable translation values still fall back to $default
 * - Diagnostics are modernized through the native log service
 * - Missing language files now produce one log event instead of the old
 *   indirect two-step pattern
 *
 * Notes:
 * - Real configuration errors still fail fast
 * - This service intentionally depends on the native log service
 * - Absence of the logger is considered framework misconfiguration and should
 *   propagate normally through the global error handler
 * - Cache lifetime is the current request/process lifetime
 */
final class Txt extends BaseService {
	
	private const LOG_FILE = 'txt.jsonl';
	private const APP_LAYER = 'app';
	private const APP_LANGUAGE_PATH = \CITOMNI_APP_PATH . '/language';
	private const VENDOR_PATH = \CITOMNI_APP_PATH . '/vendor';
	private const FILE_PATTERN = '~^[A-Za-z0-9](?:[A-Za-z0-9/_-]*[A-Za-z0-9])?$~';
	private const LAYER_PATTERN = '~^[a-z0-9._-]+/[a-z0-9._-]+$~i';
	private const LANGUAGE_PATTERN = '~^[a-z]{2}(?:_[A-Z]{2})?$~';


	/**
	 * Cached language payloads keyed by absolute file path.
	 *
	 * Array shape:
	 * - <absolute-file-path> => array<string, mixed>
	 *
	 * Invalid or missing payloads are normalized to an empty array after a
	 * single log event during first load.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cache = [];


	/**
	 * Cached active language code validated during initialization.
	 *
	 * @var string
	 */
	private string $language;


	/**
	 * Initialize the service and validate required configuration once.
	 *
	 * Behavior:
	 * - Reads cfg.locale.language
	 * - Validates the language format
	 * - Stores the validated value for hot-path reuse
	 * - Clears constructor options after initialization
	 *
	 * @return void
	 * @throws TxtConfigException When locale.language is missing or invalid.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg;

		if (!isset($cfg->locale) || !isset($cfg->locale->language)) {
			throw new TxtConfigException('Missing required config: locale.language');
		}

		$language = (string)$cfg->locale->language;

		if (!\preg_match(self::LANGUAGE_PATTERN, $language)) {
			throw new TxtConfigException(
				"Invalid locale.language '{$language}'. Expected 'xx' or 'xx_YY' (e.g. 'da' or 'da_DK')."
			);
		}

		$this->language = $language;
		$this->options = [];
	}


	/**
	 * Resolve a translated string from a language file.
	 *
	 * Behavior:
	 * - Validates caller-supplied file and layer arguments
	 * - Loads and caches the target language file on first access
	 * - Returns the resolved value if it is string or scalar
	 * - Logs and returns $default for missing, empty, or non-scalar values
	 * - Applies %NAME%-style placeholder replacement when $vars is non-empty
	 *
	 * @param string $key Translation key to resolve.
	 * @param string $file Relative language file name without .php extension.
	 * @param string $layer Layer identifier. Use 'app' or 'vendor/package'.
	 * @param string $default Fallback value when lookup cannot return a usable value.
	 * @param array $vars Placeholder values mapped to %UPPERCASE_KEY%.
	 * @return string Resolved text or fallback string.
	 * @throws \InvalidArgumentException When $file or $layer is invalid.
	 */
	public function get(string $key, string $file, string $layer = self::APP_LAYER, string $default = '', array $vars = []): string {
		$this->assertValidFile($file);

		$filePath = $this->buildFilePath($file, $layer, $this->language);
		$data = $this->loadFileData($filePath, $file, $key, $layer);

		if (!isset($data[$key]) || $data[$key] === null || $data[$key] === '') {
			$this->logMissingKey($filePath, $key, $layer);

			return $this->applyVars($default, $vars);
		}

		$value = $data[$key];

		if (\is_string($value)) {
			return $this->applyVars($value, $vars);
		}

		if (\is_scalar($value)) {
			return $this->applyVars((string)$value, $vars);
		}

		$this->logNonScalarValue($filePath, $key, $layer, $value);

		return $this->applyVars($default, $vars);
	}


	/**
	 * Validate the language file identifier.
	 *
	 * @param string $file Relative language file name without extension.
	 * @return void
	 * @throws \InvalidArgumentException When the file name is empty or unsafe.
	 */
	private function assertValidFile(string $file): void {
		if (
			$file === '' ||
			\str_contains($file, '..') ||
			!\preg_match(self::FILE_PATTERN, $file)
		) {
			throw new \InvalidArgumentException("Invalid language file name '{$file}'.");
		}
	}


	/**
	 * Build the absolute path to a language file.
	 *
	 * @param string $file Relative language file name without extension.
	 * @param string $layer Layer identifier. Use 'app' or 'vendor/package'.
	 * @param string $language Active language code.
	 * @return string Absolute file path.
	 * @throws \InvalidArgumentException When the layer identifier is invalid.
	 */
	private function buildFilePath(string $file, string $layer, string $language): string {
		if ($layer === self::APP_LAYER) {
			return self::APP_LANGUAGE_PATH . '/' . $language . '/' . $file . '.php';
		}

		$slug = \trim($layer, '/');

		if (!\preg_match(self::LAYER_PATTERN, $slug)) {
			throw new \InvalidArgumentException(
				"Invalid text layer '{$layer}'. Expected 'vendor/package', e.g. 'citomni/auth'."
			);
		}

		return self::VENDOR_PATH . '/' . $slug . '/language/' . $language . '/' . $file . '.php';
	}


	/**
	 * Load and cache a language file payload.
	 *
	 * Behavior:
	 * - Includes the file only once per absolute path
	 * - Logs missing files exactly once on first access
	 * - Normalizes missing or invalid payloads to an empty array
	 * - Logs invalid payloads once on first load
	 *
	 * Notes:
	 * - Missing files are treated as empty arrays for compatibility
	 * - Files that return non-array values are treated as operational content issues
	 *
	 * @param string $filePath Absolute language file path.
	 * @param string $file Relative language file name without extension.
	 * @param string $key Translation key for diagnostics.
	 * @param string $layer Layer identifier for diagnostics.
	 * @return array<string, mixed> Normalized language data.
	 */
	private function loadFileData(string $filePath, string $file, string $key, string $layer): array {
		if (isset($this->cache[$filePath])) {
			return $this->cache[$filePath];
		}

		if (!\is_file($filePath)) {
			$this->logMissingFile($filePath, $file, $key, $layer);
			$this->cache[$filePath] = [];

			return $this->cache[$filePath];
		}

		$data = include $filePath;

		if (!\is_array($data)) {
			$this->logInvalidFilePayload($filePath, $layer, $data);
			$this->cache[$filePath] = [];

			return $this->cache[$filePath];
		}

		$this->cache[$filePath] = $data;

		return $this->cache[$filePath];
	}


	/**
	 * Apply placeholder replacement using %UPPERCASE_KEY% tokens.
	 *
	 * @param string $text Source text.
	 * @param array $vars Placeholder values.
	 * @return string Text with placeholder substitution applied.
	 */
	private function applyVars(string $text, array $vars): string {
		if ($vars === []) {
			return $text;
		}

		$map = [];

		foreach ($vars as $key => $value) {
			$map['%' . \strtoupper((string)$key) . '%'] = (string)$value;
		}

		if ($map === []) {
			return $text;
		}

		return \strtr($text, $map);
	}


	/**
	 * Log that a language file is missing.
	 *
	 * This is intentionally a single logged condition. The file is then cached
	 * as an empty dataset so subsequent lookups remain cheap and deterministic.
	 *
	 * @param string $filePath Absolute language file path.
	 * @param string $file Relative language file name without extension.
	 * @param string $key Translation key.
	 * @param string $layer Layer identifier.
	 * @return void
	 */
	private function logMissingFile(string $filePath, string $file, string $key, string $layer): void {
		$this->app->log->write(
			self::LOG_FILE,
			'txt.missing_file',
			'Language file was not found. Caching empty dataset and returning default for lookups.',
			[
				'file_path' => $filePath,
				'file' => $file,
				'key' => $key,
				'layer' => $layer,
				'language' => $this->language,
			] + $this->getRuntimeContext()
		);
	}


	/**
	 * Log that a language file returned an invalid payload.
	 *
	 * @param string $filePath Absolute language file path.
	 * @param string $layer Layer identifier.
	 * @param mixed $payload Raw included payload.
	 * @return void
	 */
	private function logInvalidFilePayload(string $filePath, string $layer, mixed $payload): void {
		$this->app->log->write(
			self::LOG_FILE,
			'txt.invalid_file_payload',
			'Language file did not return a valid PHP array. Falling back to empty dataset.',
			[
				'file_path' => $filePath,
				'layer' => $layer,
				'language' => $this->language,
				'payload_type' => \get_debug_type($payload),
			] + $this->getRuntimeContext()
		);
	}


	/**
	 * Log that a translation key is missing or empty.
	 *
	 * @param string $filePath Absolute language file path.
	 * @param string $key Translation key.
	 * @param string $layer Layer identifier.
	 * @return void
	 */
	private function logMissingKey(string $filePath, string $key, string $layer): void {
		$this->app->log->write(
			self::LOG_FILE,
			'txt.missing_key',
			'Translation key is missing or empty. Returning default value.',
			[
				'file_path' => $filePath,
				'key' => $key,
				'layer' => $layer,
				'language' => $this->language,
			] + $this->getRuntimeContext()
		);
	}


	/**
	 * Log that a resolved translation value is non-scalar.
	 *
	 * @param string $filePath Absolute language file path.
	 * @param string $key Translation key.
	 * @param string $layer Layer identifier.
	 * @param mixed $value Resolved raw value.
	 * @return void
	 */
	private function logNonScalarValue(string $filePath, string $key, string $layer, mixed $value): void {
		$this->app->log->write(
			self::LOG_FILE,
			'txt.non_scalar_value',
			'Translation value is non-scalar. Returning default value.',
			[
				'file_path' => $filePath,
				'key' => $key,
				'layer' => $layer,
				'language' => $this->language,
				'value_type' => \get_debug_type($value),
			] + $this->getRuntimeContext()
		);
	}


	/**
	 * Build lightweight runtime context for log entries.
	 *
	 * @return array<string, string>
	 */
	private function getRuntimeContext(): array {
		$requestUri = $_SERVER['REQUEST_URI'] ?? null;

		if (\is_string($requestUri) && $requestUri !== '') {
			return [
				'runtime' => 'http',
				'request_uri' => $requestUri,
			];
		}

		return [
			'runtime' => 'cli',
		];
	}


}
