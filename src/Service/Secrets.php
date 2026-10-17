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
 * Secrets: Service that reads application secrets from the app's secret store.
 *
 * Keeps credentials out of cfg. Each environment has a store of its own: a PHP file under the
 * app's var/secrets that returns a flat map of string keys to string values, such as
 * mail.smtp.password. The file is executed with require, so it must be free of side effects.
 * Writing and rotating the files, and keeping them out of version control, belong to the
 * app's deployment.
 *
 * Rules:
 * - Expose a value only to the caller that asks for its key; never the map, the keys, or a
 *   value in debug output.
 * - Load the store at most once per instance, so an instance keeps the values it first read.
 * - Never fall back to the store of another environment.
 */
class Secrets extends BaseService {

	/** Whether the store of this instance has been loaded. */
	private bool $loaded = false;

	/** @var array<string,string> Secret values by key. */
	private array $secrets = [];


	/**
	 * Tell whether a secret exists.
	 *
	 * Behavior:
	 * - The first call to has() or get() loads the store of CITOMNI_ENVIRONMENT. A missing
	 *   store file is an empty store.
	 *
	 * Typical usage:
	 *   if ($this->app->secrets->has('service.optional_token')) {
	 *       $token = $this->app->secrets->get('service.optional_token');
	 *   }
	 *
	 * @param  string  $key  Exact key; never trimmed.
	 * @return bool
	 * @throws \InvalidArgumentException  When the key is empty or has leading or trailing whitespace.
	 * @throws \RuntimeException  When CITOMNI_ENVIRONMENT is missing or not dev, stage or prod, or the store file is not readable.
	 * @throws \UnexpectedValueException  When the store path is not a regular file, or the file returns anything but a map of non-empty, unpadded string keys to string values.
	 */
	public function has(string $key): bool {
		$this->validateKey($key);
		$this->load();

		return \array_key_exists($key, $this->secrets);
	}


	/**
	 * Return one secret.
	 *
	 * Typical usage:
	 *   $password = $this->app->secrets->get('mail.smtp.password');
	 *
	 * @param  string  $key  Exact key; never trimmed.
	 * @return string
	 * @throws \InvalidArgumentException  When the key is empty or has leading or trailing whitespace.
	 * @throws \OutOfBoundsException  When the store holds no secret with the key.
	 * @throws \RuntimeException  When CITOMNI_ENVIRONMENT is missing or not dev, stage or prod, or the store file is not readable.
	 * @throws \UnexpectedValueException  When the store path is not a regular file, or the file returns anything but a map of non-empty, unpadded string keys to string values.
	 * @see \CitOmni\Infrastructure\Service\Secrets::has()  When the store is loaded.
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
	 * Describe the service for var_dump() without keys or values.
	 *
	 * @return array{loaded:bool}
	 */
	public function __debugInfo(): array {
		return [
			'loaded' => $this->loaded,
		];
	}


	/**
	 * Load and check the store of the active environment, once per instance.
	 *
	 * Behavior:
	 * - A missing file loads as an empty store.
	 *
	 * @return void
	 * @throws \RuntimeException  When the environment is invalid or the file is not readable.
	 * @throws \UnexpectedValueException  When the path is not a regular file, or the file returns anything but a map of non-empty, unpadded string keys to string values.
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
	 * Return the path of the store file for CITOMNI_ENVIRONMENT.
	 *
	 * @return string  var/secrets/app.secret.ENV.php under the app root, where ENV is dev, stage or prod.
	 * @throws \RuntimeException  When CITOMNI_ENVIRONMENT is not defined or not dev, stage or prod.
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
	 * Reject a key that is empty or has leading or trailing whitespace, without changing it.
	 *
	 * @param  string  $key  Key as the caller passed it.
	 * @return void
	 * @throws \InvalidArgumentException  When the key is empty or padded.
	 */
	private function validateKey(string $key): void {
		if ($key === '' || \trim($key) !== $key) {
			throw new \InvalidArgumentException('Secret key must be a non-empty string without leading or trailing whitespace.');
		}
	}


}
