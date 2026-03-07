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

use CitOmni\Infrastructure\Exception\LogConfigException;
use CitOmni\Infrastructure\Exception\LogDirectoryException;
use CitOmni\Infrastructure\Exception\LogFileException;
use CitOmni\Infrastructure\Exception\LogRotationException;
use CitOmni\Infrastructure\Exception\LogWriteException;
use CitOmni\Kernel\Service\BaseService;


/**
 * Writes newline-delimited JSON log entries with deterministic rotation.
 *
 * This service is a native CitOmni replacement for the former LiteLog-backed
 * wrapper. The public API is intentionally preserved so downstream code can
 * continue using $this->app->log unchanged.
 *
 * Behavior:
 * - Resolves and validates supported log configuration values during init
 * - Writes exactly one JSON object per line using "\n" as the line separator
 *   1) timestamp
 *   2) category
 *   3) message
 *   4) context, only when non-empty
 * - Uses a sidecar lock file per active log file to serialize append, rotation, and pruning
 * - Rotates before append when the active file is already at or above the limit
 * - Rotates after append when the write pushes the file over the limit
 * - Pruning failures are non-fatal, but lock, open, write, and rotation failures are fatal
 *
 * Notes:
 * - Optional cfg node access uses CitOmni cfg semantics, including $this->app->cfg->log ?? null
 * - This service intentionally avoids direct dependency on the concrete Cfg class
 * - Supported child config values are validated explicitly during init
 * - Grossly invalid log node shape is allowed to fail fast during child access
 * - File names are flat only; subdirectories are forbidden in $file
 * - Logger-managed files are normalized to the ".jsonl" extension
 * - Hidden-file style and dot-only names are rejected
 * - Encoding fallback is intentionally bounded and only used when normal JSON encoding fails
 * - Fallback normalization avoids deep or cyclic object traversal and may replace complex values with deterministic placeholders
 *
 * Typical usage:
 *   $this->app->log->write(null, 'auth', 'User logged in', ['userId' => 42]);
 */
final class Log extends BaseService {

	private const DEFAULT_DIR = CITOMNI_APP_PATH . '/var/logs';
	private const DEFAULT_FILE = 'citomni_app.jsonl';
	private const DEFAULT_MAX_FILE_SIZE = 10485760;
	private const MIN_MAX_FILE_SIZE = 1024;
	private const MAX_NORMALIZE_DEPTH = 8;
	private const LINE_FEED = "\n";
	private const JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE;
	private const CONTROL_CHARS_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

	private string $dir = '';
	private string $defaultFile = self::DEFAULT_FILE;
	private int $maxFileSize = self::DEFAULT_MAX_FILE_SIZE;
	private ?int $maxRotatedFiles = null;


	/**
	 * Initializes validated scalar configuration for the logger.
	 *
	 * Behavior:
	 * - Loads cfg->log as an optional top-level node using CitOmni cfg semantics
	 * - Reads child values once into local variables when the node is present
	 * - Validates supported child values explicitly
	 * - Stores the derived scalar runtime state
	 * - Releases constructor options after initialization
	 *
	 * Notes:
	 * - The optional access pattern $this->app->cfg->log ?? null is intentional
	 *   and supported by CitOmni's cfg wrapper semantics
	 * - This service does not depend on the concrete Cfg class directly
	 * - Supported child values are validated explicitly
	 * - Grossly invalid log node shape is allowed to fail fast during child access
	 *   instead of being rewrapped into a logger-specific exception
	 *
	 * @return void
	 * @throws LogConfigException When supported logger configuration values are invalid.
	 * @throws LogDirectoryException When the configured log directory cannot be used.
	 */
	protected function init(): void {
		$logCfg = $this->app->cfg->log ?? null;

		$path = null;
		$defaultFile = null;
		$maxBytes = null;
		$maxFiles = null;

		if ($logCfg !== null) {
			$path = $logCfg->path ?? null;
			$defaultFile = $logCfg->default_file ?? null;
			$maxBytes = $logCfg->max_bytes ?? null;
			$maxFiles = $logCfg->max_files ?? null;
		}

		if ($path !== null && (!\is_string($path) || $path === '')) {
			throw new LogConfigException('Log configuration "path" must be a non-empty string when provided.');
		}

		if ($defaultFile !== null && (!\is_string($defaultFile) || $defaultFile === '')) {
			throw new LogConfigException('Log configuration "default_file" must be a non-empty string when provided.');
		}

		if ($maxBytes !== null && !\is_int($maxBytes)) {
			throw new LogConfigException('Log configuration "max_bytes" must be an integer.');
		}

		if ($maxFiles !== null && !\is_int($maxFiles)) {
			throw new LogConfigException('Log configuration "max_files" must be an integer or null.');
		}

		$this->setDir($path ?? self::DEFAULT_DIR, true);

		if ($defaultFile !== null) {
			$this->defaultFile = $this->normalizeFilename($defaultFile);
		}

		if ($maxBytes !== null) {
			$this->setMaxFileSize($maxBytes);
		}

		$this->setMaxRotatedFiles($maxFiles);

		$this->options = [];
	}


	/**
	 * Sets and validates the default log directory.
	 *
	 * @param string $dir Absolute or relative directory path.
	 * @param bool $autoCreate Whether the directory should be created automatically when missing.
	 * @return void
	 * @throws LogDirectoryException When the directory cannot be created, found, or written.
	 */
	public function setDir(string $dir, bool $autoCreate = false): void {
		$dir = $this->normalizeDirectory($dir);

		if (!\is_dir($dir)) {
			if (!$autoCreate) {
				throw new LogDirectoryException('Log directory does not exist: ' . $dir);
			}

			if (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
				throw new LogDirectoryException('Failed to create log directory: ' . $dir);
			}
		}

		if (!\is_writable($dir)) {
			throw new LogDirectoryException('Log directory is not writable: ' . $dir);
		}

		$this->dir = $dir;
	}


	/**
	 * Sets the maximum active log file size before rotation.
	 *
	 * @param int $bytes Maximum size in bytes, must be >= 1024.
	 * @return void
	 * @throws LogConfigException When the size is invalid.
	 */
	public function setMaxFileSize(int $bytes): void {
		if ($bytes < self::MIN_MAX_FILE_SIZE) {
			throw new LogConfigException('Log max file size must be >= 1024 bytes.');
		}

		$this->maxFileSize = $bytes;
	}


	/**
	 * Sets the maximum number of rotated files to keep.
	 *
	 * @param int|null $count Maximum rotated files to keep, or null for unlimited.
	 * @return void
	 * @throws LogConfigException When the count is invalid.
	 */
	public function setMaxRotatedFiles(?int $count): void {
		if ($count !== null && $count < 1) {
			throw new LogConfigException('Log max rotated files must be >= 1 or null.');
		}

		$this->maxRotatedFiles = $count;
	}


	/**
	 * Writes a single log entry to the configured log directory.
	 *
	 * Behavior:
	 * - Resolves null or empty $file to the configured default file
	 * - Normalizes valid flat names to ".jsonl"
	 * - Acquires an exclusive lock on a sidecar lock file
	 * - Rotates before append when the current file already exceeds the limit
	 * - Appends a single JSON line using "\n"
	 * - Rotates again after append when the write crossed the limit
	 * - Treats pruning failures as non-fatal housekeeping failures
	 *
	 * @param string|null $file Target log file name without subdirectories. Null or empty uses the default file.
	 * @param string $category Application-defined category.
	 * @param string|array|object $message Message payload to serialize into JSON.
	 * @param array $context Optional structured context data.
	 * @return void
	 * @throws LogFileException When file validation, lock opening, lock acquisition, or append opening fails.
	 * @throws LogWriteException When the entry cannot be written completely.
	 * @throws LogRotationException When rotation fails.
	 */
	public function write(?string $file, string $category, string|array|object $message, array $context = []): void {
		$file = ($file === null || $file === '') ? $this->defaultFile : $this->normalizeFilename($file);

		$filePath = $this->dir . $file;
		$lockPath = $filePath . '.lock';
		$lockHandle = @\fopen($lockPath, 'c+b');

		if ($lockHandle === false) {
			throw new LogFileException('Cannot open log lock file: ' . $lockPath);
		}

		try {
			if (!\flock($lockHandle, \LOCK_EX)) {
				throw new LogFileException('Cannot acquire log lock for: ' . $filePath);
			}

			\clearstatcache(true, $filePath);

			if (\is_file($filePath)) {
				$size = \filesize($filePath);
				if ($size !== false && $size >= $this->maxFileSize) {
					$this->rotateLocked($filePath);
					$this->pruneLocked($filePath);
				}
			}

			$entryLine = $this->encodeJsonLine($category, $message, $context);

			$fileHandle = @\fopen($filePath, 'ab');
			if ($fileHandle === false) {
				throw new LogFileException('Failed to open log file for append: ' . $filePath);
			}

			try {
				$expectedBytes = \strlen($entryLine);
				$writtenBytes = \fwrite($fileHandle, $entryLine);

				if ($writtenBytes === false || $writtenBytes !== $expectedBytes) {
					throw new LogWriteException('Short write to log file: ' . $filePath);
				}

				$stat = \fstat($fileHandle);
			} finally {
				\fclose($fileHandle);
			}

			if (\is_array($stat) && isset($stat['size']) && \is_int($stat['size']) && $stat['size'] >= $this->maxFileSize) {
				$this->rotateLocked($filePath);
				$this->pruneLocked($filePath);
			}
		} finally {
			\flock($lockHandle, \LOCK_UN);
			\fclose($lockHandle);
		}
	}


	/**
	 * Normalizes a directory path to a directory string with trailing separator.
	 *
	 * @param string $dir Directory path to normalize.
	 * @return string Normalized directory path with trailing separator.
	 * @throws LogDirectoryException When the path is empty.
	 */
	private function normalizeDirectory(string $dir): string {
		$dir = \rtrim($dir, "/\\ \t\n\r\0\x0B");
		if ($dir === '') {
			throw new LogDirectoryException('Log directory must not be empty.');
		}

		return $dir . \DIRECTORY_SEPARATOR;
	}


	/**
	 * Validates and normalizes a flat log filename to ".jsonl".
	 *
	 * Accepted policy:
	 * - ASCII letters, digits, ".", "_" and "-" only
	 * - No subdirectories
	 * - No hidden-file style names starting with "."
	 * - No dot-only or otherwise meaningless base names
	 * - Valid flat names are normalized to end with ".jsonl"
	 *
	 * Examples:
	 * - "app" => "app.jsonl"
	 * - "app.log" => "app.jsonl"
	 * - "audit.jsonl" => "audit.jsonl"
	 *
	 * Rejected examples:
	 * - ".env"
	 * - ".log"
	 * - "..."
	 * - "../app.log"
	 *
	 * @param string $file File name to normalize.
	 * @return string Normalized ".jsonl" file name.
	 * @throws LogFileException When the file name is invalid.
	 */
	private function normalizeFilename(string $file): string {
		if ($file === '') {
			throw new LogFileException('Log filename must not be empty.');
		}

		if (\str_contains($file, '/') || \str_contains($file, '\\')) {
			throw new LogFileException('Subdirectories are not allowed in log filenames.');
		}

		if ($file[0] === '.') {
			throw new LogFileException('Hidden-file style log filenames are not allowed: ' . $file);
		}

		$length = \strlen($file);
		for ($i = 0; $i < $length; $i++) {
			$ord = \ord($file[$i]);

			$isDigit = ($ord >= 48 && $ord <= 57);
			$isUpper = ($ord >= 65 && $ord <= 90);
			$isLower = ($ord >= 97 && $ord <= 122);
			$isSafePunctuation = ($ord === 46 || $ord === 95 || $ord === 45);

			if (!$isDigit && !$isUpper && !$isLower && !$isSafePunctuation) {
				throw new LogFileException('Invalid log filename: ' . $file);
			}
		}

		$dotPos = \strrpos($file, '.');
		$base = $dotPos === false ? $file : \substr($file, 0, $dotPos);

		if ($base === '' || \trim($base, '.') === '') {
			throw new LogFileException('Meaningless log filename is not allowed: ' . $file);
		}

		return $base . '.jsonl';
	}


	/**
	 * Encodes a single log entry as a JSON line.
	 *
	 * Behavior:
	 * - First attempts normal encoding with UTF-8 substitution support
	 * - Falls back to explicit bounded normalization only when necessary
	 * - Emits a deterministic fallback line if JSON still cannot be produced
	 *
	 * Notes:
	 * - The fallback path avoids deep or cyclic object traversal
	 *
	 * @param string $category Entry category.
	 * @param string|array|object $message Entry message payload.
	 * @param array $context Optional context payload.
	 * @return string JSON line ending with "\n".
	 */
	private function encodeJsonLine(string $category, string|array|object $message, array $context): string {
		$timestamp = \date(\DATE_ATOM);
		$entry = [
			'timestamp' => $timestamp,
			'category' => $category,
			'message' => $message,
		];

		if ($context !== []) {
			$entry['context'] = $context;
		}

		$json = \json_encode($entry, self::JSON_FLAGS);
		if ($json !== false) {
			return $json . self::LINE_FEED;
		}

		$normalized = $this->normalizeEncodableValue($entry);
		$json = \json_encode($normalized, self::JSON_FLAGS);
		if ($json !== false) {
			return $json . self::LINE_FEED;
		}

		return $this->buildFallbackLine($timestamp, $category, \json_last_error_msg());
	}


	/**
	 * Rotates the active log file while the caller holds the lock.
	 *
	 * @param string $filePath Active log file path.
	 * @return void
	 * @throws LogRotationException When rename fails.
	 */
	private function rotateLocked(string $filePath): void {
		if (!\is_file($filePath)) {
			return;
		}

		$info = \pathinfo($filePath);
		$dir = $info['dirname'] ?? '.';
		$filename = $info['filename'] ?? 'log';
		$pid = \getmypid() ?: 0;
		$timestamp = \date('Ymd_His');

		$counter = 0;
		do {
			$suffix = $counter === 0 ? '' : '_' . $counter;
			$rotatedPath = $dir . \DIRECTORY_SEPARATOR . $filename . '_' . $timestamp . '_' . $pid . $suffix . '.jsonl';
			$counter++;
		} while (\file_exists($rotatedPath));

		if (!@\rename($filePath, $rotatedPath)) {
			$error = \error_get_last();
			$message = $error['message'] ?? 'Unknown rename failure';
			throw new LogRotationException('Failed to rotate log file to "' . $rotatedPath . '": ' . $message);
		}
	}


	/**
	 * Prunes old rotated files while the caller holds the lock.
	 *
	 * Behavior:
	 * - Non-fatal by design
	 * - Only housekeeping failures are softened in this service
	 *
	 * @param string $filePath Active log file path used as the rotation family anchor.
	 * @return void
	 */
	private function pruneLocked(string $filePath): void {
		if ($this->maxRotatedFiles === null) {
			return;
		}

		$info = \pathinfo($filePath);
		$dir = $info['dirname'] ?? '.';
		$filename = $info['filename'] ?? 'log';

		$files = \glob($dir . \DIRECTORY_SEPARATOR . $filename . '_*.jsonl', \GLOB_NOSORT);
		if ($files === false) {
			return;
		}

		$count = \count($files);
		if ($count <= $this->maxRotatedFiles) {
			return;
		}

		\usort($files, static function(string $a, string $b): int {
			$timeA = \filemtime($a);
			$timeB = \filemtime($b);

			if ($timeA === $timeB) {
				return $a <=> $b;
			}

			if ($timeA === false) {
				return -1;
			}

			if ($timeB === false) {
				return 1;
			}

			return $timeA <=> $timeB;
		});

		$deleteCount = $count - $this->maxRotatedFiles;
		for ($i = 0; $i < $deleteCount; $i++) {
			@\unlink($files[$i]);
		}
	}


	/**
	 * Normalizes values into a form that is more likely to encode successfully.
	 *
	 * Behavior:
	 * - Strings are preserved when already valid UTF-8
	 * - Invalid strings are converted with a bounded fallback path
	 * - Arrays are normalized recursively up to a fixed maximum depth
	 * - Objects are reduced to a deterministic placeholder structure
	 *
	 * Notes:
	 * - This fallback is intentionally bounded to avoid deep or cyclic traversal
	 * - Object handling is intentionally lossy and does not traverse object graphs
	 * - This fallback exists for logging robustness, not for full object serialization fidelity
	 *
	 * @param mixed $value Value to normalize.
	 * @param int $depth Current normalization depth.
	 * @return mixed Normalized value.
	 */
	private function normalizeEncodableValue(mixed $value, int $depth = 0): mixed {
		if ($depth >= self::MAX_NORMALIZE_DEPTH) {
			return '[max-normalize-depth-exceeded]';
		}

		if (\is_string($value)) {
			return $this->normalizeString($value);
		}

		if (\is_array($value)) {
			$out = [];
			foreach ($value as $key => $item) {
				$normalizedKey = \is_string($key) ? $this->normalizeString($key) : $key;
				$out[$normalizedKey] = $this->normalizeEncodableValue($item, $depth + 1);
			}
			return $out;
		}

		if (\is_object($value)) {
			return [
				'__log_object' => \get_class($value),
			];
		}

		return $value;
	}


	/**
	 * Normalizes a string toward valid UTF-8 using a cheap bounded fallback path.
	 *
	 * @param string $value String to normalize.
	 * @return string UTF-8 safe string.
	 */
	private function normalizeString(string $value): string {
		if (\function_exists('mb_check_encoding') && \mb_check_encoding($value, 'UTF-8')) {
			return $value;
		}

		$converted = @\iconv('CP1252', 'UTF-8//IGNORE', $value);
		if (\is_string($converted) && $converted !== '') {
			if (!\function_exists('mb_check_encoding') || \mb_check_encoding($converted, 'UTF-8')) {
				return $converted;
			}
		}

		if (\function_exists('mb_convert_encoding')) {
			$converted = @\mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
			if (\is_string($converted) && $converted !== '') {
				if (!\function_exists('mb_check_encoding') || \mb_check_encoding($converted, 'UTF-8')) {
					return $converted;
				}
			}
		} else {
			$converted = @\iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);
			if (\is_string($converted) && $converted !== '') {
				if (!\function_exists('mb_check_encoding') || \mb_check_encoding($converted, 'UTF-8')) {
					return $converted;
				}
			}
		}

		return \preg_replace(self::CONTROL_CHARS_PATTERN, '', $value) ?? '';
	}


	/**
	 * Builds a deterministic fallback line when JSON encoding still fails.
	 *
	 * Behavior:
	 * - Preserves the original category when practical
	 * - Sanitizes the category conservatively for safe fallback output
	 * - Does not attempt to preserve the full original payload
	 *
	 * @param string $timestamp Precomputed entry timestamp.
	 * @param string $category Original entry category.
	 * @param string $error Json encoding error message.
	 * @return string Fallback JSON line.
	 */
	private function buildFallbackLine(string $timestamp, string $category, string $error): string {
		$safeCategory = $this->sanitizeFallbackCategory($category);

		$fallback = [
			'timestamp' => $timestamp,
			'category' => $safeCategory,
			'message' => 'Log fallback: JSON encode failed: ' . $error,
		];

		$json = \json_encode($fallback, self::JSON_FLAGS);
		if ($json !== false) {
			return $json . self::LINE_FEED;
		}

		return '{"timestamp":"' . \addslashes($timestamp) . '","category":"' . \addslashes($safeCategory) . '","message":"Log fallback: JSON encode failed"}' . self::LINE_FEED;
	}


	/**
	 * Sanitizes a category string for deterministic fallback output.
	 *
	 * @param string $category Original category.
	 * @return string Sanitized category.
	 */
	private function sanitizeFallbackCategory(string $category): string {
		$category = $this->normalizeString($category);
		$category = \preg_replace(self::CONTROL_CHARS_PATTERN, '', $category) ?? '';

		return $category !== '' ? $category : 'error';
	}
	

}
