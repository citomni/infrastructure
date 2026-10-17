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

use CitOmni\Infrastructure\Exception\MailerConfigException;
use CitOmni\Infrastructure\Exception\MailerException;
use CitOmni\Infrastructure\Exception\MailerSendException;
use CitOmni\Kernel\Service\BaseService;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;


/**
 * Mailer: Service that sends one email message per call through PHPMailer.
 *
 * Delivers finished messages: The caller passes the recipients, the subject and the bodies,
 * and cfg mail supplies the default sender and Reply-To and the transport. What a message
 * says, how its bodies are rendered and escaped, and what to do when the transport refuses it
 * belong to the caller; MIME encoding and the transports themselves belong to PHPMailer.
 *
 * Rules:
 * - Keep no message state between calls: Build every message on a new PHPMailer from the
 *   arguments of its send() and the values read in init(), and restore PHPMailer's
 *   process-wide address validator, which PHPMailer switches while it takes addresses, so
 *   that a failed or nested send cannot reach another message.
 * - Read and check cfg mail only in init(), and report a wrong value with
 *   MailerConfigException.
 * - Never write a message body or a credential into a log line or an exception message.
 *   Mark body parameters SensitiveParameter, and turn zend.exception_ignore_args on while
 *   PHPMailer holds a message.
 * - Read the SMTP password from the secrets service only in a send that authenticates.
 * - Keep logging best-effort: A failing log write never changes what send() returns or throws.
 */
class Mailer extends BaseService {

	/** Log file of the lines that send() writes. */
	private const string LOG_FILE = 'mailer.jsonl';

	/** Keys that cfg mail no longer has, with what replaces each; init() refuses them. */
	private const array REMOVED_KEYS = [
		'format'  => 'send() takes html or text per message',
		'logging' => 'mail.log_errors and mail.log_success replace it',
	];

	/** Keys that cfg mail.smtp no longer has, with what replaces each; init() refuses them. */
	private const array REMOVED_SMTP_KEYS = [
		'auth'      => 'a non-empty mail.smtp.username turns authentication on',
		'auto_tls'  => 'mail.smtp.encryption alone sets the TLS policy',
		'keepalive' => 'every message opens its own connection',
		'password'  => 'the secret that mail.smtp.password_secret names holds the password',
	];

	/** Sender address of a message that names no sender. */
	private string $fromEmail;

	/** Display name of that sender; '' for none. */
	private string $fromName;

	/** @var list<array{0:string,1:string}> Address and display name pairs for the Reply-To of a message that names none; [] for none. */
	private array $replyTo;

	/** Host name for the Message-ID header and the SMTP greeting; '' leaves the choice to PHPMailer. */
	private string $hostname;

	/** Transport: smtp, sendmail or mail. */
	private string $transport;

	/** Binary of the sendmail transport. */
	private string $sendmailPath;

	/** SMTP host, or several separated by ';', each optionally with :port. */
	private string $smtpHost;

	/** SMTP port. */
	private int $smtpPort;

	/** PHPMailer SMTPSecure value: tls for STARTTLS, ssl for implicit TLS, '' for none. */
	private string $smtpSecure;

	/** SMTP user name; '' for no authentication. */
	private string $smtpUsername;

	/** Secrets key that holds the SMTP password. */
	private string $smtpPasswordSecret;

	/** Seconds that connecting and each reply of the SMTP server may take. */
	private int $smtpTimeout;

	/** Log sends that the transport refused. */
	private bool $logErrors;

	/** Log sends that the transport accepted. */
	private bool $logSuccess;


	// ----------------------------------------------------------------
	// Service bootstrap
	// ----------------------------------------------------------------

	/**
	 * Read and check cfg mail once.
	 *
	 * Behavior:
	 * - Requires mail.from.email always, mail.smtp.host for the smtp transport and
	 *   mail.sendmail_path for the sendmail transport. Every other value only has to be valid,
	 *   also when its transport is not the one in use.
	 * - Checks addresses with PHPMailer's php validator, whichever validator PHPMailer uses for
	 *   the process at the time.
	 * - Accepts in mail.smtp.host only what PHPMailer connects to: host names or IP addresses
	 *   separated by ';', each optionally with :port. Refuses an ssl:// or tls:// prefix, with
	 *   which PHPMailer would replace the encryption of mail.smtp.encryption for that host.
	 * - Refuses the keys that cfg mail no longer has, such as mail.smtp.auth and
	 *   mail.smtp.password, whatever their value, and names only the type of that value.
	 * - Reads no secret and opens no connection.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\MailerConfigException  When a value under cfg mail is invalid, empty where it is required, or under a key that cfg mail no longer has; the exception names its cfg path.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$mail = $this->app->cfg->mail;
		$smtp = $mail->smtp;

		// Keys the baseline does not ship, so their presence is checked on the arrays, not read.
		// Left in cfg, they would be ignored: auth would no longer decide whether to
		// authenticate, and a password would stay unread in every cfg file and cache.
		self::refuseRemovedKeys($mail->toArray(), 'mail', self::REMOVED_KEYS);
		self::refuseRemovedKeys($smtp->toArray(), 'mail.smtp', self::REMOVED_SMTP_KEYS);

		$transport = $mail->transport;
		if (!\in_array($transport, ['smtp', 'sendmail', 'mail'], true)) {
			throw new MailerConfigException('mail.transport', "'smtp', 'sendmail' or 'mail'", $transport);
		}

		$encryption = $smtp->encryption;
		$secure = match ($encryption) {
			'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
			'smtps'    => PHPMailer::ENCRYPTION_SMTPS,
			'none'     => '',
			default    => throw new MailerConfigException('mail.smtp.encryption', "'starttls', 'smtps' or 'none'", $encryption),
		};

		$hostname = $mail->hostname;
		if (!\is_string($hostname) || ($hostname !== '' && !PHPMailer::isValidHost($hostname))) {
			throw new MailerConfigException('mail.hostname', "'' or a valid host name", $hostname);
		}

		// The secrets service refuses a padded key; refusing it here names the cfg path instead.
		$passwordSecret = $smtp->password_secret;
		if (!\is_string($passwordSecret) || $passwordSecret === '' || \trim($passwordSecret) !== $passwordSecret) {
			throw new MailerConfigException('mail.smtp.password_secret', 'a non-empty string without leading or trailing whitespace', $passwordSecret);
		}

		$replyToEmail = self::cfgAddress($mail->reply_to->email, 'mail.reply_to.email', false);
		$replyToName  = self::cfgString($mail->reply_to->name, 'mail.reply_to.name', false);

		$this->transport          = $transport;
		$this->fromEmail          = self::cfgAddress($mail->from->email, 'mail.from.email', true);
		$this->fromName           = self::cfgString($mail->from->name, 'mail.from.name', false);
		$this->replyTo            = $replyToEmail !== '' ? [[$replyToEmail, $replyToName]] : [];
		$this->hostname           = $hostname;
		$this->sendmailPath       = self::cfgString($mail->sendmail_path, 'mail.sendmail_path', $transport === 'sendmail');
		$this->smtpHost           = self::cfgHosts($smtp->host, 'mail.smtp.host', $transport === 'smtp');
		$this->smtpPort           = self::cfgInt($smtp->port, 'mail.smtp.port', 1, 65535);
		$this->smtpSecure         = $secure;
		$this->smtpUsername       = self::cfgString($smtp->username, 'mail.smtp.username', false);
		$this->smtpPasswordSecret = $passwordSecret;
		$this->smtpTimeout        = self::cfgInt($smtp->timeout, 'mail.smtp.timeout', 1, \PHP_INT_MAX);
		$this->logErrors          = self::cfgBool($mail->log_errors, 'mail.log_errors');
		$this->logSuccess         = self::cfgBool($mail->log_success, 'mail.log_success');
	}


	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Send one message and return its Message-ID.
	 *
	 * Returns only when the transport has accepted the message for every recipient. What to do
	 * after a refusal belongs to the caller; the exception lists the recipients that the
	 * transport confirmed anyway.
	 *
	 * Behavior:
	 * - An address argument is one address, or an array of addresses and of
	 *   address => display name pairs.
	 * - An address that to, cc and bcc name more than once, in any letter case or with its
	 *   domain once in Unicode and once in punycode, receives one copy, in the first of them
	 *   that names it. An internationalized domain is sent in punycode.
	 * - With html, the message has a plain-text alternative: text, or htmlToText() of html
	 *   when text is ''. An alternative that comes out empty is left out.
	 * - from and replyTo replace the cfg default for this message: null keeps it, and
	 *   replyTo [] sends no Reply-To. With SMTP, the From address is also the envelope sender.
	 * - Checks every argument before it reads a secret or opens a connection.
	 * - Reads the secret that cfg mail.smtp.password_secret names only when it authenticates.
	 * - Checks all addresses of the message with one validator: PHPMailer's process-wide one,
	 *   or eai when that is php and an address has UTF-8 in its local part, the switch that
	 *   PHPMailer itself makes at such an address. The order of the addresses does not matter.
	 * - Leaves PHPMailer's process-wide address validator as it found it.
	 * - Writes a mailer.error line to mailer.jsonl for a refused send when cfg mail.log_errors
	 *   is true, and a mailer.success line for an accepted send when cfg mail.log_success is
	 *   true, both only when the app has a log service. A line holds the Message-ID, the
	 *   transport, the addresses as sent, the subject and the duration, and for a refusal the
	 *   error, which quotes the server's reply as the server wrote it, and the addresses that
	 *   accepted the message.
	 *
	 * Notes:
	 * - Bodies go out as given. Escape untrusted values for HTML before they reach html.
	 * - A transport can refuse some recipients and deliver to the others. Sending the same
	 *   message again then reaches MailerSendException::$accepted a second time.
	 * - MailerSendException::$accepted holds only the recipients that the transport confirmed.
	 *   When the connection fails or times out before an SMTP server has answered the message
	 *   data, the server may deliver the message although accepted is [].
	 * - A host that locks zend.exception_ignore_args off keeps arguments in the stack traces of
	 *   exceptions that PHPMailer raises, and PHPMailer's frames then show the message.
	 *
	 * Typical usage:
	 *   $messageId = $this->app->mailer->send(to: $email, subject: $subject, html: $html);
	 *
	 * @param  string|array<int|string,string>  $to  Recipients; [] when cc or bcc names one.
	 * @param  string  $subject
	 * @param  string  $html  HTML body; '' for none.
	 * @param  string  $text  Plain-text body; '' for none.
	 * @param  string|array<int|string,string>  $cc
	 * @param  string|array<int|string,string>  $bcc  Recipients left out of the headers.
	 * @param  string|array<int|string,string>|null  $replyTo  Null for the Reply-To of cfg mail.reply_to.
	 * @param  string|array<int|string,string>|null  $from  One address; null for the sender of cfg mail.from.
	 * @param  array<int|string,string>  $attachments  Paths of readable files; a string key is the file name the recipient sees.
	 * @return string  The Message-ID header value, angle brackets included.
	 * @throws \InvalidArgumentException  When no recipient or no body is given, from does not name exactly one address, an entry of an array argument is not a string, an address has a non-ASCII domain that cannot be converted to punycode, or PHPMailer rejects an address or cannot read an attachment. Nothing is sent.
	 * @throws \CitOmni\Infrastructure\Exception\MailerException  When PHPMailer cannot build the message from valid arguments, such as when signing that an override of newPhpMailer() sets up fails. Nothing is sent.
	 * @throws \CitOmni\Infrastructure\Exception\MailerSendException  When the transport did not confirm the message for every recipient; its message quotes the server's reply as the server wrote it, and its accepted list uses the spelling of the arguments.
	 * @throws \OutOfBoundsException  When the secret that cfg mail.smtp.password_secret names does not exist.
	 * @see \CitOmni\Infrastructure\Service\Secrets::get()  The other failures to read that secret.
	 */
	public function send(string|array $to, string $subject, #[\SensitiveParameter] string $html = '', #[\SensitiveParameter] string $text = '', string|array $cc = [], string|array $bcc = [], string|array|null $replyTo = null, string|array|null $from = null, array $attachments = []): string {

		// -- 1. Check the arguments ---------------------------------------
		if ($html === '' && $text === '') {
			throw new \InvalidArgumentException('A message needs an html or a text body.');
		}

		$sender = $from === null ? [[$this->fromEmail, $this->fromName]] : self::addresses($from, 'from');
		if (\count($sender) !== 1) {
			throw new \InvalidArgumentException('from must name exactly one address.');
		}

		$lists = [
			'to'      => self::addresses($to, 'to'),
			'cc'      => self::addresses($cc, 'cc'),
			'bcc'     => self::addresses($bcc, 'bcc'),
			'replyTo' => $replyTo === null ? $this->replyTo : self::addresses($replyTo, 'replyTo'),
		];
		if ($lists['to'] === [] && $lists['cc'] === [] && $lists['bcc'] === []) {
			throw new \InvalidArgumentException('A message needs a recipient in to, cc or bcc.');
		}

		foreach ($attachments as $path) {
			if (!\is_string($path)) {
				throw new \InvalidArgumentException('Every attachment must be a file path string.');
			}
		}

		// PHPMailer hands the message between its own methods as arguments, so an exception
		// raised while it works would keep the message in its stack trace, where an error
		// handler may log it. Traces leave arguments out until PHPMailer is done, unless the
		// host has locked the setting off.
		$ignoreArgs = \ini_set('zend.exception_ignore_args', '1');
		// PHPMailer switches its process-wide validator when it meets UTF-8 in an address.
		$validator = PHPMailer::$validator;
		try {

			// -- 2. Build the message -------------------------------------
			$mail = $this->newPhpMailer();
			try {
				$spelling = $this->compose($mail, $sender[0], $lists, $subject, $html, $text, $attachments);
			} catch (PHPMailerException $e) {
				throw new \InvalidArgumentException($e->getMessage(), 0, $e);
			}
			try {
				$mail->preSend();
			} catch (PHPMailerException $e) {
				// compose() has handed PHPMailer only checked addresses and readable files, so
				// what fails here is the building, such as signing set up by an override.
				throw new MailerException('PHPMailer could not build the message: ' . $e->getMessage(), 0, $e);
			}

			// -- 3. Hand it to the transport ------------------------------
			if ($mail->SMTPAuth) {
				$mail->Password = $this->app->secrets->get($this->smtpPasswordSecret);
			}

			$accepted = [];
			// PHPMailer reports each recipient's outcome here; SMTP reports them only once DATA has gone out.
			$mail->action_function = static function (bool $isSent, array $toPairs, array $ccPairs, array $bccPairs) use (&$accepted, $spelling): void {
				if ($isSent) {
					foreach ([...$toPairs, ...$ccPairs, ...$bccPairs] as $pair) {
						$accepted[] = $spelling[\strtolower($pair[0])] ?? $pair[0];
					}
				}
			};

			$refusal   = null;
			$startedAt = \microtime(true);
			try {
				$mail->postSend();
			} catch (PHPMailerException $e) {
				$refusal = $e;
			} finally {
				$mail->Password = '';
			}
		} finally {
			PHPMailer::$validator = $validator;
			if ($ignoreArgs !== false) {
				\ini_set('zend.exception_ignore_args', $ignoreArgs);
			}
		}

		// -- 4. Report the outcome ----------------------------------------
		if ($refusal !== null) {
			// ErrorInfo adds the server's reply to the exception message when there was one.
			$error = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $refusal->getMessage();
			if ($this->logErrors) {
				$this->log('mailer.error', 'Mail transport refused the message.', self::logContext($mail, $startedAt) + ['error' => $error, 'accepted' => $accepted]);
			}
			throw new MailerSendException($error, $accepted, $refusal);
		}

		if ($this->logSuccess) {
			$this->log('mailer.success', 'Mail transport accepted the message.', self::logContext($mail, $startedAt));
		}

		return $mail->getLastMessageID();
	}


	// ----------------------------------------------------------------
	// Extension points
	// ----------------------------------------------------------------

	/**
	 * Create the PHPMailer for one message, set up with the transport of cfg mail and no message.
	 *
	 * The one place where the service configures PHPMailer. A subclass may override it to set
	 * what cfg mail does not cover, such as DKIM signing or SMTP stream options, on the
	 * instance that the parent returns.
	 *
	 * Behavior:
	 * - Every PHPMailer error on the instance throws a PHPMailer exception.
	 * - Sends UTF-8 with the X-Mailer header CitOmni Mailer.
	 * - SMTP uses exactly the encryption of cfg mail.smtp.encryption, sets PHPMailer's time
	 *   limits for connecting and for a reply to cfg mail.smtp.timeout, and authenticates when
	 *   cfg mail.smtp.username is set; the password is left to send().
	 *
	 * @return \PHPMailer\PHPMailer\PHPMailer
	 * @see \CitOmni\Infrastructure\Service\Mailer::send()  How a message is reported that PHPMailer cannot build with what an override set up.
	 */
	protected function newPhpMailer(): PHPMailer {
		$mail = new PHPMailer(true);
		$mail->CharSet  = PHPMailer::CHARSET_UTF8;
		$mail->XMailer  = 'CitOmni Mailer';
		$mail->Hostname = $this->hostname;

		if ($this->transport === 'smtp') {
			$mail->isSMTP();
			$mail->Host       = $this->smtpHost;
			$mail->Port       = $this->smtpPort;
			$mail->SMTPSecure = $this->smtpSecure;
			// cfg mail.smtp.encryption is the whole TLS policy, so 'none' must not upgrade on its own.
			$mail->SMTPAutoTLS = false;
			$mail->Timeout     = $this->smtpTimeout;
			// Timeout covers connecting and reading a reply that has begun; the wait for a reply
			// to begin is the SMTP instance's Timelimit, 300 seconds unless set.
			$mail->getSMTPInstance()->Timelimit = $this->smtpTimeout;
			$mail->SMTPAuth = $this->smtpUsername !== '';
			$mail->Username = $this->smtpUsername;
		} elseif ($this->transport === 'sendmail') {
			$mail->isSendmail();
			$mail->Sendmail = $this->sendmailPath;
		} else {
			$mail->isMail();
		}

		return $mail;
	}


	/**
	 * Render an HTML body as the text of its plain-text alternative.
	 *
	 * Behavior:
	 * - Drops the head, title, style and script elements with their content, and every other tag.
	 * - Writes a link as its text followed by its target in parentheses. A link without text,
	 *   or whose text is its target, becomes the target alone; one without a target, its text.
	 *   A link that is not closed ends at the next link or at the end of the body.
	 * - Collapses whitespace as a browser does. A paragraph, heading, list, table or block
	 *   quote ends with an empty line; a line break, division or table row with a line break.
	 *   A list item starts a line with "- ", and table cells are separated by a space.
	 * - Decodes HTML entities and trims every line.
	 * - Keeps the text of a step that PCRE cannot complete as it was before that step, so the
	 *   result degrades instead of coming out empty.
	 *
	 * @param  string  $html  HTML body.
	 * @return string
	 */
	protected function htmlToText(#[\SensitiveParameter] string $html): string {
		// The patterns work on bytes, without /u, so invalid UTF-8 cannot fail them. Possessive
		// quantifiers let an element left open cost one scan instead of a backtrack per position.
		$html = \preg_replace('~<(head|title|style|script)\b[^>]*+>(?:[^<]++|<(?!/\1\b))*+</\1\s*>~i', '', $html) ?? $html;
		$html = \preg_replace('~[ \t\r\n\f]+~', ' ', $html) ?? $html;
		$html = self::inlineLinks($html);
		$html = \preg_replace(
			['~<li\b[^>]*>~i', '~</t[dh]\s*>~i', '~<br\b[^>]*>|</(?:div|tr)\s*>~i', '~</(?:p|h[1-6]|ul|ol|table|blockquote)\s*>~i'],
			["\n- ", ' ', "\n", "\n\n"],
			$html
		) ?? $html;

		$text = \html_entity_decode(\strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
		$text = \preg_replace(['~ *\n *~', '~\n{3,}~'], ["\n", "\n\n"], $text) ?? $text;

		return \trim($text);
	}


	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	/**
	 * Put the sender, the recipients, the subject, the bodies and the attachments on a new PHPMailer.
	 *
	 * Behavior:
	 * - Hands every address to PHPMailer trimmed and with an internationalized domain in
	 *   punycode, and keeps one copy of each, compared in lower case: one across to, cc and
	 *   bcc, and one in Reply-To.
	 * - Leaves preSend() no address to check that PHPMailer has not checked already.
	 * - Switches PHPMailer's process-wide validator to eai before the first address when it is
	 *   php and an address has UTF-8 in its local part; send() restores it.
	 *
	 * @param  \PHPMailer\PHPMailer\PHPMailer  $mail  Instance from newPhpMailer().
	 * @param  array{0:string,1:string}  $sender  Address and display name.
	 * @param  array{to:list<array{0:string,1:string}>,cc:list<array{0:string,1:string}>,bcc:list<array{0:string,1:string}>,replyTo:list<array{0:string,1:string}>}  $lists  Address and display name pairs by kind.
	 * @param  string  $subject
	 * @param  string  $html  HTML body; '' for none.
	 * @param  string  $text  Plain-text body; '' for none.
	 * @param  array<int|string,string>  $attachments  Paths; a string key is the file name the recipient sees.
	 * @return array<string,string>  The spelling of each recipient in the arguments, by the address handed to PHPMailer in lower case.
	 * @throws \InvalidArgumentException  When an address keeps a non-ASCII domain, or PHPMailer refuses an address without throwing.
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects an address or cannot read an attachment.
	 */
	private function compose(PHPMailer $mail, array $sender, array $lists, string $subject, #[\SensitiveParameter] string $html, #[\SensitiveParameter] string $text, array $attachments): array {
		// PHPMailer 6.10 and later switch the process-wide validator from php to eai at the first
		// address with UTF-8 in it, so the addresses before that one would meet another validator
		// than the ones after it. Switched up front, every address of the message meets the same
		// one. Before 6.10, PHPMailer reads eai as php.
		if (PHPMailer::$validator === 'php' && self::hasUnicodeLocalPart([$sender, ...$lists['to'], ...$lists['cc'], ...$lists['bcc'], ...$lists['replyTo']])) {
			PHPMailer::$validator = 'eai';
		}

		$mail->setFrom(self::sendable($mail, $sender[0], 'from'), $sender[1]);

		// PHPMailer queues an address with a non-ASCII domain until preSend(), where a queued
		// recipient that is also queued as Reply-To is lost, and one that duplicates an address
		// given in punycode is dropped from its header. Converted here, no address is queued, and
		// with duplicates dropped as PHPMailer would drop them, an add that returns false means
		// PHPMailer refused the address.
		$spelling = [];
		$seen     = ['recipient' => [], 'replyTo' => []];
		foreach ($lists as $kind => $pairs) {
			$group = $kind === 'replyTo' ? 'replyTo' : 'recipient';
			foreach ($pairs as [$address, $name]) {
				$sent = self::sendable($mail, $address, $kind);
				$key  = \strtolower($sent);
				if (isset($seen[$group][$key])) {
					continue;
				}
				$seen[$group][$key] = true;
				if ($group === 'recipient') {
					$spelling[$key] = $address;
				}

				$added = match ($kind) {
					'to'      => $mail->addAddress($sent, $name),
					'cc'      => $mail->addCC($sent, $name),
					'bcc'     => $mail->addBCC($sent, $name),
					'replyTo' => $mail->addReplyTo($sent, $name),
				};
				if (!$added) {
					throw new \InvalidArgumentException("PHPMailer refused {$address} ({$kind}).");
				}
			}
		}

		$mail->Subject = $subject;
		// send() refuses a message without a body; PHPMailer's own check would also refuse "0".
		$mail->AllowEmpty = true;
		if ($html !== '') {
			$mail->isHTML(true);
			$mail->Body    = $html;
			$mail->AltBody = $text !== '' ? $text : $this->htmlToText($html);
		} else {
			$mail->isHTML(false);
			$mail->Body = $text;
		}

		foreach ($attachments as $name => $path) {
			$mail->addAttachment($path, \is_string($name) ? $name : '');
		}

		return $spelling;
	}


	/**
	 * Write every link of an HTML text as its text followed by its target in parentheses.
	 *
	 * Behavior:
	 * - A link without text, or whose text is its target, becomes the target alone; a link
	 *   without a target, its text.
	 * - A link that is not closed ends at the next link or at the end of the text.
	 * - Other tags stay for the later steps of htmlToText().
	 *
	 * @param  string  $html  HTML text.
	 * @return string
	 */
	private static function inlineLinks(#[\SensitiveParameter] string $html): string {
		// Only the a tags are matched, never the text between them, so the work stays linear
		// however long a link that is left open runs. A quoted attribute value may hold a >.
		$parts = \preg_split('~(<a\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>|</a\s*>)~i', $html, -1, \PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) {
			return $html;
		}

		$out   = '';
		$url   = null;
		$label = '';
		foreach ($parts as $i => $part) {
			// Even indexes hold the text between a tags, odd indexes the tags themselves.
			if ($i % 2 === 0) {
				if ($url === null) {
					$out .= $part;
				} else {
					$label .= $part;
				}
				continue;
			}

			if ($url !== null) {
				$out  .= self::linkText($url, $label);
				$url   = null;
				$label = '';
			}
			if ($part[1] !== '/') {
				$url = \preg_match('~\shref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $part, $m) === 1
					? \trim(($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? ''))
					: '';
			}
		}

		return $url !== null ? $out . self::linkText($url, $label) : $out;
	}


	/**
	 * Return one link as its text followed by its target in parentheses.
	 *
	 * @param  string  $url  Target as written in the href attribute; '' for none.
	 * @param  string  $label  HTML between the opening tag and the end of the link.
	 * @return string  HTML; the label alone when the target is '' or the label's text, the target alone when the label has no text.
	 */
	private static function linkText(string $url, string $label): string {
		$text = \trim(\strip_tags($label));
		if ($url === '' || $url === $text) {
			return $label;
		}

		// Escaped, so that a raw < in the target cannot open a tag for strip_tags(); decoded later.
		$url = \str_replace(['<', '>'], ['&lt;', '&gt;'], $url);
		if ($text === '') {
			return $url;
		}

		// Whitespace that ends the label stays after the target, between it and what follows.
		$trimmed = \rtrim($label);
		return $trimmed . ' (' . $url . ')' . \substr($label, \strlen($trimmed));
	}


	/**
	 * Return an address as PHPMailer is to send it: trimmed, with an internationalized domain in punycode.
	 *
	 * @param  \PHPMailer\PHPMailer\PHPMailer  $mail  Instance that converts the domain in its CharSet.
	 * @param  string  $address  Address as the caller gave it.
	 * @param  string  $kind  Argument name, for the exception.
	 * @return string  An address whose domain is ASCII; PHPMailer checks the rest when it takes the address.
	 * @throws \InvalidArgumentException  When the domain stays non-ASCII: the intl and mbstring extensions are missing, or the domain is not a valid internationalized domain name.
	 */
	private static function sendable(PHPMailer $mail, string $address, string $kind): string {
		$sent = $mail->punyencodeAddress(\trim($address));
		$at   = \strrpos($sent, '@');

		// PHPMailer would take a non-ASCII domain without checking it and check it in preSend(),
		// where a failure could no longer be told from a failure to build the message.
		if ($at !== false && \preg_match('/[\x80-\xFF]/', \substr($sent, $at + 1)) === 1) {
			throw new \InvalidArgumentException(PHPMailer::idnSupported()
				? "The domain of {$address} ({$kind}) is not a valid internationalized domain name."
				: "Sending to {$address} ({$kind}) needs the intl and mbstring extensions for its non-ASCII domain.");
		}

		return $sent;
	}


	/**
	 * Tell whether any of the addresses has a byte outside ASCII before its last @.
	 *
	 * The domain is left out because sendable() converts it to punycode.
	 *
	 * @param  list<array{0:string,1:string}>  $pairs  Address and display name pairs.
	 * @return bool
	 */
	private static function hasUnicodeLocalPart(array $pairs): bool {
		foreach ($pairs as [$address]) {
			$address = \trim($address);
			$at      = \strrpos($address, '@');
			if (\preg_match('/[\x80-\xFF]/', $at === false ? $address : \substr($address, 0, $at)) === 1) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Read one address argument as address and display name pairs.
	 *
	 * @param  string|array<int|string,mixed>  $value  One address, or addresses and address => display name pairs.
	 * @param  string  $param  Parameter name, for the exception.
	 * @return list<array{0:string,1:string}>  In the order given.
	 * @throws \InvalidArgumentException  When an array entry is not a string.
	 */
	private static function addresses(string|array $value, string $param): array {
		if (\is_string($value)) {
			return [[$value, '']];
		}

		$pairs = [];
		foreach ($value as $key => $entry) {
			if (!\is_string($entry)) {
				throw new \InvalidArgumentException("Every entry of {$param} must be a string.");
			}
			// An int key holds a bare address; a string key is the address and the entry its display name.
			$pairs[] = \is_int($key) ? [$entry, ''] : [$key, $entry];
		}

		return $pairs;
	}


	/**
	 * Return the fields of a log line about a send: no body and no credential.
	 *
	 * @param  \PHPMailer\PHPMailer\PHPMailer  $mail  The message, after preSend().
	 * @param  float  $startedAt  microtime(true) when the transport started.
	 * @return array{message_id:string,transport:string,from:string,to:list<string>,cc:list<string>,bcc:list<string>,subject:string,duration_ms:int}
	 */
	private static function logContext(PHPMailer $mail, float $startedAt): array {
		return [
			'message_id'  => $mail->getLastMessageID(),
			'transport'   => $mail->Mailer,
			'from'        => $mail->From,
			'to'          => \array_column($mail->getToAddresses(), 0),
			'cc'          => \array_column($mail->getCcAddresses(), 0),
			'bcc'         => \array_column($mail->getBccAddresses(), 0),
			'subject'     => $mail->Subject,
			'duration_ms' => (int)\round((\microtime(true) - $startedAt) * 1000),
		];
	}


	/**
	 * Write one line to mailer.jsonl through the log service, when the app has one.
	 *
	 * Notes:
	 * - Best-effort: A write that fails is dropped, so that it can neither hide a refusal nor
	 *   turn an accepted send into an exception.
	 *
	 * @param  string  $category  mailer.error or mailer.success.
	 * @param  string  $message  Human-readable message.
	 * @param  array<string,mixed>  $context  Fields of the line.
	 * @return void
	 */
	private function log(string $category, string $message, array $context): void {
		if (!$this->app->hasService('log')) {
			return;
		}

		try {
			$this->app->log->write(self::LOG_FILE, $category, $message, $context);
		} catch (\Throwable) {
			// Dropped: the caller must learn whether the message went out, also when the log is broken.
		}
	}


	/**
	 * Refuse the keys of a cfg node that cfg mail no longer has.
	 *
	 * @param  array<string,mixed>  $node  The node as an array.
	 * @param  string  $path  Cfg path of the node.
	 * @param  array<string,string>  $removed  What replaces each removed key, by key.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\MailerConfigException  When the node has one of the keys, whatever its value; the message names only the type of that value.
	 */
	private static function refuseRemovedKeys(array $node, string $path, array $removed): void {
		foreach ($removed as $key => $replacement) {
			if (\array_key_exists($key, $node)) {
				throw new MailerConfigException($path . '.' . $key, 'absent (' . $replacement . ')', $node[$key], false);
			}
		}
	}


	/**
	 * Return a cfg list of SMTP hosts as PHPMailer reads them, without a scheme.
	 *
	 * @param  mixed  $value  The cfg value.
	 * @param  string  $path  Cfg path, for the exception.
	 * @param  bool  $required  Reject ''.
	 * @return string  Hosts separated by ';', or '' when not required.
	 * @throws \CitOmni\Infrastructure\Exception\MailerConfigException  When the value is not a string, is '' while required, or has an entry that, trimmed, is not a host name or IP address that PHPMailer accepts, optionally followed by :port with a port from 1 to 65535.
	 */
	private static function cfgHosts(mixed $value, string $path, bool $required): string {
		$hosts = self::cfgString($value, $path, $required);
		if ($hosts === '') {
			return $hosts;
		}

		// PHPMailer skips an entry that it cannot parse and fails only when no entry is left, as a
		// refused send. The pattern is its own split of an entry, less the ssl:// or tls:// with
		// which it would replace mail.smtp.encryption for that host; isValidHost() refuses a
		// scheme left in the host part.
		foreach (\explode(';', $hosts) as $entry) {
			if (\preg_match('/^(.+?)(?::(\d+))?$/', \trim($entry), $m) !== 1 || !PHPMailer::isValidHost($m[1]) || (isset($m[2]) && ((int)$m[2] < 1 || (int)$m[2] > 65535))) {
				throw new MailerConfigException($path, "host names or IP addresses separated by ';', each optionally with :port, without ssl:// or tls://", $hosts);
			}
		}

		return $hosts;
	}


	/**
	 * Return a cfg string.
	 *
	 * @param  mixed  $value  The cfg value.
	 * @param  string  $path  Cfg path, for the exception.
	 * @param  bool  $required  Reject ''.
	 * @return string
	 * @throws \CitOmni\Infrastructure\Exception\MailerConfigException  When the value is not a string, or '' while required.
	 */
	private static function cfgString(mixed $value, string $path, bool $required): string {
		if (!\is_string($value) || ($required && $value === '')) {
			throw new MailerConfigException($path, $required ? 'a non-empty string' : 'a string', $value);
		}

		return $value;
	}


	/**
	 * Return a cfg email address.
	 *
	 * @param  mixed  $value  The cfg value.
	 * @param  string  $path  Cfg path, for the exception.
	 * @param  bool  $required  Reject ''.
	 * @return string  A valid address, or '' when not required.
	 * @throws \CitOmni\Infrastructure\Exception\MailerConfigException  When the value is not '' or an address that PHPMailer's php validator accepts, or '' while required.
	 */
	private static function cfgAddress(mixed $value, string $path, bool $required): string {
		// The php validator is named, because PHPMailer switches its process-wide default.
		if (!\is_string($value) || ($value === '' ? $required : !PHPMailer::validateAddress($value, 'php'))) {
			throw new MailerConfigException($path, $required ? 'a valid email address' : "'' or a valid email address", $value);
		}

		return $value;
	}


	/**
	 * Return a cfg integer within a range.
	 *
	 * @param  mixed  $value  The cfg value.
	 * @param  string  $path  Cfg path, for the exception.
	 * @param  int  $min  Smallest valid value.
	 * @param  int  $max  Largest valid value; PHP_INT_MAX for no limit.
	 * @return int
	 * @throws \CitOmni\Infrastructure\Exception\MailerConfigException  When the value is not an integer within the range.
	 */
	private static function cfgInt(mixed $value, string $path, int $min, int $max): int {
		if (!\is_int($value) || $value < $min || $value > $max) {
			throw new MailerConfigException($path, $max === \PHP_INT_MAX ? "an integer >= {$min}" : "an integer from {$min} to {$max}", $value);
		}

		return $value;
	}


	/**
	 * Return a cfg boolean.
	 *
	 * @param  mixed  $value  The cfg value.
	 * @param  string  $path  Cfg path, for the exception.
	 * @return bool
	 * @throws \CitOmni\Infrastructure\Exception\MailerConfigException  When the value is not a bool.
	 */
	private static function cfgBool(mixed $value, string $path): bool {
		if (!\is_bool($value)) {
			throw new MailerConfigException($path, 'a boolean', $value);
		}

		return $value;
	}


}
