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

use CitOmni\Infrastructure\Exception\CurlConfigException;
use CitOmni\Infrastructure\Exception\CurlExecException;
use CitOmni\Infrastructure\Exception\CurlResponseParseException;
use CitOmni\Kernel\Service\BaseService;

/**
 * Execute generic outbound requests via PHP cURL.
 *
 * Behavior:
 * - Executes one outbound request per call with explicit, deterministic options.
 * - Throws on transport and configuration failures.
 * - Returns HTTP responses normally, including 4xx/5xx statuses.
 * - Supports file-based cookie persistence for login and session flows.
 *
 * Notes:
 * - This service is transport-only. It does not know JSON schemas, APIs, or business rules.
 * - Request and response bodies are handled as raw strings.
 * - Response headers are parsed into a lowercase associative array.
 *
 * Typical usage:
 *   $response = $this->app->curl->execute([
 *       'url' => 'https://api.example.com/v1/items',
 *       'method' => 'POST',
 *       'headers' => [
 *           'Accept: application/json',
 *           'Content-Type: application/json',
 *       ],
 *       'body' => '{"hello":"world"}',
 *   ]);
 *
 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When the request format is invalid.
 * @throws \CitOmni\Infrastructure\Exception\CurlExecException When cURL fails at transport level.
 * @throws \CitOmni\Infrastructure\Exception\CurlResponseParseException When a captured response cannot be parsed as expected.
 */
final class Curl extends BaseService {

	/** @var array<string,mixed> */
	private array $defaults = [];

	/** @var bool */
	private bool $logErrors = true;

	/** @var bool */
	private bool $logSuccess = false;

	/** @var string|null */
	private ?string $defaultLogFile = null;






	// ----------------------------------------------------------------
	// Service bootstrap
	// ----------------------------------------------------------------

	/**
	 * Initialize service defaults from cfg and service options.
	 *
	 * @return void
	 */
	protected function init(): void {

		$cfg = $this->app->cfg->curl;

		$this->defaults = [
			'method'            => 'GET',
			'headers'           => [],
			'body'              => null,
			'query'             => [],
			'timeout'           => (int)($cfg->timeout ?? 30),
			'connect_timeout'   => (int)($cfg->connect_timeout ?? 10),
			'follow_redirects'  => (bool)($cfg->follow_redirects ?? false),
			'max_redirects'     => (int)($cfg->max_redirects ?? 0),
			'user_agent'        => (string)($cfg->user_agent ?? 'CitOmni Curl'),
			'verify_peer'       => (bool)($cfg->verify_peer ?? true),
			'verify_host'       => (int)($cfg->verify_host ?? 2),
			'ca_info'           => $cfg->ca_info ?? null,
			'proxy'             => $cfg->proxy ?? null,
			'cookie_store'      => null,
			'cookie_file'       => null,
			'cookie_jar'        => null,
			'return_headers'    => (bool)($cfg->return_headers ?? true),
			'capture_info'      => (bool)($cfg->capture_info ?? true),
			'auto_referer'      => (bool)($cfg->auto_referer ?? true),
			'referer'           => null,
			'basic_auth'        => null,
			'bearer_token'      => null,
			'curl_options'      => [],
			'log_file'          => $cfgLogFile !== null ? (string)$cfgLogFile : null,
			'log_context'       => [],
		];

		$this->logErrors      = (bool)($cfg->log_errors ?? true);
		$this->logSuccess     = (bool)($cfg->log_success ?? false);
		$this->defaultLogFile = $cfg->log_file ?? null;

		if (isset($this->options['defaults']) && \is_array($this->options['defaults'])) {
			foreach ($this->options['defaults'] as $key => $value) {
				$this->defaults[(string)$key] = $value;
			}
		}

		if (isset($this->options['log_errors'])) {
			$this->logErrors = (bool)$this->options['log_errors'];
		}

		if (isset($this->options['log_success'])) {
			$this->logSuccess = (bool)$this->options['log_success'];
		}

		if (\array_key_exists('log_file', $this->options)) {
			$this->defaultLogFile = $this->options['log_file'] !== null ? (string)$this->options['log_file'] : null;
		}
	}







	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Execute one outbound cURL request.
	 *
	 * Request keys:
	 * - url (required)
	 * - method
	 * - headers
	 * - body
	 * - query
	 * - timeout
	 * - connect_timeout
	 * - follow_redirects
	 * - max_redirects
	 * - user_agent
	 * - verify_peer
	 * - verify_host
	 * - ca_info
	 * - proxy
	 * - cookie_store
	 * - cookie_file
	 * - cookie_jar
	 * - return_headers
	 * - capture_info
	 * - auto_referer
	 * - referer
	 * - basic_auth
	 * - bearer_token
	 * - curl_options
	 * - log_file
	 * - log_context
	 *
	 * @param array<string,mixed> $request Request configuration.
	 * @return array<string,mixed> Normalized response payload.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When the request is invalid.
	 * @throws \CitOmni\Infrastructure\Exception\CurlExecException When cURL transport fails.
	 * @throws \CitOmni\Infrastructure\Exception\CurlResponseParseException When the received response cannot be parsed as expected.
	 */
	public function execute(array $request): array {

		$request = $this->normalizeRequest($request);

		$handle = \curl_init();
		if ($handle === false) {
			throw new CurlExecException('Failed to initialize cURL handle.');
		}

		$options = $this->buildCurlOptions($request);

		if (\curl_setopt_array($handle, $options) !== true) {
			\curl_close($handle);
			throw new CurlExecException('Failed to apply cURL options.');
		}

		$result = \curl_exec($handle);
		if ($result === false) {
			$errno = \curl_errno($handle);
			$error = \curl_error($handle);
			$info  = \curl_getinfo($handle);
			\curl_close($handle);

			$this->logError($request, $errno, $error, \is_array($info) ? $info : []);

			throw new CurlExecException(
				'cURL transport failed: ' . $error,
				$errno,
				$request['method'],
				$request['url'],
				\is_array($info) ? $info : []
			);
		}

		$info = \curl_getinfo($handle);
		\curl_close($handle);

		if (!\is_string($result)) {
			$this->logError($request, 0, 'Unexpected non-string cURL result.', \is_array($info) ? $info : []);
			throw new CurlExecException(
				'Unexpected non-string cURL result.',
				0,
				$request['method'],
				$request['url'],
				\is_array($info) ? $info : []
			);
		}

		$response = $this->buildResponse($request, $result, \is_array($info) ? $info : []);

		$this->logSuccess($request, $response);

		return $response;
	}







	// ----------------------------------------------------------------
	// Request normalization
	// ----------------------------------------------------------------

	/**
	 * Normalize and validate one request array.
	 *
	 * @param array<string,mixed> $request Raw request.
	 * @return array<string,mixed> Normalized request.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When the request is invalid.
	 */
	private function normalizeRequest(array $request): array {

		$request = \array_replace($this->defaults, $request);

		if (!isset($request['url']) || !\is_string($request['url']) || $request['url'] === '') {
			throw new CurlConfigException('Request key "url" is required and must be a non-empty string.');
		}

		$request['url'] = $this->buildUrl($request['url'], $request['query']);

		$request['method'] = \strtoupper(\trim((string)($request['method'] ?? 'GET')));
		if ($request['method'] === '' || !\preg_match('/^[A-Z][A-Z0-9_-]*$/', $request['method'])) {
			throw new CurlConfigException('Request key "method" must be a valid method token.');
		}

		if (!\is_array($request['headers'])) {
			throw new CurlConfigException('Request key "headers" must be an array of header lines.');
		}

		foreach ($request['headers'] as $headerLine) {
			if (!\is_string($headerLine) || $headerLine === '' || !\str_contains($headerLine, ':')) {
				throw new CurlConfigException('Each header must be a non-empty string containing ":".');
			}
		}

		if ($request['body'] !== null && !\is_string($request['body'])) {
			throw new CurlConfigException('Request key "body" must be null or string.');
		}

		if (!\is_int($request['timeout']) && !\is_float($request['timeout']) && !\is_numeric((string)$request['timeout'])) {
			throw new CurlConfigException('Request key "timeout" must be numeric.');
		}

		if (!\is_int($request['connect_timeout']) && !\is_float($request['connect_timeout']) && !\is_numeric((string)$request['connect_timeout'])) {
			throw new CurlConfigException('Request key "connect_timeout" must be numeric.');
		}

		$request['timeout'] = (int)$request['timeout'];
		$request['connect_timeout'] = (int)$request['connect_timeout'];

		if ($request['timeout'] < 0) {
			throw new CurlConfigException('Request key "timeout" must be >= 0.');
		}

		if ($request['connect_timeout'] < 0) {
			throw new CurlConfigException('Request key "connect_timeout" must be >= 0.');
		}

		$request['follow_redirects'] = (bool)$request['follow_redirects'];

		
		$request['max_redirects']    = (int)$request['max_redirects'];
		
		if ($request['max_redirects'] < 0) {
			throw new CurlConfigException('Request key "max_redirects" must be >= 0.');
		}


		$request['verify_peer']      = (bool)$request['verify_peer'];
		$request['verify_host']      = (int)$request['verify_host'];
		$request['return_headers']   = (bool)$request['return_headers'];
		$request['capture_info']     = (bool)$request['capture_info'];
		$request['auto_referer']     = (bool)$request['auto_referer'];

		if ($request['verify_host'] !== 0 && $request['verify_host'] !== 2) {
			throw new CurlConfigException('Request key "verify_host" must be 0 or 2.');
		}

		if ($request['follow_redirects'] === false && $request['max_redirects'] > 0) {
			$request['max_redirects'] = 0;
		}

		if ($request['ca_info'] !== null && (!\is_string($request['ca_info']) || $request['ca_info'] === '')) {
			throw new CurlConfigException('Request key "ca_info" must be null or non-empty string.');
		}

		if ($request['proxy'] !== null && (!\is_string($request['proxy']) || $request['proxy'] === '')) {
			throw new CurlConfigException('Request key "proxy" must be null or non-empty string.');
		}

		if ($request['referer'] !== null && (!\is_string($request['referer']) || $request['referer'] === '')) {
			throw new CurlConfigException('Request key "referer" must be null or non-empty string.');
		}

		if (!\is_array($request['curl_options'])) {
			throw new CurlConfigException('Request key "curl_options" must be an array.');
		}

		if (!\is_array($request['log_context'])) {
			throw new CurlConfigException('Request key "log_context" must be an array.');
		}

		if ($request['log_file'] !== null && (!\is_string($request['log_file']) || $request['log_file'] === '')) {
			throw new CurlConfigException('Request key "log_file" must be null or non-empty string.');
		}

		$request = $this->normalizeAuth($request);
		$request = $this->normalizeCookies($request);
		$request = $this->normalizeHeaders($request);

		return $request;
	}


	/**
	 * Normalize authentication-related request fields.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed> Request with normalized auth settings.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When auth input is invalid.
	 */
	private function normalizeAuth(array $request): array {

		if ($request['basic_auth'] !== null) {
			if (!\is_array($request['basic_auth'])) {
				throw new CurlConfigException('Request key "basic_auth" must be null or array.');
			}

			$username = $request['basic_auth']['username'] ?? null;
			$password = $request['basic_auth']['password'] ?? '';

			if (!\is_string($username) || $username === '') {
				throw new CurlConfigException('Request key "basic_auth.username" must be a non-empty string.');
			}

			if (!\is_string($password)) {
				throw new CurlConfigException('Request key "basic_auth.password" must be a string.');
			}

			$request['basic_auth'] = [
				'username' => $username,
				'password' => $password,
			];
		}

		if ($request['bearer_token'] !== null) {
			if (!\is_string($request['bearer_token']) || $request['bearer_token'] === '') {
				throw new CurlConfigException('Request key "bearer_token" must be null or non-empty string.');
			}
		}

		return $request;
	}


	/**
	 * Normalize cookie-related request fields.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed> Request with normalized cookie settings.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When cookie settings are invalid.
	 */
	private function normalizeCookies(array $request): array {

		$cookieStoreProvided = false;

		if ($request['cookie_store'] !== null) {
			if (!\is_string($request['cookie_store']) || $request['cookie_store'] === '') {
				throw new CurlConfigException('Request key "cookie_store" must be null or non-empty string.');
			}

			$request['cookie_file'] = $request['cookie_store'];
			$request['cookie_jar']  = $request['cookie_store'];
			$cookieStoreProvided    = true;
		}

		if ($request['cookie_file'] !== null) {
			if (!\is_string($request['cookie_file']) || $request['cookie_file'] === '') {
				throw new CurlConfigException('Request key "cookie_file" must be null or non-empty string.');
			}

			if ($cookieStoreProvided === true) {
				$this->ensureCookieWritePathReady($request['cookie_file']);
			} else {
				$this->ensureCookieSourcePathReady($request['cookie_file']);
			}
		}

		if ($request['cookie_jar'] !== null) {
			if (!\is_string($request['cookie_jar']) || $request['cookie_jar'] === '') {
				throw new CurlConfigException('Request key "cookie_jar" must be null or non-empty string.');
			}

			$this->ensureCookieWritePathReady($request['cookie_jar']);
		}

		return $request;
	}


	/**
	 * Normalize request headers.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed> Request with final header list.
	 */
	private function normalizeHeaders(array $request): array {

		$headers     = [];
		$headerNames = [];

		foreach ($request['headers'] as $headerLine) {
			$headers[] = $headerLine;

			$name = \strtolower(\trim((string)\strtok($headerLine, ':')));
			if ($name !== '') {
				$headerNames[$name] = true;
			}
		}

		$hasAuthorizationHeader = isset($headerNames['authorization']);

		if ($request['basic_auth'] !== null && ($request['bearer_token'] !== null || $hasAuthorizationHeader)) {
			throw new CurlConfigException('basic_auth cannot be combined with bearer_token or an explicit Authorization header.');
		}

		if ($request['bearer_token'] !== null && $hasAuthorizationHeader) {
			throw new CurlConfigException('bearer_token cannot be combined with an explicit Authorization header.');
		}

		if ($request['bearer_token'] !== null) {
			$headers[] = 'Authorization: Bearer ' . $request['bearer_token'];
		}

		if (!isset($headerNames['user-agent'])) {
			$request['_resolved_user_agent'] = ($request['user_agent'] !== null && $request['user_agent'] !== '')
				? (string)$request['user_agent']
				: null;
		} else {
			$request['_resolved_user_agent'] = null;
		}

		$request['headers'] = $headers;

		return $request;
	}


	/**
	 * Build a final URL including query parameters.
	 *
	 * @param mixed $url Base URL.
	 * @param mixed $query Query data.
	 * @return string Final URL.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When URL/query input is invalid.
	 */
	private function buildUrl(mixed $url, mixed $query): string {

		if (!\is_string($url) || $url === '') {
			throw new CurlConfigException('Request key "url" must be a non-empty string.');
		}

		if ($query === null || $query === []) {
			return $url;
		}

		if (!\is_array($query)) {
			throw new CurlConfigException('Request key "query" must be an array.');
		}

		$queryString = \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
		if ($queryString === '') {
			return $url;
		}

		$fragment = '';
		$fragmentPos = \strpos($url, '#');

		if ($fragmentPos !== false) {
			$fragment = (string)\substr($url, $fragmentPos);
			$url = (string)\substr($url, 0, $fragmentPos);
		}

		return $url . (\str_contains($url, '?') ? '&' : '?') . $queryString . $fragment;
	}


	/**
	 * Ensure that a cookie source path is usable when it exists.
	 *
	 * Behavior:
	 * - Accepts a missing file and lets cURL proceed without pre-existing cookies.
	 * - Requires a regular readable file when the path already exists.
	 *
	 * @param string $path Cookie file path.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When the existing path is not a readable regular file.
	 */
	private function ensureCookieSourcePathReady(string $path): void {

		if (!\file_exists($path)) {
			return;
		}

		if (!\is_file($path) || !\is_readable($path)) {
			throw new CurlConfigException('Cookie file must be a readable regular file when it exists: ' . $path);
		}
	}


	/**
	 * Ensure that a cookie destination file can be written.
	 *
	 * Behavior:
	 * - Creates the parent directory when needed.
	 * - Accepts an existing writable regular file.
	 * - Creates the file when it does not yet exist.
	 *
	 * @param string $path Cookie file path.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When the path cannot be created or written.
	 */
	private function ensureCookieWritePathReady(string $path): void {
		$directory = \dirname($path);

		if (!\is_dir($directory)) {
			if (!@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
				throw new CurlConfigException('Failed to create cookie directory: ' . $directory);
			}
		}

		if (\file_exists($path)) {
			if (!\is_file($path) || !\is_writable($path)) {
				throw new CurlConfigException('Cookie jar must be a writable file: ' . $path);
			}

			return;
		}

		$handle = @\fopen($path, 'ab');
		if ($handle === false) {
			throw new CurlConfigException('Failed to create cookie file: ' . $path);
		}

		\fclose($handle);
		@chmod($path, 0600);
	}







	// ----------------------------------------------------------------
	// cURL option building
	// ----------------------------------------------------------------

	/**
	 * Build cURL options from a normalized request.
	 *
	 * @param array<string,mixed> $request Normalized request.
	 * @return array<int,mixed> cURL options.
	 */
	private function buildCurlOptions(array $request): array {

		$options = [
			CURLOPT_URL            => $request['url'],
			CURLOPT_CUSTOMREQUEST  => $request['method'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => $request['return_headers'],
			CURLOPT_FOLLOWLOCATION => $request['follow_redirects'],
			CURLOPT_MAXREDIRS      => $request['max_redirects'],
			CURLOPT_TIMEOUT        => $request['timeout'],
			CURLOPT_CONNECTTIMEOUT => $request['connect_timeout'],
			CURLOPT_SSL_VERIFYPEER => $request['verify_peer'],
			CURLOPT_SSL_VERIFYHOST => $request['verify_host'],
			CURLOPT_HTTPHEADER     => $request['headers'],
		];

		if ($request['_resolved_user_agent'] !== null) {
			$options[CURLOPT_USERAGENT] = $request['_resolved_user_agent'];
		}

		if ($request['auto_referer'] === true && $request['follow_redirects'] === true) {
			$options[CURLOPT_AUTOREFERER] = true;
		}

		if ($request['referer'] !== null) {
			$options[CURLOPT_REFERER] = $request['referer'];
		}

		if ($request['ca_info'] !== null) {
			$options[CURLOPT_CAINFO] = $request['ca_info'];
		}

		if ($request['proxy'] !== null) {
			$options[CURLOPT_PROXY] = $request['proxy'];
		}

		if ($request['cookie_file'] !== null) {
			$options[CURLOPT_COOKIEFILE] = $request['cookie_file'];
		}

		if ($request['cookie_jar'] !== null) {
			$options[CURLOPT_COOKIEJAR] = $request['cookie_jar'];
		}

		if ($request['basic_auth'] !== null) {
			$options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
			$options[CURLOPT_USERPWD]  = $request['basic_auth']['username'] . ':' . $request['basic_auth']['password'];
		}

		$method = $request['method'];

		if ($method === 'HEAD') {
			$options[CURLOPT_NOBODY] = true;
		} elseif ($request['body'] !== null) {
			$options[CURLOPT_POSTFIELDS] = $request['body'];
		}

		foreach ($request['curl_options'] as $option => $value) {
			if (\is_int($option)) {
				$options[$option] = $value;
			}
		}

		return $options;
	}








	// ----------------------------------------------------------------
	// Response building
	// ----------------------------------------------------------------

	/**
	 * Build a normalized response payload.
	 *
	 * @param array<string,mixed> $request Normalized request.
	 * @param string $rawResult Raw cURL result.
	 * @param array<string,mixed> $info cURL info.
	 * @return array<string,mixed> Normalized response.
	 * @throws \CitOmni\Infrastructure\Exception\CurlResponseParseException When the response headers and body cannot be split as expected.
	 */
	private function buildResponse(array $request, string $rawResult, array $info): array {

		$headersRaw = '';
		$headers    = [];
		$body       = $rawResult;

		if ($request['return_headers'] === true) {
			$headerSize = isset($info['header_size']) ? (int)$info['header_size'] : 0;

			if ($headerSize < 0 || $headerSize > \strlen($rawResult)) {
				throw new CurlResponseParseException(
					'Failed to split cURL response headers and body.',
					$request['method'],
					$request['url'],
					$info
				);
			}

			$headersRaw = (string)\substr($rawResult, 0, $headerSize);
			$body       = (string)\substr($rawResult, $headerSize);
			$headers    = $this->parseResponseHeaders($headersRaw);
		}

		$statusCode = isset($info['http_code']) ? (int)$info['http_code'] : 0;

		return [
			'request' => [
				'method' => $request['method'],
				'url'    => $request['url'],
			],
			'status_code'     => $statusCode,
			'is_http_success' => ($statusCode >= 200 && $statusCode < 300),
			'headers_raw'     => $headersRaw,
			'headers'         => $headers,
			'body'            => $body,
			'body_bytes'      => \strlen($body),
			'effective_url'   => isset($info['url']) ? (string)$info['url'] : $request['url'],
			'content_type'    => isset($info['content_type']) && \is_string($info['content_type']) ? $info['content_type'] : null,
			'info'            => $request['capture_info'] ? $info : [],
		];
	}


	/**
	 * Parse the last HTTP header block into a lowercase associative array.
	 *
	 * @param string $headersRaw Raw response headers.
	 * @return array<string,string|array<int,string>>
	 */
	private function parseResponseHeaders(string $headersRaw): array {

		if ($headersRaw === '') {
			return [];
		}

		$headers = [];
		$blocks  = \preg_split("/\r\n\r\n|\n\n|\r\r/", \trim($headersRaw), -1, PREG_SPLIT_NO_EMPTY);
		if ($blocks === false || $blocks === []) {
			return [];
		}

		$lastBlock = (string)$blocks[\array_key_last($blocks)];
		$lines     = \preg_split("/\r\n|\n|\r/", $lastBlock);
		if ($lines === false) {
			return [];
		}

		foreach ($lines as $index => $line) {
			if ($index === 0 || $line === '' || !\str_contains($line, ':')) {
				continue;
			}

			[$name, $value] = \explode(':', $line, 2);

			$name  = \strtolower(\trim($name));
			$value = \trim($value);

			if ($name === '') {
				continue;
			}

			if (!\array_key_exists($name, $headers)) {
				$headers[$name] = $value;
				continue;
			}

			if (\is_array($headers[$name])) {
				$headers[$name][] = $value;
				continue;
			}

			$headers[$name] = [
				(string)$headers[$name],
				$value,
			];
		}

		return $headers;
	}








	// ----------------------------------------------------------------
	// Logging
	// ----------------------------------------------------------------

	/**
	 * Log a transport-level cURL failure when logging is enabled and available.
	 *
	 * @param array<string,mixed> $request Normalized request.
	 * @param int $errno cURL errno.
	 * @param string $error cURL error text.
	 * @param array<string,mixed> $info cURL info array.
	 * @return void
	 */
	private function logError(array $request, int $errno, string $error, array $info): void {

		if ($this->logErrors !== true || !$this->app->hasService('log')) {
			return;
		}

		$context = $request['log_context'];
		$context['method']         = $request['method'];
		$context['url']            = $request['url'];
		$context['curl_errno']     = $errno;
		$context['curl_error']     = $error;
		$context['timeout']        = $request['timeout'];
		$context['connect_timeout'] = $request['connect_timeout'];

		if (isset($info['http_code'])) {
			$context['http_code'] = (int)$info['http_code'];
		}

		if (isset($info['total_time'])) {
			$context['total_time'] = $info['total_time'];
		}

		if (isset($info['primary_ip'])) {
			$context['primary_ip'] = $info['primary_ip'];
		}

		$this->app->log->write(
			$this->resolveLogFile($request),
			'curl.error',
			'Outbound cURL transport failed.',
			$context
		);
	}


	/**
	 * Log a successful transfer when success logging is enabled.
	 *
	 * @param array<string,mixed> $request Normalized request.
	 * @param array<string,mixed> $response Normalized response.
	 * @return void
	 */
	private function logSuccess(array $request, array $response): void {

		if ($this->logSuccess !== true || !$this->app->hasService('log')) {
			return;
		}

		$context = $request['log_context'];
		$context['method']      = $request['method'];
		$context['url']         = $request['url'];
		$context['status_code'] = $response['status_code'];

		if (isset($response['info']['total_time'])) {
			$context['total_time'] = $response['info']['total_time'];
		}

		$this->app->log->write(
			$this->resolveLogFile($request),
			'curl.success',
			'Outbound cURL request completed.',
			$context
		);
	}


	/**
	 * Resolve the final log file name for one request.
	 *
	 * @param array<string,mixed> $request Normalized request.
	 * @return string|null Log file name or null for logger default.
	 */
	private function resolveLogFile(array $request): ?string {

		if ($request['log_file'] !== null) {
			return $request['log_file'];
		}

		return $this->defaultLogFile;
	}

}
