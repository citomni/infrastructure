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

/*
 * citomni/kernel doubles for the suites. Not a suite of its own.
 *
 * Suites, workers and probes require this file instead of a Composer bootstrap.
 * Each runs in its own process, so the real kernel classes are never loaded
 * next to these.
 *
 * - CitOmni\Kernel\Cfg mirrors the kernel's read semantics: unknown keys throw
 *   \OutOfBoundsException, isset() is true for every present key (also null),
 *   empty and associative arrays become nested nodes, lists stay arrays. Db tests
 *   "instanceof Cfg", so the FQCN must be the kernel's.
 * - CitOmni\Kernel\Service\BaseService keeps the app and options, then calls init().
 * - CitOmni\Kernel\Repository\BaseRepository keeps the app, then calls init().
 * - CitOmni\Kernel\Controller\BaseController keeps the app and route config, then
 *   calls init().
 * - CitOmni\Infrastructure\Tests\Support\App holds cfg and the services a suite
 *   registers. Like the kernel App it resolves services through __get(), throws on
 *   unknown ids and returns CITOMNI_APP_PATH from getAppRoot() unless a suite
 *   passes its own root.
 * - LogRecorder stands in for the log service and keeps every write.
 * - MemorySecrets stands in for the secrets service. It serves secrets from memory,
 *   so a password from CITOMNI_TEST_PASSWORD never touches the disk.
 * - mergeLastWins() layers cfg overrides on the package baseline the way the kernel
 *   merges cfg: associative arrays merge deeply, lists and scalars are replaced,
 *   and so is a node overridden with an empty array.
 */

namespace CitOmni\Kernel {

	/** Read-only configuration node with the kernel Cfg read semantics. */
	final class Cfg {

		public function __construct(private array $data) {
		}

		public function __get(string $key): mixed {
			if (!\array_key_exists($key, $this->data)) {
				throw new \OutOfBoundsException("Unknown cfg key: '{$key}'");
			}
			$value = $this->data[$key];
			if (\is_array($value) && ($value === [] || !\array_is_list($value))) {
				return new self($value);
			}
			return $value;
		}

		public function __isset(string $key): bool {
			return \array_key_exists($key, $this->data);
		}

		public function toArray(): array {
			return $this->data;
		}
	}
}

namespace CitOmni\Kernel\Service {

	/** Base service double: keeps the app and options, then calls init() when defined. */
	abstract class BaseService {

		protected object $app;

		protected array $options;

		public function __construct(object $app, array $options = []) {
			$this->app = $app;
			$this->options = $options;
			if (\method_exists($this, 'init')) {
				$this->init();
			}
		}
	}
}

namespace CitOmni\Kernel\Repository {

	/** Base repository double: keeps the app, then calls init() when defined. */
	abstract class BaseRepository {

		protected object $app;

		public function __construct(object $app) {
			$this->app = $app;
			if (\method_exists($this, 'init')) {
				$this->init();
			}
		}
	}
}

namespace CitOmni\Kernel\Controller {

	/** Base controller double: keeps the app and route config, then calls init() when defined. */
	abstract class BaseController {

		protected object $app;

		protected array $routeConfig = [];

		public function __construct(object $app, array $routeConfig = []) {
			$this->app = $app;
			$this->routeConfig = $routeConfig;
			if (\method_exists($this, 'init')) {
				$this->init();
			}
		}
	}
}

namespace CitOmni\Infrastructure\Tests\Support {

	use CitOmni\Kernel\Cfg;

	/** App double: cfg and the services registered by the suite. */
	final class App {

		public readonly Cfg $cfg;

		/** @var array<string, object> */
		private array $services = [];

		/**
		 * @param array<string, mixed> $cfg      Merged cfg for this app.
		 * @param string|null          $appRoot  Application root; null means CITOMNI_APP_PATH, as in the kernel.
		 */
		public function __construct(array $cfg = [], private ?string $appRoot = null) {
			$this->cfg = new Cfg($cfg);
		}

		/** Register a service instance under its service id. */
		public function set(string $id, object $service): void {
			$this->services[$id] = $service;
		}

		public function __get(string $id): object {
			return $this->services[$id] ?? throw new \RuntimeException("Unknown app component: app->{$id}");
		}

		public function hasService(string $id): bool {
			return isset($this->services[$id]);
		}

		public function getAppRoot(): string {
			return $this->appRoot ?? \CITOMNI_APP_PATH;
		}
	}


	/** Log service double: keeps every write in order. */
	final class LogRecorder {

		/** @var list<array{file: ?string, category: string, message: string|array|object, context: array}> */
		public array $records = [];

		public function write(?string $file, string $category, string|array|object $message, array $context = []): void {
			$this->records[] = ['file' => $file, 'category' => $category, 'message' => $message, 'context' => $context];
		}

		/**
		 * Records with the given category, in order.
		 *
		 * @return list<array{file: ?string, category: string, message: string|array|object, context: array}>
		 */
		public function category(string $category): array {
			return \array_values(\array_filter($this->records, static fn(array $r): bool => $r['category'] === $category));
		}
	}


	/** Secrets service double: the Secrets lookup contract, served from memory. */
	final class MemorySecrets {

		/** @var list<string> Keys requested through get(), in order. */
		public array $reads = [];

		/** @param array<string, string> $secrets */
		public function __construct(private array $secrets = []) {
		}

		public function has(string $key): bool {
			return \array_key_exists($key, $this->secrets);
		}

		public function get(string $key): string {
			$this->reads[] = $key;
			if (!\array_key_exists($key, $this->secrets)) {
				throw new \OutOfBoundsException("Unknown secret: '{$key}'");
			}
			return $this->secrets[$key];
		}

		/** Keep secret values out of failure messages and dumps. */
		public function __debugInfo(): array {
			return ['reads' => $this->reads];
		}
	}


	/**
	 * Layer a cfg override on a baseline: associative arrays merge deeply, lists and
	 * scalars are replaced, as in CitOmni\Kernel\Arr::mergeAssocLastWins().
	 */
	function mergeLastWins(array $base, array $override): array {
		foreach ($override as $key => $value) {
			if (
				\is_string($key)
				&& \is_array($value) && !\array_is_list($value)
				&& \is_array($base[$key] ?? null) && !\array_is_list($base[$key])
			) {
				$base[$key] = mergeLastWins($base[$key], $value);
				continue;
			}
			$base[$key] = $value;
		}
		return $base;
	}
}
