<?php
declare(strict_types=1);

$captureFile = \getenv('CITOMNI_CURL_TEST_CAPTURE');
if (!\is_string($captureFile) || $captureFile === '') {
	\http_response_code(500);
	echo 'Missing capture file.';
	return;
}

$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
\file_put_contents($captureFile, $requestUri . "\n", \FILE_APPEND | \LOCK_EX);

$path = \parse_url($requestUri, \PHP_URL_PATH);

if ($path === '/redirect') {
	\header('Location: /final?apiKey=redirect-secret&foo=bar', true, 302);
	echo 'redirect';
	return;
}

\header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
