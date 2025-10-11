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
// use PHPMailer\PHPMailer\Exception;

/**
 * Mailer: Thin PHPMailer facade with CitOmni config and structured logging.
 *
 * Responsibilities:
 * - Provide a fluent API to compose and send emails (to/cc/bcc/replyTo/subject/body/attach).
 * - Apply transport and message defaults from app config (From, Reply-To, HTML vs text).
 * - Perform dev-friendly, structured JSON logging (optional and policy-driven).
 * - Stay lightweight: No templating engine, only simple {key} placeholder injection.
 *
 * Collaborators:
 * - Reads: $this->app->cfg->mail (transport, defaults, logging policy).
 * - Writes: $this->app->log (mail_log.jsonl, mailer_errors.jsonl).
 * - Optional: $this->app->request (IP detection in HTTP; falls back safely in CLI).
 *
 * Configuration keys:
 * - mail.format (string) - Default "html" or "text". Default: "html".
 * - mail.transport (string) - "smtp"|"sendmail"|"qmail"|"mail". Default: "mail".
 * - mail.from.email (string) - Sender address. Default: "" (unset).
 * - mail.from.name (string) - Sender display name. Default: "".
 * - mail.reply_to.email (string) - Reply-To address. Default: "" (unset).
 * - mail.reply_to.name (string) - Reply-To name. Default: "".
 * - mail.sendmail_path (string) - Path for sendmail/qmail. Default: "/usr/sbin/sendmail" or "/var/qmail/bin/sendmail".
 * - mail.smtp.host (string) - SMTP host(s), ";" separated allowed. Default: "".
 * - mail.smtp.port (int) - SMTP port. Default: 587.
 * - mail.smtp.encryption (string) - "tls"|"ssl"|"" (none). Default: "".
 * - mail.smtp.auth (bool) - Enable SMTP auth. Default: true.
 * - mail.smtp.username (string) - SMTP username. Default: "".
 * - mail.smtp.password (string) - SMTP password. Default: "".
 * - mail.smtp.auto_tls (bool) - Opportunistic STARTTLS. Default: true.
 * - mail.smtp.timeout (int) - Seconds before SMTP timeout. Default: 15.
 * - mail.smtp.keepalive (bool) - Keep the SMTP connection alive. Default: false.
 * - mail.logging.log_success (bool) - Dev-only success logs. Default: false.
 * - mail.logging.debug_transcript (bool) - Capture limited SMTP transcript. Default: false.
 * - mail.logging.max_lines (int) - Max transcript lines. Default: 200.
 * - mail.logging.include_bodies (bool) - Include message bodies in logs (prod caution). Default: false.
 *
 * Error handling:
 * - Fail-fast on invalid inputs (e.g., unreadable attachment/template): throws \InvalidArgumentException.
 * - Transport errors during send(): Caught locally to produce a structured error log; method returns false.
 * - No output is echoed; all diagnostics are routed to logs when enabled.
 *
 * Typical usage:
 * - Use for composing and sending transactional emails with minimal ceremony; let config drive the transport.
 *
 * Examples:
 *
 * Core:
 *   $this->app->mailer->to('user@example.com')->subject('Hi')->body('<p>Hello</p>')->send();
 *
 * Scenario (SMTP HTML with auto AltBody):
 *   $this->app->mailer->subject('Welcome')->body('<h1>Hey</h1>')->to('a@ex.tld')->send();
 *
 * Scenario (Plain text, multiple recipients):
 *   $m = $this->app->mailer->body('Ping', false)->to(['a@x.tld','b@x.tld']); $m->send();
 *
 * Scenario (Template with placeholders):
 *   $this->app->mailer->templateVars(['name'=>'Sara'])->body('Hi {name}', true)->to('u@x.tld')->send();
 *
 * Scenario (Attachment):
 *   $this->app->mailer->attach('/abs/report.pdf')->to('u@x.tld')->subject('Rpt')->body('See attached')->send();
 *
 * Scenario (Dev success logging):
 *   // Enable "mail.logging.log_success=true" in dev to capture message summaries in mail_log.jsonl
 *
 * Failure:
 * - Misconfigured SMTP or unreachable host: send() returns false and logs to mailer_errors.jsonl with safe context.
 */
class Mailer extends BaseService {

	/** Underlying PHPMailer instance (configured in init()). */
	protected PHPMailer $mailer;

	/**
	 * Template variables for simple placeholder injection.
	 * Keys are used as `{key}` in the body; values are string-cast.
	 *
	 * @var array<string,scalar|\Stringable>
	 */
	protected array $vars = [];

	/** Default message format (true = HTML, false = plain text). */
	protected bool $defaultHtml = true;

	/** Enable per-message JSON logging (also auto-enabled in dev environment). */
	protected bool $logEmails = false;

	/**
	 * Context of last failure from send(); null if last send() succeeded.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $lastError = null;


	/**
	 * Lifecycle hook (called automatically if present).
	 *
	 * Behavior:
	 * - Instantiates PHPMailer and sets base properties (UTF-8, XMailer).
	 * - Applies transport (SMTP/sendmail/qmail/mail) from cfg.
	 * - Applies message defaults (From/Reply-To/format) from cfg.
	 *
	 * Notes:
	 * - Keep this lightweight; no heavy I/O or long operations.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->mailer = new PHPMailer(true);
		$this->mailer->CharSet = 'UTF-8';
		$this->mailer->XMailer = 'CitOmni Mailer';

		$this->applyTransportFromCfg();
		$this->applyMessageDefaultsFromCfg();

		// Enable success logging from config (DEV can set to true)
		$logSuccess = (bool)($this->app->cfg->mail->logging->log_success ?? false);
		if ($logSuccess) {
			$this->logEmails(true);
		}
	}


	/**
	 * Re-apply transport and message defaults from current cfg.
	 *
	 * Use this after runtime config changes to 'mail' settings.
	 *
	 * @return void
	 */
	public function refreshConfig(): void {
		$this->applyTransportFromCfg();
		$this->applyMessageDefaultsFromCfg();
	}


	/**
	 * Apply From/Reply-To/default format from cfg.
	 *
	 * Reads:
	 * - $this->app->cfg->mail->from->email/name
	 * - $this->app->cfg->mail->reply_to->email/name
	 * - $this->app->cfg->mail->format ('html'|'text')
	 *
	 * @return void
	 */
	protected function applyMessageDefaultsFromCfg(): void {
		$mail = $this->app->cfg->mail ?? null;

		$fromEmail = $mail->from->email ?? '';
		$fromName  = $mail->from->name  ?? '';
		if ($fromEmail !== '') {
			$this->mailer->setFrom($fromEmail, $fromName);
		}

		$rtEmail = $mail->reply_to->email ?? '';
		$rtName  = $mail->reply_to->name  ?? '';
		if ($rtEmail !== '') {
			$this->mailer->clearReplyTos();
			$this->mailer->addReplyTo($rtEmail, $rtName);
		}

		$format = \strtolower((string)($mail->format ?? 'html'));
		$this->defaultHtml = ($format === 'html');
	}


	/**
	 * Apply transport configuration from app cfg to PHPMailer.
	 *
	 * Behavior:
	 * - Read mail.transport and switch PHPMailer mode: smtp | sendmail | qmail | mail.
	 * - For SMTP: Set Host, Port, SMTPSecure from encryption (tls|ssl|none), SMTPAuth, Username, Password.
	 * - For SMTP: Apply operational tuning (SMTPAutoTLS, Timeout, SMTPKeepAlive).
	 * - For sendmail/qmail: Set Sendmail binary path.
	 * - Disable immediate debug output (SMTPDebug=0, Debugoutput is a no-op); send() handles transcript capture.
	 *
	 * Notes:
	 * - Idempotent: Safe to call multiple times (used by refreshConfig()).
	 * - Encryption policy: Unknown/empty encryption results in no implicit TLS/SSL.
	 * - No I/O is performed; this only sets PHPMailer properties.
	 * - Uses cfg defaults when fields are missing; does not validate credentials or reachability.
	 *
	 * Typical usage:
	 *   Refresh PHPMailer after config changes or during service init().
	 *
	 * Examples:
	 *
	 *   // SMTP with STARTTLS on port 587
	 *   $this->app->cfg->mail->transport = 'smtp';
	 *   $this->app->cfg->mail->smtp = (object)['host'=>'smtp.example.com','port'=>587,'encryption'=>'tls'];
	 *   $this->applyTransportFromCfg();
	 *
	 *   // Switch to system sendmail
	 *   $this->app->cfg->mail->transport = 'sendmail';
	 *   $this->app->cfg->mail->sendmail_path = '/usr/sbin/sendmail';
	 *   $this->applyTransportFromCfg();
	 *
	 * Failure:
	 * - None: Property assignment only; no exceptions are thrown here.
	 *
	 * @return void
	 * @throws void This method does not throw.
	 */
	protected function applyTransportFromCfg(): void {
		$mail = $this->app->cfg->mail ?? null;
		$transport = \strtolower((string)($mail->transport ?? 'mail'));
		switch ($transport) {
			case 'smtp':
				$this->mailer->isSMTP();
				
				$smtp = $mail->smtp ?? null;
				
				// Host can be a semicolon-separated list; PHPMailer supports this natively.
				$this->mailer->Host = (string)($smtp->host ?? '');
				$this->mailer->Port = (int)($smtp->port ?? 587);
				
				// Encryption
				$enc = \strtolower((string)($smtp->encryption ?? ''));
				if ($enc === 'tls') {
					$this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
				} elseif ($enc === 'ssl') {
					$this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
				} else {
					// none/null - leave empty (no implicit magic)
					$this->mailer->SMTPSecure = '';
				}
				
				// Auth
				$this->mailer->SMTPAuth = (bool)($smtp->auth ?? true);
				if ($this->mailer->SMTPAuth) {
					$this->mailer->Username = (string)($smtp->username ?? '');
					$this->mailer->Password = (string)($smtp->password ?? '');
				}
				
				// Operational tuning
				$this->mailer->SMTPAutoTLS = (bool)($smtp->auto_tls ?? true);
				$this->mailer->Timeout     = (int)($smtp->timeout  ?? 15);
				$this->mailer->SMTPKeepAlive = (bool)($smtp->keepalive ?? false);
				
				// Debugging
				// Never echo from transport setup; debug is handled safely in send()
				$this->mailer->SMTPDebug   = 0;
				$this->mailer->Debugoutput = static function (): void {};
				break;
				
			case 'sendmail':
				$this->mailer->isSendmail();
				// PHPMailer uses the same property for sendmail/qmail path
				$this->mailer->Sendmail = (string)($mail->sendmail_path ?? '/usr/sbin/sendmail');
				break;
			case 'qmail':
				$this->mailer->isQmail();
				$this->mailer->Sendmail = (string)($mail->sendmail_path ?? '/var/qmail/bin/sendmail');
				break;
			case 'mail':
			default:
				// PHP's mail() transport
				$this->mailer->isMail();
				break;
		}
	}


	/**
	 * Clear current message state and re-apply message defaults.
	 *
	 * Clears: Recipients (To/Cc/Bcc), Reply-Tos, attachments, custom headers,
	 * Subject, Body, AltBody, and template vars.
	 *
	 * @return void
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
	 * Set the sender (From).
	 *
	 * @param string $email Sender email address.
	 * @param string $name  Optional display name.
	 * @return self         Fluent self.
	 */
	public function from(string $email, string $name = ''): self {
		$this->mailer->setFrom($email, $name);
		return $this;
	}


	/**
	 * Add one or more recipients (To).
	 *
	 * Accepts:
	 * - string email: "user@example.com"
	 * - array of strings: ["a@example.com","b@example.com"]
	 * - array of arrays: [["email"=>"a@example.com","name"=>"A"], ...]
	 *
	 * @param string|array    $email Recipient(s).
	 * @param string|null     $name  Name used when $email is a single string.
	 * @return self                   Fluent self.
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
	 * Add one or more recipients (Cc).
	 *
	 * Same accepted shapes as to().
	 *
	 * @param string|array $email Recipient(s).
	 * @param string|null  $name  Name used when $email is a single string.
	 * @return self                Fluent self.
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
	 * Add one or more recipients (Bcc).
	 *
	 * Same accepted shapes as to().
	 *
	 * @param string|array $email Recipient(s).
	 * @param string|null  $name  Name used when $email is a single string.
	 * @return self                Fluent self.
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
	 * Same accepted shapes as to().
	 *
	 * @param string|array $email Address(es).
	 * @param string|null  $name  Name used when $email is a single string.
	 * @return self                Fluent self.
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
	 * Set the Subject.
	 *
	 * @param string $subject Subject line.
	 * @return self           Fluent self.
	 */
	public function subject(string $subject): self {
		$this->mailer->Subject = $subject;
		return $this;
	}


	/**
	 * Set the message body and format.
	 *
	 * Behavior:
	 * - If $isHtml is null, uses the default format from cfg (this->defaultHtml).
	 * - Injects template vars using {key} placeholders.
	 *
	 * @param string   $body   Message body (HTML or plain).
	 * @param bool|null $isHtml True for HTML, false for plain; null = use default.
	 * @return self            Fluent self.
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
	 * @param string $altBody Text-only alternative content.
	 * @return self           Fluent self.
	 */
	public function altBody(string $altBody): self {
		$this->mailer->AltBody = $altBody;
		return $this;
	}


	/**
	 * Convert basic HTML into a readable plain-text representation.
	 *
	 * - Replaces <br> and common block closings with newlines.
	 * - Strips tags and decodes entities.
	 * - Normalizes whitespace/newlines.
	 *
	 * @param string $html Source HTML.
	 * @return string      Plain-text version.
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
	 * @param string      $file Absolute or relative path to a readable file.
	 * @param string|null $name Optional override of attachment filename.
	 * @return self              Fluent self.
	 * @throws \InvalidArgumentException If the file is not readable.
	 */
	public function attach(string $file, ?string $name = null): self {
		if (!\is_readable($file)) {
			throw new \InvalidArgumentException("Attachment not readable: {$file}");
		}
		$this->mailer->addAttachment($file, $name ?? '');
		return $this;
	}


	/**
	 * Send the message.
	 *
	 * Behavior:
	 * - If only AltBody is set, forces text mode.
	 * - If HTML is set but AltBody is empty, generates AltBody from HTML.
	 * - Delegates to PHPMailer::send() (exceptions bubble up).
	 * - Optionally logs a JSON summary to 'mail_log.jsonl' in dev or if logEmails(true).
	 *
	 * @return void
	 * @throws \PHPMailer\PHPMailer\Exception On PHPMailer send errors.
	 */
/* 	 
	public function send(): void {
		
		// If Body empty but AltBody present, force text mode
		if ($this->mailer->Body === '' && $this->mailer->AltBody !== '') {
			$this->mailer->isHTML(false);
		}
		
		// Generate AltBody for HTML mails if missing
		if ($this->mailer->isHTML() && $this->mailer->AltBody === '') {
			$this->mailer->AltBody = $this->htmlToPlain($this->mailer->Body);
		}
		
		$this->mailer->send();
		
		// Optional lightweight dev logging (avoid in production)
		if ($this->logEmails && defined('CITOMNI_ENVIRONMENT') && CITOMNI_ENVIRONMENT === 'dev') {
			$this->app->log->write(
				'mail_log.jsonl',
				'info',
				'Mail sent',
				[
					'from'      => [$this->mailer->From, $this->mailer->FromName], // PHPMailer has a single From; logged as [address, name]
					'to'        => \array_map(fn($a) => $a[0], $this->mailer->getToAddresses()),
					'cc'        => \array_map(fn($a) => $a[0], $this->mailer->getCcAddresses()),
					'bcc'       => \array_map(fn($a) => $a[0], $this->mailer->getBccAddresses()),
					'subject'   => $this->mailer->Subject,
					'body'      => $this->mailer->Body,
					'altBody'   => $this->mailer->AltBody,
					'isHtml'    => $this->mailer->isHTML(),
					'transport'  => $this->mailer->Mailer,  // 'smtp', 'sendmail', 'qmail', 'mail'
					'message_id' => $this->mailer->getLastMessageID(), // Useful for correlation at the MTA (Mail Transfer Agent)				
					'timestamp' => \date('Y-m-d H:i:s'),
					'ip'        => $this->app->request->ip(),
					'user_agent'=> $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
				]
			);
		}

		$this->resetMessage();
	}
*/
	 
	/**
	 * Send the prepared email with structured logging and no output.
	 *
	 * Behavior:
	 * - Normalize bodies: If only AltBody is set, force text mode; if HTML body lacks AltBody, auto-generate it.
	 * - Capture optional SMTP transcript in memory (no echo) per mail.logging.debug_transcript and max_lines.
	 * - Attempt transport send via PHPMailer::send() and measure duration.
	 * - On success in dev and when enabled, write a summary entry to mail_log.jsonl.
	 * - On failure, log a structured error to mailer_errors.jsonl and populate lastError.
	 * - Always reset the message state (recipients, headers, bodies, vars) before returning.
	 * - Never echoes debug or error text.
	 * - DEV: Logs full structured context; SMTP transcript only if logging.debug_transcript=true (capped by max_lines).
	 * - PROD: Logs safe context (no body content; only length + sha256) unless include_bodies=true.
	 * - Single log entry per failure: mailer_errors.jsonl / category=error.
	 * - Optional success log in DEV (mail_log.jsonl) when log_success=true.
	 *
	 * Notes:
	 * - No echo or header writes: Safe for HTTP and CLI.
	 * - Credentials are masked; bodies are only logged when mail.logging.include_bodies=true.
	 * - Uses isHtmlMode() (read-only) instead of calling isHTML() with no args (setter side effect).
	 *
	 * Typical usage:
	 *   Call after composing recipients, subject, and body to dispatch a single message.
	 *
	 * Examples:
	 *
	 *   // Happy path: HTML body; AltBody auto-generated
	 *   $ok = $this->app->mailer->to('u@x.tld')->subject('Hi')->body('<b>Hello</b>', true)->send();
	 *
	 *   // Edge case: Only AltBody set; forces text mode
	 *   $ok = $this->app->mailer->to('u@x.tld')->altBody('Plain only')->send();
	 *
	 * Failure:
	 * - Transport errors are caught; a structured record is logged and the method returns false.
	 *
	 * @return bool True if the message was accepted by the transport; false otherwise.
	 * @throws \PHPMailer\PHPMailer\Exception Never thrown: Caught internally and converted to false.
	 */
	public function send(): bool {
		
		// Normalize bodies
		if ($this->mailer->Body === '' && $this->mailer->AltBody !== '') {
			$this->mailer->isHTML(false);
		}
		if ($this->isHtmlMode() && $this->mailer->AltBody === '') {
			$this->mailer->AltBody = $this->htmlToPlain($this->mailer->Body);
		}

		$envIsDev = \defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev';

		// Logging/debug policy from cfg
		$logCfg          = $this->app->cfg->mail->logging ?? null;
		$debugTranscript = (bool)($logCfg->debug_transcript ?? false);
		$debugMaxLines   = (int)($logCfg->max_lines ?? 200);
		$includeBodies   = (bool)($logCfg->include_bodies ?? false);

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

			// Normalize to UTF-8
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

			// Log the error
			$this->app->log->write('mailer_errors.jsonl', 'error', 'Mailer send failed', $context);
			
			// Tell caller that we failed...
			return false;
			
		} finally {
			$this->resetMessage();
		}
	}


	/**
	 * Detect HTML body mode without mutating PHPMailer state.
	 *
	 * Behavior:
	 * - Inspect PHPMailer ContentType and compare to "text/html".
	 * - Return true when HTML mode is active; false otherwise.
	 * - Perform a read-only check; never calls the isHTML() setter.
	 *
	 * Notes:
	 * - Avoid calling isHTML() with no args: It enables HTML mode (side effect).
	 * - Works regardless of how Body/AltBody were assigned.
	 *
	 * @return bool True if PHPMailer is in HTML content mode; otherwise false.
	 */
	private function isHtmlMode(): bool {
		return \strcasecmp($this->mailer->ContentType, \PHPMailer\PHPMailer\PHPMailer::CONTENT_TYPE_TEXT_HTML) === 0;
	}


	/**
	 * Mask an email/username for safe logging (e.g., "jo*****@example.com").
	 *
	 * @param string $s Non-empty email-like string.
	 * @return string Masked representation.
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
	 * Collect environment metadata safely for both HTTP and CLI modes.
	 *
	 * @return array{0:string,1:string} [$ip, $userAgent]
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
	 * Normalize strings, arrays, and objects to valid UTF-8.
	 *
	 * Behavior:
	 * - Return strings that are already valid UTF-8 unchanged.
	 * - Attempt CP1252 -> UTF-8 conversion; accept only if result is valid UTF-8.
	 * - Fall back to ISO-8859-1 -> UTF-8 via mb_convert_encoding or iconv.
	 * - If all conversions fail, strip control bytes and return a best-effort string.
	 * - For arrays: Recursively normalize both keys (when string) and values.
	 * - For objects: Normalize via get_object_vars() and return the normalized array.
	 *
	 * Notes:
	 * - No exceptions are thrown; conversion warnings are silenced intentionally.
	 * - Objects are returned as arrays by design (safe for JSON logging).
	 * - Order is preserved; references are not retained.
	 *
	 * Typical usage:
	 *   Prepare log payloads or JSON-safe context that may contain mixed encodings.
	 *
	 * Examples:
	 *
	 *   // Happy path: Already UTF-8
	 *   $clean = $this->utf8Normalize("Hello"); // "Hello"
	 *
	 *   // Edge: Mixed array with CP1252 fragments
	 *   $clean = $this->utf8Normalize(["k\xA0y" => "na\xE9ve"]); // normalized keys/values
	 *
	 * Failure:
	 * - None: Returns a sanitized value; invalid bytes are removed as a last resort.
	 *
	 * @param mixed $v Input value (string|array|object|scalar).
	 * @return mixed  UTF-8 string, normalized array, or original scalar; objects become arrays.
	 * @throws void   This method does not throw.
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
			// $converted = @\iconv('CP1252', 'UTF-8', $v);
			$converted = @\iconv('CP1252', 'UTF-8//IGNORE', $v);
			if ($converted !== false && (!$hasMb || \mb_check_encoding($converted, 'UTF-8'))) {
				return $converted;
			}

			// Fallback: ISO-8859-1 via mb_convert_encoding if available; else iconv
			if (\function_exists('mb_convert_encoding')) {
				$converted = @\mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
			} else {
				// $converted = @\iconv('ISO-8859-1', 'UTF-8', $v);
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
	 * Enable/disable JSON logging of sent emails (in addition to dev auto-logging).
	 *
	 * @param bool $enable True to enable, false to disable.
	 * @return self        Fluent self.
	 */
	public function logEmails(bool $enable = true): self {
		$this->logEmails = $enable;
		return $this;
	}


	/**
	 * Set template variables for {key} placeholder injection.
	 *
	 * @param array<string,scalar|\Stringable> $vars Key/value pairs (string-cast on injection).
	 * @return self                                Fluent self.
	 */
	public function templateVars(array $vars): self {
		$this->vars = $vars;
		return $this;
	}


	/**
	 * Inject template variables into body using {key} placeholders.
	 *
	 * @param string $body Raw body (HTML or text).
	 * @return string      Body with placeholders replaced.
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
	 * Set message body from a template string or file.
	 *
	 * @param string     $fileOrString Path to template file, or template contents (if $isFile=false).
	 * @param array      $vars         Template variables for {key} placeholders.
	 * @param bool       $isFile       True to load from file; false to use the string as-is.
	 * @param bool|null  $isHtml       True=HTML, False=plain, null=use defaultHtml.
	 * @return self                    Fluent self.
	 * @throws \InvalidArgumentException If $isFile is true and file is not readable.
	 */
	public function setTemplate(string $fileOrString, array $vars = [], bool $isFile = true, ?bool $isHtml = null): self {
		$template = $isFile ? $this->loadTemplateFile($fileOrString) : $fileOrString;
		$this->templateVars($vars);
		return $this->body($template, $isHtml);
	}


	/**
	 * Load template contents from a file.
	 *
	 * @param string $filePath Absolute/relative path to a readable file.
	 * @return string          Template contents.
	 * @throws \InvalidArgumentException If file is not readable.
	 */
	protected function loadTemplateFile(string $filePath): string {
		if (!\is_readable($filePath)) {
			throw new \InvalidArgumentException("Template file not readable: {$filePath}");
		}
		return (string)\file_get_contents($filePath);
	}


	/**
	 * Get a concise, human-readable message for the previous send() failure.
	 *
	 * @return string|null Error message or null if last send() succeeded / not called yet.
	 */
	public function getLastErrorMessage(): ?string {
		if ($this->lastError === null) {
			return null;
		}
		return (string)($this->lastError['error_info']
			?? ($this->lastError['exception']['message'] ?? 'Unknown mail error'));
	}


	/**
	 * Get full structured context for the previous send() failure.
	 *
	 * @return array|null Associative array or null if last send() succeeded.
	 */
	public function getLastErrorContext(): ?array {
		return $this->lastError;
	}


	/**
	 * Return a sanitized text snippet with multibyte fallback.
	 *
	 * Behavior:
	 * - Strip HTML tags from input.
	 * - Truncate to at most $len characters.
	 * - Prefer mb_substr when available; fall back to substr.
	 * - Do not change encoding; return a raw substring.
	 *
	 * Notes:
	 * - Does not collapse whitespace; caller may normalize if needed.
	 * - Safe for logs and previews where bodies may contain HTML.
	 *
	 * Typical usage:
	 *   Produce a short, readable preview for logging or UI badges.
	 *
	 * Examples:
	 *
	 *   // HTML body preview (<= 40 chars)
	 *   $preview = $this->mbSnippet('<p>Hello <b>world</b></p>', 40);
	 *
	 *   // Input shorter than limit: unchanged after strip
	 *   $preview = $this->mbSnippet('<i>OK</i>', 200);
	 *
	 * Failure:
	 * - None: Returns a string; no exceptions are thrown.
	 *
	 * @param string $s   Source text (may include HTML).
	 * @param int    $len Maximum characters to keep (>= 0).
	 * @return string     Stripped substring, length <= $len.
	 */
	private function mbSnippet(string $s, int $len = 200): string {
		if (\function_exists('mb_substr')) {
			return \mb_substr(\strip_tags($s), 0, $len);
		}
		return \substr(\strip_tags($s), 0, $len);
	}


	/**
	 * Destructor: Close persistent SMTP connection if enabled and in SMTP mode.
	 *
	 * @return void
	 */
	public function __destruct() {
		if ($this->mailer instanceof PHPMailer && $this->mailer->SMTPKeepAlive && $this->mailer->isSMTP()) {
			$this->mailer->smtpClose();
		}
	}
	
	
}
