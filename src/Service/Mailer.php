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

use CitOmni\Kernel\Service\BaseService;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Mailer: Service that composes and sends email through PHPMailer with the transport and defaults of cfg mail.
 *
 * Gives code a fluent API to compose one message at a time and send it over the transport
 * that cfg mail chooses, with the sender, Reply-To and format defaults of cfg mail. The
 * wording, templates and recipients of a message belong to the caller; the transport itself
 * belongs to PHPMailer.
 *
 * Rules:
 * - Never echo or print. Diagnostics go to the log service only.
 * - Never log a credential.
 * - Read the SMTP password from the secrets service only for a send, and clear it afterwards.
 * - Leave no message state behind a send: The next message starts from the cfg defaults.
 *
 * Typical usage:
 *   $sent = $this->app->mailer
 *       ->to('user@example.com')
 *       ->subject('Welcome')
 *       ->templateVars(['name' => $name])
 *       ->body($html)
 *       ->send();
 *   if (!$sent) {
 *       $reason = $this->app->mailer->getLastErrorMessage();
 *   }
 */
class Mailer extends BaseService {

	/** PHPMailer instance that holds the transport settings and the message being composed. */
	protected PHPMailer $mailer;

	/** @var array<string,scalar|\Stringable> Values for the {key} placeholders of the next body(), cast to string. */
	protected array $vars = [];

	/** Default body format: true for HTML, false for plain text. */
	protected bool $defaultHtml = true;

	/** Log each sent message to mail_log.jsonl; takes effect only in the dev environment. */
	protected bool $logEmails = false;

	/** @var array<string,mixed>|null Context of the last failed send(), or null while no send() has failed. */
	private ?array $lastError = null;


	/**
	 * Set up PHPMailer with the transport and the message defaults of cfg mail.
	 *
	 * Behavior:
	 * - Sends as UTF-8, with CitOmni Mailer as X-Mailer.
	 * - Turns success logging on when cfg mail.logging.log_success is true.
	 * - Opens no connection and reads no secret.
	 *
	 * @return void
	 * @throws \UnexpectedValueException  When the transport is smtp and the removed cfg key mail.smtp.password is set, or mail.smtp.auth or mail.smtp.auto_tls is not a bool.
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects the address in cfg mail.from.email or mail.reply_to.email.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$this->mailer = new PHPMailer(true);
		$this->mailer->CharSet = 'UTF-8';
		$this->mailer->XMailer = 'CitOmni Mailer';

		$this->applyTransportFromCfg();
		$this->applyMessageDefaultsFromCfg();

		// Enable success logging from config (DEV can set to true)
		$logSuccess = (bool)$this->app->cfg->mail->logging->log_success;
		if ($logSuccess) {
			$this->logEmails(true);
		}
	}


	/**
	 * Apply the transport and the message defaults of cfg mail again.
	 *
	 * Behavior:
	 * - Recipients, subject, bodies and attachments composed so far stay. From is set, and the
	 *   Reply-To addresses are replaced, when cfg mail names them.
	 *
	 * @return void
	 * @throws \UnexpectedValueException  When the transport is smtp and the removed cfg key mail.smtp.password is set, or mail.smtp.auth or mail.smtp.auto_tls is not a bool.
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects the address in cfg mail.from.email or mail.reply_to.email.
	 */
	public function refreshConfig(): void {
		$this->applyTransportFromCfg();
		$this->applyMessageDefaultsFromCfg();
	}


	/**
	 * Apply the sender, the Reply-To address and the default format of cfg mail.
	 *
	 * Behavior:
	 * - Sets From when cfg mail.from.email is not ''.
	 * - Replaces every Reply-To address with cfg mail.reply_to when its email is not ''.
	 * - Makes HTML the default format when cfg mail.format is html, in any letter case, and
	 *   plain text otherwise.
	 *
	 * @return void
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects the address in cfg mail.from.email or mail.reply_to.email.
	 */
	protected function applyMessageDefaultsFromCfg(): void {
		$mail = $this->app->cfg->mail;

		$fromEmail = (string)$mail->from->email;
		$fromName  = (string)$mail->from->name;
		if ($fromEmail !== '') {
			$this->mailer->setFrom($fromEmail, $fromName);
		}

		$rtEmail = (string)$mail->reply_to->email;
		$rtName  = (string)$mail->reply_to->name;
		if ($rtEmail !== '') {
			$this->mailer->clearReplyTos();
			$this->mailer->addReplyTo($rtEmail, $rtName);
		}

		$format = \strtolower((string)$mail->format);
		$this->defaultHtml = ($format === 'html');
	}


	/**
	 * Apply the transport settings of cfg mail.
	 *
	 * Behavior:
	 * - Transport smtp, sendmail or qmail selects that transport; any other value selects PHP's
	 *   mail().
	 * - For SMTP: Sets host, port, encryption (tls for STARTTLS, ssl for implicit TLS, anything
	 *   else for none), authentication with the user name, auto TLS, timeout and keepalive, and
	 *   turns debug output off. The password stays empty until send().
	 * - Rejects the removed cfg key mail.smtp.password, so a password left in cfg is not
	 *   silently ignored, and requires booleans for mail.smtp.auth and mail.smtp.auto_tls, so
	 *   null cannot turn either off.
	 * - For sendmail and qmail: Sets the binary from cfg mail.sendmail_path, or the transport's
	 *   standard path when it is ''.
	 * - Applying it again gives the same state. Performs no IO.
	 *
	 * @return void
	 * @throws \UnexpectedValueException  When the transport is smtp and mail.smtp.password is set, or mail.smtp.auth or mail.smtp.auto_tls is not a bool.
	 */
	protected function applyTransportFromCfg(): void {
		$mail = $this->app->cfg->mail;
		$transport = \strtolower((string)$mail->transport);
		switch ($transport) {
			case 'smtp':
				$this->mailer->isSMTP();
				
				$smtp = $mail->smtp;
				// A removed key, so its presence is checked on the array, not read.
				if (\array_key_exists('password', $smtp->toArray())) {
					throw new \UnexpectedValueException(
						'Mailer config key "mail.smtp.password" is no longer supported; use secret "mail.smtp.password".'
					);
				}
				
				// Host can be a semicolon-separated list; PHPMailer supports this natively.
				$this->mailer->Host = (string)$smtp->host;
				$this->mailer->Port = (int)$smtp->port;
				
				// Encryption
				$enc = \strtolower((string)$smtp->encryption);
				if ($enc === 'tls') {
					$this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				} elseif ($enc === 'ssl') {
					$this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
				} else {
					// none/null - leave empty (no implicit magic)
					$this->mailer->SMTPSecure = '';
				}
				
				// Auth
				$this->mailer->SMTPAuth = self::flag($smtp->auth, 'mail.smtp.auth');
				if ($this->mailer->SMTPAuth) {
					$this->mailer->Username = (string)$smtp->username;
				} else {
					$this->mailer->Username = '';
				}
				$this->mailer->Password = '';
				
				// Operational tuning
				$this->mailer->SMTPAutoTLS = self::flag($smtp->auto_tls, 'mail.smtp.auto_tls');
				$this->mailer->Timeout     = (int)$smtp->timeout;
				$this->mailer->SMTPKeepAlive = (bool)$smtp->keepalive;
				
				// Debugging
				// Never echo from transport setup; debug is handled safely in send()
				$this->mailer->SMTPDebug   = 0;
				$this->mailer->Debugoutput = static function (): void {};
				break;
				
			case 'sendmail':
				$this->mailer->isSendmail();
				// PHPMailer uses the same property for sendmail/qmail path; '' means the transport's standard binary
				$path = (string)$mail->sendmail_path;
				$this->mailer->Sendmail = $path !== '' ? $path : '/usr/sbin/sendmail';
				break;
			case 'qmail':
				$this->mailer->isQmail();
				$path = (string)$mail->sendmail_path;
				$this->mailer->Sendmail = $path !== '' ? $path : '/var/qmail/bin/sendmail';
				break;
			case 'mail':
			default:
				// PHP's mail() transport
				$this->mailer->isMail();
				break;
		}
	}


	/**
	 * Return a mail cfg flag, rejecting anything but a bool.
	 *
	 * @param  mixed   $value  The cfg value.
	 * @param  string  $path   Dotted cfg path, for the message.
	 * @return bool
	 * @throws \UnexpectedValueException  When the value is not a bool.
	 */
	private static function flag(mixed $value, string $path): bool {
		if (!\is_bool($value)) {
			throw new \UnexpectedValueException("Mailer config {$path} must be a boolean.");
		}
		return $value;
	}


	/**
	 * Clear the message being composed and apply the message defaults of cfg mail again.
	 *
	 * Behavior:
	 * - Clears the To, Cc and Bcc recipients, the Reply-To addresses, attachments, custom
	 *   headers, subject, both bodies and the template variables.
	 *
	 * @return void
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects the address in cfg mail.from.email or mail.reply_to.email.
	 */
	public function resetMessage(): void {
		$this->mailer->clearAllRecipients();
		$this->mailer->clearReplyTos();
		$this->mailer->clearAttachments();
		$this->mailer->clearCustomHeaders();
		$this->mailer->Subject = '';
		$this->mailer->Body = '';
		$this->mailer->AltBody = '';
		$this->vars = [];
		$this->applyMessageDefaultsFromCfg();
	}


	/**
	 * Set the sender of the message.
	 *
	 * @param  string  $email  Sender address.
	 * @param  string  $name  Display name; '' for none.
	 * @return self
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects the address.
	 */
	public function from(string $email, string $name = ''): self {
		$this->mailer->setFrom($email, $name);
		return $this;
	}


	/**
	 * Add one or more To recipients.
	 *
	 * @param  string|list<string|array{email:string,name?:string}>  $email  One address, or a list of addresses and email and name pairs.
	 * @param  string|null  $name  Display name of every address given as a string.
	 * @return self
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects an address.
	 */
	public function to(string|array $email, ?string $name = null): self {
		foreach ((array)$email as $e) {
			if (\is_array($e)) {
				$this->mailer->addAddress($e['email'], $e['name'] ?? '');
			} else {
				$this->mailer->addAddress($e, $name ?? '');
			}
		}
		return $this;
	}


	/**
	 * Add one or more Cc recipients.
	 *
	 * @param  string|list<string|array{email:string,name?:string}>  $email  One address, or a list of addresses and email and name pairs.
	 * @param  string|null  $name  Display name of every address given as a string.
	 * @return self
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects an address.
	 */
	public function cc(string|array $email, ?string $name = null): self {
		foreach ((array)$email as $e) {
			if (\is_array($e)) {
				$this->mailer->addCC($e['email'], $e['name'] ?? '');
			} else {
				$this->mailer->addCC($e, $name ?? '');
			}
		}
		return $this;
	}


	/**
	 * Add one or more Bcc recipients.
	 *
	 * @param  string|list<string|array{email:string,name?:string}>  $email  One address, or a list of addresses and email and name pairs.
	 * @param  string|null  $name  Display name of every address given as a string.
	 * @return self
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects an address.
	 */
	public function bcc(string|array $email, ?string $name = null): self {
		foreach ((array)$email as $e) {
			if (\is_array($e)) {
				$this->mailer->addBCC($e['email'], $e['name'] ?? '');
			} else {
				$this->mailer->addBCC($e, $name ?? '');
			}
		}
		return $this;
	}


	/**
	 * Add one or more Reply-To addresses.
	 *
	 * @param  string|list<string|array{email:string,name?:string}>  $email  One address, or a list of addresses and email and name pairs.
	 * @param  string|null  $name  Display name of every address given as a string.
	 * @return self
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer rejects an address.
	 */
	public function replyTo(string|array $email, ?string $name = null): self {
		foreach ((array)$email as $e) {
			if (\is_array($e)) {
				$this->mailer->addReplyTo($e['email'], $e['name'] ?? '');
			} else {
				$this->mailer->addReplyTo($e, $name ?? '');
			}
		}
		return $this;
	}


	/**
	 * Set the subject of the message.
	 *
	 * @param  string  $subject
	 * @return self
	 */
	public function subject(string $subject): self {
		$this->mailer->Subject = $subject;
		return $this;
	}


	/**
	 * Set the body of the message and its format, filling in the template variables.
	 *
	 * Behavior:
	 * - Replaces each {key} placeholder with the template variable set before this call.
	 *
	 * @param  string  $body  HTML or plain text.
	 * @param  bool|null  $isHtml  True for HTML, false for plain text, null for the default format of cfg mail.format.
	 * @return self
	 */
	public function body(string $body, ?bool $isHtml = null): self {
		$useHtml = $isHtml ?? $this->defaultHtml;
		$body = $this->injectVars($body);
		$this->mailer->isHTML($useHtml);
		$this->mailer->Body = $body;
		return $this;
	}


	/**
	 * Set the plain-text alternative body.
	 *
	 * Behavior:
	 * - Fills in no template variables; body($text, false) does that for a plain-text body.
	 * - Without a body, send() sends this text as a text/plain message.
	 *
	 * @param  string  $altBody  Plain text.
	 * @return self
	 */
	public function altBody(string $altBody): self {
		$this->mailer->AltBody = $altBody;
		return $this;
	}


	/**
	 * Convert HTML to readable plain text.
	 *
	 * Behavior:
	 * - Turns br tags and the end tags of p, li, div and h1 to h6 into line breaks, strips
	 *   every other tag and decodes entities.
	 * - Collapses runs of spaces and tabs, and keeps at most one empty line in a row.
	 *
	 * @param  string  $html  HTML body.
	 * @return string  Trimmed plain text.
	 */
	protected function htmlToPlain(string $html): string {
		$withBreaks = \preg_replace(
			['~<br\s*/?>~i', '~</(p|li|div|h[1-6])\s*>~i'],
			"\n",
			$html
		);
		$text = \html_entity_decode(\strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = \preg_replace('/[ \t]+/', ' ', $text);
		$text = \preg_replace('/\n{3,}/', "\n\n", $text);
		return \trim($text);
	}


	/**
	 * Attach a file to the message.
	 *
	 * @param  string  $file  Path of a readable file.
	 * @param  string|null  $name  File name shown to the recipient; null for the file's own name.
	 * @return self
	 * @throws \InvalidArgumentException  When the file is not readable.
	 * @throws \PHPMailer\PHPMailer\Exception  When PHPMailer refuses the path, such as a stream wrapper path.
	 */
	public function attach(string $file, ?string $name = null): self {
		if (!\is_readable($file)) {
			throw new \InvalidArgumentException("Attachment not readable: {$file}");
		}
		$this->mailer->addAttachment($file, $name ?? '');
		return $this;
	}


	/**
	 * Send the message, log the outcome and reset the message state.
	 *
	 * A transport failure does not throw: send() returns false, logs the failure and keeps it
	 * for getLastErrorMessage() and getLastErrorContext(). Only a failure to read the SMTP
	 * password throws.
	 *
	 * Behavior:
	 * - A message with only a plain-text alternative body is sent as plain text with that
	 *   text as body. An HTML body without one gets it generated from the HTML.
	 * - For authenticated SMTP, reads the secret mail.smtp.password right before sending and
	 *   clears it afterwards.
	 * - A failure writes one error line to mailer_errors.jsonl with the PHPMailer error, the
	 *   transport settings with a masked user name, the addresses, the subject and the duration,
	 *   and:
	 *     - the bodies when cfg mail.logging.include_bodies is true, else their lengths and SHA-256;
	 *     - the SMTP transcript, up to mail.logging.max_lines lines, when
	 *       mail.logging.debug_transcript is true;
	 *     - the first 200 characters of the body without tags, in the dev environment.
	 * - A success writes a line with the whole message to mail_log.jsonl when success logging
	 *   is on and the environment is dev.
	 * - Resets the message state afterwards, whatever the outcome.
	 *
	 * Typical usage:
	 *   $sent = $this->app->mailer->to($email)->altBody('Plain only')->send();
	 *
	 * @return bool  True when the transport accepted the message.
	 * @throws \OutOfBoundsException  When authenticated SMTP is used and the secret mail.smtp.password is missing.
	 * @throws \RuntimeException  When the secret store cannot be read.
	 * @throws \UnexpectedValueException  When the secret store is invalid.
	 */
	public function send(): bool {
		
		// Normalize bodies. PHPMailer refuses an empty Body, so a text-only message
		// sends its AltBody as the plain-text Body.
		if ($this->mailer->Body === '' && $this->mailer->AltBody !== '') {
			$this->mailer->isHTML(false);
			$this->mailer->Body = $this->mailer->AltBody;
			$this->mailer->AltBody = '';
		}
		if ($this->isHtmlMode() && $this->mailer->AltBody === '') {
			$this->mailer->AltBody = $this->htmlToPlain($this->mailer->Body);
		}

		$envIsDev = \defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev';

		// Logging/debug policy from cfg
		$logCfg          = $this->app->cfg->mail->logging;
		$debugTranscript = (bool)$logCfg->debug_transcript;
		$debugMaxLines   = (int)$logCfg->max_lines;
		$includeBodies   = (bool)$logCfg->include_bodies;

		$smtpDebug = [];
		$startedAt = \microtime(true);

		// Capture PHPMailer debug to memory only (no echo)
		$this->mailer->SMTPDebug   = $debugTranscript ? 2 : 0;
		$this->mailer->Debugoutput = static function (string $str, int $level) use (&$smtpDebug, $debugMaxLines): void {
			if (\count($smtpDebug) < $debugMaxLines) {
				$smtpDebug[] = ['level' => $level, 'message' => $str];
			}
		};

		try {
			$this->applySecretCredentials();
			$ok = $this->mailer->send();

			// Optional success log in DEV
			if ($ok && $this->logEmails && $envIsDev) {
				
				[$ip, $ua] = $this->envMeta();
				
				$this->app->log->write(
					'mail_log.jsonl',
					'info',
					'Mail sent',
					[
						'from'       => [$this->mailer->From, $this->mailer->FromName],
						'to'         => \array_map(fn($a) => $a[0], $this->mailer->getToAddresses()),
						'cc'         => \array_map(fn($a) => $a[0], $this->mailer->getCcAddresses()),
						'bcc'        => \array_map(fn($a) => $a[0], $this->mailer->getBccAddresses()),
						'subject'    => $this->mailer->Subject,
						'body'       => $this->mailer->Body, // DEV only; OK because log_success is DEV-only
						'altBody'    => $this->mailer->AltBody,
						'isHtml'     => $this->isHtmlMode(),
						'transport'  => $this->mailer->Mailer,
						'message_id' => $this->mailer->getLastMessageID(),
						'duration_ms'=> (int)\round((\microtime(true) - $startedAt) * 1000),
						'ip'          => $ip,
						'user_agent'  => $ua,
					]
				);
			}

			return $ok;
		} catch (\PHPMailer\PHPMailer\Exception $e) {
			$durationMs = (int)\round((\microtime(true) - $startedAt) * 1000);

			// Prepare body stats
			$bodyStr = (string)$this->mailer->Body;
			$altStr  = (string)$this->mailer->AltBody;

			$bodyLen  = \strlen($bodyStr);
			$bodyHash = \hash('sha256', $bodyStr);
			$altLen   = \strlen($altStr);
			$altHash  = \hash('sha256', $altStr);

			// Never log credentials
			$maskedUser = $this->mailer->Username ? $this->maskEmailLike($this->mailer->Username) : null;

			[$ip, $ua] = $this->envMeta();

			$context = [
				'error_info'  => $this->mailer->ErrorInfo,
				'exception'   => [
					'class'   => $e::class,
					'message' => $e->getMessage(),
					'code'    => $e->getCode(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				],
				'transport'   => [
					'mailer'     => $this->mailer->Mailer,
					'host'       => $this->mailer->Host,
					'port'       => $this->mailer->Port,
					'secure'     => $this->mailer->SMTPSecure,
					'timeout'    => $this->mailer->Timeout,
					'smtp_auth'  => $this->mailer->SMTPAuth,
					'username'   => $maskedUser,
					'keep_alive' => $this->mailer->SMTPKeepAlive ?? false,
				],
				'from'        => [$this->mailer->From, $this->mailer->FromName],
				'to'          => \array_map(fn($a) => $a[0], $this->mailer->getToAddresses()),
				'cc'          => \array_map(fn($a) => $a[0], $this->mailer->getCcAddresses()),
				'bcc'         => \array_map(fn($a) => $a[0], $this->mailer->getBccAddresses()),
				'subject'     => $this->mailer->Subject,
				'is_html'     => $this->isHtmlMode(),
				'message_id'  => $this->mailer->getLastMessageID(),
				'duration_ms' => $durationMs,
				'ip'          => $ip,
				'user_agent'  => $ua,
				// SMTP transcript only when enabled
				'smtp_debug'  => $debugTranscript ? $smtpDebug : null,
			];

			// Body logging policy
			if ($includeBodies) {
				$context['body']    = $bodyStr;
				$context['altBody'] = $altStr;
			} else {
				$context['body_len']    = $bodyLen;
				$context['body_sha256'] = $bodyHash;
				$context['alt_len']     = $altLen;
				$context['alt_sha256']  = $altHash;
			}

			// DEV nicety: short preview regardless of include_bodies flag
			if ($envIsDev) {
				$context['body_preview'] = $this->mbSnippet($bodyStr, 200);
			}

			$context = $this->utf8Normalize($context);

			// Build normalized last-error-information (safe for JSON)
			$this->lastError = $this->utf8Normalize([
				'error_info' => $this->mailer->ErrorInfo,
				'exception'  => [
					'class'   => $e::class,
					'message' => $e->getMessage(),
					'code'    => $e->getCode(),
				],
				'duration_ms' => $durationMs,
			]);

			$this->app->log->write('mailer_errors.jsonl', 'error', 'Mailer send failed', $context);

			return false;
			
		} finally {
			$this->mailer->Password = '';
			$this->resetMessage();
		}
	}


	/**
	 * Set the SMTP password from the secrets service right before a send.
	 *
	 * Behavior:
	 * - Reads the secret mail.smtp.password only for SMTP with authentication; otherwise sets
	 *   an empty password.
	 *
	 * @return void
	 * @throws \OutOfBoundsException  When the secret mail.smtp.password is missing.
	 * @throws \RuntimeException  When the secret store cannot be read.
	 * @throws \UnexpectedValueException  When the secret store is invalid.
	 */
	private function applySecretCredentials(): void {
		if ($this->mailer->Mailer !== 'smtp' || !$this->mailer->SMTPAuth) {
			$this->mailer->Password = '';
			return;
		}

		$this->mailer->Password = $this->app->secrets->get('mail.smtp.password');
	}


	/**
	 * Tell whether the message is in HTML mode, without changing it.
	 *
	 * Notes:
	 * - PHPMailer's isHTML() is a setter: Called without an argument it switches HTML mode on.
	 *
	 * @return bool  True when the content type is text/html.
	 */
	private function isHtmlMode(): bool {
		return \strcasecmp($this->mailer->ContentType, \PHPMailer\PHPMailer\PHPMailer::CONTENT_TYPE_TEXT_HTML) === 0;
	}


	/**
	 * Mask an email address or user name for a log line.
	 *
	 * @param  string  $s  Non-empty address or user name.
	 * @return string  The first two characters, five asterisks and, for an address, @ and the domain.
	 */
	private function maskEmailLike(string $s): string {
		$parts = \explode('@', $s, 2);
		$local = $parts[0] ?? $s;
		$domain = $parts[1] ?? '';
		$keep = \min(2, \strlen($local));
		return $domain !== ''
			? \substr($local, 0, $keep) . '*****@' . $domain
			: \substr($local, 0, $keep) . '*****';
	}

	/**
	 * Return the client IP address and user agent for a log line.
	 *
	 * @return array{0:string,1:string}  'cli' and 'CLI' without a request service; otherwise the request's IP address, or REMOTE_ADDR or 'unknown' when that fails, and HTTP_USER_AGENT or 'Unknown'.
	 */
	private function envMeta(): array {
		$ip = 'cli';
		$ua = 'CLI';
		if (\method_exists($this->app, 'hasService') && $this->app->hasService('request')) {
			try {
				$ip = (string)$this->app->request->ip();
			} catch (\Throwable) {
				$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
			}
			$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
		}
		return [$ip, $ua];
	}


	/**
	 * Convert the strings in a value to valid UTF-8 for a JSON log line.
	 *
	 * Behavior:
	 * - Valid UTF-8 passes unchanged. Another string is converted from CP1252, else from
	 *   ISO-8859-1, else returned without ASCII control characters.
	 * - Arrays are converted key by key and value by value, in order. An object becomes the
	 *   array of its accessible properties, converted the same way.
	 * - Other values pass unchanged. Conversion warnings are suppressed.
	 *
	 * Notes:
	 * - Without mbstring, every string passes unchanged.
	 *
	 * @param  mixed  $v  Value to convert.
	 * @return mixed
	 */
	private function utf8Normalize(mixed $v): mixed {
		// Fast path for strings
		if (\is_string($v)) {
			$hasMb = \function_exists('mb_check_encoding');

			// If mb extension is missing or the string is already UTF-8, return as-is
			if (!$hasMb || \mb_check_encoding($v, 'UTF-8')) {
				return $v;
			}

			// Try CP1252 first - common legacy default on some systems
			$converted = @\iconv('CP1252', 'UTF-8//IGNORE', $v);
			if ($converted !== false && (!$hasMb || \mb_check_encoding($converted, 'UTF-8'))) {
				return $converted;
			}

			// Fallback: ISO-8859-1 via mb_convert_encoding if available; else iconv
			if (\function_exists('mb_convert_encoding')) {
				$converted = @\mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
			} else {
				$converted = @\iconv('ISO-8859-1', 'UTF-8//IGNORE', $v);
			}
			if ($converted !== false && (!$hasMb || \mb_check_encoding($converted, 'UTF-8'))) {
				return $converted;
			}

			// Last resort: Strip ASCII control chars that commonly break JSON/log sinks
			return \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? '';
		}

		// Recursively normalize arrays
		if (\is_array($v)) {
			$out = [];
			foreach ($v as $k => $vv) {
				// Normalize string keys as well to avoid mixed-encoding map keys
				$nk = \is_string($k) ? self::utf8Normalize($k) : $k;
				$out[$nk] = self::utf8Normalize($vv);
			}
			return $out;
		}

		// Objects are converted to arrays of properties, then normalized
		if (\is_object($v)) {
			return self::utf8Normalize(\get_object_vars($v));
		}

		// Scalars other than strings (int, float, bool, null) are returned unchanged
		return $v;
	}


	/**
	 * Turn success logging on or off for later sends of this instance.
	 *
	 * Notes:
	 * - Success lines are written only in the dev environment.
	 *
	 * @param  bool  $enable
	 * @return self
	 */
	public function logEmails(bool $enable = true): self {
		$this->logEmails = $enable;
		return $this;
	}


	/**
	 * Set the values for the {key} placeholders of the next body().
	 *
	 * Behavior:
	 * - Replaces the variables set before. They stay until the message is reset.
	 *
	 * @param  array<string,scalar|\Stringable>  $vars  Values by placeholder name, cast to string.
	 * @return self
	 */
	public function templateVars(array $vars): self {
		$this->vars = $vars;
		return $this;
	}


	/**
	 * Replace the {key} placeholders of a body with the template variables.
	 *
	 * @param  string  $body  HTML or plain text.
	 * @return string
	 */
	protected function injectVars(string $body): string {
		if (!$this->vars) {
			return $body;
		}
		$replace = [];
		foreach ($this->vars as $k => $v) {
			$replace['{' . $k . '}'] = (string)$v;
		}
		return \strtr($body, $replace);
	}


	/**
	 * Set the body of the message from a template file or string, with its template variables.
	 *
	 * Behavior:
	 * - Reads a template file as text; it is not executed.
	 * - Replaces the template variables with $vars, then sets the body as body() does.
	 *
	 * @param  string  $fileOrString  Path of a template file, or the template itself when $isFile is false.
	 * @param  array<string,scalar|\Stringable>  $vars  Values for the {key} placeholders, cast to string.
	 * @param  bool  $isFile  Read $fileOrString as a path.
	 * @param  bool|null  $isHtml  True for HTML, false for plain text, null for the default format of cfg mail.format.
	 * @return self
	 * @throws \InvalidArgumentException  When $isFile is true and the file is not readable.
	 */
	public function setTemplate(string $fileOrString, array $vars = [], bool $isFile = true, ?bool $isHtml = null): self {
		$template = $isFile ? $this->loadTemplateFile($fileOrString) : $fileOrString;
		$this->templateVars($vars);
		return $this->body($template, $isHtml);
	}


	/**
	 * Read a template file as text.
	 *
	 * @param  string  $filePath  Path of a readable file.
	 * @return string
	 * @throws \InvalidArgumentException  When the file is not readable.
	 */
	protected function loadTemplateFile(string $filePath): string {
		if (!\is_readable($filePath)) {
			throw new \InvalidArgumentException("Template file not readable: {$filePath}");
		}
		return (string)\file_get_contents($filePath);
	}


	/**
	 * Return PHPMailer's error message of the last failed send().
	 *
	 * Typical usage:
	 *   if (!$this->app->mailer->to($email)->subject($subject)->body($html)->send()) {
	 *       $reason = $this->app->mailer->getLastErrorMessage();
	 *   }
	 *
	 * @return string|null  Null while no send() of this instance has failed.
	 */
	public function getLastErrorMessage(): ?string {
		if ($this->lastError === null) {
			return null;
		}
		return (string)($this->lastError['error_info']
			?? ($this->lastError['exception']['message'] ?? 'Unknown mail error'));
	}


	/**
	 * Return the context of the last failed send().
	 *
	 * @return array{error_info:string,exception:array{class:string,message:string,code:int},duration_ms:int}|null  Null while no send() of this instance has failed.
	 */
	public function getLastErrorContext(): ?array {
		return $this->lastError;
	}


	/**
	 * Return the start of a text, without HTML tags.
	 *
	 * @param  string  $s  Text, possibly HTML.
	 * @param  int  $len  Maximum length, >= 0; in characters, or in bytes without mbstring.
	 * @return string  Whitespace and encoding unchanged.
	 */
	private function mbSnippet(string $s, int $len = 200): string {
		if (\function_exists('mb_substr')) {
			return \mb_substr(\strip_tags($s), 0, $len);
		}
		return \substr(\strip_tags($s), 0, $len);
	}


	/**
	 * Close the SMTP connection that keepalive leaves open, when the service is destroyed.
	 */
	public function __destruct() {
		if ($this->mailer instanceof PHPMailer && $this->mailer->SMTPKeepAlive && $this->mailer->isSMTP()) {
			$this->mailer->smtpClose();
		}
	}
	
	
}
