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
use LiteTxt\LiteTxt;

/**
 * Txt: Lightweight text/translation loader for app and vendor layers.
 *
 * Responsibilities:
 * - Resolve language strings from PHP array files under /language/{lang}/{file}.php.
 * - Interpolate %UPPERCASE% placeholders from a provided map.
 * - Select source layer: (1) app (CITOMNI_APP_PATH/language) or (2) vendor/package
 *   (CITOMNI_APP_PATH/vendor/{vendor/package}/language).
 *
 * Collaborators:
 * - Reads configuration from App->cfg (locale.language, txt.log.*).
 * - Delegates text file loading and miss-logging to LiteTxt.
 *
 * Configuration keys:
 * - locale.language (string) - Current locale in "xx" or "xx_YY" form; required.
 * - txt.log.file (string) - Log filename for LiteTxt warnings; required.
 * - txt.log.path (string) - Absolute or app-relative directory path; required.
 *
 * Error handling:
 * - Fail-fast: Invalid or missing configuration throws \InvalidArgumentException.
 * - Fail-soft (by design): Missing translation keys return the provided default;
 *   LiteTxt logs a JSON line to txt.log.path/txt.log.file.
 *
 * Typical usage:
 * Resolve UI copy and system messages with optional placeholder substitution for both app and package layers.
 *
 * Examples:
 *
 *   // Core example: App layer (en), simple lookup (no placeholders)
 *   // File: CITOMNI_APP_PATH/language/en/common.php -> ['hello' => 'Hello']
 *   $msg = $this->app->txt->get('hello', 'common', 'app', 'Hi');
 *   // "Hello"
 *
 *   // Scenario: Missing key -> default returned, JSON line logged
 *   // File: CITOMNI_APP_PATH/language/en/common.php  // No 'missing_key' defined
 *   $msg = $this->app->txt->get('missing_key', 'common', 'app', '[N/A]');
 *   // "[N/A]"  (LiteTxt logs to CITOMNI_APP_PATH/var/logs/{txt.log.file})
 *
 *   // Scenario: App layer subdirectory + placeholder
 *   // File: CITOMNI_APP_PATH/language/da/member/profile.php -> ['headline' => 'Hej %NAME%!']
 *   $msg = $this->app->txt->get('headline', 'member/profile', 'app', 'Hej!', ['name' => 'Sarah']);
 *   // "Hej Sarah!"
 *
 *   // Scenario: Vendor layer (en) with placeholder
 *   // File: CITOMNI_APP_PATH/vendor/citomni/auth/language/en/login.php -> ['error_locked' => 'Account for %EMAIL% is locked.']
 *   $msg = $this->app->txt->get('error_locked', 'login', 'citomni/auth', 'Locked.', ['email' => 'jane@example.com']);
 *   // "Account for jane@example.com is locked."
 *
 *   // Scenario: Regioned locale (en_US)
 *   // File: CITOMNI_APP_PATH/language/en_US/common.php -> ['ok' => 'Fine.']
 *   $msg = $this->app->txt->get('ok', 'common', 'app', 'Default');
 *   // "Fine."
 *
 *   // Scenario: Invalid inputs -> throws \InvalidArgumentException
 *   // Layer must be 'app' or 'vendor/package'; file must be safe (no '..')
 *   $this->app->txt->get('k', 'bad/../path', 'app', 'x');         // throws
 *   $this->app->txt->get('k', 'f', 'citomni', 'x');               // throws
 *
 * Failure:
 * - Missing or malformed cfg (locale.language, txt.log.file, txt.log.path) => \InvalidArgumentException.
 * - Missing translation key => returns $default; LiteTxt emits a JSON line to the configured log file.
 */
final class Txt extends BaseService {

	/**
	 * Return a translated/templated string from a language file.
	 *
	 * Behavior:
	 * - Resolve base directory from $layer ("app" or "vendor/package").
	 * - Validate and read locale.language; enforce "xx" or "xx_YY".
	 * - Validate txt.log.file and txt.log.path and build full log path.
	 * - Build {base}/{lang}/{file}.php and resolve $key via LiteTxt.
	 * - Interpolate %UPPERCASE% placeholders from $vars (values string-cast).
	 *
	 * Notes:
	 * - No filesystem probing here (is_dir, exists); keep hot path cheap.
	 * - Filename validation forbids traversal and reserves only safe chars.
	 *
	 * Failure:
	 * - Invalid $layer or $file => \InvalidArgumentException.
	 * - Missing/invalid cfg keys => \InvalidArgumentException.
	 *
	 * @param string $key Array key to fetch from the language file.
	 * @param string $file File path (no ".php"), e.g. "login" or "member/profile".
	 * @param string $layer "app" or "vendor/package" (e.g., "citomni/auth").
	 * @param string $default Fallback text if key is missing.
	 * @param array<string,string|int|float|bool|\Stringable> $vars Placeholder map; values string-cast.
	 * @return string Resolved text or $default if not found.
	 * @throws \InvalidArgumentException On invalid/missing cfg or unsafe inputs.
	 */
	public function get(string $key, string $file, string $layer = 'app', string $default = '', array $vars = []): string {
		
		$basePath = $this->resolveBasePath($layer);
		$lang     = $this->requireValidLanguage();
		[$logDir, $logFile] = $this->requireLogCfg();

		// Validate file segments: Safe chars and forward slashes only; no traversal.
		if (
			$file === '' ||
			\str_contains($file, '..') ||
			!\preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9/_-]*[A-Za-z0-9])?$~', $file)
		) {
			throw new \InvalidArgumentException("Invalid language file name '{$file}'.");
		}

		// Build absolute source and log paths (no IO checks by policy; cheap string work only).
		$filePath = $basePath . '/' . $lang . '/' . $file . '.php';
		$logPath  = \rtrim($logDir, "/\\") . '/' . $logFile;

		// Delegate the lookup and miss-logging to LiteTxt (keeps this class lean).
		$txt = LiteTxt::get($filePath, $key, $default, $logPath);

		// Placeholder interpolation via a single strtr for O(n) replacement.
		if ($vars) {
			$map = [];
			foreach ($vars as $k => $v) {
				$map['%' . \strtoupper((string)$k) . '%'] = (string)$v;
			}
			if ($map) {
				$txt = \strtr($txt, $map);
			}
		}

		return $txt;
	}


	/**
	 * Resolve base language directory for the given layer.
	 *
	 * Notes:
	 * - Slugs are validated as "vendor/package" with safe characters.
	 *
	 * Typical usage:
	 *   Called by get() prior to building the absolute {base}/{lang}/{file}.php.
	 *
	 * Examples:
	 *
	 *   // App layer
	 *   // resolveBasePath('app') => /.../app/language
	 *
	 *   // Vendor layer
	 *   // resolveBasePath('citomni/auth') => /.../app/vendor/citomni/auth/language
	 *
	 * Failure:
	 * - Invalid slug => \InvalidArgumentException.
	 *
	 * @param string $layer "app" or "vendor/package".
	 * @return string Absolute base path.
	 * @throws \InvalidArgumentException If layer is malformed.
	 */
	private function resolveBasePath(string $layer): string {
		
		if ($layer === 'app') {
			return \CITOMNI_APP_PATH . '/language';
		}
		$slug = \trim($layer, '/');

		// Security boundary: Accept only simple "vendor/package" slugs (no traversal or extra separators).
		if (!\preg_match('~^[a-z0-9._-]+/[a-z0-9._-]+$~i', $slug)) {
			throw new \InvalidArgumentException(
				"Invalid text layer '{$layer}'. Expected 'vendor/package', e.g. 'citomni/auth'."
			);
		}
		return \CITOMNI_APP_PATH . '/vendor/' . $slug . '/language';
	}


	/**
	 * Validate and return the configured locale language.
	 *
	 * Behavior:
	 * - Read $this->app->cfg->locale->language.
	 * - Enforce pattern "xx" or "xx_YY" (lowercase lang, optional uppercase region: i.e. 'da' or 'da_DK').
	 * - Return the validated language string.
	 *
	 * Notes:
	 * - Regex keeps the check ASCII-cheap and avoids locale conversions.
	 *
	 * Typical usage:
	 *   Called by get() before constructing the language file path.
	 *
	 * Examples:
	 *
	 *   // Accept
	 *   // 'da' -> 'da', 'en_US' -> 'en_US'
	 *
	 *   // Reject
	 *   // 'en-us' or 'english' => throws \InvalidArgumentException
	 *
	 * Failure:
	 * - Missing or invalid locale.language => \InvalidArgumentException.
	 *
	 * @return string Validated language code.
	 * @throws \InvalidArgumentException If locale.language is missing or malformed.
	 */
	private function requireValidLanguage(): string {
		
		$cfg = $this->app->cfg;
		
		if (!isset($cfg->locale) || !isset($cfg->locale->language)) {
			throw new \InvalidArgumentException('Missing required config: locale.language');
		}
		$lang = (string)$cfg->locale->language;

		// Policy: "xx" or "xx_YY" (keeps matrix small and explicit).
		if (!\preg_match('~^[a-z]{2}(?:_[A-Z]{2})?$~', $lang)) {
			throw new \InvalidArgumentException(
				"Invalid locale.language '{$lang}'. Expected 'xx' or 'xx_YY' (e.g. 'da' or 'da_DK')."
			);
		}
		
		return $lang;
	}


	/**
	 * Validate and return [logDir, logFile] from configuration.
	 *
	 * Behavior:
	 * - Require presence of txt.log.file and txt.log.path.
	 * - Ensure txt.log.file is a plain filename (basename-only).
	 * - Return [path, file] for caller to assemble a full path.
	 *
	 * Notes:
	 * - No directory existence checks here; IO is left to the writer (LiteTxt).
	 * - Cheap validation prevents accidental traversal or surprising separators.
	 *
	 * Typical usage:
	 *   Called by get() to supply LiteTxt with a destination for JSON lines.
	 *
	 * Examples:
	 *
	 *   // Accept
	 *   // ['path' => '/var/app/var/logs', 'file' => 'litetxt_errors.jsonl']
	 *
	 *   // Reject
	 *   // file => '../oops.jsonl' or 'logs/oops.jsonl' => throws \InvalidArgumentException
	 *
	 * Failure:
	 * - Missing txt.log.* or invalid filename/path => \InvalidArgumentException.
	 *
	 * @return array{0:string,1:string} [logDir, logFile].
	 * @throws \InvalidArgumentException If required keys are missing or invalid.
	 */
	private function requireLogCfg(): array {
		
		$cfg = $this->app->cfg;

		if (!isset($cfg->txt) || !isset($cfg->txt->log)) {
			throw new \InvalidArgumentException('Missing required config: txt.log');
		}
		if (!isset($cfg->txt->log->file)) {
			throw new \InvalidArgumentException('Missing required config: txt.log.file');
		}
		if (!isset($cfg->txt->log->path)) {
			throw new \InvalidArgumentException('Missing required config: txt.log.path');
		}

		$logFile = (string)$cfg->txt->log->file;
		$logDir  = (string)$cfg->txt->log->path;

		// Security boundary: Only accept basename as filename to avoid sneaky directories.
		if ($logFile === '' || $logFile !== \basename($logFile)) {
			throw new \InvalidArgumentException("Invalid txt.log.file '{$logFile}'. Filename only, no directories.");
		}
		if ($logDir === '') {
			throw new \InvalidArgumentException('Invalid txt.log.path (empty).');
		}

		return [$logDir, $logFile];
	}
	
}
