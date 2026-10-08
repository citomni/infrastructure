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

namespace CitOmni\Infrastructure\Tests\Mailer;

/*
 * Minimal SMTP server for the mailer suite. Not a suite of its own.
 *
 * Serves one connection at a time on 127.0.0.1:<port>. It advertises AUTH PLAIN
 * and no STARTTLS, accepts any credentials, rejects RCPT TO addresses in the
 * domain reject.invalid with 550, and appends every session to
 * <dir>/sessions.jsonl when the client quits or disconnects. On QUIT the session
 * is written before the 221 reply, so it is on disk when the client returns:
 *
 *   {"auth": "<decoded PLAIN response>"|null, "from": "<address>"|null,
 *    "rcpt": ["<address>", ...], "data": "<message>"|null}
 *
 * Usage (run.php starts it and stops it again):
 *   php smtp-server.php <port> <dir>
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}


/** Send one reply line. */
function reply($client, string $line): void {
	\fwrite($client, $line . "\r\n");
}


/** Append one session to <dir>/sessions.jsonl. */
function record(string $dir, array $session): void {
	\file_put_contents($dir . '/sessions.jsonl', \json_encode($session, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE) . "\n", \FILE_APPEND);
}


/** The address between < and > in a MAIL FROM or RCPT TO argument. */
function address(string $line): string {
	return \preg_match('/<([^>]*)>/', $line, $m) === 1 ? $m[1] : '';
}


/**
 * Serve one SMTP session and record what the client sent; a bare connection probe is not recorded.
 *
 * @param  resource  $client
 */
function serve($client, string $dir): void {
	reply($client, '220 citomni.test ESMTP test server');
	$session = ['auth' => null, 'from' => null, 'rcpt' => [], 'data' => null];
	$active  = false;

	while (($line = \fgets($client)) !== false) {
		$active = true;
		$line   = \rtrim($line, "\r\n");
		$verb   = \strtoupper((string)\strtok($line, ' '));

		switch ($verb) {
			case 'EHLO':
				\fwrite($client, "250-citomni.test\r\n250-AUTH PLAIN\r\n250-8BITMIME\r\n250 SIZE 10240000\r\n");
				break;
			case 'HELO':
				reply($client, '250 citomni.test');
				break;
			case 'AUTH':
				$parts = \explode(' ', $line);
				if (\strtoupper($parts[1] ?? '') !== 'PLAIN') {
					reply($client, '504 5.5.4 Unrecognized authentication type');
					break;
				}
				$response = $parts[2] ?? null;
				if ($response === null) {
					reply($client, '334 ');
					$response = \rtrim((string)\fgets($client), "\r\n");
				}
				$session['auth'] = (string)\base64_decode($response, true);
				reply($client, '235 2.7.0 Authentication successful');
				break;
			case 'MAIL':
				$session['from'] = address($line);
				reply($client, '250 2.1.0 OK');
				break;
			case 'RCPT':
				$to = address($line);
				if (\str_ends_with(\strtolower($to), '@reject.invalid')) {
					reply($client, '550 5.1.1 Recipient rejected');
				} else {
					$session['rcpt'][] = $to;
					reply($client, '250 2.1.5 OK');
				}
				break;
			case 'DATA':
				reply($client, '354 End data with <CR><LF>.<CR><LF>');
				$data = '';
				while (($dataLine = \fgets($client)) !== false && $dataLine !== ".\r\n") {
					// Undo dot-stuffing.
					$data .= \str_starts_with($dataLine, '..') ? \substr($dataLine, 1) : $dataLine;
				}
				$session['data'] = $data;
				reply($client, '250 2.0.0 Queued');
				break;
			case 'RSET':
			case 'NOOP':
				reply($client, '250 2.0.0 OK');
				break;
			case 'QUIT':
				record($dir, $session);
				reply($client, '221 2.0.0 Bye');
				return;
			default:
				reply($client, '502 5.5.2 Command not recognized');
		}
	}

	if ($active) {
		record($dir, $session);
	}
}


[, $port, $dir] = $argv + [null, '0', ''];

$server = \stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
	\fwrite(\STDERR, "Cannot listen on 127.0.0.1:{$port}: {$errstr}\n");
	exit(1);
}

while (true) {
	$client = @\stream_socket_accept($server, -1);
	if ($client === false) {
		continue;
	}
	\stream_set_timeout($client, 10);
	serve($client, $dir);
	\fclose($client);
}
