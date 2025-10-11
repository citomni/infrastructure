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
use LiteLog\LiteLog;

/**
 * Log: Lightweight logging facade for CitOmni using LiteLog.
 *
 * Responsibilities:
 * - Provide a minimal, fast, deterministic logging API available as $this->app->log.
 * - Adopt logging defaults from config (directory, default filename, rotation).
 * - Delegate actual JSONL writes and rotation to LiteLog with fail-fast semantics.
 *
 * Collaborators:
 * - Reads configuration from $this->app->cfg->log.
 * - Writes through LiteLog (single process-safe JSONL appends with rotation).
 *
 * Configuration keys:
 * - log.path (string) - Absolute directory for logs. Default: CITOMNI_APP_PATH.'/var/logs'.
 * - log.default_file (string) - Default filename. Default: 'citomni_app.log'.
 * - log.max_bytes (int) - Max bytes per file before rotation (>= 1024). Default: LiteLog default.
 * - log.max_files (int|null) - Max rotated files to keep; null keeps all. Default: null.
 *
 * Error handling:
 * - Fail-fast. Invalid paths, unwritable directories, or bad filenames raise \RuntimeException
 *   from LiteLog and bubble to the global error handler (no silent failures).
 *
 * Typical usage:
 * - Use from controllers/services to append structured JSONL entries for app, auth, sql, etc.
 *
 * Examples:
 *   
 *   // Core: Write to default file after automatic init
 *   $this->app->log->write(null, 'auth', 'User logged in', ['userId' => $id]);
 *
 *   // Scenario: Domain-specific file + extra context
 *   $this->app->log->write('orders.log', 'order.create', 'Created', ['orderId' => $oid, 'amount' => $amount]);
 *
 *   // Scenario: Tune rotation from config (or at runtime)
 *   $this->app->log->setMaxFileSize(2_000_000);
 *   $this->app->log->setMaxRotatedFiles(10);
 *
 * Failure:
 * - If log.path is missing/unwritable or filename is invalid, \RuntimeException is thrown by LiteLog.
 */
final class Log extends BaseService {

	/** Default log file name (e.g., 'citomni_app.log'). */
	private string $defaultFile = 'citomni_app.log';

	/**
	 * Adopt config, ensure directory exists, and apply rotation defaults.
	 *
	 * Behavior:
	 * - Reads log.path, log.default_file, log.max_bytes, log.max_files from $this->app->cfg->log.
	 * - Ensures the log directory exists (autoCreate=true) and is writable.
	 * - Applies max file size and rotated file retention to LiteLog.
	 *
	 * Notes:
	 * - Runs eagerly via BaseService constructor; keep work minimal (one-time I/O only).
	 * - Fail-fast by design: Invalid dirs or settings throw and surface to the app error handler.
	 *
	 * Failure:
	 * - Throws \RuntimeException if the directory cannot be created or is not writable.
	 *
	 * @return void
	 * @throws \RuntimeException Directory creation or permission validation failed.
	 */
	protected function init(): void {
		$dir = (string)($this->app->cfg->log->path ?? (CITOMNI_APP_PATH . '/var/logs'));

		// One-time I/O to set and (optionally) create the directory; fail-fast if impossible
		LiteLog::setDefaultDir($dir, true);

		$cfgDefault = $this->app->cfg->log->default_file ?? null;
		if (\is_string($cfgDefault) && $cfgDefault !== '') {
			$this->defaultFile = $cfgDefault;
		}

		if (\is_int($this->app->cfg->log->max_bytes ?? null)) {
			LiteLog::setMaxFileSize((int)$this->app->cfg->log->max_bytes);
		}
		if (isset($this->app->cfg->log->max_files)) {
			$mf = $this->app->cfg->log->max_files;
			LiteLog::setMaxRotatedFiles($mf === null ? null : (int)$mf);
		}
	}

	/**
	 * Set the default log directory (optionally creating it).
	 *
	 * Behavior:
	 * - Sets LiteLog's default directory for subsequent writes.
	 * - If $autoCreate is true, creates missing directories recursively.
	 *
	 * Notes:
	 * - Provide an absolute path; relative paths are error-prone under FPM/CLI.
	 * - Directory writability is validated; we do not defer failures.
	 *
	 * Typical usage:
	 *   Override the directory in boot code or a provider, if different from defaults.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   $this->app->log->setDir(CITOMNI_APP_PATH . '/var/logs', true);
	 *
	 *   // Edge: Existing directory without autoCreate
	 *   $this->app->log->setDir('/var/log/app'); // must already exist and be writable
	 *
	 * Failure:
	 * - Throws \RuntimeException if the directory does not exist (and $autoCreate=false) or is not writable.
	 *
	 * @param string $dir Absolute path to a writable directory.
	 * @param bool   $autoCreate Create directory if missing.
	 * @return void
	 * @throws \RuntimeException Directory is invalid or not writable.
	 */
	public function setDir(string $dir, bool $autoCreate = false): void {
		LiteLog::setDefaultDir($dir, $autoCreate);
	}

	/**
	 * Set the maximum size per log file before rotation.
	 *
	 * Behavior:
	 * - Applies a global threshold (in bytes) to LiteLog for future writes.
	 * - When a file reaches or exceeds the threshold, it is rotated on the next write.
	 *
	 * Notes:
	 * - Minimum allowed by LiteLog is 1024 bytes.
	 * - Rotation naming and atomicity are handled by LiteLog.
	 *
	 * Typical usage:
	 *   Tighten file sizes in shared hosting to keep disk usage predictable.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   $this->app->log->setMaxFileSize(2_000_000); // 2 MB
	 *
	 *   // Edge: Use a small but valid threshold
	 *   $this->app->log->setMaxFileSize(1024);
	 *
	 * Failure:
	 * - Throws \RuntimeException if $bytes < 1024.
	 *
	 * @param int $bytes Threshold in bytes (>= 1024).
	 * @return void
	 * @throws \RuntimeException Threshold too small.
	 */
	public function setMaxFileSize(int $bytes): void {
		LiteLog::setMaxFileSize($bytes);
	}

	/**
	 * Control how many rotated files to keep per base file.
	 *
	 * Behavior:
	 * - If $count is null, all rotated files are kept.
	 * - Otherwise, LiteLog prunes older rotations keeping the most recent $count.
	 *
	 * Notes:
	 * - Applies to files produced after this call; existing excess rotations are pruned on subsequent writes.
	 * - Pruning order is based on file modification time.
	 *
	 * Typical usage:
	 *   Bound retention on shared hosts to avoid unbounded disk growth.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   $this->app->log->setMaxRotatedFiles(10);
	 *
	 *   // Edge: Unlimited retention
	 *   $this->app->log->setMaxRotatedFiles(null);
	 *
	 * Failure:
	 * - Throws \RuntimeException if $count < 1 and not null.
	 *
	 * @param int|null $count Number of rotated files to keep or null for unlimited.
	 * @return void
	 * @throws \RuntimeException Invalid retention count.
	 */
	public function setMaxRotatedFiles(?int $count): void {
		LiteLog::setMaxRotatedFiles($count);
	}

	/**
	 * Append a structured JSONL entry to a log file.
	 *
	 * Behavior:
	 * - Uses $file if provided; otherwise falls back to the configured default file.
	 * - Serializes $message and $context to a single JSON line via LiteLog and appends it.
	 * - Triggers rotation and pruning when thresholds are reached.
	 *
	 * Notes:
	 * - Filenames must not include directory separators; subdirectories are rejected.
	 * - Accepted message types: string|array|object (keep it light for hot paths).
	 *
	 * Typical usage:
	 *   Record app events, domain actions, and timings without heavy formatting.
	 *
	 * Examples:
	 *
	 *   // Happy path
	 *   $this->app->log->write(null, 'auth', 'User logged in', ['userId' => $id]);
	 *
	 *   // Edge: Empty filename resolves to default
	 *   $this->app->log->write('', 'sql.query', 'products.selectById', ['duration_ms' => 17]);
	 *
	 * Failure:
	 * - Throws \RuntimeException if the directory is unset/invalid, the filename is invalid,
	 *   or exclusive locking/writing fails at the OS level.
	 *
	 * @param string|null         $file     Log filename or null/empty for the default file.
	 * @param string              $category Short category tag (e.g., 'auth', 'order', 'sql').
	 * @param string|array|object $message  Message payload.
	 * @param array               $context  Additional structured fields.
	 * @return void
	 * @throws \RuntimeException Underlying LiteLog failure (dir, filename, lock, or write).
	 */
	public function write(?string $file, string $category, string|array|object $message, array $context = []): void {
		
		// Treat null/empty as "use default file" for ergonomic call sites
		$file = ($file === null || $file === '') ? $this->defaultFile : $file;

		LiteLog::log($file, $category, $message, $context);
	}
}
