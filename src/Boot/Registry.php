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

namespace CitOmni\Infrastructure\Boot;

/**
 * Registry:
 * Declares this package's contributions to the host app:
 * - MAP_HTTP / MAP_CLI service bindings
 * - CFG_HTTP / CFG_CLI config overlay
 * - ROUTES_HTTP route definitions
 *
 * The App boot process will merge these into the final runtime.
 */
final class Registry {
	
	public const MAP_HTTP = [
		'db'   => \CitOmni\Infrastructure\Service\Db::class,
		'log'  => \CitOmni\Infrastructure\Service\Log::class,
		'txt'  => \CitOmni\Infrastructure\Service\Txt::class,
		'mailer' => \CitOmni\Infrastructure\Service\Mailer::class,
		// 'cache'=> \CitOmni\Infrastructure\Service\FileCache::class,
		// 'fs'=> \CitOmni\Infrastructure\Service\Filesystem\Filesystem::class,
	];


	public const CFG_HTTP = [

		/*
		 *------------------------------------------------------------------
		 * DATABASE CREDENTIALS
		 *------------------------------------------------------------------
		 */
		
		'db' => [
			'host'		=> 'localhost',
			'user'		=> 'root',
			'pass'		=> '',
			'name'		=> 'citomni',
			'charset'	=> 'utf8mb4',
		],


		/*
		 *------------------------------------------------------------------
		 * LOG
		 *------------------------------------------------------------------
		 */
		
		'log' => [
			'path'         => \CITOMNI_APP_PATH . '/var/logs',
			'default_file' => 'citomni_app.log',
			'max_bytes'    => 2_000_000,
			'max_files'    => 10, // or null for unlimited
		],


		/*
		 *------------------------------------------------------------------
		 * STATIC TEXT
		 *------------------------------------------------------------------
		 */
		 
		'txt' => [
			'log' => [
				'file' => 'litetxt_errors.jsonl',
				'path' => \CITOMNI_APP_PATH . '/var/logs',
			],
		],


		/*
		 *------------------------------------------------------------------
		 * E-MAIL SETTINGS
		 *------------------------------------------------------------------
		 */
		'mail' => [
			// Default sender for all system emails
			'from' => [
				'email' => '',
				'name'  => '',
			],

			// Default reply-to (optional)
			'reply_to' => [
				'email' => '',
				'name'  => '',
			],

			// Default content format
			'format'    => 'html',     // 'html' | 'text'  (maps to PHPMailer::isHTML(true/false))

			// Transport selection
			'transport' => 'smtp',     // 'smtp' | 'mail' | 'sendmail' | 'qmail'

			// Path for Sendmail/Qmail transports (optional; PHPMailer uses the same property)
			// 'sendmail_path' => '/usr/sbin/sendmail', // e.g. '/usr/sbin/sendmail' or '/var/qmail/bin/sendmail'

			// SMTP settings (used only when transport === 'smtp')
			'smtp' => [
				'host'       => '', // You can provide a semicolon-separated list: "smtp1;smtp2"
				'port'       => 587,  // Common: 25, 465 (SSL), 587 (STARTTLS)
				'encryption' => null,  // 'tls' | 'ssl' | null
				'auth'       => true,
				'username'   => '',
				'password'   => '',

				// Operational tuning
				'auto_tls'   => true,  // PHPMailer::SMTPAutoTLS
				'timeout'    => 15,  // Seconds for SMTP operations (PHPMailer::Timeout)
				'keepalive'  => false,  // Reuse SMTP connection across messages (batch jobs)

				// NOTE: Legacy debug keys (no longer used by Mailer):
				// Debugging (set level=0 in production)
				// 'debug' => [
					// 'level'  => 0,              // 0: No output (Off – recommended for production), 1: Commands: Client -> Server, 2: Data: Client <-> Server (shows commands and server responses), 3: As 2 plus connection status and more, 4: Low-level data output, all traffic (most verbose)
					// 'output' => 'error_log',         // 'echo' | 'html' | 'error_log'
				// ],
			],
			
			// Logging & debug policy
			'logging' => [
				'log_success' 	   => false, // Log successful sends to mail_log.jsonl? (Dev = true, Prod = false)			
				'debug_transcript' => false, // Enable detailed SMTP transcript capture for error logs? (true/false)				
				'max_lines' 	   => 200, // Cap number of transcript lines persisted (avoid runaway logs).				
				'include_bodies'   => false, // Include full mail bodies in error logs? (never in prod!) true = log entire Body/AltBody on error, false = only log length + sha256.
			],			
		],
		
		
		/*
		 *------------------------------------------------------------------
		 * SECURITY
		 *------------------------------------------------------------------
		 */
		
		'security' => [
			'csrf_protection'		=> true, // true | false; Prevent CSRF (Cross-Site Request Forgery) attacks.
			'csrf_field_name'		=> 'csrf_token',
			
			// Anti-bots
			'captcha_protection'	=> true, // true | false; The native captcha will help prevent bots from filling out forms.
			'honeypot_protection'	=> true, // true | false; Enables honeypot protection to prevent automated bot submissions.	
			'form_action_switching'	=> true, // true | false; Enables dynamic form action switching to prevent bot submissions.
		],



		/*
		 *------------------------------------------------------------------
		 * VIEW / CONTENT / TEMPLATE ENGINE 
		 *------------------------------------------------------------------
		 */
		
		'view' => [
			'template_layers' => [
				'citomni/infrastructure'   => \CITOMNI_APP_PATH . '/vendor/citomni/infrastructure/templates',
			],
		],

	];
	
	
	public const ROUTES_HTTP = [
	
		'/kontakt.html' => [
			'controller' => \CitOmni\Infrastructure\Controller\InfrastructureController::class,
			'action' => 'contact',
			'methods' => ['GET','POST'],
			'template_file' => 'public/contact.html',
			'template_layer' => 'citomni/infrastructure'
		],
		'/captcha' => [
			'controller' => \CitOmni\Infrastructure\Controller\InfrastructureController::class,
			'action' => 'captcha',
			'methods' => ['GET']
		],

	];
	
	

	// Same defaults for CLI
	public const MAP_CLI = self::MAP_HTTP;

	// Same defaults for CLI
	public const CFG_CLI = self::CFG_HTTP;

}
