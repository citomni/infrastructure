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
 * boot, the same for HTTP and CLI. Pages, routes and commands that use the services belong
 * to the app; merging these constants with the app's own maps belongs to the kernel.
 *
 * Rules:
 * - Declarative constants only.
 * - Services and cfg go in MAP_COMMON and CFG_COMMON. A mode-specific constant holds only
 *   values that depend on one runtime.
 * - Ship a baseline for every cfg path the package reads, with a comment on what each key
 *   controls and its unit.
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

		// Deprecated and to be removed; see FormatNumber.
		'formatNumber' => \CitOmni\Infrastructure\Service\FormatNumber::class,
	];


	/** Cfg baseline for HTTP and CLI. */
	public const array CFG_COMMON = [

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


		'log' => [
			'path'         => \CITOMNI_APP_PATH . '/var/logs', // Log directory; created when missing.
			'default_file' => 'citomni_app.log',               // File for write(null, ...); normalized to .jsonl.
			'max_bytes'    => 2_000_000,                       // Size in bytes at which a file is rotated; >= 1024.
			'max_files'    => 10,                              // Rotated files kept per log file; null for unlimited.
		],


		'curl' => [
			'timeout'           => 30,             // Total transfer timeout in seconds; 0 for none, fractions allowed.
			'connect_timeout'   => 10,             // Connect timeout in seconds; 0 for the libcurl default, fractions allowed.
			'follow_redirects'  => false,          // Follow Location headers.
			'max_redirects'     => 10,             // Maximum number of redirects to follow, >= 0; applies only when follow_redirects is true.
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
			'reuse_connections' => true,           // Reuse DNS lookups and connections between the requests of one service instance.
		],


		'mail' => [
			'from' => [
				'email' => '', // Default sender address; '' sets none.
				'name'  => '', // Display name of the default sender.
			],

			'reply_to' => [
				'email' => '', // Default Reply-To address; '' sets none.
				'name'  => '', // Display name of the default Reply-To address.
			],

			'format'        => 'html', // Default body format: 'html' for HTML, any other value for plain text.
			'transport'     => 'smtp', // 'smtp', 'sendmail' or 'qmail'; any other value sends with PHP's mail().
			'sendmail_path' => '',     // Binary for the sendmail and qmail transports; '' for /usr/sbin/sendmail or /var/qmail/bin/sendmail.

			'smtp' => [
				// The node holds no password; the secret mail.smtp.password does.
				'host'       => '',    // SMTP host, or several hosts separated by ';'.
				'port'       => 587,   // SMTP port.
				'encryption' => null,  // 'tls' for STARTTLS, 'ssl' for implicit TLS; any other value for none.
				'auth'       => true,  // Authenticate with username and the secret mail.smtp.password; must be a bool.
				'username'   => '',    // SMTP user name; used when auth is true.
				'auto_tls'   => true,  // Use STARTTLS when the server offers it, also with encryption none; must be a bool.
				'timeout'    => 15,    // Timeout of SMTP operations in seconds.
				'keepalive'  => false, // Keep the SMTP connection open between the messages of one service instance.
			],

			'logging' => [
				'log_success'      => false, // Log every sent message to mail_log.jsonl; takes effect only when CITOMNI_ENVIRONMENT is dev.
				'debug_transcript' => false, // Add the SMTP transcript to the error log of a failed send.
				'max_lines'        => 200,   // Maximum number of transcript lines kept per send.
				'include_bodies'   => false, // Log the full bodies of a failed send; false logs only their length and SHA-256.
			],
		],


		'security' => [

			// Brute force contexts by name. Every context holds the same keys; a caller names
			// its context in each call to the bruteForce service.
			'bruteforce' => [
				'default' => [
					'max_identifier_attempts' => 5,      // Failed attempts within the window at which an identifier is blocked; >= 1.
					'max_ip_attempts'         => 25,     // Failed attempts within the window at which an IP address is blocked; >= 1.
					'interval_minutes'        => 15,     // Length of the counting window in minutes; >= 1.
					'retry_after_seconds'     => 900,    // How long a block lasts, in seconds; >= 1.
					'prune_after_seconds'     => 604800, // Seconds without a new failure after which prune() deletes a counter; optional, 604800 when missing or < 1.
				],
			],

		],


		'locale' => [
			'format' => [
				'decimal_separator'              => ',',    // Decimal separator of numbers in forms and output; one ASCII character.
				'thousand_separator'             => '.',    // Thousand separator of numbers in forms and output; '' for none, otherwise one ASCII character other than decimal_separator.
				'group_thousands'                => true,   // Group thousands in formatted output; has no effect while thousand_separator is ''.
				'decimal_scale'                  => 2,      // Default number of decimals in formatted output; 0-18.
				'decimal_string_rounding'        => 'fail', // Nonzero digits of a stored decimal beyond the scale: 'fail' throws, 'truncate' drops them, 'half_up' rounds; case-sensitive.
				'decimal_trim_trailing_zeros'    => false,  // Drop trailing zeros of the decimals in formatted output.
				'time_include_seconds'           => false,  // Include seconds in values for input type="time".
				'datetime_local_include_seconds' => false,  // Include seconds in values for input type="datetime-local".
			],
		],


	];


	// Mode-specific contributions. Not used: every service and cfg key works in both modes.
	// public const array MAP_HTTP = [];
	// public const array MAP_CLI = [];
	// public const array CFG_HTTP = [];
	// public const array CFG_CLI = [];

}
