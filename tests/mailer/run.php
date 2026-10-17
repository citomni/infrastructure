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
use CitOmni\Infrastructure\Exception\MailerConfigException;
use CitOmni\Infrastructure\Exception\MailerException;
use CitOmni\Infrastructure\Exception\MailerSendException;
use CitOmni\Infrastructure\Service\Mailer;
use CitOmni\Infrastructure\Tests\Support\App;
use CitOmni\Infrastructure\Tests\Support\LogRecorder;
use CitOmni\Infrastructure\Tests\Support\MemorySecrets;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
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
require __DIR__ . '/../../src/Exception/MailerException.php';
require __DIR__ . '/../../src/Exception/MailerConfigException.php';
require __DIR__ . '/../../src/Exception/MailerSendException.php';
require __DIR__ . '/../../src/Service/Mailer.php';

/** Secret SMTP password of the checks; it must never show up in a log record. */
const SMTP_PASSWORD = 'smtp-secret-7f3a';


/**
 * Mailer with access to its protected extension points.
 */
class InspectableMailer extends Mailer {

	/** The PHPMailer that the next message would start from. */
	public function transport(): PHPMailer {
		return $this->newPhpMailer();
	}

	/** The plain-text alternative generated for an HTML body. */
	public function text(string $html): string {
		return $this->htmlToText($html);
	}
}


/**
 * Mailer whose PHPMailer signs with S/MIME files that do not exist, so that PHPMailer cannot build a message.
 */
class SigningMailer extends InspectableMailer {

	protected function newPhpMailer(): PHPMailer {
		$mail = parent::newPhpMailer();
		$mail->sign(SmtpServer::$dir . '/missing-cert.pem', SmtpServer::$dir . '/missing-key.pem', '');
		return $mail;
	}
}


/**
 * The fixture SMTP server.
 */
class SmtpServer {

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
	 * @return list<array{helo: ?string, auth: ?string, from: ?string, rcpt: list<string>, data: ?string}>
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
 * The fixture server offers no STARTTLS, so encryption is none unless $mail sets it.
 *
 * @param  array<string, mixed>             $mail     Overrides for the "mail" cfg node.
 * @param  array<string, string>            $secrets  Secrets; by default the SMTP password.
 * @param  object|null                      $log      Log service; by default a LogRecorder.
 * @param  class-string<InspectableMailer>  $class    Mailer class to create.
 * @return array{0: InspectableMailer, 1: object, 2: MemorySecrets}
 */
function mailer(array $mail = [], array $secrets = ['mail.smtp.password' => SMTP_PASSWORD], ?object $log = null, string $class = InspectableMailer::class): array {
	$cfg = mergeLastWins(Registry::CFG_COMMON, ['mail' => mergeLastWins([
		'from'     => ['email' => 'noreply@example.com', 'name' => 'CitOmni Test'],
		'reply_to' => ['email' => 'support@example.com', 'name' => 'Support'],
		'hostname' => 'mailer.citomni.test',
		'smtp'     => ['host' => '127.0.0.1', 'port' => SmtpServer::$port, 'encryption' => 'none', 'username' => 'mailer@example.com', 'timeout' => 5],
	], $mail)]);
	$app = new App($cfg);
	$log ??= new LogRecorder();
	$store = new MemorySecrets($secrets);
	$app->set('log', $log);
	$app->set('secrets', $store);
	return [new $class($app), $log, $store];
}


/** The value of one header in a raw message, unfolded; null when absent. */
function headerValue(string $data, string $name): ?string {
	[$head] = \explode("\r\n\r\n", $data, 2);
	$head = (string)\preg_replace('/\r\n[ \t]+/', ' ', $head);
	return \preg_match('/^' . \preg_quote($name, '/') . ':[ \t]*(.*)$/mi', $head, $m) === 1 ? \rtrim($m[1]) : null;
}


/** The body of the first MIME part with the given content type, up to the blank line before the next boundary; null when absent. */
function part(string $data, string $type): ?string {
	$pattern = '~\r\nContent-Type: ' . \preg_quote($type, '~') . '[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n\r\n--~s';
	return \preg_match($pattern, $data, $m) === 1 ? $m[1] : null;
}


/** Fail unless $needle occurs in $haystack. */
function contains(string $needle, string $haystack, string $what): void {
	expect(\str_contains($haystack, $needle), $what . ': ' . export($needle) . ' missing from ' . export($haystack));
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'the shipped mail cfg is refused until the app sets a sender and an SMTP host' => static function (): void {
		same('mail.from.email', thrown(MailerConfigException::class, static fn() => new Mailer(new App(Registry::CFG_COMMON)), 'baseline')->path, 'first missing value');
		$withSender = mergeLastWins(Registry::CFG_COMMON, ['mail' => ['from' => ['email' => 'noreply@example.com']]]);
		same('mail.smtp.host', thrown(MailerConfigException::class, static fn() => new Mailer(new App($withSender)), 'baseline with a sender')->path, 'next missing value');
		new Mailer(new App(mergeLastWins($withSender, ['mail' => ['transport' => 'mail']])));
	},

	'a sender and an SMTP host give a STARTTLS transport that reads no secret before it sends' => static function (): void {
		$app = new App(mergeLastWins(Registry::CFG_COMMON, ['mail' => [
			'from' => ['email' => 'noreply@example.com'],
			'smtp' => ['host' => 'smtp.example.com', 'username' => 'mailer@example.com'],
		]]));
		$secrets = new MemorySecrets(['mail.smtp.password' => SMTP_PASSWORD]);
		$app->set('secrets', $secrets);
		$m = (new InspectableMailer($app))->transport();
		same(
			['smtp', 'smtp.example.com', 587, PHPMailer::ENCRYPTION_STARTTLS, false, true, 'mailer@example.com', '', 15, false, 'utf-8', 'CitOmni Mailer', ''],
			[$m->Mailer, $m->Host, $m->Port, $m->SMTPSecure, $m->SMTPAutoTLS, $m->SMTPAuth, $m->Username, $m->Password, $m->Timeout, $m->SMTPKeepAlive, $m->CharSet, $m->XMailer, $m->Hostname],
			'transport, host, port, secure, auto TLS, auth, username, password, timeout, keepalive, charset, X-Mailer, host name'
		);
		same([], $secrets->reads, 'secret reads');
	},

	'transport and encryption follow the mail cfg' => static function (): void {
		$sendmail = mailer(['transport' => 'sendmail'])[0]->transport();
		same(['sendmail', '/usr/sbin/sendmail'], [$sendmail->Mailer, $sendmail->Sendmail], 'sendmail baseline');
		$sendmail = mailer(['transport' => 'sendmail', 'sendmail_path' => '/opt/bin/sendmail'])[0]->transport();
		same(['sendmail', '/opt/bin/sendmail'], [$sendmail->Mailer, $sendmail->Sendmail], 'sendmail path');
		same('mail', mailer(['transport' => 'mail'])[0]->transport()->Mailer, 'mail()');
		$smtps = mailer(['smtp' => ['encryption' => 'smtps']])[0]->transport();
		same([PHPMailer::ENCRYPTION_SMTPS, false], [$smtps->SMTPSecure, $smtps->SMTPAutoTLS], 'smtps');
		$none = mailer(['smtp' => ['encryption' => 'none', 'username' => '']])[0]->transport();
		same(['', false, false, '', 'mailer.citomni.test'], [$none->SMTPSecure, $none->SMTPAutoTLS, $none->SMTPAuth, $none->Username, $none->Hostname], 'none, without username');
	},

	'invalid mail cfg values are refused with their cfg path' => static function (): void {
		$cases = [
			[['transport' => 'qmail'], 'mail.transport'],
			[['transport' => 'SMTP'], 'mail.transport'],
			[['smtp' => ['encryption' => 'tls']], 'mail.smtp.encryption'],
			[['smtp' => ['encryption' => null]], 'mail.smtp.encryption'],
			[['hostname' => 'not a host'], 'mail.hostname'],
			[['reply_to' => ['email' => 'nobody']], 'mail.reply_to.email'],
			[['reply_to' => ['name' => null]], 'mail.reply_to.name'],
			[['from' => ['email' => 'nobody']], 'mail.from.email'],
			[['from' => ['name' => null]], 'mail.from.name'],
			[['transport' => 'sendmail', 'sendmail_path' => ''], 'mail.sendmail_path'],
			[['smtp' => ['port' => '587']], 'mail.smtp.port'],
			[['smtp' => ['port' => 0]], 'mail.smtp.port'],
			[['smtp' => ['port' => 65536]], 'mail.smtp.port'],
			[['smtp' => ['username' => null]], 'mail.smtp.username'],
			[['smtp' => ['password_secret' => '']], 'mail.smtp.password_secret'],
			[['smtp' => ['password_secret' => ' mail.smtp.password']], 'mail.smtp.password_secret'],
			[['smtp' => ['password' => 'cfg-password-123']], 'mail.smtp.password'],
			[['smtp' => ['password' => null]], 'mail.smtp.password'],
			[['format' => 'html'], 'mail.format'],
			[['logging' => ['log_success' => false]], 'mail.logging'],
			[['smtp' => ['auth' => false]], 'mail.smtp.auth'],
			[['smtp' => ['auto_tls' => true]], 'mail.smtp.auto_tls'],
			[['smtp' => ['keepalive' => false]], 'mail.smtp.keepalive'],
			[['smtp' => ['host' => 'ssl://smtp.example.com']], 'mail.smtp.host'],
			[['smtp' => ['host' => 'smtp.example.com;tls://backup.example.com:587']], 'mail.smtp.host'],
			[['smtp' => ['host' => 'smtp example.com']], 'mail.smtp.host'],
			[['smtp' => ['host' => 'smtp.example.com:0']], 'mail.smtp.host'],
			[['smtp' => ['host' => 'smtp.example.com;']], 'mail.smtp.host'],
			[['smtp' => ['host' => 'smtp.bøger.dk']], 'mail.smtp.host'],
			[['smtp' => ['timeout' => 0]], 'mail.smtp.timeout'],
			[['log_errors' => 1], 'mail.log_errors'],
			[['log_success' => 'false'], 'mail.log_success'],
		];
		foreach ($cases as [$mail, $path]) {
			$e = thrown(MailerConfigException::class, static fn() => mailer($mail), 'cfg ' . export($mail));
			same($path, $e->path, 'path for ' . export($mail));
		}
		$e = thrown(MailerConfigException::class, static fn() => mailer(['smtp' => ['encryption' => 'tls']]), 'encryption tls');
		same("Cfg mail.smtp.encryption must be 'starttls', 'smtps' or 'none', got 'tls'.", $e->getMessage(), 'message');
		// A removed key is refused with what replaces it, and without its value.
		$e = thrown(MailerConfigException::class, static fn() => mailer(['smtp' => ['password' => 'cfg-password-123']]), 'password in cfg');
		same('Cfg mail.smtp.password must be absent (the secret that mail.smtp.password_secret names holds the password), got string.', $e->getMessage(), 'password message');
		$e = thrown(MailerConfigException::class, static fn() => mailer(['smtp' => ['auth' => false, 'encryption' => 'tls']]), 'auth in cfg');
		same('Cfg mail.smtp.auth must be absent (a non-empty mail.smtp.username turns authentication on), got bool.', $e->getMessage(), 'auth message');
		// A scheme in a host would replace mail.smtp.encryption for that host.
		$e = thrown(MailerConfigException::class, static fn() => mailer(['smtp' => ['host' => 'ssl://smtp.example.com']]), 'host with a scheme');
		same("Cfg mail.smtp.host must be host names or IP addresses separated by ';', each optionally with :port, without ssl:// or tls://, got 'ssl://smtp.example.com'.", $e->getMessage(), 'host message');
		// Only the transport in use needs its host or binary.
		mailer(['transport' => 'mail', 'smtp' => ['host' => ''], 'sendmail_path' => '']);
		// A host list as PHPMailer reads it: entries trimmed, each optionally with a port.
		mailer(['smtp' => ['host' => 'smtp1.example.com; smtp2.example.com:2525;[::1]:25;127.0.0.1']]);
	},

	// Regression: init() checked addresses with PHPMailer's process-wide validator, which
	// PHPMailer switches to a laxer one after it has seen a Unicode address.
	'cfg addresses are checked the same whichever validator PHPMailer uses for the process' => static function (): void {
		PHPMailer::$validator = 'eai';
		try {
			same('mail.from.email', thrown(MailerConfigException::class, static fn() => mailer(['from' => ['email' => 'noreply@localhost']]), 'from.email noreply@localhost')->path, 'path');
		} finally {
			PHPMailer::$validator = 'php';
		}
	},

	'an HTML message goes out with the cfg sender and Reply-To, a generated text part and the Message-ID it returns' => static function (): void {
		[$mailer] = mailer();
		$id = $mailer->send(to: ['sarah@example.com' => 'Sarah'], subject: 'Welcome', html: '<p>Hello <b>Sarah</b></p><p>Bye &amp; thanks</p>');
		$session = SmtpServer::last();
		same(['mailer.citomni.test', 'noreply@example.com', ['sarah@example.com']], [$session['helo'], $session['from'], $session['rcpt']], 'greeting and envelope');
		$data = (string)$session['data'];
		same(
			['CitOmni Test <noreply@example.com>', 'Support <support@example.com>', 'Welcome', 'Sarah <sarah@example.com>', $id],
			[headerValue($data, 'From'), headerValue($data, 'Reply-To'), headerValue($data, 'Subject'), headerValue($data, 'To'), headerValue($data, 'Message-ID')],
			'From, Reply-To, Subject, To, Message-ID'
		);
		expect(\str_ends_with($id, '@mailer.citomni.test>'), 'Message-ID outside the cfg host name: ' . $id);
		contains('multipart/alternative', (string)headerValue($data, 'Content-Type'), 'Content-Type');
		same('<p>Hello <b>Sarah</b></p><p>Bye &amp; thanks</p>', \rtrim((string)part($data, 'text/html')), 'HTML part');
		same("Hello Sarah\r\n\r\nBye & thanks", part($data, 'text/plain'), 'text part');
	},

	// Regression: the previous Mailer wrote a link as its text alone and kept the
	// content of style elements, so a reset link was missing from the text part.
	'the generated text part keeps link targets and drops style, script and tags' => static function (): void {
		[$mailer] = mailer();
		$html = '<html><head><title>T</title><style>p { color: red; }</style></head><body>'
			. '<h1>Reset</h1><p>Click <a href="https://example.com/reset?t=abc&amp;u=1">Reset your password</a>.</p>'
			. '<p><a href="https://example.com/">https://example.com/</a></p>'
			. '<p><a class="btn" href=https://example.com/bare>Bare</a> <a href=\'https://example.com/single\'><img alt="x"></a>'
			. ' <a href="">Home</a> <a data-href="https://example.com/no" title="t">Plain</a> <a href="https://example.com/?q=<2>">Odd</a></p>'
			. '<ul><li>One</li><li>Two</li></ul>'
			. '<table><tr><td>A</td><td>B</td></tr></table>'
			. "<script>alert(1)</script><p>Line<br>break\n  across   source lines</p></body></html>";
		same(
			"Reset\n\nClick Reset your password (https://example.com/reset?t=abc&u=1).\n\nhttps://example.com/\n\n"
				. "Bare (https://example.com/bare) https://example.com/single Home Plain Odd (https://example.com/?q=<2>)\n\n- One\n- Two\n\nA B\n\nLine\nbreak across source lines",
			$mailer->text($html),
			'text'
		);
	},

	// Regression: one link left open in a long body exhausted PCRE's backtrack limit, and
	// the plain-text alternative came out empty.
	'a link left open in a long HTML body keeps its target and the rest of the text part' => static function (): void {
		[$mailer] = mailer();
		$html = '<p>Start <a href="https://example.com/open">open</p>' . \str_repeat('<p>Paragraph text here.</p>', 8000)
			. '<p>End <a href="https://example.com/end">end</a></p>';
		$text = $mailer->text($html);
		expect(
			\str_starts_with($text, "Start open\n\nParagraph text here.\n\n") && \str_ends_with($text, "\n\nEnd (https://example.com/open) end (https://example.com/end)"),
			'text: ' . export(\substr($text, 0, 60)) . ' ... ' . export(\substr($text, -80))
		);
		$mailer->send(to: 'a@example.com', subject: 'Long', html: $html);
		contains('End (https://example.com/open) end (https://example.com/end)', (string)SmtpServer::last()['data'], 'message');
	},

	'a text body goes out as text/plain, and a given text part replaces the generated one' => static function (): void {
		[$mailer] = mailer();
		$mailer->send(to: 'a@example.com', subject: 'Plain', text: "Plain only\nSecond line");
		$data = (string)SmtpServer::last()['data'];
		contains('text/plain', (string)headerValue($data, 'Content-Type'), 'Content-Type');
		same("Plain only\r\nSecond line", \rtrim(\explode("\r\n\r\n", $data, 2)[1] ?? '', "\r\n"), 'body');
		$mailer->send(to: 'a@example.com', subject: 'Both', html: '<p>HTML version</p>', text: 'Text version');
		same('Text version', part((string)SmtpServer::last()['data'], 'text/plain'), 'text part');
		$mailer->send(to: 'a@example.com', subject: 'Banner', html: '<img src="https://example.com/banner.png">');
		contains('text/html', (string)headerValue((string)SmtpServer::last()['data'], 'Content-Type'), 'Content-Type of an HTML body without text');
	},

	'authenticated SMTP reads the named password secret at send time' => static function (): void {
		[$mailer, , $secrets] = mailer(['smtp' => ['password_secret' => 'relay.password']], ['relay.password' => SMTP_PASSWORD]);
		same([], $secrets->reads, 'secret reads before send()');
		$mailer->send(to: 'a@example.com', subject: 'Auth', text: 'x');
		same("\0mailer@example.com\0" . SMTP_PASSWORD, SmtpServer::last()['auth'], 'AUTH PLAIN credentials at the server');
		same(['relay.password'], $secrets->reads, 'secret reads');
	},

	'SMTP without a username never authenticates or reads a secret' => static function (): void {
		[$mailer, , $secrets] = mailer(['smtp' => ['username' => '']], []);
		$mailer->send(to: 'a@example.com', subject: 'No auth', text: 'x');
		same([null, []], [SmtpServer::last()['auth'], $secrets->reads], 'AUTH at the server and secret reads');
	},

	'a missing SMTP password throws before anything is sent' => static function (): void {
		[$mailer] = mailer([], []);
		$before = \count(SmtpServer::sessions());
		thrown(\OutOfBoundsException::class, static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x'), 'send()');
		same($before, \count(SmtpServer::sessions()), 'sessions');
	},

	'mail.smtp.host is tried entry by entry, and a port in an entry replaces mail.smtp.port' => static function (): void {
		$closed = freePort();
		[$mailer] = mailer(['smtp' => ['host' => '127.0.0.1:' . $closed . '; 127.0.0.1:' . SmtpServer::$port, 'port' => $closed]]);
		$mailer->send(to: 'second@example.com', subject: 'Second host', text: 'x');
		same(['second@example.com'], SmtpServer::last()['rcpt'], 'recipients at the second host');
	},

	'starttls refuses a server without STARTTLS instead of sending in plain text' => static function (): void {
		[$mailer] = mailer(['smtp' => ['encryption' => 'starttls']]);
		$e = thrown(MailerSendException::class, static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x'), 'send()');
		contains('STARTTLS', $e->getMessage(), 'error');
		$session = SmtpServer::last();
		same([null, null, null], [$session['auth'], $session['from'], $session['data']], 'AUTH, MAIL FROM and DATA at the server');
	},

	// Regression: mail.smtp.timeout did not bound the wait for a reply, so a server that took
	// the connection and never answered held send() for PHPMailer's 300 seconds.
	'a server that never answers fails the send after mail.smtp.timeout' => static function (): void {
		// The system completes connections into the listen backlog; nothing ever answers them.
		$silent = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		expect($silent !== false, 'cannot listen: ' . $errstr);
		try {
			$name = (string)\stream_socket_get_name($silent, false);
			[$mailer] = mailer(['smtp' => ['port' => (int)\substr($name, (int)\strrpos($name, ':') + 1), 'timeout' => 1]]);
			$started = \microtime(true);
			thrown(MailerSendException::class, static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x'), 'send()');
			$elapsed = \microtime(true) - $started;
			expect($elapsed < 10, 'send() took ' . \round($elapsed, 1) . ' s with a timeout of 1 s');
		} finally {
			\fclose($silent);
		}
	},

	'a server that never answers the message data leaves accepted empty although it has the message' => static function (): void {
		[$mailer] = mailer(['smtp' => ['timeout' => 1]]);
		$before  = \count(SmtpServer::sessions());
		$started = \microtime(true);
		$e = thrown(MailerSendException::class, static fn() => $mailer->send(to: 'held@silent.invalid', subject: 'x', text: 'x'), 'send()');
		$elapsed = \microtime(true) - $started;
		expect($elapsed < 10, 'send() took ' . \round($elapsed, 1) . ' s with a timeout of 1 s');
		same([], $e->accepted, 'accepted');
		$sessions = SmtpServer::sessions();
		same($before + 1, \count($sessions), 'sessions');
		expect($sessions[\array_key_last($sessions)]['data'] !== null, 'the server did not get the message data');
	},

	// Regression: the previous Mailer returned false although the message had
	// reached the recipients the server accepted.
	'a partly refused send throws and names the recipients that got the message' => static function (): void {
		[$mailer] = mailer();
		$e = thrown(MailerSendException::class, static fn() => $mailer->send(to: ['good@example.com', 'bad@reject.invalid'], subject: 'x', text: 'x'), 'send()');
		same(['good@example.com'], $e->accepted, 'accepted');
		contains('bad@reject.invalid', $e->getMessage(), 'error');
		contains('Recipient rejected', $e->getMessage(), 'server reply in the error');
		$session = SmtpServer::last();
		expect($session['rcpt'] === ['good@example.com'] && $session['data'] !== null, 'the server did not deliver to the accepted recipient: ' . export($session));
		$e = thrown(MailerSendException::class, static fn() => $mailer->send(to: 'bad@reject.invalid', subject: 'x', text: 'x'), 'send() to a refused recipient only');
		same([], $e->accepted, 'accepted when no recipient got the message');
		$e = thrown(MailerSendException::class, static fn() => $mailer->send(to: [' Good@Example.com', 'bad@reject.invalid'], subject: 'x', text: 'x'), 'send() with a padded address');
		same([' Good@Example.com'], $e->accepted, 'accepted in the spelling of the arguments');
	},

	'a refused send logs metadata, never a body or the password' => static function (): void {
		[$mailer, $log] = mailer();
		thrown(MailerSendException::class, static fn() => $mailer->send(to: ['good@example.com', 'bad@reject.invalid'], subject: 'Token', html: '<p>private body 123456</p>'), 'send()');
		same(1, \count($log->records), 'log records');
		$rec = $log->records[0];
		same(['mailer.jsonl', 'mailer.error'], [$rec['file'], $rec['category']], 'file and category');
		$context = $rec['context'];
		same(
			['smtp', 'noreply@example.com', ['good@example.com', 'bad@reject.invalid'], [], [], 'Token', ['good@example.com']],
			[$context['transport'] ?? null, $context['from'] ?? null, $context['to'] ?? null, $context['cc'] ?? null, $context['bcc'] ?? null, $context['subject'] ?? null, $context['accepted'] ?? null],
			'transport, from, to, cc, bcc, subject, accepted'
		);
		contains('bad@reject.invalid', (string)($context['error'] ?? ''), 'error');
		contains('Recipient rejected', (string)($context['error'] ?? ''), 'server reply in the error');
		expect(\is_string($context['message_id'] ?? null) && \is_int($context['duration_ms'] ?? null), 'message_id or duration_ms missing: ' . export($context));
		$serialized = \serialize($log->records);
		expect(!\str_contains($serialized, SMTP_PASSWORD) && !\str_contains($serialized, '123456'), 'log record contains the password or the body');
	},

	'log_errors and log_success decide what goes to mailer.jsonl' => static function (): void {
		[$mailer, $log] = mailer();
		$mailer->send(to: 'a@example.com', subject: 'Quiet', text: 'x');
		same([], $log->records, 'log records of an accepted send by default');

		[$mailer, $log] = mailer(['log_success' => true]);
		$id = $mailer->send(to: 'a@example.com', subject: 'Logged', text: 'x');
		same(1, \count($log->records), 'log records with log_success');
		same(['mailer.jsonl', 'mailer.success', $id], [$log->records[0]['file'], $log->records[0]['category'], $log->records[0]['context']['message_id'] ?? null], 'file, category and Message-ID');

		[$mailer, $log] = mailer(['log_errors' => false]);
		thrown(MailerSendException::class, static fn() => $mailer->send(to: 'bad@reject.invalid', subject: 'x', text: 'x'), 'send()');
		same([], $log->records, 'log records of a refused send without log_errors');
	},

	'a log write that fails changes neither a refusal nor an accepted send' => static function (): void {
		$broken = new class {
			public function write(?string $file, string $category, string|array|object $message, array $context = []): void {
				throw new \RuntimeException('disk full');
			}
		};
		[$mailer] = mailer(['log_success' => true], log: $broken);
		$id = $mailer->send(to: 'a@example.com', subject: 'x', text: 'x');
		same($id, headerValue((string)SmtpServer::last()['data'], 'Message-ID'), 'Message-ID of an accepted send');
		$e = thrown(MailerSendException::class, static fn() => $mailer->send(to: ['good@example.com', 'bad@reject.invalid'], subject: 'x', text: 'x'), 'send()');
		same(['good@example.com'], $e->accepted, 'accepted');
	},

	'recipients take strings, lists and address => name pairs, a repeated address gets one copy, and Bcc stays out of the headers' => static function (): void {
		[$mailer] = mailer();
		$mailer->send(
			to: ['a@example.com', 'b@example.com' => 'B Person'],
			subject: 'Many',
			text: 'x',
			cc: 'c@example.com',
			bcc: ['d@example.com', 'A@Example.com', 'c@example.com' => 'Again'],
		);
		$session = SmtpServer::last();
		same(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'], $session['rcpt'], 'envelope recipients');
		$data = (string)$session['data'];
		same(['a@example.com, B Person <b@example.com>', 'c@example.com', null], [headerValue($data, 'To'), headerValue($data, 'Cc'), headerValue($data, 'Bcc')], 'To, Cc, Bcc');
		expect(!\str_contains($data, 'd@example.com'), 'Bcc address in the message');
	},

	// Regression: PHPMailer queued an address with a non-ASCII domain until preSend(), where a
	// Cc recipient that was also the Reply-To address was lost, and one also given in
	// punycode lost its place in the To header.
	'internationalized addresses go out in punycode, once each, and no recipient is lost' => static function (): void {
		[$mailer] = mailer();
		if (!PHPMailer::idnSupported()) {
			thrown(\InvalidArgumentException::class, static fn() => $mailer->send(to: 'owner@example.com', subject: 'x', text: 'x', cc: 'visitor@bøger.dk'), 'send() without intl and mbstring');
			return;
		}

		$mailer->send(to: 'owner@example.com', subject: 'Contact', text: 'x', cc: 'visitor@bøger.dk', replyTo: 'visitor@bøger.dk');
		$session = SmtpServer::last();
		$data = (string)$session['data'];
		same(
			[['owner@example.com', 'visitor@xn--bger-gra.dk'], 'visitor@xn--bger-gra.dk', 'visitor@xn--bger-gra.dk'],
			[$session['rcpt'], headerValue($data, 'Cc'), headerValue($data, 'Reply-To')],
			'recipients, Cc and Reply-To'
		);

		$mailer->send(to: ['kunde@bøger.dk', 'x@example.com'], subject: 'x', text: 'x', bcc: 'kunde@xn--bger-gra.dk');
		$session = SmtpServer::last();
		same(
			[['kunde@xn--bger-gra.dk', 'x@example.com'], 'kunde@xn--bger-gra.dk, x@example.com'],
			[$session['rcpt'], headerValue((string)$session['data'], 'To')],
			'recipients and To with the punycode form in bcc'
		);

		$e = thrown(MailerSendException::class, static fn() => $mailer->send(to: ['Kunde@bøger.dk', 'bad@reject.invalid'], subject: 'x', text: 'x'), 'send() with a refused recipient');
		same(['Kunde@bøger.dk'], $e->accepted, 'accepted in the spelling of the arguments');
	},

	'from and replyTo replace the cfg defaults for one message only' => static function (): void {
		[$mailer] = mailer();
		$mailer->send(to: 'a@example.com', subject: 'x', text: 'x', from: ['team@example.com' => 'Team'], replyTo: ['help@example.com' => 'Help', 'desk@example.com']);
		$session = SmtpServer::last();
		$data = (string)$session['data'];
		same(
			['team@example.com', 'Team <team@example.com>', 'Help <help@example.com>, desk@example.com'],
			[$session['from'], headerValue($data, 'From'), headerValue($data, 'Reply-To')],
			'envelope sender, From and Reply-To'
		);
		$mailer->send(to: 'a@example.com', subject: 'x', text: 'x', replyTo: []);
		same(null, headerValue((string)SmtpServer::last()['data'], 'Reply-To'), 'Reply-To with replyTo []');
		$mailer->send(to: 'a@example.com', subject: 'x', text: 'x');
		$session = SmtpServer::last();
		$data = (string)$session['data'];
		same(
			['noreply@example.com', 'CitOmni Test <noreply@example.com>', 'Support <support@example.com>'],
			[$session['from'], headerValue($data, 'From'), headerValue($data, 'Reply-To')],
			'envelope sender, From and Reply-To of the next message'
		);
	},

	'invalid arguments throw InvalidArgumentException before anything is sent' => static function (): void {
		[$mailer, , $secrets] = mailer();
		$before = \count(SmtpServer::sessions());
		$cases = [
			'no recipient'                 => static fn() => $mailer->send(to: [], subject: 'x', text: 'x'),
			'no body'                      => static fn() => $mailer->send(to: 'a@example.com', subject: 'x'),
			'an invalid to address'        => static fn() => $mailer->send(to: 'not-an-address', subject: 'x', text: 'x'),
			'an invalid cc address'        => static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x', cc: 'b@'),
			'an invalid replyTo address'   => static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x', replyTo: 'help'),
			'an invalid from address'      => static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x', from: 'team'),
			// An internationalized domain that punycode cannot express; PHPMailer alone would
			// check it only while it builds the message.
			'an invalid IDN to address'    => static fn() => $mailer->send(to: 'kunde@bøger..dk', subject: 'x', text: 'x'),
			'an invalid IDN from address'  => static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x', from: 'shop@bøger..dk'),
			'two from addresses'           => static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x', from: ['a@example.com', 'b@example.com']),
			'a display name not a string'  => static fn() => $mailer->send(to: ['a@example.com' => null], subject: 'x', text: 'x'),
			'an unreadable attachment'     => static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x', attachments: [SmtpServer::$dir . '/missing.pdf']),
			'an attachment not a path'     => static fn() => $mailer->send(to: 'a@example.com', subject: 'x', text: 'x', attachments: [['report.pdf']]),
		];
		foreach ($cases as $what => $send) {
			thrown(\InvalidArgumentException::class, $send, $what);
		}
		same([$before, []], [\count(SmtpServer::sessions()), $secrets->reads], 'sessions and secret reads');
	},

	// Regression: PHPMailer's exception, kept as the previous one, held the message in the
	// arguments of its stack trace, and the CLI error handler prints arguments.
	'stack traces of a refused send hold neither the bodies nor the message' => static function (): void {
		[$mailer] = mailer();
		$ignoreArgs = \ini_set('zend.exception_ignore_args', '0');
		try {
			$e = thrown(MailerSendException::class, static fn() => $mailer->send(to: 'bad@reject.invalid', subject: 'x', html: '<p>private 123456</p>', text: 'private 123456'), 'send()');
			same('0', \ini_get('zend.exception_ignore_args'), 'zend.exception_ignore_args after send()');
		} finally {
			\ini_set('zend.exception_ignore_args', (string)$ignoreArgs);
		}
		$frame = $e->getTrace()[0] ?? [];
		same(
			['send', true, true],
			[$frame['function'] ?? null, ($frame['args'][2] ?? null) instanceof \SensitiveParameterValue, ($frame['args'][3] ?? null) instanceof \SensitiveParameterValue],
			'frame, html and text in the trace of MailerSendException'
		);
		$previous = $e->getPrevious();
		expect($previous instanceof \PHPMailer\PHPMailer\Exception, 'previous exception: ' . \get_debug_type($previous));
		$withArgs = \array_filter($previous->getTrace(), static fn(array $f): bool => ($f['args'] ?? []) !== []);
		same([], \array_column($withArgs, 'function'), 'frames with arguments in the trace of the PHPMailer exception');
	},

	'attachments travel as MIME parts under the given file name' => static function (): void {
		[$mailer] = mailer();
		\file_put_contents(SmtpServer::$dir . '/report-7.txt', 'report contents');
		\file_put_contents(SmtpServer::$dir . '/notes.txt', 'notes');
		$mailer->send(to: 'a@example.com', subject: 'Report', text: 'See attached.', attachments: ['Report.txt' => SmtpServer::$dir . '/report-7.txt', SmtpServer::$dir . '/notes.txt']);
		$data = (string)SmtpServer::last()['data'];
		contains('multipart/mixed', (string)headerValue($data, 'Content-Type'), 'Content-Type');
		contains('filename=Report.txt', $data, 'attachment with a given name');
		contains('filename=notes.txt', $data, 'attachment with its own name');
		contains(\base64_encode('report contents'), $data, 'attachment content');
	},

	// Regression: the previous Mailer kept the recipients of a message whose
	// composition failed, and PHPMailer's SMTPUTF8 mode after a Unicode address,
	// for every later message of the instance.
	'a failed send leaves nothing behind for the next one' => static function (): void {
		[$mailer] = mailer();
		thrown(\InvalidArgumentException::class, static fn() => $mailer->send(to: 'alice@example.com', subject: 'x', text: 'x', attachments: [SmtpServer::$dir . '/missing.pdf']), 'send() with a missing attachment');
		$mailer->send(to: 'bob@example.com', subject: 'For Bob', text: 'x');
		same(['bob@example.com'], SmtpServer::last()['rcpt'], 'recipients after a failed composition');

		try {
			// The fixture server has no SMTPUTF8, so PHPMailer refuses the Unicode local
			// part: when it sends from 6.10 on, and as an invalid address before.
			thrown(\Exception::class, static fn() => $mailer->send(to: 'jørgen@example.com', subject: 'x', text: 'x'), 'send() to a Unicode local part');
			$mailer->send(to: 'ascii@example.com', subject: 'After', text: 'x');
			same(['ascii@example.com'], SmtpServer::last()['rcpt'], 'recipients after a Unicode local part');
			// From 6.10 on, PHPMailer switches its process-wide validator to eai on a Unicode
			// address, and eai accepts an address that php refuses.
			same('php', PHPMailer::$validator, 'PHPMailer validator after a Unicode local part');
			thrown(\InvalidArgumentException::class, static fn() => $mailer->send(to: 'noreply@localhost', subject: 'x', text: 'x'), 'send() to an address that only eai accepts');
		} finally {
			// Keeps the later checks independent of a Mailer that fails to restore it.
			PHPMailer::$validator = 'php';
		}
	},

	// Regression: from 6.10, PHPMailer switches to its eai validator at the first address with
	// UTF-8 in it, so an address before that one was refused and the same address after it
	// accepted.
	'every address of a message meets the same validator, whatever their order' => static function (): void {
		[$mailer] = mailer();
		$outcomes = [];
		try {
			foreach ([['jørgen@example.com', 'noreply@localhost'], ['noreply@localhost', 'jørgen@example.com']] as $to) {
				try {
					$mailer->send(to: $to, subject: 'x', text: 'x');
					$outcomes[] = 'sent';
				} catch (\Exception $e) {
					$outcomes[] = $e::class;
				}
			}
		} finally {
			// Keeps the later checks independent of a Mailer that fails to restore it.
			PHPMailer::$validator = 'php';
		}
		same($outcomes[0], $outcomes[1], 'outcome of the two orders');
	},

	'a message that PHPMailer cannot build from valid arguments throws MailerException and sends nothing' => static function (): void {
		[$mailer, , $secrets] = mailer(class: SigningMailer::class);
		$before = \count(SmtpServer::sessions());
		$e = thrown(MailerException::class, static fn() => $mailer->send(to: 'a@example.com', subject: 'Signed', text: 'x'), 'send() with signing that fails');
		same(MailerException::class, $e::class, 'exception class');
		expect($e->getPrevious() instanceof PHPMailerException, 'previous is not PHPMailer\'s exception: ' . export($e->getPrevious()));
		same([], $secrets->reads, 'secret reads');
		same($before, \count(SmtpServer::sessions()), 'sessions');
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
