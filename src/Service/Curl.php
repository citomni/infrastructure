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
use CitOmni\Kernel\Service\BaseService;

/**
 * Curl: Execute generic outbound requests via PHP cURL.
 *
 * Behavior:
 * - Executes one outbound request per call with explicit, deterministic options.
 * - Throws on transport and configuration failures.
 * - Returns HTTP responses normally, including 4xx/5xx statuses.
 * - Supports file-based cookie persistence for login and session flows.
 * - Reuses DNS lookups and live connections across calls within this service instance
 *   (one request/process) when cfg `curl.reuse_connections` is enabled.
 *
 * Notes:
 * - Transport-only. No JSON schemas, API knowledge, or business rules.
 * - Request and response bodies are handled as raw strings.
 * - Response headers are captured through CURLOPT_HEADERFUNCTION and parsed into a
 *   lowercase associative array for the final response (after redirects, 1xx, and proxy CONNECT).
 *   HTTP trailer fields (after a chunked body) are not treated as headers.
 * - GET, HEAD, and POST use libcurl's native request modes, so redirects follow the
 *   method rules of the redirect status code. Other methods use CURLOPT_CUSTOMREQUEST.
 * - No SQL. No HTTP/CLI transport concerns of the host app.
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
 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the request format is invalid.
 * @throws \CitOmni\Infrastructure\Exception\CurlExecException  When cURL fails at transport level.
 */
final class Curl extends BaseService {

	/** Every accepted request key. Anything else is rejected (typos must not be silently ignored). */
	private const REQUEST_KEYS = [
		'url'              => true,
		'method'           => true,
		'headers'          => true,
		'body'             => true,
		'query'            => true,
		'timeout'          => true,
		'connect_timeout'  => true,
		'follow_redirects' => true,
		'max_redirects'    => true,
		'user_agent'       => true,
		'verify_peer'      => true,
		'verify_host'      => true,
		'ca_info'          => true,
		'proxy'            => true,
		'cookie_store'     => true,
		'cookie_file'      => true,
		'cookie_jar'       => true,
		'return_headers'   => true,
		'capture_info'     => true,
		'auto_referer'     => true,
		'referer'          => true,
		'basic_auth'       => true,
		'bearer_token'     => true,
		'curl_options'     => true,
		'log_file'         => true,
		'log_context'      => true,
	];

	/** @var array<string,mixed> Request defaults merged under every request. */
	private array $defaults = [];

	private bool $logErrors = true;

	private bool $logSuccess = false;

	private ?string $defaultLogFile = null;

	private bool $reuseConnections = true;

	/** Lazily created share handle holding the DNS cache and connection pool. */
	private ?\CurlShareHandle $share = null;


	// ----------------------------------------------------------------
	// Service bootstrap
	// ----------------------------------------------------------------

	/**
	 * Initialize request defaults and logging policy from cfg and service options.
	 *
	 * Behavior:
	 * - Reads the package-owned `curl` cfg node (baseline shipped in Registry::CFG_HTTP / CFG_CLI).
	 * - Applies service options on top: `defaults` (array), `log_errors`, `log_success`, `log_file`.
	 *
	 * Notes:
	 * - `timeout`, `connect_timeout`, `verify_peer`, and `verify_host` are passed through uncast and
	 *   validated per request, so fractional seconds survive and invalid cfg fails loudly instead of
	 *   being silently cast (0 means "no timeout" / "no host verification" in libcurl).
	 * - No IO. The share handle is created lazily on the first request.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When service option `defaults` contains unknown request keys.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->curl;

		$this->defaults = [
			'method'           => 'GET',
			'headers'          => [],
			'body'             => null,
			'query'            => [],
			'timeout'          => $cfg->timeout ?? 30,
			'connect_timeout'  => $cfg->connect_timeout ?? 10,
			'follow_redirects' => (bool)($cfg->follow_redirects ?? false),
			'max_redirects'    => (int)($cfg->max_redirects ?? 10),
			'user_agent'       => (string)($cfg->user_agent ?? 'CitOmni cURL'),
			'verify_peer'      => $cfg->verify_peer ?? true,
			'verify_host'      => $cfg->verify_host ?? 2,
			'ca_info'          => $cfg->ca_info ?? null,
			'proxy'            => $cfg->proxy ?? null,
			'cookie_store'     => null,
			'cookie_file'      => null,
			'cookie_jar'       => null,
			'return_headers'   => (bool)($cfg->return_headers ?? true),
			'capture_info'     => (bool)($cfg->capture_info ?? true),
			'auto_referer'     => (bool)($cfg->auto_referer ?? true),
			'referer'          => null,
			'basic_auth'       => null,
			'bearer_token'     => null,
			'curl_options'     => [],
			'log_file'         => null,
			'log_context'      => [],
		];

		$cfgLogFile = $cfg->log_file ?? null;

		$this->logErrors        = (bool)($cfg->log_errors ?? true);
		$this->logSuccess       = (bool)($cfg->log_success ?? false);
		$this->defaultLogFile   = $cfgLogFile !== null ? (string)$cfgLogFile : null;
		$this->reuseConnections = (bool)($cfg->reuse_connections ?? true);

		if (isset($this->options['defaults']) && \is_array($this->options['defaults'])) {
			$this->assertKnownRequestKeys($this->options['defaults'], 'Service option "defaults"');
			$this->defaults = \array_replace($this->defaults, $this->options['defaults']);
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
	 * Request keys (all optional except `url`; missing keys fall back to service defaults; unknown keys are rejected):
	 * - url               string                Absolute URL. Required.
	 * - method            string                Method token, case-insensitive. Default GET.
	 * - headers           list<string>          Raw "Name: value" lines. No CR/LF/NUL.
	 * - body              string|null           Raw request body. Ignored for HEAD.
	 * - query             array                 Appended with http_build_query (RFC 3986), fragment-safe.
	 * - timeout           int|float|string      Total transfer timeout in seconds. 0 = none. Fractions allowed.
	 * - connect_timeout   int|float|string      Connect timeout in seconds. 0 = libcurl default. Fractions allowed.
	 * - follow_redirects  bool                  Follow Location headers.
	 * - max_redirects     int                   Redirect limit (>= 0) when following. 0 makes libcurl refuse any redirect.
	 * - user_agent        string|null           Ignored when an explicit User-Agent header is given.
	 * - verify_peer       bool|int|string       TLS peer verification: bool, 0, 1, "0", or "1".
	 * - verify_host       int|string|bool       0, 2, "0", "2", or bool (true = 2, false = 0).
	 * - ca_info           string|null           CA bundle path.
	 * - proxy             string|null           Proxy URL.
	 * - cookie_store      string|null           Read and write cookies from/to this file (sets cookie_file + cookie_jar).
	 * - cookie_file       string|null           Read cookies from this file (may not exist yet).
	 * - cookie_jar        string|null           Write cookies to this file (created when missing).
	 * - return_headers    bool                  Capture and parse response headers.
	 * - capture_info      bool                  Include curl_getinfo() in the response.
	 * - auto_referer      bool                  Set Referer on redirects (only with follow_redirects).
	 * - referer           string|null           Explicit Referer. No CR/LF/NUL.
	 * - basic_auth        array|null            ['username' => string, 'password' => string].
	 * - bearer_token      string|null           Adds "Authorization: Bearer <token>". No CR/LF/NUL.
	 * - curl_options      array<int,mixed>      Raw CURLOPT_* overrides (integer keys only), applied last.
	 * - log_file          string|null           Per-request log file override.
	 * - log_context       array                 Extra log context.
	 *
	 * Behavior:
	 * - Validates the full request before any filesystem IO (cookie paths are prepared last).
	 * - GET/HEAD/POST use native libcurl modes; POST without body sends "Content-Length: 0".
	 *   Other methods use CURLOPT_CUSTOMREQUEST, which libcurl keeps across redirects
	 *   (pass CURLFOLLOW_OBEYCODE via curl_options on libcurl >= 8.13 to change that).
	 * - Integer timeouts map to CURLOPT_TIMEOUT / CURLOPT_CONNECTTIMEOUT; fractional ones map to
	 *   the *_MS variants, rounded up to at least 1 ms (never silently to "no timeout").
	 * - The cURL handle is released right after the transfer, which is when libcurl writes the cookie jar.
	 *
	 * Notes:
	 * - Sub-second timeouts can fail immediately on libcurl builds using the synchronous resolver
	 *   with signals enabled; set CURLOPT_NOSIGNAL via curl_options in that environment.
	 * - With connection reuse, libcurl may transparently resend a request once when a reused
	 *   connection turns out to be dead before any response byte arrived. Pass CURLOPT_FRESH_CONNECT
	 *   and CURLOPT_FORBID_REUSE via curl_options to opt a request out.
	 *
	 * Response shape:
	 *   [
	 *     'request'         => ['method' => string, 'url' => string],
	 *     'status_code'     => int,
	 *     'is_http_success' => bool,                                 // 2xx
	 *     'headers_raw'     => string,                               // All response heads as received, no trailers
	 *     'headers'         => array<string,string|list<string>>,    // Final head only, lowercase names, no trailers
	 *     'body'            => string,
	 *     'body_bytes'      => int,
	 *     'effective_url'   => string,
	 *     'content_type'    => string|null,
	 *     'info'            => array<string,mixed>,                  // Empty unless capture_info
	 *   ]
	 *
	 * Typical usage:
	 *   $response = $this->app->curl->execute(['url' => 'https://example.com/health']);
	 *
	 * @param  array<string,mixed>  $request  Request configuration.
	 * @return array<string,mixed>  Normalized response payload (see shape above).
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the request is invalid or cookie paths cannot be prepared.
	 * @throws \CitOmni\Infrastructure\Exception\CurlExecException  When cURL fails to initialize, rejects an option, or fails at transport level.
	 */
	public function execute(array $request): array {
		$request = $this->normalizeRequest($request);

		// -- 1. Prepare header capture ------------------------------------
		// libcurl calls the header function once per complete header line:
		// - A status line starts a new response head (1xx, redirect hop, proxy CONNECT, final),
		//   so $headerLines ends up holding only the final head's fields.
		// - The empty line closes a head. Lines arriving while no head is open are HTTP
		//   trailer fields (sent after a chunked body); they are not response headers and
		//   are dropped from both $headerLines and $headersRaw.
		$headersRaw     = '';
		$headerLines    = [];
		$inHead         = true;
		$headerFunction = null;

		if ($request['return_headers']) {
			$headerFunction = static function (\CurlHandle $handle, string $line) use (&$headersRaw, &$headerLines, &$inHead): int {
				$length = \strlen($line);

				if (\str_starts_with($line, 'HTTP/')) {
					$headersRaw  .= $line;
					$headerLines  = [];
					$inHead       = true;

					return $length;
				}

				if (!$inHead) {
					return $length;
				}

				$headersRaw .= $line;
				$field       = \rtrim($line, "\r\n");

				if ($field === '') {
					$inHead = false;
				} else {
					$headerLines[] = $field;
				}

				return $length;
			};
		}

		$options = $this->buildCurlOptions($request, $headerFunction);

		// -- 2. Run the transfer -------------------------------------------
		$handle = \curl_init();
		if ($handle === false) {
			throw new CurlExecException('Failed to initialize cURL handle.', 0, $request['method'], $request['url']);
		}

		if (!\curl_setopt_array($handle, $options)) {
			throw new CurlExecException(
				'Failed to apply cURL options: ' . $this->describeRejectedOption($handle, $options) . ' was rejected.',
				0,
				$request['method'],
				$request['url']
			);
		}

		$result = \curl_exec($handle);
		$info   = \curl_getinfo($handle);
		$errno  = $result === false ? \curl_errno($handle) : 0;
		$error  = $result === false ? \curl_error($handle) : '';

		// Destroy the handle now (curl_close() has been a no-op since PHP 8.0 and is deprecated in 8.5).
		// libcurl writes CURLOPT_COOKIEJAR during handle cleanup, so this keeps the jar
		// flush deterministic: it has happened before execute() returns or throws.
		unset($handle);

		// -- 3. Map the outcome --------------------------------------------
		if ($result === false) {
			if ($error === '') {
				$error = \curl_strerror($errno) ?? 'Unknown cURL error.';
			}

			$this->logError($request, $errno, $error, $info);

			throw new CurlExecException('cURL transport failed: ' . $error, $errno, $request['method'], $request['url'], $info);
		}

		// Only reachable when curl_options redirected the body (e.g. CURLOPT_RETURNTRANSFER=false or CURLOPT_FILE).
		if (!\is_string($result)) {
			$this->logError($request, 0, 'Unexpected non-string cURL result.', $info);

			throw new CurlExecException('Unexpected non-string cURL result.', 0, $request['method'], $request['url'], $info);
		}

		$response = $this->buildResponse($request, $result, $info, $headersRaw, $headerLines);

		$this->logSuccess($request, $response['status_code'], $info);

		return $response;
	}


	// ----------------------------------------------------------------
	// Request normalization
	// ----------------------------------------------------------------

	/**
	 * Normalize and validate one request array.
	 *
	 * Behavior:
	 * - Rejects unknown request keys.
	 * - Merges the request over service defaults.
	 * - Validates every key before any filesystem IO; cookie paths are prepared last.
	 *
	 * @param  array<string,mixed>  $request  Raw request.
	 * @return array<string,mixed>  Normalized request.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the request is invalid.
	 */
	private function normalizeRequest(array $request): array {
		$this->assertKnownRequestKeys($request, 'Request');

		$request = \array_replace($this->defaults, $request);

		if (!isset($request['url']) || !\is_string($request['url']) || $request['url'] === '') {
			throw new CurlConfigException('Request key "url" is required and must be a non-empty string.');
		}

		$request['url'] = $this->buildUrl($request['url'], $request['query']);

		$method = $request['method'] ?? 'GET';
		$method = \is_string($method) ? \strtoupper(\trim($method)) : '';
		if (!\preg_match('/^[A-Z][A-Z0-9_-]*$/', $method)) {
			throw new CurlConfigException('Request key "method" must be a valid method token.');
		}
		$request['method'] = $method;

		if (!\is_array($request['headers'])) {
			throw new CurlConfigException('Request key "headers" must be an array of header lines.');
		}

		if ($request['body'] !== null && !\is_string($request['body'])) {
			throw new CurlConfigException('Request key "body" must be null or string.');
		}

		$request['timeout']         = $this->normalizeSeconds($request['timeout'], 'timeout');
		$request['connect_timeout'] = $this->normalizeSeconds($request['connect_timeout'], 'connect_timeout');

		$request['follow_redirects'] = (bool)$request['follow_redirects'];
		$request['max_redirects']    = (int)$request['max_redirects'];

		if ($request['max_redirects'] < 0) {
			throw new CurlConfigException('Request key "max_redirects" must be >= 0.');
		}

		if ($request['follow_redirects'] === false) {
			$request['max_redirects'] = 0;
		}

		$request['verify_peer']    = $this->normalizeVerifyPeer($request['verify_peer']);
		$request['verify_host']    = $this->normalizeVerifyHost($request['verify_host']);
		$request['return_headers'] = (bool)$request['return_headers'];
		$request['capture_info']   = (bool)$request['capture_info'];
		$request['auto_referer']   = (bool)$request['auto_referer'];

		$this->assertOptionalNonEmptyString($request['ca_info'], 'ca_info');
		$this->assertOptionalNonEmptyString($request['proxy'], 'proxy');
		$this->assertOptionalNonEmptyString($request['referer'], 'referer');
		$this->assertOptionalNonEmptyString($request['log_file'], 'log_file');

		if ($request['referer'] !== null) {
			$this->assertNoLineBreaks($request['referer'], 'Request key "referer"');
		}

		if ($request['user_agent'] !== null) {
			if (!\is_string($request['user_agent'])) {
				throw new CurlConfigException('Request key "user_agent" must be null or string.');
			}

			$this->assertNoLineBreaks($request['user_agent'], 'Request key "user_agent"');
		}

		if (!\is_array($request['curl_options'])) {
			throw new CurlConfigException('Request key "curl_options" must be an array.');
		}

		foreach ($request['curl_options'] as $option => $value) {
			if (!\is_int($option)) {
				throw new CurlConfigException('Request key "curl_options" must be keyed by CURLOPT_* integers; got "' . $option . '".');
			}
		}

		if (!\is_array($request['log_context'])) {
			throw new CurlConfigException('Request key "log_context" must be an array.');
		}

		$request = $this->normalizeAuth($request);
		$request = $this->normalizeHeaders($request);

		// Last: this step may create directories and files.
		return $this->normalizeCookies($request);
	}


	/**
	 * Normalize a timeout value in seconds.
	 *
	 * Behavior:
	 * - Accepts int, float, or numeric string.
	 * - Whole-number values become int (mapped to the seconds-based cURL option).
	 * - Fractional values stay float (mapped to the millisecond-based cURL option).
	 *
	 * @param  mixed   $value  Raw value.
	 * @param  string  $key    Request key name for error messages.
	 * @return int|float  Normalized seconds (>= 0).
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the value is not a finite, non-negative number.
	 */
	private function normalizeSeconds(mixed $value, string $key): int|float {
		if (\is_string($value) && \is_numeric($value)) {
			$value = +$value;
		}

		if (\is_int($value)) {
			if ($value < 0) {
				throw new CurlConfigException('Request key "' . $key . '" must be >= 0.');
			}

			return $value;
		}

		if (!\is_float($value)) {
			throw new CurlConfigException('Request key "' . $key . '" must be numeric.');
		}

		// The upper bound keeps the millisecond conversion inside int range without lossy casts.
		if (\is_nan($value) || $value < 0.0 || $value >= 9.0E15) {
			throw new CurlConfigException('Request key "' . $key . '" must be a finite number of seconds >= 0.');
		}

		return \floor($value) === $value ? (int)$value : $value;
	}


	/**
	 * Normalize the TLS peer verification flag.
	 *
	 * Notes:
	 * - Accepts exactly bool, 0, 1, "0", or "1". Anything else (null, arrays, 2, "yes", 0.5) is
	 *   rejected: a lenient cast could silently disable certificate verification.
	 *
	 * @param  mixed  $value  Raw value.
	 * @return bool  Normalized flag.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the value is outside the accepted set.
	 */
	private function normalizeVerifyPeer(mixed $value): bool {
		return match ($value) {
			true, 1, '1'  => true,
			false, 0, '0' => false,
			default        => throw new CurlConfigException('Request key "verify_peer" must be bool, 0, 1, "0", or "1".'),
		};
	}


	/**
	 * Normalize the TLS host verification level.
	 *
	 * Notes:
	 * - Accepts exactly 0, 2, "0", "2", or bool (true = 2, false = 0). libcurl treats 1 as 2, so
	 *   1 is rejected as ambiguous rather than silently upgraded.
	 * - Anything else is rejected instead of being cast to 0 (which disables host verification).
	 *
	 * @param  mixed  $value  Raw value.
	 * @return int  0 or 2.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the value is outside the accepted set.
	 */
	private function normalizeVerifyHost(mixed $value): int {
		return match ($value) {
			true, 2, '2'  => 2,
			false, 0, '0' => 0,
			default        => throw new CurlConfigException('Request key "verify_host" must be 0, 2, "0", "2", or bool.'),
		};
	}


	/**
	 * Normalize authentication-related request fields.
	 *
	 * @param  array<string,mixed>  $request  Request data.
	 * @return array<string,mixed>  Request with normalized auth settings.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When auth input is invalid.
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

			$this->assertNoLineBreaks($request['bearer_token'], 'Request key "bearer_token"');
		}

		return $request;
	}


	/**
	 * Validate header lines and resolve Authorization / User-Agent handling.
	 *
	 * Behavior:
	 * - Requires "Name: value" lines with a non-empty name and no CR/LF/NUL.
	 * - Rejects contradictory auth sources (basic_auth, bearer_token, explicit Authorization header).
	 * - Appends the bearer Authorization header when configured.
	 * - Resolves the User-Agent to apply via CURLOPT_USERAGENT unless an explicit header is present.
	 *
	 * @param  array<string,mixed>  $request  Request data.
	 * @return array<string,mixed>  Request with final header list and `_resolved_user_agent`.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When headers are invalid or auth sources conflict.
	 */
	private function normalizeHeaders(array $request): array {
		$hasAuthorization = false;
		$hasUserAgent     = false;

		foreach ($request['headers'] as $headerLine) {
			if (!\is_string($headerLine) || !\str_contains($headerLine, ':')) {
				throw new CurlConfigException('Each header must be a non-empty string containing ":".');
			}

			$this->assertNoLineBreaks($headerLine, 'Header lines');

			$name = \strtolower(\trim((string)\strstr($headerLine, ':', true)));

			if ($name === '') {
				throw new CurlConfigException('Each header must have a non-empty field name before ":".');
			}

			if ($name === 'authorization') {
				$hasAuthorization = true;
			} elseif ($name === 'user-agent') {
				$hasUserAgent = true;
			}
		}

		if ($request['basic_auth'] !== null && ($request['bearer_token'] !== null || $hasAuthorization)) {
			throw new CurlConfigException('basic_auth cannot be combined with bearer_token or an explicit Authorization header.');
		}

		if ($request['bearer_token'] !== null) {
			if ($hasAuthorization) {
				throw new CurlConfigException('bearer_token cannot be combined with an explicit Authorization header.');
			}

			$request['headers'][] = 'Authorization: Bearer ' . $request['bearer_token'];
		}

		$userAgent = $request['user_agent'];

		$request['_resolved_user_agent'] = (!$hasUserAgent && $userAgent !== null && $userAgent !== '') ? $userAgent : null;

		return $request;
	}


	/**
	 * Normalize cookie-related request fields and prepare cookie paths.
	 *
	 * Behavior:
	 * - `cookie_store` sets both `cookie_file` and `cookie_jar`.
	 * - Read-only cookie files may be missing; write targets are created (directory 0700, file 0600).
	 *
	 * @param  array<string,mixed>  $request  Request data.
	 * @return array<string,mixed>  Request with normalized cookie settings.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When cookie settings are invalid or paths cannot be prepared.
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

			if ($cookieStoreProvided) {
				$this->ensureCookieWritePathReady($request['cookie_file']);
			} else {
				$this->ensureCookieSourcePathReady($request['cookie_file']);
			}
		}

		if ($request['cookie_jar'] !== null) {
			if (!\is_string($request['cookie_jar']) || $request['cookie_jar'] === '') {
				throw new CurlConfigException('Request key "cookie_jar" must be null or non-empty string.');
			}

			if (!$cookieStoreProvided) {
				$this->ensureCookieWritePathReady($request['cookie_jar']);
			}
		}

		return $request;
	}


	/**
	 * Build the final URL including query parameters.
	 *
	 * @param  string  $url    Base URL.
	 * @param  mixed   $query  Query data (null or array).
	 * @return string  Final URL.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When query input is invalid.
	 */
	private function buildUrl(string $url, mixed $query): string {
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

		$fragment    = '';
		$fragmentPos = \strpos($url, '#');

		if ($fragmentPos !== false) {
			$fragment = \substr($url, $fragmentPos);
			$url      = \substr($url, 0, $fragmentPos);
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
	 * @param  string  $path  Cookie file path.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the existing path is not a readable regular file.
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
	 * - Creates the parent directory (0700) when needed.
	 * - Accepts an existing writable regular file.
	 * - Creates the file (0600) when it does not yet exist.
	 *
	 * @param  string  $path  Cookie file path.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the path cannot be created or written.
	 */
	private function ensureCookieWritePathReady(string $path): void {
		$directory = \dirname($path);

		if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
			throw new CurlConfigException('Failed to create cookie directory: ' . $directory);
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
		@\chmod($path, 0600);
	}


	/**
	 * Reject request keys the service does not know.
	 *
	 * @param  array<array-key,mixed>  $request  Request (or request defaults) to check.
	 * @param  string                  $subject  Subject for the error message.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When unknown keys are present.
	 */
	private function assertKnownRequestKeys(array $request, string $subject): void {
		$unknown = \array_diff_key($request, self::REQUEST_KEYS);

		if ($unknown !== []) {
			throw new CurlConfigException($subject . ' contains unknown key(s): "' . \implode('", "', \array_keys($unknown)) . '".');
		}
	}


	/**
	 * Require null or a non-empty string.
	 *
	 * @param  mixed   $value  Value to check.
	 * @param  string  $key    Request key name for error messages.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the value is neither null nor a non-empty string.
	 */
	private function assertOptionalNonEmptyString(mixed $value, string $key): void {
		if ($value !== null && (!\is_string($value) || $value === '')) {
			throw new CurlConfigException('Request key "' . $key . '" must be null or non-empty string.');
		}
	}


	/**
	 * Reject values that would be emitted verbatim into the request head and contain CR, LF, or NUL.
	 *
	 * Notes:
	 * - libcurl does not sanitize these; embedded line breaks inject extra header lines.
	 *
	 * @param  string  $value  Value to check.
	 * @param  string  $label  Subject for the error message.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException  When the value contains CR, LF, or NUL.
	 */
	private function assertNoLineBreaks(string $value, string $label): void {
		if (\strpbrk($value, "\r\n\0") !== false) {
			throw new CurlConfigException($label . ' must not contain CR, LF, or NUL characters.');
		}
	}


	// ----------------------------------------------------------------
	// cURL option building
	// ----------------------------------------------------------------

	/**
	 * Build cURL options from a normalized request.
	 *
	 * Behavior:
	 * - Service-managed options first, then `curl_options` on top.
	 * - Request method mapping:
	 *   1) HEAD -> CURLOPT_NOBODY
	 *   2) GET without body -> libcurl default (plain GET)
	 *   3) POST -> CURLOPT_POSTFIELDS (empty string when body is null)
	 *   4) Anything else (incl. GET with body) -> CURLOPT_CUSTOMREQUEST (+ CURLOPT_POSTFIELDS when body is set)
	 *
	 * @param  array<string,mixed>  $request         Normalized request.
	 * @param  \Closure|null        $headerFunction  Header collector, or null when headers are not captured.
	 * @return array<int,mixed>  cURL options.
	 */
	private function buildCurlOptions(array $request, ?\Closure $headerFunction): array {
		$options = [
			\CURLOPT_URL            => $request['url'],
			\CURLOPT_RETURNTRANSFER => true,
			\CURLOPT_FOLLOWLOCATION => $request['follow_redirects'],
			\CURLOPT_MAXREDIRS      => $request['max_redirects'],
			\CURLOPT_SSL_VERIFYPEER => $request['verify_peer'],
			\CURLOPT_SSL_VERIFYHOST => $request['verify_host'],
			\CURLOPT_HTTPHEADER     => $request['headers'],
		];

		if (\is_int($request['timeout'])) {
			$options[\CURLOPT_TIMEOUT] = $request['timeout'];
		} else {
			$options[\CURLOPT_TIMEOUT_MS] = self::toMilliseconds($request['timeout']);
		}

		if (\is_int($request['connect_timeout'])) {
			$options[\CURLOPT_CONNECTTIMEOUT] = $request['connect_timeout'];
		} else {
			$options[\CURLOPT_CONNECTTIMEOUT_MS] = self::toMilliseconds($request['connect_timeout']);
		}

		if ($headerFunction !== null) {
			$options[\CURLOPT_HEADERFUNCTION] = $headerFunction;
		}

		if ($request['_resolved_user_agent'] !== null) {
			$options[\CURLOPT_USERAGENT] = $request['_resolved_user_agent'];
		}

		if ($request['auto_referer'] && $request['follow_redirects']) {
			$options[\CURLOPT_AUTOREFERER] = true;
		}

		if ($request['referer'] !== null) {
			$options[\CURLOPT_REFERER] = $request['referer'];
		}

		if ($request['ca_info'] !== null) {
			$options[\CURLOPT_CAINFO] = $request['ca_info'];
		}

		if ($request['proxy'] !== null) {
			$options[\CURLOPT_PROXY] = $request['proxy'];
		}

		if ($request['cookie_file'] !== null) {
			$options[\CURLOPT_COOKIEFILE] = $request['cookie_file'];
		}

		if ($request['cookie_jar'] !== null) {
			$options[\CURLOPT_COOKIEJAR] = $request['cookie_jar'];
		}

		if ($request['basic_auth'] !== null) {
			$options[\CURLOPT_HTTPAUTH] = \CURLAUTH_BASIC;
			$options[\CURLOPT_USERPWD]  = $request['basic_auth']['username'] . ':' . $request['basic_auth']['password'];
		}

		$share = $this->resolveShareHandle();
		if ($share !== null) {
			$options[\CURLOPT_SHARE] = $share;
		}

		// CURLOPT_CUSTOMREQUEST replaces the method on every request of a redirect chain,
		// so it is only used where libcurl has no native mode for the method.
		$method = $request['method'];
		$body   = $request['body'];

		if ($method === 'HEAD') {
			$options[\CURLOPT_NOBODY] = true;
		} elseif ($method === 'POST') {
			$options[\CURLOPT_POSTFIELDS] = $body ?? '';
		} elseif ($method !== 'GET' || $body !== null) {
			$options[\CURLOPT_CUSTOMREQUEST] = $method;

			if ($body !== null) {
				$options[\CURLOPT_POSTFIELDS] = $body;
			}
		}

		// Keys were validated as integers in normalizeRequest().
		foreach ($request['curl_options'] as $option => $value) {
			$options[$option] = $value;
		}

		return $options;
	}


	/**
	 * Return the share handle for DNS and connection reuse, creating it on first use.
	 *
	 * Behavior:
	 * - Shares the DNS cache and the connection pool (not cookies, not TLS sessions) between
	 *   the sequential transfers of this service instance.
	 * - Falls back to no reuse for the rest of the instance lifetime if libcurl refuses a share
	 *   type. Reuse is an optimization, never a transport requirement.
	 *
	 * Notes:
	 * - Deliberately not curl_share_init_persistent(): that would keep state across PHP requests.
	 * - Single-threaded use only, which matches the service's request/process scope.
	 *
	 * @return \CurlShareHandle|null  Share handle, or null when reuse is disabled.
	 */
	private function resolveShareHandle(): ?\CurlShareHandle {
		if (!$this->reuseConnections) {
			return null;
		}

		if ($this->share !== null) {
			return $this->share;
		}

		$share = \curl_share_init();

		if (
			!\curl_share_setopt($share, \CURLSHOPT_SHARE, \CURL_LOCK_DATA_DNS)
			|| !\curl_share_setopt($share, \CURLSHOPT_SHARE, \CURL_LOCK_DATA_CONNECT)
		) {
			$this->reuseConnections = false;
			return null;
		}

		return $this->share = $share;
	}


	/**
	 * Identify the option that curl_setopt_array() rejected.
	 *
	 * Behavior:
	 * - Cold path only: re-applies options one by one on the same handle until one fails.
	 * - Resolves the CURLOPT_* constant name when possible.
	 *
	 * @param  \CurlHandle       $handle   Handle that rejected the option set.
	 * @param  array<int,mixed>  $options  Option set passed to curl_setopt_array().
	 * @return string  Human-readable option identifier.
	 */
	private function describeRejectedOption(\CurlHandle $handle, array $options): string {
		foreach ($options as $option => $value) {
			if (\curl_setopt($handle, $option, $value)) {
				continue;
			}

			foreach (\get_defined_constants(true)['curl'] ?? [] as $name => $constant) {
				if ($constant === $option && \str_starts_with($name, 'CURLOPT_')) {
					return $name . ' (' . $option . ')';
				}
			}

			return 'option ' . $option;
		}

		return 'an unidentified option';
	}


	/**
	 * Convert fractional seconds to whole milliseconds, never rounding a positive value down to 0.
	 *
	 * @param  float  $seconds  Seconds (finite, >= 0, < 9.0E15).
	 * @return int  Milliseconds.
	 */
	private static function toMilliseconds(float $seconds): int {
		return \max(1, (int)\ceil($seconds * 1000.0));
	}


	// ----------------------------------------------------------------
	// Response building
	// ----------------------------------------------------------------

	/**
	 * Build a normalized response payload.
	 *
	 * @param  array<string,mixed>  $request      Normalized request.
	 * @param  string               $body         Response body as returned by curl_exec().
	 * @param  array<string,mixed>  $info         curl_getinfo() result.
	 * @param  string               $headersRaw   All response head bytes, trailers excluded ('' when not captured).
	 * @param  list<string>         $headerLines  Field lines of the final header block.
	 * @return array<string,mixed>  Normalized response.
	 */
	private function buildResponse(array $request, string $body, array $info, string $headersRaw, array $headerLines): array {
		$statusCode = (int)($info['http_code'] ?? 0);

		return [
			'request' => [
				'method' => $request['method'],
				'url'    => $request['url'],
			],
			'status_code'     => $statusCode,
			'is_http_success' => ($statusCode >= 200 && $statusCode < 300),
			'headers_raw'     => $headersRaw,
			'headers'         => $this->parseHeaderLines($headerLines),
			'body'            => $body,
			'body_bytes'      => \strlen($body),
			'effective_url'   => isset($info['url']) ? (string)$info['url'] : $request['url'],
			'content_type'    => isset($info['content_type']) && \is_string($info['content_type']) ? $info['content_type'] : null,
			'info'            => $request['capture_info'] ? $info : [],
		];
	}


	/**
	 * Parse header field lines into a lowercase associative array.
	 *
	 * Behavior:
	 * - Repeated fields become a list in received order.
	 * - Obsolete line folding (leading SP/HTAB) extends the previous field value with one space.
	 * - Lines without ":" or with an empty name are skipped.
	 *
	 * @param  list<string>  $lines  Non-empty field lines without line terminators.
	 * @return array<string,string|list<string>>  Parsed headers.
	 */
	private function parseHeaderLines(array $lines): array {
		$headers  = [];
		$lastName = null;

		foreach ($lines as $line) {
			if ($line[0] === ' ' || $line[0] === "\t") {
				$continuation = \trim($line);

				if ($lastName !== null && $continuation !== '') {
					if (\is_array($headers[$lastName])) {
						$headers[$lastName][\array_key_last($headers[$lastName])] .= ' ' . $continuation;
					} else {
						$headers[$lastName] .= ' ' . $continuation;
					}
				}

				continue;
			}

			$colonPos = \strpos($line, ':');
			$name     = $colonPos === false ? '' : \strtolower(\trim(\substr($line, 0, $colonPos)));

			if ($name === '') {
				$lastName = null;
				continue;
			}

			$value = \trim(\substr($line, $colonPos + 1));

			if (!isset($headers[$name])) {
				$headers[$name] = $value;
			} elseif (\is_array($headers[$name])) {
				$headers[$name][] = $value;
			} else {
				$headers[$name] = [$headers[$name], $value];
			}

			$lastName = $name;
		}

		return $headers;
	}


	// ----------------------------------------------------------------
	// Logging
	// ----------------------------------------------------------------

	/**
	 * Log a transport-level cURL failure when error logging is enabled and a log service exists.
	 *
	 * @param  array<string,mixed>  $request  Normalized request.
	 * @param  int                  $errno    cURL errno.
	 * @param  string               $error    cURL error text.
	 * @param  array<string,mixed>  $info     curl_getinfo() result.
	 * @return void
	 */
	private function logError(array $request, int $errno, string $error, array $info): void {
		if (!$this->logErrors || !$this->app->hasService('log')) {
			return;
		}

		$context = $request['log_context'];
		$context['method']          = $request['method'];
		$context['url']             = $request['url'];
		$context['curl_errno']      = $errno;
		$context['curl_error']      = $error;
		$context['timeout']         = $request['timeout'];
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

		$this->app->log->write($this->resolveLogFile($request), 'curl.error', 'Outbound cURL transport failed.', $context);
	}


	/**
	 * Log a completed transfer when success logging is enabled and a log service exists.
	 *
	 * @param  array<string,mixed>  $request     Normalized request.
	 * @param  int                  $statusCode  Final HTTP status code.
	 * @param  array<string,mixed>  $info        curl_getinfo() result (independent of capture_info).
	 * @return void
	 */
	private function logSuccess(array $request, int $statusCode, array $info): void {
		if (!$this->logSuccess || !$this->app->hasService('log')) {
			return;
		}

		$context = $request['log_context'];
		$context['method']      = $request['method'];
		$context['url']         = $request['url'];
		$context['status_code'] = $statusCode;

		if (isset($info['total_time'])) {
			$context['total_time'] = $info['total_time'];
		}

		$this->app->log->write($this->resolveLogFile($request), 'curl.success', 'Outbound cURL request completed.', $context);
	}


	/**
	 * Resolve the log file for one request.
	 *
	 * @param  array<string,mixed>  $request  Normalized request.
	 * @return string|null  Log file name, or null for the logger default.
	 */
	private function resolveLogFile(array $request): ?string {
		return $request['log_file'] ?? $this->defaultLogFile;
	}

}
