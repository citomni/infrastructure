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

use CitOmni\Infrastructure\Boot\Registry;
use CitOmni\Infrastructure\Service\Mailer;
use CitOmni\Infrastructure\Tests\Support\App;
use CitOmni\Infrastructure\Tests\Support\LogRecorder;
use CitOmni\Infrastructure\Tests\Support\MemorySecrets;
use PHPMailer\PHPMailer\PHPMailer;

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
 * Standalone suite for \CitOmni\Infrastructure\Service\Mailer.
 *
 * The real Mailer and the real PHPMailer run on the kernel doubles, without the
 * Composer autoloader. PHPMailer is loaded from vendor/phpmailer/phpmailer, so
 * the package dependencies must be installed (composer install). Messages go to
 * smtp-server.php, a minimal SMTP server on a free local port that records what
 * it receives in a temporary directory, which is removed again afterwards.
 *
 * CITOMNI_ENVIRONMENT is "prod", so the checks see the production logging policy.
 *
 * Usage:
 *   php tests/mailer/run.php
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

\define('CITOMNI_ENVIRONMENT', 'prod');

const PHPMAILER_SRC = __DIR__ . '/../../vendor/phpmailer/phpmailer/src';

if (!\is_file(PHPMAILER_SRC . '/PHPMailer.php')) {
	\fwrite(\STDERR, "FAIL phpmailer/phpmailer is installed - vendor/phpmailer/phpmailer is missing; run composer install in the package root\n");
	\fwrite(\STDOUT, "0 passed, 1 failed\n");
	exit(1);
}

require PHPMAILER_SRC . '/Exception.php';
require PHPMAILER_SRC . '/PHPMailer.php';
require PHPMAILER_SRC . '/SMTP.php';
require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';

$root = tempDir('mailer');
\define('CITOMNI_APP_PATH', $root);

require __DIR__ . '/../../src/Boot/Registry.php';
require __DIR__ . '/../../src/Service/Mailer.php';

/** Secret SMTP password of the checks; it must never show up in a log record. */
const SMTP_PASSWORD = 'smtp-secret-7f3a';


/**
 * Mailer with read access to its PHPMailer instance.
 */
final class InspectableMailer extends Mailer {

	public function phpMailer(): PHPMailer {
		return $this->mailer;
	}
}


/**
 * The fixture SMTP server.
 */
final class SmtpServer {

	public static int $port = 0;

	public static string $dir = '';

	/** @var resource|null */
	private static mixed $process = null;

	public static function start(string $dir): void {
		self::$port = freePort();
		self::$dir  = $dir;
		$process = \proc_open([...phpCommand(), __DIR__ . '/smtp-server.php', (string)self::$port, $dir], [
			0 => ['pipe', 'r'],
			1 => ['file', $dir . '/server.out', 'w'],
			2 => ['file', $dir . '/server.err', 'w'],
		], $pipes);
		if (!\is_resource($process)) {
			throw new \RuntimeException('Cannot start the SMTP server.');
		}
		\fclose($pipes[0]);
		self::$process = $process;
		waitForPort(self::$port);
	}

	public static function stop(): void {
		if (\is_resource(self::$process)) {
			\proc_terminate(self::$process);
			\proc_close(self::$process);
		}
		self::$process = null;
	}

	/**
	 * Sessions the server has recorded so far, oldest first.
	 *
	 * @return list<array{auth: ?string, from: ?string, rcpt: list<string>, data: ?string}>
	 */
	public static function sessions(): array {
		return readJsonLines(self::$dir . '/sessions.jsonl');
	}

	/** The most recent session; fails the check when there is none. */
	public static function last(): array {
		$sessions = self::sessions();
		expect($sessions !== [], 'the SMTP server received no session');
		return $sessions[\array_key_last($sessions)];
	}
}


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/**
 * A mailer on the shipped mail cfg, pointed at the fixture server, plus its log and secrets.
 *
 * @param  array<string, mixed>   $mail     Overrides for the "mail" cfg node.
 * @param  array<string, string>  $secrets  Secrets; by default the SMTP password.
 * @return array{0: InspectableMailer, 1: LogRecorder, 2: MemorySecrets}
 */
function mailer(array $mail = [], array $secrets = ['mail.smtp.password' => SMTP_PASSWORD]): array {
	$cfg = mergeLastWins(Registry::CFG_HTTP, ['mail' => mergeLastWins([
		'from'     => ['email' => 'noreply@example.com', 'name' => 'CitOmni Test'],
		'reply_to' => ['email' => 'support@example.com', 'name' => 'Support'],
		'smtp'     => ['host' => '127.0.0.1', 'port' => SmtpServer::$port, 'username' => 'mailer@example.com', 'timeout' => 5],
	], $mail)]);
	$app = new App($cfg);
	$log = new LogRecorder();
	$store = new MemorySecrets($secrets);
	$app->set('log', $log);
	$app->set('secrets', $store);
	return [new InspectableMailer($app), $log, $store];
}


/** The value of one header in a raw message, unfolded; null when absent. */
function headerValue(string $data, string $name): ?string {
	[$head] = \explode("\r\n\r\n", $data, 2);
	$head = (string)\preg_replace('/\r\n[ \t]+/', ' ', $head);
	return \preg_match('/^' . \preg_quote($name, '/') . ':[ \t]*(.*)$/mi', $head, $m) === 1 ? \rtrim($m[1]) : null;
}


/** Fail unless $needle occurs in $haystack. */
function contains(string $needle, string $haystack, string $what): void {
	expect(\str_contains($haystack, $needle), $what . ': ' . export($needle) . ' missing from ' . export($haystack));
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'the shipped mail cfg builds an SMTP mailer without reading the password' => static function (): void {
		$app = new App(Registry::CFG_HTTP);
		$secrets = new MemorySecrets(['mail.smtp.password' => SMTP_PASSWORD]);
		$app->set('secrets', $secrets);
		$m = (new InspectableMailer($app))->phpMailer();
		same(
			['smtp', 587, '', true, true, 15, false, 'UTF-8', 'CitOmni Mailer', ''],
			[$m->Mailer, $m->Port, $m->SMTPSecure, $m->SMTPAuth, $m->SMTPAutoTLS, $m->Timeout, $m->SMTPKeepAlive, $m->CharSet, $m->XMailer, $m->Password],
			'transport, port, secure, auth, auto TLS, timeout, keepalive, charset, X-Mailer, password'
		);
		same([], $secrets->reads, 'secret reads');
	},

	'the legacy mail.smtp.password cfg key is rejected' => static function (): void {
		$e = thrown(\UnexpectedValueException::class, static fn() => mailer(['smtp' => ['password' => 'committed']]), 'cfg password');
		expect(!\str_contains($e->getMessage(), 'committed'), 'message contains the password: ' . $e->getMessage());
	},

	'transport and encryption follow the mail cfg' => static function (): void {
		$cases = [
			[['transport' => 'sendmail', 'sendmail_path' => '/opt/bin/sendmail'], ['sendmail', '/opt/bin/sendmail']],
			[['transport' => 'qmail'], ['qmail', '/var/qmail/bin/sendmail']],
			[['transport' => 'mail'], ['mail', null]],
			[['transport' => 'pigeon'], ['mail', null]],
		];
		foreach ($cases as [$cfg, [$transport, $path]]) {
			$m = mailer($cfg)[0]->phpMailer();
			same($transport, $m->Mailer, 'transport for ' . export($cfg));
			if ($path !== null) {
				same($path, $m->Sendmail, 'binary for ' . export($cfg));
			}
		}
		same(PHPMailer::ENCRYPTION_STARTTLS, mailer(['smtp' => ['encryption' => 'TLS']])[0]->phpMailer()->SMTPSecure, 'TLS');
		same(PHPMailer::ENCRYPTION_SMTPS, mailer(['smtp' => ['encryption' => 'ssl']])[0]->phpMailer()->SMTPSecure, 'ssl');
		same('', mailer(['smtp' => ['auth' => false]])[0]->phpMailer()->Username, 'username without auth');
	},

	'an HTML message is sent with From and Reply-To from cfg, {vars} injected and a generated text part' => static function (): void {
		[$mailer] = mailer();
		$ok = $mailer->to('sarah@example.com', 'Sarah')
			->subject('Welcome')
			->templateVars(['name' => 'Sarah'])
			->body('<p>Hello <b>{name}</b></p><p>Bye &amp; thanks</p>', true)
			->send();
		same(true, $ok, 'send()');
		$session = SmtpServer::last();
		same(['noreply@example.com', ['sarah@example.com']], [$session['from'], $session['rcpt']], 'envelope');
		$data = (string)$session['data'];
		same(['CitOmni Test <noreply@example.com>', 'Support <support@example.com>', 'Welcome', 'Sarah <sarah@example.com>'], [headerValue($data, 'From'), headerValue($data, 'Reply-To'), headerValue($data, 'Subject'), headerValue($data, 'To')], 'headers');
		contains('multipart/alternative', (string)headerValue($data, 'Content-Type'), 'Content-Type');
		contains('<p>Hello <b>Sarah</b></p>', $data, 'HTML part');
		contains("Hello Sarah\r\nBye & thanks", $data, 'text part');
	},

	'authenticated SMTP reads mail.smtp.password from Secrets at send time and clears it afterwards' => static function (): void {
		[$mailer, , $secrets] = mailer();
		same([], $secrets->reads, 'secret reads before send()');
		same(true, $mailer->to('a@example.com')->subject('Auth')->body('x')->send(), 'send()');
		same("\0mailer@example.com\0" . SMTP_PASSWORD, SmtpServer::last()['auth'], 'AUTH PLAIN credentials at the server');
		same(['mail.smtp.password'], $secrets->reads, 'secret reads');
		same('', $mailer->phpMailer()->Password, 'password left on PHPMailer');
	},

	'SMTP without authentication never reads a secret' => static function (): void {
		[$mailer, , $secrets] = mailer(['smtp' => ['auth' => false]], []);
		same(true, $mailer->to('a@example.com')->subject('No auth')->body('x')->send(), 'send()');
		same([null, []], [SmtpServer::last()['auth'], $secrets->reads], 'AUTH at the server and secret reads');
	},

	'a missing SMTP password throws before anything is sent' => static function (): void {
		[$mailer] = mailer([], []);
		$before = \count(SmtpServer::sessions());
		thrown(\OutOfBoundsException::class, static fn() => $mailer->to('a@example.com')->subject('x')->body('x')->send(), 'send()');
		same($before, \count(SmtpServer::sessions()), 'sessions');
		same([], $mailer->phpMailer()->getToAddresses(), 'recipients after the failure');
	},

	'a rejected recipient makes send() return false and logs a masked error without bodies' => static function (): void {
		[$mailer, $log] = mailer();
		same(false, $mailer->to('nobody@reject.invalid')->subject('Bounce')->body('<p>private body</p>')->send(), 'send()');
		same(1, \count($log->records), 'log records');
		$rec = $log->records[0];
		same(['mailer_errors.jsonl', 'error'], [$rec['file'], $rec['category']], 'file and category');
		same(['smtp', 'ma*****@example.com', ['nobody@reject.invalid']], [$rec['context']['transport']['mailer'] ?? null, $rec['context']['transport']['username'] ?? null, $rec['context']['to'] ?? null], 'transport and recipients');
		same(\hash('sha256', '<p>private body</p>'), $rec['context']['body_sha256'] ?? null, 'body hash');
		expect(!\array_key_exists('body', $rec['context']) && !\array_key_exists('body_preview', $rec['context']), 'body logged outside dev without include_bodies: ' . \implode(', ', \array_keys($rec['context'])));
		$serialized = \serialize($log->records);
		expect(!\str_contains($serialized, SMTP_PASSWORD) && !\str_contains($serialized, 'private body'), 'log record contains the password or the body');
		contains('nobody@reject.invalid', (string)$mailer->getLastErrorMessage(), 'last error message');
		same([], $mailer->phpMailer()->getToAddresses(), 'recipients after the failure');
	},

	'include_bodies puts both bodies into the error log' => static function (): void {
		[$mailer, $log] = mailer(['logging' => ['include_bodies' => true]]);
		$mailer->to('nobody@reject.invalid')->subject('Bounce')->body('<p>visible body</p>')->send();
		same(['<p>visible body</p>', 'visible body'], [$log->records[0]['context']['body'] ?? null, $log->records[0]['context']['altBody'] ?? null], 'body and altBody');
	},

	'log_success writes nothing outside dev' => static function (): void {
		[$mailer, $log] = mailer(['logging' => ['log_success' => true]]);
		same(true, $mailer->to('a@example.com')->subject('Quiet')->body('x')->send(), 'send()');
		same([], $log->records, 'log records');
	},

	'recipients take strings, lists and email/name pairs, and Bcc stays out of the headers' => static function (): void {
		[$mailer] = mailer();
		$mailer->to(['a@example.com', ['email' => 'b@example.com', 'name' => 'B Person']])
			->cc('c@example.com', 'C Person')
			->bcc('d@example.com')
			->subject('Many')
			->body('x', false)
			->send();
		$session = SmtpServer::last();
		same(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'], $session['rcpt'], 'envelope recipients');
		$data = (string)$session['data'];
		same(['a@example.com, B Person <b@example.com>', 'C Person <c@example.com>', null], [headerValue($data, 'To'), headerValue($data, 'Cc'), headerValue($data, 'Bcc')], 'To, Cc, Bcc');
		expect(!\str_contains($data, 'd@example.com'), 'Bcc address in the message');
	},

	// Regression: send() switched to text mode but left Body empty, and PHPMailer
	// refuses a message without a body.
	'a message with only altBody() is sent as text/plain with that text as its body' => static function (): void {
		[$mailer, $log] = mailer();
		$ok = $mailer->to('a@example.com')->subject('Plain')->altBody("Plain only\nSecond line")->send();
		same(true, $ok, 'send() (last error: ' . export($mailer->getLastErrorMessage()) . ')');
		$data = (string)SmtpServer::last()['data'];
		contains('text/plain', (string)headerValue($data, 'Content-Type'), 'Content-Type');
		same("Plain only\r\nSecond line", \rtrim(\explode("\r\n\r\n", $data, 2)[1] ?? '', "\r\n"), 'body');
		same([], $log->records, 'log records');
	},

	'every send() resets the message and keeps the cfg defaults' => static function (): void {
		[$mailer] = mailer();
		$mailer->to('a@example.com')->replyTo('other@example.com')->subject('First')->templateVars(['name' => 'X'])->body('Hi {name}')->send();
		$m = $mailer->phpMailer();
		same([[], '', ''], [$m->getToAddresses(), $m->Subject, $m->Body], 'recipients, subject and body after send()');
		same(['noreply@example.com', ['support@example.com']], [$m->From, \array_keys($m->getReplyToAddresses())], 'From and Reply-To after send()');
		$mailer->to('b@example.com')->subject('Second')->body('Hi {name}', false)->send();
		contains('Hi {name}', (string)SmtpServer::last()['data'], 'second message without template vars');
	},

	'attach() and setTemplate() reject unreadable files, and an attachment travels as a MIME part' => static function (): void {
		[$mailer] = mailer();
		thrown(\InvalidArgumentException::class, static fn() => $mailer->attach(SmtpServer::$dir . '/missing.pdf'), 'attach()');
		thrown(\InvalidArgumentException::class, static fn() => $mailer->setTemplate(SmtpServer::$dir . '/missing.html'), 'setTemplate()');
		\file_put_contents(SmtpServer::$dir . '/report.txt', 'report contents');
		\file_put_contents(SmtpServer::$dir . '/template.html', '<p>Hi {who}</p>');
		same(true, $mailer->to('a@example.com')->subject('Report')->setTemplate(SmtpServer::$dir . '/template.html', ['who' => 'Bo'])->attach(SmtpServer::$dir . '/report.txt')->send(), 'send()');
		$data = (string)SmtpServer::last()['data'];
		contains('multipart/mixed', (string)headerValue($data, 'Content-Type'), 'Content-Type');
		contains('filename=report.txt', $data, 'attachment part');
		contains('<p>Hi Bo</p>', $data, 'template body');
	},

];


$exitCode = 1;
try {
	SmtpServer::start($root);
	$exitCode = runChecks($checks);
} finally {
	SmtpServer::stop();
	removeTree($root);
}
exit($exitCode);
