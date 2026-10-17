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
 * Log: Service that appends JSON lines to log files and rotates the files by size.
 *
 * Gives every caller one way to write structured log lines that several processes can append
 * to at once: one JSON object per line, written under a lock and rotated when a file reaches
 * its size limit. What is worth logging belongs to the callers; reading, shipping and
 * archiving the files belongs to the server.
 *
 * Rules:
 * - Write exactly one JSON object per line, terminated by "\n", also when the payload cannot
 *   be encoded as given.
 * - Hold the lock of a log file across every append, rotation and pruning of that file.
 * - Keep pruning best-effort: A failed deletion never fails a write.
 * - Keep every log file directly in the log directory, with the extension .jsonl.
 */
class Log extends BaseService {

	/** Smallest accepted rotation size in bytes. */
	private const MIN_MAX_FILE_SIZE = 1024;

	/** Deepest array level that the fallback encoding keeps; a deeper value becomes a placeholder. */
	private const MAX_NORMALIZE_DEPTH = 8;

	/** Terminator of every log line. */
	private const LINE_FEED = "\n";

	/** json_encode() flags: Unicode and slashes unescaped, invalid UTF-8 replaced by U+FFFD. */
	private const JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE;

	/** ASCII control characters except tab, line feed and carriage return. */
	private const CONTROL_CHARS_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

	/** UTF-8 for each byte 0x80-0xFF read as CP1252; U+FFFD where CP1252 leaves a byte undefined. */
	private const CP1252_TO_UTF8 = [
		"\x80" => "\u{20AC}", "\x81" => "\u{FFFD}", "\x82" => "\u{201A}", "\x83" => "\u{0192}",
		"\x84" => "\u{201E}", "\x85" => "\u{2026}", "\x86" => "\u{2020}", "\x87" => "\u{2021}",
		"\x88" => "\u{02C6}", "\x89" => "\u{2030}", "\x8A" => "\u{0160}", "\x8B" => "\u{2039}",
		"\x8C" => "\u{0152}", "\x8D" => "\u{FFFD}", "\x8E" => "\u{017D}", "\x8F" => "\u{FFFD}",
		"\x90" => "\u{FFFD}", "\x91" => "\u{2018}", "\x92" => "\u{2019}", "\x93" => "\u{201C}",
		"\x94" => "\u{201D}", "\x95" => "\u{2022}", "\x96" => "\u{2013}", "\x97" => "\u{2014}",
		"\x98" => "\u{02DC}", "\x99" => "\u{2122}", "\x9A" => "\u{0161}", "\x9B" => "\u{203A}",
		"\x9C" => "\u{0153}", "\x9D" => "\u{FFFD}", "\x9E" => "\u{017E}", "\x9F" => "\u{0178}",
		"\xA0" => "\u{00A0}", "\xA1" => "\u{00A1}", "\xA2" => "\u{00A2}", "\xA3" => "\u{00A3}",
		"\xA4" => "\u{00A4}", "\xA5" => "\u{00A5}", "\xA6" => "\u{00A6}", "\xA7" => "\u{00A7}",
		"\xA8" => "\u{00A8}", "\xA9" => "\u{00A9}", "\xAA" => "\u{00AA}", "\xAB" => "\u{00AB}",
		"\xAC" => "\u{00AC}", "\xAD" => "\u{00AD}", "\xAE" => "\u{00AE}", "\xAF" => "\u{00AF}",
		"\xB0" => "\u{00B0}", "\xB1" => "\u{00B1}", "\xB2" => "\u{00B2}", "\xB3" => "\u{00B3}",
		"\xB4" => "\u{00B4}", "\xB5" => "\u{00B5}", "\xB6" => "\u{00B6}", "\xB7" => "\u{00B7}",
		"\xB8" => "\u{00B8}", "\xB9" => "\u{00B9}", "\xBA" => "\u{00BA}", "\xBB" => "\u{00BB}",
		"\xBC" => "\u{00BC}", "\xBD" => "\u{00BD}", "\xBE" => "\u{00BE}", "\xBF" => "\u{00BF}",
		"\xC0" => "\u{00C0}", "\xC1" => "\u{00C1}", "\xC2" => "\u{00C2}", "\xC3" => "\u{00C3}",
		"\xC4" => "\u{00C4}", "\xC5" => "\u{00C5}", "\xC6" => "\u{00C6}", "\xC7" => "\u{00C7}",
		"\xC8" => "\u{00C8}", "\xC9" => "\u{00C9}", "\xCA" => "\u{00CA}", "\xCB" => "\u{00CB}",
		"\xCC" => "\u{00CC}", "\xCD" => "\u{00CD}", "\xCE" => "\u{00CE}", "\xCF" => "\u{00CF}",
		"\xD0" => "\u{00D0}", "\xD1" => "\u{00D1}", "\xD2" => "\u{00D2}", "\xD3" => "\u{00D3}",
		"\xD4" => "\u{00D4}", "\xD5" => "\u{00D5}", "\xD6" => "\u{00D6}", "\xD7" => "\u{00D7}",
		"\xD8" => "\u{00D8}", "\xD9" => "\u{00D9}", "\xDA" => "\u{00DA}", "\xDB" => "\u{00DB}",
		"\xDC" => "\u{00DC}", "\xDD" => "\u{00DD}", "\xDE" => "\u{00DE}", "\xDF" => "\u{00DF}",
		"\xE0" => "\u{00E0}", "\xE1" => "\u{00E1}", "\xE2" => "\u{00E2}", "\xE3" => "\u{00E3}",
		"\xE4" => "\u{00E4}", "\xE5" => "\u{00E5}", "\xE6" => "\u{00E6}", "\xE7" => "\u{00E7}",
		"\xE8" => "\u{00E8}", "\xE9" => "\u{00E9}", "\xEA" => "\u{00EA}", "\xEB" => "\u{00EB}",
		"\xEC" => "\u{00EC}", "\xED" => "\u{00ED}", "\xEE" => "\u{00EE}", "\xEF" => "\u{00EF}",
		"\xF0" => "\u{00F0}", "\xF1" => "\u{00F1}", "\xF2" => "\u{00F2}", "\xF3" => "\u{00F3}",
		"\xF4" => "\u{00F4}", "\xF5" => "\u{00F5}", "\xF6" => "\u{00F6}", "\xF7" => "\u{00F7}",
		"\xF8" => "\u{00F8}", "\xF9" => "\u{00F9}", "\xFA" => "\u{00FA}", "\xFB" => "\u{00FB}",
		"\xFC" => "\u{00FC}", "\xFD" => "\u{00FD}", "\xFE" => "\u{00FE}", "\xFF" => "\u{00FF}",
	];

	/** Log directory with a trailing separator. */
	private string $dir = '';

	/** File for write() without a file name, with the extension .jsonl. */
	private string $defaultFile;

	/** Size in bytes at which a log file is rotated. */
	private int $maxFileSize;

	/** Rotated files kept per log file, or null to keep all. */
	private ?int $maxRotatedFiles = null;


	/**
	 * Read and check the log cfg once.
	 *
	 * Behavior:
	 * - Creates the log directory, parents included, when it is missing.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\LogConfigException  When cfg log.path or log.default_file is not a non-empty string, log.max_bytes is not an integer >= 1024, or log.max_files is neither null nor an integer >= 1.
	 * @throws \CitOmni\Infrastructure\Exception\LogDirectoryException  When the directory cannot be created or is not writable.
	 * @throws \CitOmni\Infrastructure\Exception\LogFileException  When cfg log.default_file is not a valid log file name.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$logCfg = $this->app->cfg->log;

		$path = $logCfg->path;
		$defaultFile = $logCfg->default_file;
		$maxBytes = $logCfg->max_bytes;
		$maxFiles = $logCfg->max_files;

		if (!\is_string($path) || $path === '') {
			throw new LogConfigException('Log configuration "path" must be a non-empty string.');
		}

		if (!\is_string($defaultFile) || $defaultFile === '') {
			throw new LogConfigException('Log configuration "default_file" must be a non-empty string.');
		}

		if (!\is_int($maxBytes)) {
			throw new LogConfigException('Log configuration "max_bytes" must be an integer.');
		}

		if ($maxFiles !== null && !\is_int($maxFiles)) {
			throw new LogConfigException('Log configuration "max_files" must be an integer or null.');
		}

		$this->setDir($path, true);
		$this->defaultFile = $this->normalizeFilename($defaultFile);
		$this->setMaxFileSize($maxBytes);
		$this->setMaxRotatedFiles($maxFiles);

		$this->options = [];
	}


	/**
	 * Set the log directory for all later writes of this instance.
	 *
	 * Behavior:
	 * - With $autoCreate, a missing directory is created with mode 0755, parents included.
	 *
	 * Notes:
	 * - The service is shared within a request or process, so the change applies to every
	 *   caller.
	 *
	 * @param  string  $dir  Directory path; trailing slashes, backslashes and whitespace are ignored.
	 * @param  bool  $autoCreate  Create the directory when it is missing.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\LogDirectoryException  When the path is empty, or the directory is missing and not created, or it is not writable.
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
	 * Set the size at which a log file is rotated, for all later writes of this instance.
	 *
	 * @param  int  $bytes  Size in bytes, >= 1024.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\LogConfigException  When $bytes is below 1024.
	 */
	public function setMaxFileSize(int $bytes): void {
		if ($bytes < self::MIN_MAX_FILE_SIZE) {
			throw new LogConfigException('Log max file size must be >= 1024 bytes.');
		}

		$this->maxFileSize = $bytes;
	}


	/**
	 * Set how many rotated files are kept per log file, for all later writes of this instance.
	 *
	 * @param  int|null  $count  Number of files, >= 1, or null to keep all.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\LogConfigException  When $count is below 1.
	 */
	public function setMaxRotatedFiles(?int $count): void {
		if ($count !== null && $count < 1) {
			throw new LogConfigException('Log max rotated files must be >= 1 or null.');
		}

		$this->maxRotatedFiles = $count;
	}


	/**
	 * Append one entry to a log file as a JSON line.
	 *
	 * Behavior:
	 * - The line is a JSON object with timestamp (local time, DATE_ATOM), category, message
	 *   and, when not empty, context.
	 * - A payload that json_encode() rejects is encoded once more with strings converted to
	 *   UTF-8, arrays cut at MAX_NORMALIZE_DEPTH and objects replaced by their class name. If
	 *   that fails too, the line holds timestamp, category and the JSON error only.
	 * - $file null or '' writes to the default file; any other name gets the extension .jsonl.
	 * - A file already at or above the size limit is rotated before the append, and a file that
	 *   reaches the limit with the append is rotated after it. The rotated file is renamed to
	 *   NAME_YYYYMMDD_HHMMSS_PID.jsonl in UTC, with _N added for further rotations within the
	 *   same second. Each rotation then deletes the oldest rotated files beyond the kept number.
	 * - Appends, rotations and deletions of one file are serialized across processes by an
	 *   exclusive lock on a .lock file next to it.
	 *
	 * Typical usage:
	 *   $this->app->log->write('auth.jsonl', 'auth.login', 'User logged in', ['user_id' => 42]);
	 *
	 * @param  string|null  $file  File name without directory: ASCII letters, digits, ., _ and -, not starting with a dot; null or '' for the default file.
	 * @param  string  $category  Category chosen by the caller, such as auth.login.
	 * @param  string|array<array-key,mixed>|object  $message  Message payload.
	 * @param  array<array-key,mixed>  $context  Extra structured data; left out of the line when empty.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\LogFileException  When the file name is invalid, or the lock file or the log file cannot be opened or locked.
	 * @throws \CitOmni\Infrastructure\Exception\LogWriteException  When the line is not written completely.
	 * @throws \CitOmni\Infrastructure\Exception\LogRotationException  When a full file cannot be renamed.
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
	 * Return a directory path with exactly one trailing separator.
	 *
	 * @param  string  $dir  Directory path; trailing slashes, backslashes and whitespace are removed.
	 * @return string
	 * @throws \CitOmni\Infrastructure\Exception\LogDirectoryException  When nothing remains.
	 */
	private function normalizeDirectory(string $dir): string {
		$dir = \rtrim($dir, "/\\ \t\n\r\0\x0B");
		if ($dir === '') {
			throw new LogDirectoryException('Log directory must not be empty.');
		}

		return $dir . \DIRECTORY_SEPARATOR;
	}


	/**
	 * Check a log file name and return it with the extension .jsonl.
	 *
	 * Behavior:
	 * - Accepts ASCII letters, digits, ., _ and - only, without a leading dot, so .env and
	 *   ../app.log are rejected.
	 * - Replaces the last extension with .jsonl, or adds it: app and app.log become app.jsonl,
	 *   and audit.jsonl stays.
	 * - Rejects a name whose part before the last dot is empty or only dots.
	 *
	 * @param  string  $file  File name without directory.
	 * @return string
	 * @throws \CitOmni\Infrastructure\Exception\LogFileException  When the name is not accepted.
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
	 * Encode one entry as a JSON line.
	 *
	 * Behavior:
	 * - Encodes the entry as given when json_encode() accepts it.
	 * - Otherwise encodes it once more after normalizeEncodableValue(), and as a last resort
	 *   returns the line of buildFallbackLine().
	 *
	 * @param  string  $category  Category of the entry.
	 * @param  string|array<array-key,mixed>|object  $message  Message payload.
	 * @param  array<array-key,mixed>  $context  Extra data; left out when empty.
	 * @return string  JSON line ending in "\n".
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
	 * Rotate an active log file; the caller holds its lock.
	 *
	 * Behavior:
	 * - Renames the file to NAME_YYYYMMDD_HHMMSS_PID.jsonl, with the current time in UTC.
	 * - A later rotation within the same second gets _N appended, numbered past every rotated
	 *   file of that second from any process, so a name freed by pruning is never reused and
	 *   the numbers follow the order of the rotations.
	 * - Does nothing when the file does not exist.
	 *
	 * Notes:
	 * - The name uses UTC (gmdate()), so processes with different time zones number and order
	 *   their rotations alike.
	 * - The directory is listed with scandir(), so its path is used literally.
	 *
	 * @param  string  $filePath  Path of the active log file.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\LogRotationException  When the rename fails.
	 */
	private function rotateLocked(string $filePath): void {
		if (!\is_file($filePath)) {
			return;
		}

		$info = \pathinfo($filePath);
		$dir = $info['dirname'] ?? '.';
		$filename = $info['filename'] ?? 'log';
		$pid = \getmypid() ?: 0;
		$timestamp = \gmdate('Ymd_His');
		$prefix = $dir . \DIRECTORY_SEPARATOR . $filename . '_' . $timestamp . '_';

		$sequence = 0;
		foreach (@\scandir($dir, \SCANDIR_SORT_NONE) ?: [] as $name) {
			$key = $this->rotatedFileKey($name, $filename);
			if ($key !== null && $key[0] === $timestamp && $key[1] >= $sequence) {
				$sequence = $key[1] + 1;
			}
		}

		do {
			$rotatedPath = $prefix . $pid . ($sequence === 0 ? '' : '_' . $sequence) . '.jsonl';
			$sequence++;
		} while (\file_exists($rotatedPath));

		if (!@\rename($filePath, $rotatedPath)) {
			$error = \error_get_last();
			$message = $error['message'] ?? 'Unknown rename failure';
			throw new LogRotationException('Failed to rotate log file to "' . $rotatedPath . '": ' . $message);
		}
	}


	/**
	 * Delete the oldest rotated files of a log file beyond the kept number; the caller holds its lock.
	 *
	 * Behavior:
	 * - Considers only files named like rotations of this log file,
	 *   NAME_YYYYMMDD_HHMMSS_PID.jsonl with an optional _N before the extension. Another log
	 *   whose name starts with NAME_ is left alone unless its own name has that form.
	 * - Deletes the oldest ones until the kept number remains, ordered by:
	 *     1) modification time
	 *     2) the timestamp in the name
	 *     3) the sequence number in the name, compared as a number
	 * - Never fails: A directory that cannot be listed or a file that cannot be deleted is
	 *   skipped.
	 *
	 * Notes:
	 * - Modification times have a resolution of one second, so the name only orders the
	 *   rotations within one second. Names in another time zone, such as local-time names,
	 *   therefore cannot reorder files from different seconds.
	 * - The directory is listed with scandir() and filtered by name, so its path is used
	 *   literally; glob() would read [, * and ? in the path as a pattern.
	 *
	 * @param  string  $filePath  Path of the active log file whose rotations are pruned.
	 * @return void
	 */
	private function pruneLocked(string $filePath): void {
		if ($this->maxRotatedFiles === null) {
			return;
		}

		$info = \pathinfo($filePath);
		$dir = $info['dirname'] ?? '.';
		$filename = $info['filename'] ?? 'log';

		$names = @\scandir($dir, \SCANDIR_SORT_NONE);
		if ($names === false) {
			return;
		}

		$keys = [];
		foreach ($names as $name) {
			$key = $this->rotatedFileKey($name, $filename);
			if ($key !== null) {
				$keys[$dir . \DIRECTORY_SEPARATOR . $name] = $key;
			}
		}

		$deleteCount = \count($keys) - $this->maxRotatedFiles;
		if ($deleteCount <= 0) {
			return;
		}

		$files = [];
		foreach ($keys as $path => [$timestamp, $sequence]) {
			// A file that vanished in the meantime sorts first; unlink() then does nothing.
			$files[] = [@\filemtime($path) ?: 0, $timestamp, $sequence, $path];
		}

		// Arrays compare element by element: modification time, timestamp, sequence, path.
		\sort($files);

		for ($i = 0; $i < $deleteCount; $i++) {
			@\unlink($files[$i][3]);
		}
	}


	/**
	 * Parse the name of a rotated file.
	 *
	 * @param  string  $name  File name, such as app_20260101_120000_4242_3.jsonl.
	 * @param  string  $filename  Log file name without extension, such as app.
	 * @return array{0:string,1:int}|null  Timestamp YYYYMMDD_HHMMSS and sequence number; null when $name is not a rotated file of $filename.
	 */
	private function rotatedFileKey(string $name, string $filename): ?array {
		// Cheap prefix test first; the directory listing holds every file in the directory.
		if (!\str_starts_with($name, $filename . '_')) {
			return null;
		}

		if (\preg_match('/^' . \preg_quote($filename, '/') . '_(\d{8}_\d{6})_\d+(?:_(\d+))?\.jsonl$/D', $name, $match) !== 1) {
			return null;
		}

		return [$match[1], (int)($match[2] ?? 0)];
	}


	/**
	 * Make a value more likely to encode as JSON, giving up detail where needed.
	 *
	 * Behavior:
	 * - Strings, string keys included, are converted to UTF-8 by normalizeString().
	 * - Arrays are walked to MAX_NORMALIZE_DEPTH levels; a deeper value becomes the string
	 *   [max-normalize-depth-exceeded].
	 * - An object becomes ['__log_object' => its class name]; its properties are not walked,
	 *   so a cycle cannot recurse.
	 * - Other values pass unchanged.
	 *
	 * @param  mixed  $value  Value to normalize.
	 * @param  int  $depth  Current depth; 0 at the top.
	 * @return mixed
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
	 * Return a string as UTF-8, decoding it from CP1252 when it is not valid UTF-8.
	 *
	 * Behavior:
	 * - Valid UTF-8 passes unchanged.
	 * - Any other string is decoded byte by byte as CP1252. The five bytes CP1252 leaves
	 *   undefined, 0x81, 0x8D, 0x8F, 0x90 and 0x9D, become U+FFFD.
	 *
	 * Notes:
	 * - The result is the same on every PHP build, with or without mbstring and iconv.
	 *
	 * @param  string  $value  String to convert.
	 * @return string  Valid UTF-8.
	 */
	private function normalizeString(string $value): string {
		// An empty pattern with the u modifier matches exactly when the subject is valid UTF-8.
		if (\preg_match('//u', $value) === 1) {
			return $value;
		}

		// A table instead of iconv or mbstring: both are optional extensions, and they do not
		// agree on the undefined bytes.
		return \strtr($value, self::CP1252_TO_UTF8);
	}


	/**
	 * Build the line for an entry that cannot be encoded at all.
	 *
	 * Behavior:
	 * - Holds the timestamp, the cleaned category and a message with the JSON error; message
	 *   and context of the entry are dropped.
	 *
	 * @param  string  $timestamp  Timestamp of the entry.
	 * @param  string  $category  Category of the entry.
	 * @param  string  $error  Message of the last json_encode() error.
	 * @return string  JSON line ending in "\n".
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
	 * Clean a category for the fallback line.
	 *
	 * @param  string  $category  Category of the entry.
	 * @return string  Converted by normalizeString() and without control characters; 'error' when nothing remains.
	 */
	private function sanitizeFallbackCategory(string $category): string {
		$category = $this->normalizeString($category);
		$category = \preg_replace(self::CONTROL_CHARS_PATTERN, '', $category) ?? '';

		return $category !== '' ? $category : 'error';
	}
	

}
