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
 * Registry: Boot contributions of citomni/infrastructure.
 *
 * Declares the services and the cfg baseline that the package contributes to the app at
 * boot. HTTP and CLI get the same services and the same baseline. The package contributes
 * no routes, commands, controllers or templates; pages that use its services, such as a
 * contact form, belong to the app.
 *
 * Rules:
 * - Declarative constants only.
 * - Services and cfg go in MAP_COMMON and CFG_COMMON. A mode-specific constant holds only
 *   values that depend on one runtime.
 */
class Registry {

	/** Services for HTTP and CLI. */
	public const array MAP_COMMON = [

		'log'			=> \CitOmni\Infrastructure\Service\Log::class,
		'txt'			=> \CitOmni\Infrastructure\Service\Txt::class,
		'secrets'		=> \CitOmni\Infrastructure\Service\Secrets::class,
		'mailer'		=> \CitOmni\Infrastructure\Service\Mailer::class,
		'curl'			=> \CitOmni\Infrastructure\Service\Curl::class,
		'db'			=> \CitOmni\Infrastructure\Service\Db::class,
		'valueToSql'	=> \CitOmni\Infrastructure\Service\ValueToSql::class,
		'valueFromSql'	=> \CitOmni\Infrastructure\Service\ValueFromSql::class,
		'bruteForce'	=> \CitOmni\Infrastructure\Service\BruteForce::class,
		
		// FormatNumber has been deprecated and will be removed soon...
		'formatNumber' => \CitOmni\Infrastructure\Service\FormatNumber::class,
		
		// 'cache'=> \CitOmni\Infrastructure\Service\FileCache::class,
		// 'fs'=> \CitOmni\Infrastructure\Service\Filesystem\Filesystem::class,
		
		// Test service
		// 'winProbe' => \CitOmni\Infrastructure\Service\WinProbe::class,
	];


	/** Cfg baseline for HTTP and CLI. */
	public const array CFG_COMMON = [

		/*
		 *------------------------------------------------------------------
		 * DATABASE SETTINGS
		 *------------------------------------------------------------------
		 */		
		'db' => [
			'host'                  => 'localhost',   // Database server host name or IP address.
			'name'                  => 'citomni',     // Database (schema) name.
			'user'                  => 'citomni',     // Database user.
			// The node holds no password; password_secret names the secret that does.
			'charset'               => 'utf8mb4',     // Connection character set; must not be empty.
			'port'                  => 3306,          // TCP port, 1-65535.
			'socket'                => null,          // Unix socket path; null or '' for the mysqli default: the default socket for host localhost, TCP otherwise.
			'connect_timeout'       => 5,             // Connection timeout in seconds, >= 1.
			'password_secret'       => 'db.password', // Secrets key that holds the database password.
			'sql_mode'              => null,          // Session sql_mode; null or '' keeps the server default.
			'timezone'              => null,          // Session time_zone; null or '' sets the UTC offset of PHP's active time zone.
			'statement_cache_limit' => 128,           // Maximum cached prepared statements; 0 disables the cache.
		],


		/*
		 *------------------------------------------------------------------
		 * LOG
		 *------------------------------------------------------------------
		 */		
		'log' => [
			'path'         => \CITOMNI_APP_PATH . '/var/logs', // Log directory; created when missing.
			'default_file' => 'citomni_app.log',               // File for write(null, ...); normalized to .jsonl.
			'max_bytes'    => 2_000_000,                       // Size in bytes at which a file is rotated; >= 1024.
			'max_files'    => 10,                              // Rotated files kept per log file; null for unlimited.
		],


		/*
		 *------------------------------------------------------------------
		 * cURL
		 *------------------------------------------------------------------
		 */
		'curl' => [
			'timeout'           => 30,             // Total transfer timeout in seconds; 0 for none, fractions allowed.
			'connect_timeout'   => 10,             // Connect timeout in seconds; 0 for the libcurl default, fractions allowed.
			'follow_redirects'  => false,          // Follow Location headers.
			'max_redirects'     => 10, // Only applies when follow_redirects is true.
			'user_agent'        => 'CitOmni cURL', // User-Agent unless a request sets the header.
			'verify_peer'       => true,           // TLS peer verification: bool, 0, 1, "0" or "1".
			'verify_host'       => 2,              // TLS host name verification: 0, 2, "0", "2" or bool.
			'ca_info'           => null,           // CA bundle path; null for the system default.
			'proxy'             => null,           // Proxy URL; with null, libcurl's proxy environment variables still apply.
			'return_headers'    => true,           // Capture and parse response headers.
			'capture_info'      => true,           // Include curl_getinfo() in the response.
			'auto_referer'      => true,           // Set Referer on followed redirects.
			'log_errors'        => true,           // Log transport failures through the log service.
			'log_success'       => false,          // Log completed transfers through the log service.
			'log_file'          => 'curl.jsonl',   // Log file for both; null for the log service's default file.
			'reuse_connections' => true, // Share DNS cache + connection pool across calls within one request/process.
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

			// Binary for the sendmail and qmail transports; '' uses /usr/sbin/sendmail or /var/qmail/bin/sendmail.
			'sendmail_path' => '',

			// SMTP settings (used only when transport === 'smtp')
			'smtp' => [
				'host'       => '', // You can provide a semicolon-separated list: "smtp1;smtp2"
				'port'       => 587,  // Common: 25, 465 (SSL), 587 (STARTTLS)
				'encryption' => null,  // 'tls' | 'ssl' | null
				'auth'       => true,
				'username'   => '',
				// Password is read from $app->secrets key `mail.smtp.password`.

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

			// Brute force protection
			'bruteforce' => [
				'default' => [
					'max_identifier_attempts' => 5,
					'max_ip_attempts'         => 25,
					'interval_minutes'        => 15,
					'retry_after_seconds'     => 900,
					'prune_after_seconds'     => 604800, // optional, default: 7 days
				],
			],

		],


		/*
		 *------------------------------------------------------------------
		 * LOCALE
		 *------------------------------------------------------------------
		 */		 
		'locale' => [
			'format' => [

				// Required: Separators (strict)
				'decimal_separator' => ',', // Must be exactly 1 char.
				'thousand_separator' => '.', // '' or exactly 1 char (set '' to disable grouping separator). Must differ from decimal_separator!

				// Required: Integer/decimal grouping
				'group_thousands' => true, // Note: If group_thousands=true but thousand_separator='', grouping is effectively disabled (I know; slightly self-contradictory cfg, but that's what I chose to go with).

				// Required: Decimal policy (strict)
				'decimal_scale' => 2, // Must be between 0 and 18
				'decimal_string_rounding' => 'fail', // "fail" | "truncate" | "half_up". NOTE: Case-sensitive!
				'decimal_trim_trailing_zeros' => false,

				// Required: HTML <input type="time"> precision
				'time_include_seconds' => false,

				// Required: HTML <input type="datetime-local"> precision
				'datetime_local_include_seconds' => false,
			],
		],


	];


	// Mode-specific contributions, only when a value depends on one runtime:
	// public const array MAP_HTTP = [];
	// public const array MAP_CLI = [];
	// public const array CFG_HTTP = [];
	// public const array CFG_CLI = [];

}
