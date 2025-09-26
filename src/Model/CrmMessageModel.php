<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni Infrastructure - Lean cross-mode infrastructure services for CitOmni applications (HTTP & CLI).
 * Source:  https://github.com/citomni/infrastructure
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Infrastructure\Model;


 
/**
 * CrmMessageModel: Persist and notify on contact form submissions.
 *
 * Responsibilities:
 * - Accepts only whitelisted fields (no mass assignment).
 * - Fills meta when missing: client IP, request URI, created timestamp.
 * - Validates (user-facing required fields): Subject, body, name, email.
 * - Optional CSRF/Captcha/Honeypot checks based on cfg toggles.
 * - Insert a record into the `crm_msg` table and send a notification email.
 *
 * Notes:
 * - Returns structured arrays (HTTP-like codes) for controller ergonomics.
 * - DB/Mail exceptions are caught locally to produce a 5xx result; unexpected errors bubble up.
 *
 * Collaborators:
 * - $this->app->db (write): Inserts into the `crm_msg` table.
 * - $this->app->mailer (write): Sends notification email.
 * - $this->app->request (read): Resolves client IP and current URI.
 * - $this->app->session (read): Reads Captcha value when enabled.
 * - $this->app->security (read): Verifies CSRF and logs failed attempts.
 * - $this->app->txt (read): Provides localized strings and templates.
 * - $this->app->cfg (read): Provides feature toggles and identity/mail settings.
 *
 * Configuration keys:
 * - security.csrf_protection (bool): Enable CSRF verification (default: false).
 * - security.captcha_protection (bool): Enable Captcha verification (default: false).
 * - security.honeypot_protection (bool): Enable Honeypot check (default: false).
 * - http.trust_proxy (bool): Honor proxy headers for client IP (default: false).
 * - locale.charset (string): Charset for HTML escaping (default: "UTF-8").
 * - identity.email (string): Notification recipient (preferred).
 * - mail.from.email (string): Fallback recipient if identity.email is missing.
 *
 * Error handling:
 * - Validation and security failures fail-soft with structured arrays (4xx).
 * - Database and mail operations are guarded; failures return 5xx with context.
 * - No global catch-all: Unexpected exceptions outside guards bubble to the global handler.
 *
 * Typical usage:
 * - Called by a POST-handling controller action to store and notify on contact messages.
 *
 * Examples:
  
 *   // Core: Minimal valid submission (no CSRF/Captcha/Honeypot enabled)
 *   $model = new \CitOmni\Infrastructure\Model\CrmMessageModel($this->app);
 *   $res = $model->create([
 *     'msg_subject' => 'Hello', 'msg_body' => 'World',
 *     'msg_from_name' => 'Alice', 'msg_from_email' => 'alice@example.com'
 *   ]);
 *
 *   // Scenario: CSRF enabled; invalid token yields 403 with user-safe message
 *   // cfg.security.csrf_protection = true
 *   $model = new \CitOmni\Infrastructure\Model\CrmMessageModel($this->app);
 *   $res = $model->create([
 *     'msg_subject' => 'Hi', 'msg_body' => 'There',
 *     'msg_from_name' => 'Bob', 'msg_from_email' => 'bob@example.com',
 *     'csrf_token' => 'bad-token'
 *   ]);
 *
 *   // Edge: Missing fields -> 422
 *   $model = new \CitOmni\Infrastructure\Model\CrmMessageModel($this->app);
 *   $res = $model->create([
 *     'msg_subject' => '', 'msg_body' => '',
 *     'msg_from_name' => '', 'msg_from_email' => 'not-an-email'
 *   ]);
 *
 * Failure:
 * - 422 on missing/invalid required fields; 403 on CSRF/Captcha/Honeypot; 5xx on DB/Mail errors.
 *
 * Optional: Standalone
 * - For tutorials only: Instantiate App with CITOMNI_APP_PATH + autoload, then new self($app) and call create().
 */
final class CrmMessageModel extends BaseModelLiteMySQLi {

	private const TABLE  = 'crm_msg';

	/** @var array<int,string> */
	private const FIELDS = [
		'msg_subject',
		'msg_body',
		'msg_from_name',
		'msg_from_company_name',
		'msg_from_company_no',
		'msg_from_email',
		'msg_from_phone',
		'msg_from_ip',
		'msg_posted_from_url',
		'msg_added_dt',
	];

	// Whitelisted fields (typed; default null)
	public ?string $msg_subject = null;
	public ?string $msg_body = null;
	public ?string $msg_from_name = null;
	public ?string $msg_from_company_name = null;
	public ?string $msg_from_company_no = null;
	public ?string $msg_from_email = null;
	public ?string $msg_from_phone = null;
	public ?string $msg_from_ip = null;
	public ?string $msg_posted_from_url = null;
	public ?string $msg_added_dt = null;

	// Security auxiliaries
	public ?string $csrf_token = null;
	public ?string $captcha = null;
	public ?string $honeypot = null;


	/**
	 * Create a CRM message, persist it, and notify via email.
	 *
	 * Behavior:
	 * - Maps only whitelisted fields from input to properties.
	 * - Fills meta defaults (IP, posted-from URL, timestamp) if missing.
	 * - Validates required fields (subject, body, name, email).
	 * - Applies CSRF/Captcha/Honeypot checks if enabled in config.
	 * - Inserts into `crm_msg` and sends a notification email.
	 * - Returns a structured array with response code and user message.
	 *
	 * Notes:
	 * - Texts are read via Txt service ("contact" file in citomni/infrastructure layer).
	 * - IP resolution honors http.trust_proxy; use with care behind load balancers.
	 * - Email body is HTML; Mailer will set AltBody automatically if needed.
	 *
	 * Failure:
	 * - Returns 422/403/500 with a user-facing message; unexpected exceptions bubble up.
	 *
	 * @param array<string,mixed> $data Contact form inputs.
	 * @return array{response:int,msg:string,error_msg:?string} Structured result for controller flow.
	 */
	public function create(array $data): array {

		// 1) Map only whitelisted fields
		foreach (self::FIELDS as $f) {
			$val = $data[$f] ?? null;			
			$this->{$f} = \is_string($val) ? $val : null;  // Only accept real strings for text fields; anything else becomes null
		}

		// 2) Security/meta fields (not stored)
		$this->csrf_token = isset($data['csrf_token']) ? (string)$data['csrf_token'] : null;
		$this->captcha    = isset($data['captcha']) ? (string)$data['captcha'] : null;
		$this->honeypot   = isset($data['honeypot']) ? (string)$data['honeypot'] : null;

		// 3) Charset (for escapes)
		$charset = (string)($this->app->cfg->locale->charset ?? 'UTF-8');

		// 4) Meta defaults (prefer Request service)
		// Proxy-aware client IP when configured
		$trustProxy = (bool)($this->app->cfg->http->trust_proxy ?? false);
		$this->msg_from_ip = $this->msg_from_ip
			?? (string)$this->app->request->ip($trustProxy);

		
		// Prefer Request service URI; fall back to server var to be defensive
		$this->msg_posted_from_url = $this->msg_posted_from_url
			?? (string)($this->app->request->uri() ?? ($_SERVER['REQUEST_URI'] ?? ''));

		// Deterministic server timestamp (app clocks apply)
		$this->msg_added_dt = $this->msg_added_dt ?? \date('Y-m-d H:i:s');

		// 5) Validation (user-facing)
		$errors = [];
		if ($this->isBlankText($this->msg_subject)) {
			$errors[] = $this->app->txt->get('err_subject_required', 'contact', 'citomni/infrastructure');
		}
		if ($this->isBlankText($this->msg_body)) {
			$errors[] = $this->app->txt->get('err_body_required', 'contact', 'citomni/infrastructure');
		}
		if ($this->isBlankText($this->msg_from_name)) {
			$errors[] = $this->app->txt->get('err_name_required', 'contact', 'citomni/infrastructure');
		}
		if ($this->isBlankText($this->msg_from_email) || !\filter_var((string)$this->msg_from_email, \FILTER_VALIDATE_EMAIL)) {
			$errors[] = $this->app->txt->get('err_invalid_email', 'contact', 'citomni/infrastructure');
		}
		if ($errors) {
			return ['response' => 422, 'msg' => \implode('|', $errors), 'error_msg' => null];
		}

		// 6) CSRF (verify only when enabled; log failed attempts)
		if (!empty($this->app->cfg->security->csrf_protection)) {
			if (!$this->app->security->verifyCsrf((string)$this->csrf_token)) {
				$this->app->security->logFailedCsrf('crm_msg', [
					'url'   => $this->msg_posted_from_url,
					'name'  => $this->msg_from_name,
					'email' => $this->msg_from_email,
				]);
				return [
					'response' => 403,
					'msg' => $this->app->txt->get('err_invalid_csrf', 'contact', 'citomni/infrastructure'),
					'error_msg' => null,
				];
			}
		}

		// 7) Captcha (compare case-insensitively with session value)
		if (!empty($this->app->cfg->security->captcha_protection)) {
			$sess = $this->app->session->get('captcha');
			if (!$sess || \strcasecmp((string)$sess, (string)$this->captcha) !== 0) {
				return [
					'response' => 403,
					'msg' => $this->app->txt->get('err_invalid_captcha', 'contact', 'citomni/infrastructure'),
					'error_msg' => null,
				];
			}
		}

		// 8) Honeypot (any non-empty value is treated as bot traffic)
		if (!empty($this->app->cfg->security->honeypot_protection) && \trim((string)$this->honeypot) !== '') {
			return [
				'response' => 403,
				'msg' => $this->app->txt->get('err_honeypot', 'contact', 'citomni/infrastructure'),
				'error_msg' => null,
			];
		}

		// 9) DB insert - persist only the explicit field set
		try {
			$insert = [];
			foreach (self::FIELDS as $f) {
				$insert[$f] = $this->{$f};
			}
			$insertId = $this->app->db->insert(self::TABLE, $insert);
			if ($insertId <= 0) {
				return [
					'response' => 500,
					'msg' => $this->app->txt->get('err_db_save', 'contact', 'citomni/infrastructure'),
					'error_msg' => (string)$this->app->db->getLastError(),
				];
			}
		} catch (\Throwable $e) {
			return [
				'response' => 500,
				'msg' => $this->app->txt->get('err_db_exception', 'contact', 'citomni/infrastructure'),
				'error_msg' => $e->getMessage(),
			];
		}

		// 10) Notify recipient using configured identity/mail; HTML body with safe escaping
		try {
			$recipient = (string)($this->app->cfg->identity->email
				?? ($this->app->cfg->mail->from->email ?? 'errors@citomni.com'));

			$appName = (string)($this->app->cfg->identity->app_name ?? 'CitOmni App');

			$ok = $this->app->mailer
				->to($recipient)
				->subject($this->app->txt->get(
					'form_send_success_email_subject',
					'contact',
					'citomni/infrastructure',
					'New contact message from %APP_NAME%',
					['APP_NAME' => $appName]
				))
				->body(
					$this->app->txt->get(
						'form_send_success_email_body',
						'contact',
						'citomni/infrastructure',
						'A visitor has sent the following via the contact form on %APP_NAME%: <br><br>',
						['APP_NAME' => $appName]
					) .
					'<b>' . $this->app->txt->get('lbl_from', 'contact', 'citomni/infrastructure') . ":</b><br> " .
					$this->app->txt->get('lbl_name', 'contact', 'citomni/infrastructure') . ': ' . \htmlspecialchars((string)$this->msg_from_name, \ENT_QUOTES | \ENT_SUBSTITUTE, $charset) . '<br>' .
					$this->app->txt->get('lbl_company', 'contact', 'citomni/infrastructure') . ': ' . \htmlspecialchars((string)$this->msg_from_company_name, \ENT_QUOTES | \ENT_SUBSTITUTE, $charset) . '<br>' .
					$this->app->txt->get('lbl_cvr', 'contact', 'citomni/infrastructure') . ': ' . \htmlspecialchars((string)$this->msg_from_company_no, \ENT_QUOTES | \ENT_SUBSTITUTE, $charset) . '<br>' .
					$this->app->txt->get('lbl_phone', 'contact', 'citomni/infrastructure') . ': ' . \htmlspecialchars((string)$this->msg_from_phone, \ENT_QUOTES | \ENT_SUBSTITUTE, $charset) . '<br>' .
					$this->app->txt->get('lbl_email', 'contact', 'citomni/infrastructure') . ': ' . \htmlspecialchars((string)$this->msg_from_email, \ENT_QUOTES | \ENT_SUBSTITUTE, $charset) . '<br><br>' .
					'<b>' . $this->app->txt->get('lbl_subject', 'contact', 'citomni/infrastructure') . ":</b><br> " . \htmlspecialchars((string)$this->msg_subject, \ENT_QUOTES | \ENT_SUBSTITUTE, $charset) . '<br><br>' .
					'<b>' . $this->app->txt->get('lbl_message', 'contact', 'citomni/infrastructure') . ":</b><br> " . \nl2br(\htmlspecialchars((string)$this->msg_body, \ENT_QUOTES | \ENT_SUBSTITUTE, $charset), false) . '<br>',
					true
				)
				->send();

			if (!$ok) {
				return [
					'response' => 500,
					'msg' => $this->app->txt->get('err_mail_failed', 'contact', 'citomni/infrastructure'),
					'error_msg' => $this->app->mailer->getLastErrorMessage() ?? 'Unknown mail error',
				];
			}
		} catch (\Throwable $e) {
			return [
				'response' => 500,
				'msg' => $this->app->txt->get('err_mail_failed', 'contact', 'citomni/infrastructure'),
				'error_msg' => $e->getMessage(),
			];
		}

		return [
			'response' => 200,
			'msg' => $this->app->txt->get('form_send_success', 'contact', 'citomni/infrastructure'),
			'error_msg' => null,
		];
	}

	
	/**
	 * Treat non-strings, whitespace-only, and '0' as blank.
	 *
	 * Behavior:
	 * - Returns true for non-strings (arrays, ints, bools, null).
	 * - Returns true for strings that trim to '' or '0'.
	 * - Returns false only for meaningful, non-blank strings.
	 *
	 * Notes:
	 * - Prefer over empty(): empty('   ') is false (whitespace slips through),
	 *   and empty(trim((string)$v)) misclassifies arrays as non-blank ("Array").
	 * - Keeps policy explicit for CRM text fields: accept strings with content only.
	 *
	 * Typical usage:
	 *   Validate subject, body, from_name, and from_email.
	 *
	 * Examples:
	 *   $this->isBlankText('   '); // true
	 *   $this->isBlankText('0');   // true
	 *   $this->isBlankText('Hi');  // false
	 *
	 * Failure:
	 * - None; pure check.
	 *
	 * @param mixed $v Candidate value.
	 * @return bool True if considered blank; false otherwise.
	 */
	private function isBlankText(mixed $v): bool {
		if (!\is_string($v)) {
			return true; // reject non-strings up front for text fields
		}
		$t = \trim($v);
		return $t === '' || $t === '0';
	}


}
