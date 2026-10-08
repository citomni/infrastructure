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

namespace CitOmni\Infrastructure\Tests\Curl;

use CitOmni\Infrastructure\Boot\Registry;
use CitOmni\Infrastructure\Exception\CurlConfigException;
use CitOmni\Infrastructure\Exception\CurlExecException;
use CitOmni\Infrastructure\Service\Curl;
use CitOmni\Infrastructure\Tests\Support\App;
use CitOmni\Infrastructure\Tests\Support\LogRecorder;

use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\freePort;
use function CitOmni\Infrastructure\Tests\Support\mergeLastWins;
use function CitOmni\Infrastructure\Tests\Support\phpCommand;
use function CitOmni\Infrastructure\Tests\Support\readJsonLines;
use function CitOmni\Infrastructure\Tests\Support\removeTree;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\tempDir;
use function CitOmni\Infrastructure\Tests\Support\thrown;
use function CitOmni\Infrastructure\Tests\Support\waitForPort;

/*
 * Standalone suite for \CitOmni\Infrastructure\Service\Curl.
 *
 * The real service runs on the kernel doubles, without Composer, against PHP's
 * built-in web server with router.php on a free local port. The router writes
 * every request it receives to <docroot>/requests.jsonl, so checks compare what
 * the service returned and logged with what actually reached the server. The
 * docroot is a temporary directory, removed again afterwards.
 *
 * Usage:
 *   php tests/curl/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; like the production ErrorHandler, leave
// diagnostics silenced with @ to PHP.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';

$root = tempDir('curl');
\define('CITOMNI_APP_PATH', $root);

require __DIR__ . '/../../src/Boot/Registry.php';
require __DIR__ . '/../../src/Exception/CurlException.php';
require __DIR__ . '/../../src/Exception/CurlConfigException.php';
require __DIR__ . '/../../src/Exception/CurlExecException.php';
require __DIR__ . '/../../src/Service/Curl.php';

// ----------------------------------------------------------------
// Fixtures
// ----------------------------------------------------------------

/**
 * The fixture server: PHP's built-in web server with router.php.
 */
final class Server {

	public static string $baseUrl = '';

	public static string $docroot = '';

	/** @var resource|null */
	private static mixed $process = null;

	public static function start(string $docroot): void {
		$port = freePort();
		$process = \proc_open([...phpCommand(), '-S', '127.0.0.1:' . $port, '-t', $docroot, __DIR__ . '/router.php'], [
			0 => ['pipe', 'r'],
			1 => ['file', $docroot . '/server.out', 'w'],
			2 => ['file', $docroot . '/server.err', 'w'],
		], $pipes);
		if (!\is_resource($process)) {
			throw new \RuntimeException('Cannot start the fixture server.');
		}
		\fclose($pipes[0]);
		self::$process = $process;
		self::$docroot = $docroot;
		self::$baseUrl = 'http://127.0.0.1:' . $port;
		waitForPort($port);
	}

	public static function stop(): void {
		if (\is_resource(self::$process)) {
			\proc_terminate(self::$process);
			\proc_close(self::$process);
		}
		self::$process = null;
	}

	/**
	 * Requests the router has received so far, oldest first.
	 *
	 * @return list<array{method: string, target: string, headers: array<string, string>, body: string}>
	 */
	public static function requests(): array {
		return readJsonLines(self::$docroot . '/requests.jsonl');
	}

	/** The most recent request the router received. */
	public static function last(): array {
		$requests = self::requests();
		expect($requests !== [], 'the server received no request');
		return $requests[\array_key_last($requests)];
	}
}


/**
 * A Curl service on the shipped cfg baseline with test overrides, plus its log.
 *
 * @param  array<string, mixed>  $curlCfg  Overrides for the "curl" cfg node.
 * @param  array<string, mixed>  $options  Service options.
 * @return array{0: Curl, 1: LogRecorder}
 */
function curl(array $curlCfg = [], array $options = [], bool $withLog = true): array {
	$cfg = mergeLastWins(Registry::CFG_HTTP, ['curl' => $curlCfg + [
		'timeout'         => 5,
		'connect_timeout' => 2,
		'user_agent'      => 'CitOmni Curl test',
		'log_success'     => true,
	]]);
	$app = new App($cfg);
	$log = new LogRecorder();
	if ($withLog) {
		$app->set('log', $log);
	}
	return [new Curl($app, $options), $log];
}


/** Fail unless $request is rejected as invalid configuration before anything is sent. */
function rejected(Curl $curl, array $request, string $what, string $messagePart = ''): void {
	$before = \count(Server::requests());
	$e = thrown(CurlConfigException::class, static fn() => $curl->execute($request), $what);
	if ($messagePart !== '') {
		expect(\str_contains($e->getMessage(), $messagePart), $what . ': message lacks ' . export($messagePart) . ': ' . $e->getMessage());
	}
	same($before, \count(Server::requests()), $what . ': requests sent');
}


/** Fail unless $needle occurs in $haystack. */
function contains(string $needle, string $haystack, string $what): void {
	expect(\str_contains($haystack, $needle), $what . ': ' . export($needle) . ' missing from ' . export($haystack));
}


/** Fail if $needle occurs in $haystack. */
function excludes(string $needle, string $haystack, string $what): void {
	expect(!\str_contains($haystack, $needle), $what . ': ' . export($needle) . ' found in ' . export($haystack));
}


// ----------------------------------------------------------------
// Checks: request validation
// ----------------------------------------------------------------

$validation = [

	'unknown request keys are rejected before transport' => static function (): void {
		[$curl] = curl();
		rejected($curl, ['url' => Server::$baseUrl . '/', 'timout' => 1], 'typo "timout"', '"timout"');
	},

	'service option defaults with unknown keys are rejected at construction' => static function (): void {
		$e = thrown(CurlConfigException::class, static fn() => curl([], ['defaults' => ['retries' => 3]]), 'defaults');
		contains('"retries"', $e->getMessage(), 'message');
	},

	'a missing or empty url is rejected' => static function (): void {
		[$curl] = curl();
		rejected($curl, [], 'no url', '"url"');
		rejected($curl, ['url' => ''], 'empty url', '"url"');
		rejected($curl, ['url' => 42], 'non-string url', '"url"');
	},

	'CR, LF and NUL are rejected in header lines, referer, user_agent and bearer_token' => static function (): void {
		[$curl] = curl();
		$url = Server::$baseUrl . '/';
		foreach (["\r\n", "\n", "\r", "\0"] as $break) {
			$label = \json_encode($break);
			rejected($curl, ['url' => $url, 'headers' => ["X-Test: a{$break}Injected: b"]], 'header line ' . $label, 'CR, LF, or NUL');
			rejected($curl, ['url' => $url, 'referer' => "https://example.invalid/{$break}X: y"], 'referer ' . $label, 'CR, LF, or NUL');
			rejected($curl, ['url' => $url, 'user_agent' => "agent{$break}X: y"], 'user_agent ' . $label, 'CR, LF, or NUL');
			rejected($curl, ['url' => $url, 'bearer_token' => "token{$break}X: y"], 'bearer_token ' . $label, 'CR, LF, or NUL');
		}
	},

	'header lines need a field name before a colon' => static function (): void {
		[$curl] = curl();
		rejected($curl, ['url' => Server::$baseUrl . '/', 'headers' => ['NoColon']], 'no colon');
		rejected($curl, ['url' => Server::$baseUrl . '/', 'headers' => [': value']], 'empty name');
		rejected($curl, ['url' => Server::$baseUrl . '/', 'headers' => [42]], 'non-string line');
		rejected($curl, ['url' => Server::$baseUrl . '/', 'headers' => 'X-Test: a'], 'string instead of list');
	},

	'verify_peer and verify_host accept only their documented values' => static function (): void {
		[$curl] = curl();
		foreach ([null, 2, 'yes', 'true', 0.5, []] as $value) {
			rejected($curl, ['url' => Server::$baseUrl . '/', 'verify_peer' => $value], 'verify_peer ' . export($value), '"verify_peer"');
		}
		foreach ([1, '1', 3, null, 'yes', 2.0] as $value) {
			rejected($curl, ['url' => Server::$baseUrl . '/', 'verify_host' => $value], 'verify_host ' . export($value), '"verify_host"');
		}
		foreach ([[true, 2], [1, '2'], ['1', 0], [false, false], [0, '0'], ['0', true]] as [$peer, $host]) {
			$r = $curl->execute(['url' => Server::$baseUrl . '/', 'verify_peer' => $peer, 'verify_host' => $host]);
			same(200, $r['status_code'], 'status with verify_peer ' . export($peer) . ' and verify_host ' . export($host));
		}
	},

	'negative, non-finite and non-numeric timeouts are rejected' => static function (): void {
		[$curl] = curl();
		foreach ([-1, -0.5, \NAN, \INF, 'abc', null, '1e400'] as $value) {
			rejected($curl, ['url' => Server::$baseUrl . '/', 'timeout' => $value], 'timeout ' . export($value), '"timeout"');
			rejected($curl, ['url' => Server::$baseUrl . '/', 'connect_timeout' => $value], 'connect_timeout ' . export($value), '"connect_timeout"');
		}
		rejected($curl, ['url' => Server::$baseUrl . '/', 'max_redirects' => -1, 'follow_redirects' => true], 'max_redirects -1', '"max_redirects"');
	},

	'authentication sources cannot be combined' => static function (): void {
		[$curl] = curl();
		$url = Server::$baseUrl . '/';
		rejected($curl, ['url' => $url, 'basic_auth' => ['username' => 'u', 'password' => 'p'], 'bearer_token' => 't'], 'basic_auth + bearer_token', 'basic_auth');
		rejected($curl, ['url' => $url, 'basic_auth' => ['username' => 'u'], 'headers' => ['authorization: Basic x']], 'basic_auth + Authorization header', 'basic_auth');
		rejected($curl, ['url' => $url, 'bearer_token' => 't', 'headers' => ['Authorization: Bearer other']], 'bearer_token + Authorization header', 'bearer_token');
	},

	'malformed basic_auth and bearer_token are rejected' => static function (): void {
		[$curl] = curl();
		$url = Server::$baseUrl . '/';
		rejected($curl, ['url' => $url, 'basic_auth' => 'user:pass'], 'basic_auth string', '"basic_auth"');
		rejected($curl, ['url' => $url, 'basic_auth' => ['username' => '']], 'empty username', '"basic_auth.username"');
		rejected($curl, ['url' => $url, 'basic_auth' => ['username' => 'u', 'password' => 5]], 'integer password', '"basic_auth.password"');
		rejected($curl, ['url' => $url, 'bearer_token' => ''], 'empty bearer_token', '"bearer_token"');
	},

	'curl_options must be keyed by CURLOPT_* integers' => static function (): void {
		[$curl] = curl();
		rejected($curl, ['url' => Server::$baseUrl . '/', 'curl_options' => ['CURLOPT_TIMEOUT' => 5]], 'string key', '"curl_options"');
	},

	'invalid sensitive_query_keys fail before transport' => static function (): void {
		[$curl] = curl();
		$url = Server::$baseUrl . '/invalid';
		rejected($curl, ['url' => $url, 'sensitive_query_keys' => 'apiKey'], 'string', '"sensitive_query_keys"');
		rejected($curl, ['url' => $url, 'sensitive_query_keys' => ['apiKey' => true]], 'associative array', '"sensitive_query_keys"');
		rejected($curl, ['url' => $url, 'sensitive_query_keys' => [123]], 'non-string key', '"sensitive_query_keys"');
		rejected($curl, ['url' => $url, 'sensitive_query_keys' => ['']], 'empty key', '"sensitive_query_keys"');
	},

];


// ----------------------------------------------------------------
// Checks: transport and response
// ----------------------------------------------------------------

$transport = [

	'GET, HEAD and POST use native modes; other methods and GET with a body are sent as custom requests' => static function (): void {
		[$curl] = curl();
		$url = Server::$baseUrl . '/method';
		$cases = [
			[['method' => 'GET'], 'GET', ''],
			[['method' => 'head'], 'HEAD', ''],
			[['method' => 'POST'], 'POST', ''],
			[['method' => 'POST', 'body' => 'a=1'], 'POST', 'a=1'],
			[['method' => 'put', 'body' => '{"x":1}'], 'PUT', '{"x":1}'],
			[['method' => 'DELETE'], 'DELETE', ''],
			[['method' => 'GET', 'body' => 'payload'], 'GET', 'payload'],
		];
		foreach ($cases as [$request, $method, $body]) {
			$r = $curl->execute(['url' => $url] + $request);
			same($method, $r['request']['method'], 'normalized method for ' . export($request));
			$seen = Server::last();
			same([$method, $body], [$seen['method'], $seen['body']], 'method and body at the server for ' . export($request));
		}
		$curl->execute(['url' => $url, 'method' => 'POST']);
		same('0', Server::last()['headers']['content-length'] ?? null, 'Content-Length of a POST without body');
		rejected($curl, ['url' => $url, 'method' => 'GE T'], 'method with a space', '"method"');
	},

	// Native POST follows the rules of the redirect status; a custom request method
	// is sent again on every hop.
	'a followed 302 turns a POST into a GET, while a custom method is kept' => static function (): void {
		[$curl] = curl();
		$curl->execute(['url' => Server::$baseUrl . '/redirect', 'method' => 'POST', 'body' => 'a=1', 'follow_redirects' => true]);
		$requests = \array_slice(Server::requests(), -2);
		same([['POST', '/redirect'], ['GET', '/final?apiKey=redirect-secret&foo=bar']], \array_map(static fn(array $r): array => [$r['method'], $r['target']], $requests), 'POST hops');
		$curl->execute(['url' => Server::$baseUrl . '/redirect', 'method' => 'DELETE', 'follow_redirects' => true]);
		same(['DELETE', 'DELETE'], \array_column(\array_slice(Server::requests(), -2), 'method'), 'DELETE hops');
	},

	'query is appended after an existing query and before the fragment' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/q?a=1#frag', 'query' => ['b' => 'x y', 'c' => ['d' => 'e']]]);
		same(Server::$baseUrl . '/q?a=1&b=x%20y&c%5Bd%5D=e#frag', $r['request']['url'], 'request url');
		same('/q?a=1&b=x%20y&c%5Bd%5D=e', Server::last()['target'], 'target at the server');
	},

	'4xx and 5xx responses are returned with their status, not thrown' => static function (): void {
		[$curl] = curl();
		foreach ([404, 503] as $status) {
			$r = $curl->execute(['url' => Server::$baseUrl . '/status/' . $status]);
			same([$status, false, 'status ' . $status], [$r['status_code'], $r['is_http_success'], $r['body']], "status {$status}");
		}
		$r = $curl->execute(['url' => Server::$baseUrl . '/status/204']);
		same([204, true, '', 0], [$r['status_code'], $r['is_http_success'], $r['body'], $r['body_bytes']], 'status 204');
	},

	'final response headers have lowercase names and repeated fields as lists' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/headers']);
		same('one', $r['headers']['x-single'] ?? null, 'x-single');
		same(['first', 'second'], $r['headers']['x-multi'] ?? null, 'x-multi');
		expect(\str_starts_with($r['headers_raw'], 'HTTP/1.1 200'), 'headers_raw does not start with the status line: ' . export($r['headers_raw']));
		same([], \array_values(\array_filter(\array_keys($r['headers']), static fn(string $k): bool => $k !== \strtolower($k))), 'header names with capitals');
	},

	'a followed redirect keeps every head in headers_raw and only the final head in headers' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/redirect', 'follow_redirects' => true]);
		same(200, $r['status_code'], 'status');
		same(Server::$baseUrl . '/final?apiKey=redirect-secret&foo=bar', $r['effective_url'], 'effective url');
		same(2, \preg_match_all('#^HTTP/1\.1 \d{3}#m', $r['headers_raw']), 'status lines in headers_raw');
		contains('302', $r['headers_raw'], 'headers_raw');
		expect(!isset($r['headers']['location']), 'headers still hold the Location of the redirect hop');
	},

	'without follow_redirects the redirect is returned as is' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/redirect']);
		same([302, '/final?apiKey=redirect-secret&foo=bar'], [$r['status_code'], $r['headers']['location'] ?? null], 'status and location');
	},

	'max_redirects 0 refuses to follow a redirect' => static function (): void {
		[$curl] = curl();
		$e = thrown(CurlExecException::class, static fn() => $curl->execute(['url' => Server::$baseUrl . '/redirect', 'follow_redirects' => true, 'max_redirects' => 0]), 'max_redirects 0');
		same(\CURLE_TOO_MANY_REDIRECTS, $e->getCurlErrno(), 'curl errno');
	},

	'return_headers and capture_info can be switched off' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/headers', 'return_headers' => false, 'capture_info' => false]);
		same([[], '', []], [$r['headers'], $r['headers_raw'], $r['info']], 'headers, headers_raw and info');
		same('headers', $r['body'], 'body');
	},

	'basic_auth and bearer_token reach the server as Authorization headers' => static function (): void {
		[$curl] = curl();
		$curl->execute(['url' => Server::$baseUrl . '/auth', 'basic_auth' => ['username' => 'user', 'password' => 'p@ss:word']]);
		same('Basic ' . \base64_encode('user:p@ss:word'), Server::last()['headers']['authorization'] ?? null, 'basic');
		$curl->execute(['url' => Server::$baseUrl . '/auth', 'bearer_token' => 'tok123']);
		same('Bearer tok123', Server::last()['headers']['authorization'] ?? null, 'bearer');
	},

	'an explicit User-Agent header wins over user_agent, and curl_options apply last' => static function (): void {
		[$curl] = curl();
		$curl->execute(['url' => Server::$baseUrl . '/ua']);
		same('CitOmni Curl test', Server::last()['headers']['user-agent'] ?? null, 'cfg user_agent');
		$curl->execute(['url' => Server::$baseUrl . '/ua', 'headers' => ['User-Agent: header-ua']]);
		same('header-ua', Server::last()['headers']['user-agent'] ?? null, 'explicit header');
		$curl->execute(['url' => Server::$baseUrl . '/ua', 'curl_options' => [\CURLOPT_USERAGENT => 'option-ua']]);
		same('option-ua', Server::last()['headers']['user-agent'] ?? null, 'curl_options');
	},

	'cookie_store creates its directory and file, stores the cookie and sends it back' => static function (): void {
		[$curl] = curl();
		$store = Server::$docroot . '/cookies/nested/jar.txt';
		$curl->execute(['url' => Server::$baseUrl . '/cookie/set', 'cookie_store' => $store]);
		expect(\is_file($store), 'cookie jar was not created');
		contains('session_token', (string)\file_get_contents($store), 'cookie jar');
		$curl->execute(['url' => Server::$baseUrl . '/cookie/echo', 'cookie_store' => $store]);
		same('session_token=abc123', Server::last()['headers']['cookie'] ?? null, 'Cookie header');
	},

	'a cookie_file that is not a readable file is rejected' => static function (): void {
		[$curl] = curl();
		rejected($curl, ['url' => Server::$baseUrl . '/', 'cookie_file' => Server::$docroot], 'directory as cookie_file', 'Cookie file');
	},

	'a fractional timeout is enforced in milliseconds' => static function (): void {
		[$curl] = curl();
		$start = \microtime(true);
		$e = thrown(CurlExecException::class, static fn() => $curl->execute(['url' => Server::$baseUrl . '/slow', 'timeout' => 0.3]), 'slow response');
		$elapsed = \microtime(true) - $start;
		same(\CURLE_OPERATION_TIMEDOUT, $e->getCurlErrno(), 'curl errno');
		expect($elapsed < 0.8, 'timeout 0.3 took ' . \round($elapsed, 2) . ' s');
	},

];


// ----------------------------------------------------------------
// Checks: logging
// ----------------------------------------------------------------

$logging = [

	'success logging writes method, url, status and log_context to the cfg log_file' => static function (): void {
		[$curl, $log] = curl();
		$curl->execute(['url' => Server::$baseUrl . '/status/201', 'method' => 'POST', 'log_context' => ['job' => 'sync']]);
		same(1, \count($log->records), 'records');
		$rec = $log->records[0];
		same(['curl.jsonl', 'curl.success'], [$rec['file'], $rec['category']], 'file and category');
		same(['sync', 'POST', Server::$baseUrl . '/status/201', 201], [$rec['context']['job'] ?? null, $rec['context']['method'] ?? null, $rec['context']['url'] ?? null, $rec['context']['status_code'] ?? null], 'context');
	},

	'log_file per request overrides the cfg log_file' => static function (): void {
		[$curl, $log] = curl();
		$curl->execute(['url' => Server::$baseUrl . '/', 'log_file' => 'partner.jsonl']);
		same('partner.jsonl', $log->records[0]['file'] ?? null, 'log file');
	},

	'log_success false writes nothing for completed transfers, including 5xx' => static function (): void {
		[$curl, $log] = curl(['log_success' => false]);
		$curl->execute(['url' => Server::$baseUrl . '/status/500']);
		same([], $log->records, 'records');
	},

	'a transport failure is logged as curl.error unless log_errors is false' => static function (): void {
		$closed = 'http://127.0.0.1:' . freePort() . '/down';
		[$curl, $log] = curl();
		thrown(CurlExecException::class, static fn() => $curl->execute(['url' => $closed, 'connect_timeout' => 1]), 'closed port');
		same('curl.error', $log->records[0]['category'] ?? null, 'category');
		same([$closed, 'GET'], [$log->records[0]['context']['url'] ?? null, $log->records[0]['context']['method'] ?? null], 'url and method');
		expect(($log->records[0]['context']['curl_errno'] ?? 0) > 0, 'curl_errno missing');

		[$quiet, $quietLog] = curl(['log_errors' => false]);
		thrown(CurlExecException::class, static fn() => $quiet->execute(['url' => $closed, 'connect_timeout' => 1]), 'closed port, log_errors false');
		same([], $quietLog->records, 'records with log_errors false');
	},

	'without a log service requests and failures are not logged and still work' => static function (): void {
		[$curl] = curl([], [], false);
		same(200, $curl->execute(['url' => Server::$baseUrl . '/'])['status_code'], 'status');
		thrown(CurlExecException::class, static fn() => $curl->execute(['url' => 'http://127.0.0.1:' . freePort() . '/', 'connect_timeout' => 1]), 'closed port');
	},

];


// ----------------------------------------------------------------
// Checks: sensitive query values
// ----------------------------------------------------------------

$redaction = [

	'without sensitive_query_keys the URL is sent and reported unchanged' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/plain', 'query' => ['foo' => 'a b']]);
		$url = Server::$baseUrl . '/plain?foo=a%20b';
		same([$url, $url, $url], [$r['request']['url'], $r['effective_url'], $r['info']['url'] ?? null], 'request, effective and info url');
		same('/plain?foo=a%20b', Server::last()['target'], 'target at the server');
	},

	'a sensitive value is sent unchanged but redacted from returned URLs and the success log' => static function (): void {
		[$curl, $log] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/one', 'query' => ['apiKey' => 'A B&/?', 'foo' => 'bar'], 'sensitive_query_keys' => ['apiKey']]);
		$exposed = Server::$baseUrl . '/one?apiKey=[REDACTED]&foo=bar';
		same([$exposed, $exposed, $exposed], [$r['request']['url'], $r['effective_url'], $r['info']['url'] ?? null], 'request, effective and info url');
		same('/one?apiKey=A%20B%26%2F%3F&foo=bar', Server::last()['target'], 'target at the server');
		same(['curl.success', $exposed], [$log->records[0]['category'] ?? null, $log->records[0]['context']['url'] ?? null], 'success log');
		excludes('A%20B%26%2F%3F', \serialize($log->records), 'success log');
	},

	'a sensitive top-level key also redacts nested bracket parameters' => static function (): void {
		[$curl, $log] = curl();
		$r = $curl->execute([
			'url'                  => Server::$baseUrl . '/nested',
			'query'                => ['apiKey' => ['first' => 'nested-secret-one', 'group' => ['second' => 'nested-secret-two']], 'foo' => 'bar'],
			'sensitive_query_keys' => ['apiKey'],
		]);
		$exposed = Server::$baseUrl . '/nested?apiKey%5Bfirst%5D=[REDACTED]&apiKey%5Bgroup%5D%5Bsecond%5D=[REDACTED]&foo=bar';
		same([$exposed, $exposed, $exposed], [$r['request']['url'], $r['effective_url'], $r['info']['url'] ?? null], 'request, effective and info url');
		$target = Server::last()['target'];
		contains('apiKey%5Bfirst%5D=nested-secret-one', $target, 'target at the server');
		contains('apiKey%5Bgroup%5D%5Bsecond%5D=nested-secret-two', $target, 'target at the server');
		same($exposed, $log->records[0]['context']['url'] ?? null, 'success log url');
		excludes('nested-secret', \serialize($log->records), 'success log');
	},

	'duplicate and multiple sensitive parameters are all redacted in order' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute([
			'url'                  => Server::$baseUrl . '/duplicates?apiKey=first&keep=1&apiKey=second&keep=2',
			'query'                => ['token' => 'third', 'keep2' => 'two'],
			'sensitive_query_keys' => ['apiKey', 'token'],
		]);
		same(Server::$baseUrl . '/duplicates?apiKey=[REDACTED]&keep=1&apiKey=[REDACTED]&keep=2&token=[REDACTED]&keep2=two', $r['request']['url'], 'request url');
		same('/duplicates?apiKey=first&keep=1&apiKey=second&keep=2&token=third&keep2=two', Server::last()['target'], 'target at the server');
	},

	'encoded parameter names match decoded sensitive keys without re-encoding the rest' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/encoded?api%4Bey=encoded-secret&na%6De=ok', 'sensitive_query_keys' => ['apiKey']]);
		same(Server::$baseUrl . '/encoded?api%4Bey=[REDACTED]&na%6De=ok', $r['request']['url'], 'request url');
	},

	'an absent sensitive key changes nothing' => static function (): void {
		[$curl] = curl();
		$url = Server::$baseUrl . '/absent?foo=bar&apiKey=public-for-this-request';
		same($url, $curl->execute(['url' => $url, 'sensitive_query_keys' => ['missing']])['request']['url'], 'request url');
	},

	'the request line in CURLINFO_HEADER_OUT is redacted' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute([
			'url'                  => Server::$baseUrl . '/header',
			'query'                => ['apiKey' => 'header-secret', 'foo' => 'bar'],
			'sensitive_query_keys' => ['apiKey'],
			'curl_options'         => [\CURLINFO_HEADER_OUT => true],
		]);
		$header = (string)($r['info']['request_header'] ?? '');
		contains('/header?apiKey=[REDACTED]&foo=bar', $header, 'request header');
		excludes('header-secret', $header, 'request header');
	},

	'a Referer URL in CURLINFO_HEADER_OUT is redacted' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute([
			'url'                  => Server::$baseUrl . '/referer',
			'sensitive_query_keys' => ['apiKey'],
			'referer'              => Server::$baseUrl . '/source?apiKey=referer-secret&foo=bar',
			'curl_options'         => [\CURLINFO_HEADER_OUT => true],
		]);
		$header = (string)($r['info']['request_header'] ?? '');
		contains('Referer: ' . Server::$baseUrl . '/source?apiKey=[REDACTED]&foo=bar', $header, 'request header');
		excludes('referer-secret', $header, 'request header');
		same(Server::$baseUrl . '/source?apiKey=referer-secret&foo=bar', Server::last()['headers']['referer'] ?? null, 'Referer at the server');
	},

	'the redirect_url of a pending redirect is redacted' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute(['url' => Server::$baseUrl . '/redirect', 'query' => ['apiKey' => 'initial-secret'], 'sensitive_query_keys' => ['apiKey']]);
		$redirect = (string)($r['info']['redirect_url'] ?? '');
		contains('apiKey=[REDACTED]', $redirect, 'redirect_url');
		excludes('redirect-secret', $redirect, 'redirect_url');
	},

	'the effective URL after a followed redirect is redacted' => static function (): void {
		[$curl] = curl();
		$r = $curl->execute([
			'url'                  => Server::$baseUrl . '/redirect',
			'query'                => ['apiKey' => 'initial-secret'],
			'sensitive_query_keys' => ['apiKey'],
			'follow_redirects'     => true,
			'max_redirects'        => 3,
		]);
		contains('/final?apiKey=[REDACTED]&foo=bar', $r['effective_url'], 'effective url');
		excludes('redirect-secret', $r['effective_url'] . \serialize($r['info']), 'effective url and info');
	},

	'a transport failure exposes only redacted URLs in the exception and the error log' => static function (): void {
		[$curl, $log] = curl();
		$e = thrown(CurlExecException::class, static fn() => $curl->execute([
			'url'                  => 'http://127.0.0.1:' . freePort() . '/failure',
			'query'                => ['apiKey' => 'exception-secret', 'foo' => 'bar'],
			'sensitive_query_keys' => ['apiKey'],
			'timeout'              => 0.25,
			'connect_timeout'      => 0.25,
		]), 'closed port');
		contains('apiKey=[REDACTED]', (string)$e->getRequestUrl(), 'exception request url');
		excludes('exception-secret', (string)$e->getRequestUrl() . \serialize($e->getTransferInfo()) . $e->getMessage(), 'exception');
		same('curl.error', $log->records[0]['category'] ?? null, 'log category');
		contains('apiKey=[REDACTED]', (string)($log->records[0]['context']['url'] ?? ''), 'error log url');
		excludes('exception-secret', \serialize($log->records), 'error log');
	},

];


$exitCode = 1;
try {
	Server::start($root);
	$exitCode = runChecks($validation + $transport + $logging + $redaction);
} finally {
	Server::stop();
	removeTree($root);
}
exit($exitCode);
