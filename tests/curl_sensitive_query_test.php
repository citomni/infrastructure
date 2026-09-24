<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 */

namespace CitOmni\Kernel\Service {

	abstract class BaseService {

		protected object $app;

		/** @var array<string,mixed> */
		protected array $options;

		/**
		 * Create a minimal service test double without installing the Kernel package.
		 *
		 * @param object $app Test application.
		 * @param array<string,mixed> $options Service options.
		 */
		public function __construct(object $app, array $options = []) {
			$this->app = $app;
			$this->options = $options;
			$this->init();
		}

		protected function init(): void {
		}
	}
}

namespace {

	use CitOmni\Infrastructure\Exception\CurlConfigException;
	use CitOmni\Infrastructure\Exception\CurlExecException;
	use CitOmni\Infrastructure\Service\Curl;

	require __DIR__ . '/../src/Exception/CurlException.php';
	require __DIR__ . '/../src/Exception/CurlConfigException.php';
	require __DIR__ . '/../src/Exception/CurlExecException.php';
	require __DIR__ . '/../src/Service/Curl.php';

	if (\PHP_VERSION_ID < 80500) {
		\fwrite(\STDERR, "PHP 8.5 or newer is required.\n");
		exit(1);
	}

	if (!\extension_loaded('curl')) {
		\fwrite(\STDERR, "ext-curl is required.\n");
		exit(1);
	}

	final class CurlTestLog {

		/** @var list<array{file:?string,category:string,message:string|array|object,context:array<string,mixed>}> */
		public array $records = [];

		/**
		 * Capture one log write.
		 *
		 * @param array<string,mixed> $context Log context.
		 */
		public function write(?string $file, string $category, string|array|object $message, array $context = []): void {
			$this->records[] = [
				'file' => $file,
				'category' => $category,
				'message' => $message,
				'context' => $context,
			];
		}
	}

	final class CurlTestApp {

		public object $cfg;

		public CurlTestLog $log;

		public function __construct(bool $logErrors = true, bool $logSuccess = false) {
			$this->cfg = (object)[
				'curl' => (object)[
					'timeout' => 2,
					'connect_timeout' => 1,
					'follow_redirects' => false,
					'max_redirects' => 10,
					'user_agent' => 'CitOmni Curl test',
					'verify_peer' => true,
					'verify_host' => 2,
					'return_headers' => true,
					'capture_info' => true,
					'auto_referer' => true,
					'log_errors' => $logErrors,
					'log_success' => $logSuccess,
					'reuse_connections' => false,
				],
			];
			$this->log = new CurlTestLog();
		}

		public function hasService(string $id): bool {
			return $id === 'log';
		}
	}

	$checks = 0;

	/** Check a condition independently of zend.assertions. */
	function check(bool $condition, string $message): void {
		global $checks;
		++$checks;

		if (!$condition) {
			throw new \RuntimeException($message);
		}
	}

	/** Check strict equality. */
	function same(mixed $expected, mixed $actual, string $message): void {
		check($expected === $actual, $message . ' Expected ' . \var_export($expected, true) . ', got ' . \var_export($actual, true) . '.');
	}

	/** Check that a string contains another string. */
	function contains(string $needle, string $haystack, string $message): void {
		check(\str_contains($haystack, $needle), $message . ' Missing ' . \var_export($needle, true) . '.');
	}

	/** Check that a string does not contain another string. */
	function excludes(string $needle, string $haystack, string $message): void {
		check(!\str_contains($haystack, $needle), $message . ' Unexpected ' . \var_export($needle, true) . '.');
	}

	/** Get an unused local TCP port. */
	function freePort(): int {
		$errno = 0;
		$error = '';
		$socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if ($socket === false) {
			throw new \RuntimeException('Failed to allocate local test port: ' . $error);
		}

		$name = \stream_socket_get_name($socket, false);
		\fclose($socket);

		if (!\is_string($name) || ($colon = \strrpos($name, ':')) === false) {
			throw new \RuntimeException('Failed to resolve local test port.');
		}

		return (int)\substr($name, $colon + 1);
	}

	/** Wait until the local PHP test server accepts TCP connections. */
	function waitForServer(int $port): void {
		$errno = 0;
		$error = '';

		for ($i = 0; $i < 100; ++$i) {
			$socket = @\fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
			if ($socket !== false) {
			\fclose($socket);
			return;
			}

			\usleep(20_000);
		}

		throw new \RuntimeException('Local cURL test server did not start.');
	}

	/** Read captured request targets from the local test server. */
	function capturedRequests(string $file): array {
		$content = \file_get_contents($file);
		if ($content === false || $content === '') {
			return [];
		}

		return \array_values(\array_filter(\preg_split('/\R/', \trim($content)) ?: [], static fn(string $line): bool => $line !== ''));
	}

	/** Read the most recently captured request target. */
	function lastCapturedRequest(string $file): string {
		$requests = capturedRequests($file);

		return $requests === [] ? '' : $requests[\array_key_last($requests)];
	}

	/** Expect a request configuration failure. */
	function expectConfigFailure(Curl $curl, array $request, string $message): void {
		try {
			$curl->execute($request);
		} catch (CurlConfigException) {
			check(true, $message);
			return;
		}

		check(false, $message);
	}

	$captureFile = \tempnam(\sys_get_temp_dir(), 'citomni-curl-capture-');
	if ($captureFile === false) {
		throw new \RuntimeException('Failed to create cURL test capture file.');
	}

	$port = freePort();
	$router = __DIR__ . '/fixtures/curl_sensitive_query_router.php';
	$nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
	$descriptors = [
		0 => ['file', $nullDevice, 'r'],
		1 => ['file', $nullDevice, 'a'],
		2 => ['file', $nullDevice, 'a'],
	];
	$environment = \getenv();
	$environment = \is_array($environment) ? $environment : [];
	$environment['CITOMNI_CURL_TEST_CAPTURE'] = $captureFile;
	$process = \proc_open([\PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], $descriptors, $pipes, __DIR__, $environment);

	if (!\is_resource($process)) {
		@\unlink($captureFile);
		throw new \RuntimeException('Failed to start local cURL test server.');
	}

	try {
		waitForServer($port);
		$baseUrl = 'http://127.0.0.1:' . $port;
		$app = new CurlTestApp(true, true);
		$curl = new Curl($app);

		// Normal request behavior remains unchanged when redaction is omitted.
		$response = $curl->execute([
			'url' => $baseUrl . '/plain',
			'query' => ['foo' => 'a b'],
		]);
		$plainUrl = $baseUrl . '/plain?foo=a%20b';
		same($plainUrl, $response['request']['url'], 'Normal request URL is unchanged.');
		same($plainUrl, $response['effective_url'], 'Normal effective URL is unchanged.');
		same($plainUrl, $response['info']['url'] ?? null, 'Normal transfer info URL is unchanged.');
		contains('/plain?foo=a%20b', lastCapturedRequest($captureFile), 'Normal query reaches the server.');

		// One sensitive key is sent unchanged but redacted from exposed metadata and success logs.
		$app->log->records = [];
		$response = $curl->execute([
			'url' => $baseUrl . '/one',
			'query' => [
				'apiKey' => 'A B&/?',
				'foo' => 'bar',
			],
			'sensitive_query_keys' => ['apiKey'],
		]);
		$exposedUrl = $baseUrl . '/one?apiKey=[REDACTED]&foo=bar';
		same($exposedUrl, $response['request']['url'], 'Sensitive request URL is redacted.');
		same($exposedUrl, $response['effective_url'], 'Sensitive effective URL is redacted.');
		same($exposedUrl, $response['info']['url'] ?? null, 'Sensitive transfer info URL is redacted.');
		contains('/one?apiKey=A%20B%26%2F%3F&foo=bar', lastCapturedRequest($captureFile), 'Real sensitive value reaches the server unchanged.');
		same('curl.success', $app->log->records[0]['category'] ?? null, 'Successful request is logged.');
		same($exposedUrl, $app->log->records[0]['context']['url'] ?? null, 'Success log URL is redacted.');
		excludes('A%20B%26%2F%3F', \serialize($app->log->records[0]), 'Success log does not expose the encoded sensitive value.');

		// A sensitive top-level key also redacts nested PHP bracket notation without changing the outbound values.
		$app->log->records = [];
		$response = $curl->execute([
			'url' => $baseUrl . '/nested',
			'query' => [
				'apiKey' => [
					'first' => 'nested-secret-one',
					'group' => [
						'second' => 'nested-secret-two',
					],
				],
				'foo' => 'bar',
			],
			'sensitive_query_keys' => ['apiKey'],
		]);
		$nestedExposedUrl = $baseUrl . '/nested?apiKey%5Bfirst%5D=[REDACTED]&apiKey%5Bgroup%5D%5Bsecond%5D=[REDACTED]&foo=bar';
		same($nestedExposedUrl, $response['request']['url'], 'Nested sensitive request values are redacted.');
		same($nestedExposedUrl, $response['effective_url'], 'Nested sensitive effective URL is redacted.');
		same($nestedExposedUrl, $response['info']['url'] ?? null, 'Nested sensitive transfer info URL is redacted.');
		excludes('nested-secret-one', \serialize([$response['request']['url'], $response['effective_url'], $response['info']['url'] ?? null]), 'First nested secret is absent from exposed URLs.');
		excludes('nested-secret-two', \serialize([$response['request']['url'], $response['effective_url'], $response['info']['url'] ?? null]), 'Second nested secret is absent from exposed URLs.');
		$nestedCaptured = lastCapturedRequest($captureFile);
		contains('apiKey%5Bfirst%5D=nested-secret-one', $nestedCaptured, 'First nested sensitive value reaches the server unchanged.');
		contains('apiKey%5Bgroup%5D%5Bsecond%5D=nested-secret-two', $nestedCaptured, 'Second nested sensitive value reaches the server unchanged.');
		same($nestedExposedUrl, $app->log->records[0]['context']['url'] ?? null, 'Nested success log URL is redacted.');
		excludes('nested-secret-one', \serialize($app->log->records[0] ?? []), 'Nested success log does not expose the first sensitive value.');
		excludes('nested-secret-two', \serialize($app->log->records[0] ?? []), 'Nested success log does not expose the second sensitive value.');

		// Duplicate and multiple sensitive parameters are all redacted without changing non-sensitive parameters.
		$response = $curl->execute([
			'url' => $baseUrl . '/duplicates?apiKey=first&keep=1&apiKey=second&keep=2',
			'query' => [
				'token' => 'third',
				'keep2' => 'two',
			],
			'sensitive_query_keys' => ['apiKey', 'token'],
		]);
		same(
			$baseUrl . '/duplicates?apiKey=[REDACTED]&keep=1&apiKey=[REDACTED]&keep=2&token=[REDACTED]&keep2=two',
			$response['request']['url'],
			'Duplicate and multiple sensitive parameters are redacted in order.'
		);
		contains(
			'/duplicates?apiKey=first&keep=1&apiKey=second&keep=2&token=third&keep2=two',
			lastCapturedRequest($captureFile),
			'Duplicate query parameters reach the server unchanged.'
		);

		// Encoded parameter names match decoded sensitive keys while untouched encoding remains byte-for-byte stable.
		$response = $curl->execute([
			'url' => $baseUrl . '/encoded?api%4Bey=encoded-secret&na%6De=ok',
			'sensitive_query_keys' => ['apiKey'],
		]);
		same(
			$baseUrl . '/encoded?api%4Bey=[REDACTED]&na%6De=ok',
			$response['request']['url'],
			'Encoded sensitive key is matched without re-encoding non-sensitive parameters.'
		);

		// An absent sensitive key changes nothing.
		$response = $curl->execute([
			'url' => $baseUrl . '/absent?foo=bar&apiKey=public-for-this-request',
			'sensitive_query_keys' => ['missing'],
		]);
		same(
			$baseUrl . '/absent?foo=bar&apiKey=public-for-this-request',
			$response['request']['url'],
			'Absent sensitive key leaves URL unchanged.'
		);

		// CURLINFO_HEADER_OUT can contain the complete request target and must be sanitized too.
		$response = $curl->execute([
			'url' => $baseUrl . '/header',
			'query' => ['apiKey' => 'header-secret', 'foo' => 'bar'],
			'sensitive_query_keys' => ['apiKey'],
			'curl_options' => [\CURLINFO_HEADER_OUT => true],
		]);
		$requestHeader = (string)($response['info']['request_header'] ?? '');
		contains('/header?apiKey=[REDACTED]&foo=bar', $requestHeader, 'Captured request header URL is redacted.');
		excludes('header-secret', $requestHeader, 'Captured request header does not expose the sensitive value.');

		// CURLINFO_HEADER_OUT may also contain a Referer URL carrying sensitive query values.
		$response = $curl->execute([
			'url' => $baseUrl . '/referer',
			'sensitive_query_keys' => ['apiKey'],
			'referer' => $baseUrl . '/source?apiKey=referer-secret&foo=bar',
			'curl_options' => [\CURLINFO_HEADER_OUT => true],
		]);
		$requestHeader = (string)($response['info']['request_header'] ?? '');
		contains('Referer: ' . $baseUrl . '/source?apiKey=[REDACTED]&foo=bar', $requestHeader, 'Captured Referer URL is redacted.');
		excludes('referer-secret', $requestHeader, 'Captured Referer does not expose the sensitive value.');

		// Redirect metadata is redacted both when a redirect is pending and after it is followed.
		$response = $curl->execute([
			'url' => $baseUrl . '/redirect',
			'query' => ['apiKey' => 'initial-secret'],
			'sensitive_query_keys' => ['apiKey'],
		]);
		$redirectUrl = (string)($response['info']['redirect_url'] ?? '');
		contains('apiKey=[REDACTED]', $redirectUrl, 'Pending redirect URL is redacted.');
		excludes('redirect-secret', $redirectUrl, 'Pending redirect URL does not expose the sensitive value.');

		$response = $curl->execute([
			'url' => $baseUrl . '/redirect',
			'query' => ['apiKey' => 'initial-secret'],
			'sensitive_query_keys' => ['apiKey'],
			'follow_redirects' => true,
			'max_redirects' => 3,
		]);
		contains('/final?apiKey=[REDACTED]&foo=bar', $response['effective_url'], 'Followed effective URL is redacted.');
		excludes('redirect-secret', $response['effective_url'], 'Followed effective URL does not expose redirect credentials.');

		// Invalid sensitive_query_keys configurations fail before transport.
		$requestCountBeforeInvalid = \count(capturedRequests($captureFile));
		expectConfigFailure($curl, [
			'url' => $baseUrl . '/invalid',
			'sensitive_query_keys' => 'apiKey',
		], 'String sensitive_query_keys is rejected.');
		expectConfigFailure($curl, [
			'url' => $baseUrl . '/invalid',
			'sensitive_query_keys' => ['apiKey' => true],
		], 'Associative sensitive_query_keys is rejected.');
		expectConfigFailure($curl, [
			'url' => $baseUrl . '/invalid',
			'sensitive_query_keys' => [123],
		], 'Non-string sensitive query key is rejected.');
		expectConfigFailure($curl, [
			'url' => $baseUrl . '/invalid',
			'sensitive_query_keys' => [''],
		], 'Empty sensitive query key is rejected.');
		same($requestCountBeforeInvalid, \count(capturedRequests($captureFile)), 'Invalid redaction configuration fails before transport.');

		// Transport failures expose only redacted request and transfer URLs, including the error log context.
		$closedPort = freePort();
		$app->log->records = [];
		$failed = false;
		try {
			$curl->execute([
				'url' => 'http://127.0.0.1:' . $closedPort . '/failure',
				'query' => ['apiKey' => 'exception-secret', 'foo' => 'bar'],
				'sensitive_query_keys' => ['apiKey'],
				'timeout' => 0.25,
				'connect_timeout' => 0.25,
			]);
		} catch (CurlExecException $e) {
			$failed = true;
			contains('apiKey=[REDACTED]', (string)$e->getRequestUrl(), 'Exception request URL is redacted.');
			excludes('exception-secret', (string)$e->getRequestUrl(), 'Exception request URL does not expose the sensitive value.');
			excludes('exception-secret', \serialize($e->getTransferInfo()), 'Exception transfer info does not expose the sensitive value.');
			excludes('exception-secret', $e->getMessage(), 'Exception message does not expose the sensitive value.');
		}
		check($failed, 'Closed-port request must fail at transport level.');
		same('curl.error', $app->log->records[0]['category'] ?? null, 'Transport failure is logged.');
		contains('apiKey=[REDACTED]', (string)($app->log->records[0]['context']['url'] ?? ''), 'Error log URL is redacted.');
		excludes('exception-secret', \serialize($app->log->records[0]), 'Error log record does not expose the sensitive value.');

		echo 'PASS ' . $checks . " checks. PHP " . \PHP_VERSION . ".\n";
	} finally {
		\proc_terminate($process);
		\proc_close($process);
		@\unlink($captureFile);
	}
}
