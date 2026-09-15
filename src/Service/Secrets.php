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
 * Secrets: Read application secrets from the app-local secret store.
 *
 * The store is a side-effect-free, environment-specific PHP file under
 * /var/secrets that returns a flat string-to-string map. The active file is
 * selected from CITOMNI_ENVIRONMENT:
 * - dev:   /var/secrets/app.secret.dev.php
 * - stage: /var/secrets/app.secret.stage.php
 * - prod:  /var/secrets/app.secret.prod.php
 *
 * Keys may use dotted names for readable namespacing, for example
 * `mail.smtp.password`.
 *
 * Behavior:
 * - Resolves the active secret file from CITOMNI_ENVIRONMENT.
 * - Loads that secret file lazily on the first has() or get() call.
 * - Memoizes the validated map for the lifetime of the App instance.
 * - Treats a missing secret file as an empty store.
 * - Fails fast when an existing secret file is invalid or unreadable.
 * - Never exposes the complete secret map through the public API or debug output.
 *
 * Notes:
 * - Real secret files must remain outside version control.
 * - No fallback occurs between environments; each environment has its own store.
 * - Secret rotation is visible to new App instances; an existing instance keeps
 *   its already loaded values for deterministic request/process behavior.
 * - No SQL or transport concerns.
 *
 * Typical usage:
 *   $password = $this->app->secrets->get('mail.smtp.password');
 *   if ($this->app->secrets->has('service.optional_token')) {
 *       $token = $this->app->secrets->get('service.optional_token');
 *   }
 */
final class Secrets extends BaseService {

	private bool $loaded = false;

	/** @var array<string,string> */
	private array $secrets = [];


	/**
	 * Determine whether a secret exists.
	 *
	 * @param string $key Exact secret key.
	 * @return bool True when the secret exists.
	 * @throws \InvalidArgumentException When the key is empty or padded with whitespace.
	 * @throws \RuntimeException When the environment is invalid or the secret file cannot be read.
	 * @throws \UnexpectedValueException When the secret file or its contents are invalid.
	 */
	public function has(string $key): bool {
		$this->validateKey($key);
		$this->load();

		return \array_key_exists($key, $this->secrets);
	}


	/**
	 * Return one secret value.
	 *
	 * @param string $key Exact secret key.
	 * @return string Secret value.
	 * @throws \InvalidArgumentException When the key is empty or padded with whitespace.
	 * @throws \OutOfBoundsException When the requested secret does not exist.
	 * @throws \RuntimeException When the environment is invalid or the secret file cannot be read.
	 * @throws \UnexpectedValueException When the secret file or its contents are invalid.
	 */
	public function get(string $key): string {
		$this->validateKey($key);
		$this->load();

		if (!\array_key_exists($key, $this->secrets)) {
			throw new \OutOfBoundsException("Unknown secret: '{$key}'");
		}

		return $this->secrets[$key];
	}


	/**
	 * Return a safe debug representation without secret values or keys.
	 *
	 * @return array{loaded:bool} Safe debug state.
	 */
	public function __debugInfo(): array {
		return [
			'loaded' => $this->loaded,
		];
	}


	/**
	 * Load and validate the app-local secret store once.
	 *
	 * Behavior:
	 * - A missing file produces an empty store.
	 * - An existing file must be a readable regular PHP file returning
	 *   array<string,string>.
	 *
	 * @return void
	 * @throws \RuntimeException When the secret file exists but cannot be read.
	 * @throws \UnexpectedValueException When the secret file or its contents are invalid.
	 */
	private function load(): void {
		if ($this->loaded) {
			return;
		}

		$path = $this->secretFile();

		if (!\file_exists($path)) {
			$this->loaded = true;
			return;
		}

		if (!\is_file($path)) {
			throw new \UnexpectedValueException("Secrets path is not a regular file: {$path}");
		}

		if (!\is_readable($path)) {
			throw new \RuntimeException("Secrets file is not readable: {$path}");
		}

		$data = require $path;

		if (!\is_array($data)) {
			throw new \UnexpectedValueException('Secrets file must return an array.');
		}

		foreach ($data as $key => $value) {
			if (
				!\is_string($key)
				|| $key === ''
				|| \trim($key) !== $key
				|| !\is_string($value)
			) {
				throw new \UnexpectedValueException(
					'Secrets file must return a non-empty, unpadded string-keyed map of string values.'
				);
			}
		}

		$this->secrets = $data;
		$this->loaded = true;
	}


	/**
	 * Resolve the secret file for the active application environment.
	 *
	 * @return string Absolute secret file path.
	 * @throws \RuntimeException When CITOMNI_ENVIRONMENT is missing or unsupported.
	 */
	private function secretFile(): string {
		if (!\defined('CITOMNI_ENVIRONMENT')) {
			throw new \RuntimeException('CITOMNI_ENVIRONMENT is required to resolve application secrets.');
		}

		$environment = (string)\constant('CITOMNI_ENVIRONMENT');

		$file = match ($environment) {
			'dev' => 'app.secret.dev.php',
			'stage' => 'app.secret.stage.php',
			'prod' => 'app.secret.prod.php',
			default => throw new \RuntimeException(
				"Unsupported CITOMNI_ENVIRONMENT '{$environment}' for application secrets."
			),
		};

		return $this->app->getAppRoot() . '/var/secrets/' . $file;
	}


	/**
	 * Validate a public secret lookup key without normalizing it.
	 *
	 * @param string $key Secret key supplied by the caller.
	 * @return void
	 * @throws \InvalidArgumentException When the key is empty or padded with whitespace.
	 */
	private function validateKey(string $key): void {
		if ($key === '' || \trim($key) !== $key) {
			throw new \InvalidArgumentException('Secret key must be a non-empty string without leading or trailing whitespace.');
		}
	}


}
