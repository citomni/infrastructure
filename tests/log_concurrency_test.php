<?php
declare(strict_types=1);

/*
 * Simple concurrency test launcher for CitOmni Log service against production.
 */

$baseUrl = 'https://www.malmkjaer.net/test/x9f2k-log-probe-7h3q';
$fileBase = 'concurrency_probe';
$concurrentRequests = 12;
$entriesPerRequest = 200;
$payloadSize = 800;
$timeoutSeconds = 60;

$callJson = static function(string $url, int $timeoutSeconds): array {
	$ch = \curl_init();

	\curl_setopt_array($ch, [
		\CURLOPT_URL => $url,
		\CURLOPT_RETURNTRANSFER => true,
		\CURLOPT_TIMEOUT => $timeoutSeconds,
		\CURLOPT_CONNECTTIMEOUT => 10,
		\CURLOPT_FOLLOWLOCATION => false,
		\CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
		\CURLOPT_FRESH_CONNECT => true,
		\CURLOPT_FORBID_REUSE => true,
		\CURLOPT_NOSIGNAL => true,
		\CURLOPT_HTTPHEADER => [
			'Accept: application/json',
			'Connection: close',
		],
	]);

	$body = \curl_exec($ch);
	$httpCode = (int)\curl_getinfo($ch, \CURLINFO_HTTP_CODE);
	$errorNo = \curl_errno($ch);
	$error = \curl_error($ch);

	\curl_close($ch);

	$decoded = \is_string($body) ? \json_decode($body, true) : null;

	return [
		'http_code' => $httpCode,
		'curl_errno' => $errorNo,
		'curl_error' => $error,
		'body' => $body,
		'json' => \is_array($decoded) ? $decoded : null,
	];
};

echo "Cleaning up remote test files..." . PHP_EOL;

$cleanupUrl = $baseUrl
	. '?op=cleanup'
	. '&file=' . \rawurlencode($fileBase);

$cleanup = $callJson($cleanupUrl, $timeoutSeconds);

if (
	$cleanup['http_code'] !== 200 ||
	$cleanup['curl_errno'] !== 0 ||
	!isset($cleanup['json']['ok']) ||
	$cleanup['json']['ok'] !== true
) {
	echo "Remote cleanup failed:" . PHP_EOL;
	echo \json_encode($cleanup, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;
	exit(1);
}

echo "Dispatching {$concurrentRequests} concurrent requests..." . PHP_EOL;

$multi = \curl_multi_init();
$handles = [];
$requestIds = [];

for ($i = 0; $i < $concurrentRequests; $i++) {
	$requestId = 'req_' . $i . '_' . \bin2hex(\random_bytes(4));
	$requestIds[] = $requestId;

	$url = $baseUrl
		. '?op=write'
		. '&file=' . \rawurlencode($fileBase)
		. '&request_id=' . \rawurlencode($requestId)
		. '&entries=' . $entriesPerRequest
		. '&size=' . $payloadSize;

	$ch = \curl_init();

	\curl_setopt_array($ch, [
		\CURLOPT_URL => $url,
		\CURLOPT_RETURNTRANSFER => true,
		\CURLOPT_TIMEOUT => $timeoutSeconds,
		\CURLOPT_CONNECTTIMEOUT => 10,
		\CURLOPT_FOLLOWLOCATION => false,
		\CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
		\CURLOPT_FRESH_CONNECT => true,
		\CURLOPT_FORBID_REUSE => true,
		\CURLOPT_NOSIGNAL => true,
		\CURLOPT_HTTPHEADER => [
			'Accept: application/json',
			'Connection: close',
		],
	]);

	\curl_multi_add_handle($multi, $ch);
	$handles[] = $ch;
}

$running = 0;

do {
	do {
		$status = \curl_multi_exec($multi, $running);
	} while ($status === \CURLM_CALL_MULTI_PERFORM);

	if ($status !== \CURLM_OK) {
		break;
	}

	if ($running > 0) {
		$selectResult = \curl_multi_select($multi, 1.0);

		if ($selectResult === -1) {
			\usleep(10000);
		}
	}
} while ($running > 0);

$httpFailures = [];

foreach ($handles as $index => $ch) {
	$body = \curl_multi_getcontent($ch);
	$httpCode = (int)\curl_getinfo($ch, \CURLINFO_HTTP_CODE);
	$errorNo = \curl_errno($ch);
	$error = \curl_error($ch);
	$decoded = \json_decode($body, true);
	$bodyOk = \is_array($decoded) && (($decoded['ok'] ?? false) === true);

	if ($httpCode !== 200 || $errorNo !== 0 || $error !== '' || !$bodyOk) {
		$httpFailures[] = [
			'index' => $index,
			'request_id' => $requestIds[$index],
			'http_code' => $httpCode,
			'curl_errno' => $errorNo,
			'curl_error' => $error,
			'effective_url' => (string)\curl_getinfo($ch, \CURLINFO_EFFECTIVE_URL),
			'primary_ip' => (string)\curl_getinfo($ch, \CURLINFO_PRIMARY_IP),
			'total_time' => (float)\curl_getinfo($ch, \CURLINFO_TOTAL_TIME),
			'body' => $body,
		];
	}

	\curl_multi_remove_handle($multi, $ch);
	\curl_close($ch);
}

\curl_multi_close($multi);

if ($httpFailures !== []) {
	echo 'HTTP/request failures detected:' . PHP_EOL;
	echo \json_encode($httpFailures, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;
	exit(1);
}

echo "All HTTP requests returned 200." . PHP_EOL;
echo "Fetching remote summary..." . PHP_EOL;

$summaryUrl = $baseUrl
	. '?op=summary'
	. '&file=' . \rawurlencode($fileBase);

$summary = $callJson($summaryUrl, $timeoutSeconds);

if (
	$summary['http_code'] !== 200 ||
	$summary['curl_errno'] !== 0 ||
	!isset($summary['json']['ok']) ||
	$summary['json']['ok'] !== true
) {
	echo "Remote summary failed:" . PHP_EOL;
	echo \json_encode($summary, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;
	exit(1);
}

$summaryJson = $summary['json'];
$expected = $concurrentRequests * $entriesPerRequest;
$observed = (int)($summaryJson['observed_unique_entries'] ?? 0);
$invalidLineCount = (int)($summaryJson['invalid_line_count'] ?? 0);
$categoryFailures = (int)($summaryJson['category_failures'] ?? 0);

$result = [
	'ok' => $observed === $expected && $invalidLineCount === 0 && $categoryFailures === 0,
	'expected_entries' => $expected,
	'observed_unique_entries' => $observed,
	'total_lines_read' => (int)($summaryJson['total_lines_read'] ?? 0),
	'category_failures' => $categoryFailures,
	'invalid_line_count' => $invalidLineCount,
	'log_files' => $summaryJson['log_files'] ?? [],
	'invalid_lines' => $summaryJson['invalid_lines'] ?? [],
	'seen_keys_sample' => $summaryJson['seen_keys_sample'] ?? [],
];

echo \json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($result['ok'] ? 0 : 1);