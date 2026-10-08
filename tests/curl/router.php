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

/*
 * Router for PHP's built-in web server in the curl suite. Not a suite of its own.
 *
 * Every request is appended as one JSON line to <docroot>/requests.jsonl, so the
 * suite sees exactly what reached the server: method, request target, headers
 * and body. The path selects the response:
 *
 * - /redirect        302 to /final?apiKey=redirect-secret&foo=bar
 * - /headers         X-Single once and X-Multi twice
 * - /status/<code>   that status code
 * - /cookie/set      sets the cookie session_token=abc123
 * - /slow            answers after 1.5 seconds
 * - anything else    200 "OK"
 */

$docroot = (string)$_SERVER['DOCUMENT_ROOT'];
$target  = (string)($_SERVER['REQUEST_URI'] ?? '');
$path    = (string)\parse_url($target, \PHP_URL_PATH);

$record = [
	'method'  => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
	'target'  => $target,
	'headers' => \array_change_key_case(\getallheaders(), \CASE_LOWER),
	'body'    => (string)\file_get_contents('php://input'),
];
\file_put_contents($docroot . '/requests.jsonl', \json_encode($record, \JSON_UNESCAPED_SLASHES) . "\n", \FILE_APPEND | \LOCK_EX);

if ($path === '/redirect') {
	\header('Location: /final?apiKey=redirect-secret&foo=bar', true, 302);
	echo 'redirect';
	return;
}

if ($path === '/headers') {
	\header('X-Single: one');
	\header('X-Multi: first');
	\header('X-Multi: second', false);
	echo 'headers';
	return;
}

if (\preg_match('#^/status/([1-5][0-9]{2})$#', $path, $m) === 1) {
	\http_response_code((int)$m[1]);
	echo 'status ' . $m[1];
	return;
}

if ($path === '/cookie/set') {
	\setcookie('session_token', 'abc123', ['path' => '/']);
	echo 'cookie set';
	return;
}

if ($path === '/slow') {
	\usleep(1_500_000);
	echo 'slow';
	return;
}

\header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
