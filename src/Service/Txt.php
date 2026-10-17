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
 * Txt: Service that looks up translated texts in the language files of the app and its packages.
 *
 * Gives every caller the same lookup with a fallback: Missing or unusable content returns the
 * caller's default and is logged, so a page stays readable while the log names what to fix.
 * The texts belong to the language files of each layer; writing the log lines belongs to the
 * log service.
 *
 * Rules:
 * - Throw only for invalid cfg and invalid arguments. A content problem in a language file
 *   returns the default and writes a log line.
 * - Load each language file at most once per instance.
 */
class Txt extends BaseService {

	/** Log file for content problems. */
	private const LOG_FILE = 'txt.jsonl';

	/** Layer name of the app's own language files. */
	private const APP_LAYER = 'app';

	/** Directory of the app's language files, one subdirectory per language. */
	private const APP_LANGUAGE_PATH = \CITOMNI_APP_PATH . '/language';

	/** Composer vendor directory, which holds the language files of package layers. */
	private const VENDOR_PATH = \CITOMNI_APP_PATH . '/vendor';

	/** Valid language file name: letters, digits, /, _ and -, starting and ending with a letter or digit. */
	private const FILE_PATTERN = '~^[A-Za-z0-9](?:[A-Za-z0-9/_-]*[A-Za-z0-9])?$~';

	/** Valid package layer: a Composer package name, vendor/package. */
	private const LAYER_PATTERN = '~^[a-z0-9._-]+/[a-z0-9._-]+$~i';

	/** Valid locale.language: xx or xx_YY, such as da or da_DK. */
	private const LANGUAGE_PATTERN = '~^[a-z]{2}(?:_[A-Z]{2})?$~';


	/** @var array<string,array<string,mixed>> Loaded language files by absolute path; [] for a missing or unusable file. */
	private array $cache = [];


	/** Active language from cfg locale.language. */
	private string $language;


	/**
	 * Read and check the active language once.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\TxtConfigException  When cfg locale.language is missing or not of the form xx or xx_YY.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		// locale.language is application cfg, so its absence is checked rather than assumed.
		$language = $this->app->cfg->locale->language ?? null;
		if ($language === null) {
			throw new TxtConfigException('Missing required config: locale.language');
		}
		$language = (string)$language;

		if (!\preg_match(self::LANGUAGE_PATTERN, $language)) {
			throw new TxtConfigException(
				"Invalid locale.language '{$language}'. Expected 'xx' or 'xx_YY' (e.g. 'da' or 'da_DK')."
			);
		}

		$this->language = $language;
		$this->options = [];
	}


	/**
	 * Look up one text in the active language.
	 *
	 * Behavior:
	 * - Returns the value of the key, with the placeholders applied. A scalar value that is not
	 *   a string is cast to string.
	 * - Returns $default, with the placeholders applied, when the file is missing or does not
	 *   return an array, or when the value is missing, null, '' or not scalar.
	 * - Each fallback to $default writes a txt.missing_key or txt.non_scalar_value line to
	 *   txt.jsonl. A file that is missing or does not return an array also writes one
	 *   txt.missing_file or txt.invalid_file_payload line, the first time this instance loads it.
	 * - A placeholder is a %KEY% token, with each key of $vars uppercased.
	 *
	 * Typical usage:
	 *   $label = $this->app->txt->get('label_save', 'common', 'app', 'Save');
	 *
	 * @param  string  $key  Key in the language file.
	 * @param  string  $file  File name without .php: letters, digits, /, _ and -, starting and ending with a letter or digit.
	 * @param  string  $layer  'app' for the app's language files, or a package name vendor/package for the package's.
	 * @param  string  $default  Text when the lookup finds no usable value.
	 * @param  array<array-key,scalar|\Stringable|null>  $vars  Placeholder values by placeholder name.
	 * @return string
	 * @throws \InvalidArgumentException  When $file or $layer is malformed.
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
	 * Reject a malformed language file name.
	 *
	 * @param  string  $file  File name without .php.
	 * @return void
	 * @throws \InvalidArgumentException  When the name is empty, contains .. or does not match FILE_PATTERN.
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
	 * Return the absolute path of a language file.
	 *
	 * Behavior:
	 * - Layer 'app' resolves below APP_LANGUAGE_PATH; any other layer below the package's
	 *   language directory in VENDOR_PATH. Slashes around a package layer are ignored.
	 *
	 * @param  string  $file  Checked file name without .php.
	 * @param  string  $layer  'app' or vendor/package.
	 * @param  string  $language  Active language.
	 * @return string
	 * @throws \InvalidArgumentException  When the layer is neither 'app' nor a valid vendor/package.
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
	 * Return the texts of one language file, loading it on first use.
	 *
	 * Behavior:
	 * - Includes each file at most once per instance.
	 * - A missing file, or one that does not return an array, yields [] and writes one log line
	 *   the first time.
	 *
	 * @param  string  $filePath  Absolute file path.
	 * @param  string  $file  File name without .php, for the log.
	 * @param  string  $key  Requested key, for the log.
	 * @param  string  $layer  Layer, for the log.
	 * @return array<string,mixed>  Texts by key.
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
	 * Replace the %KEY% placeholders of a text, with each key of $vars uppercased.
	 *
	 * @param  string  $text  Text with placeholders.
	 * @param  array<array-key,scalar|\Stringable|null>  $vars  Placeholder values by placeholder name.
	 * @return string
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
	 * Write a txt.missing_file line for a language file that does not exist.
	 *
	 * @param  string  $filePath  Absolute file path.
	 * @param  string  $file  File name without .php.
	 * @param  string  $key  Requested key.
	 * @param  string  $layer  Layer.
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
	 * Write a txt.invalid_file_payload line for a language file that does not return an array.
	 *
	 * @param  string  $filePath  Absolute file path.
	 * @param  string  $layer  Layer.
	 * @param  mixed  $payload  What the file returned.
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
	 * Write a txt.missing_key line for a key that is missing, null or ''.
	 *
	 * @param  string  $filePath  Absolute file path.
	 * @param  string  $key  Requested key.
	 * @param  string  $layer  Layer.
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
	 * Write a txt.non_scalar_value line for a value that is not scalar.
	 *
	 * @param  string  $filePath  Absolute file path.
	 * @param  string  $key  Requested key.
	 * @param  string  $layer  Layer.
	 * @param  mixed  $value  The value found.
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
	 * Return the runtime fields of a log line.
	 *
	 * @return array{runtime:string,request_uri?:string}  runtime is http, with the request URI, when $_SERVER has a REQUEST_URI; cli otherwise.
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
