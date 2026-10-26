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

use CitOmni\Infrastructure\Exception\SluggerConfigException;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Service\BaseService;

/**
 * Slugger: Service that makes, checks and deduplicates URL slugs.
 *
 * Gives every caller in a request or process the same slug policy, chosen once from the
 * service options and cfg. It decides what a slug looks like; whether a slug is already taken
 * belongs to the caller, which answers through a callback.
 *
 * Rules:
 * - Derive the policy only in init(); public methods never change it.
 * - Reach no storage: Ask the caller's callback whenever a fact about stored slugs is needed.
 * - Give the same result for the same input and policy on every server, whatever the locale,
 *   the platform and the installed extensions.
 * - Measure slug lengths in bytes, and never cut inside a UTF-8 character.
 *
 * Typical usage:
 *   $base = $this->app->slugger->slugify($title);
 *   $slug = $this->app->slugger->ensureUnique(fn(string $s): bool => $repository->slugExists($s), $base);
 */
class Slugger extends BaseService {

	/** ASCII replacements of letters, keyed by the letters' UTF-8 bytes. */
	private const array TRANSLITERATION = [
		// Latin-1 Supplement. A capital letter that becomes two letters becomes a capital and a
		// lowercase letter, here and below.
		"\u{C0}" => 'A', "\u{C1}" => 'A', "\u{C2}" => 'A', "\u{C3}" => 'A', "\u{C4}" => 'Ae', "\u{C5}" => 'Aa', "\u{C6}" => 'Ae', "\u{C7}" => 'C',
		"\u{C8}" => 'E', "\u{C9}" => 'E', "\u{CA}" => 'E', "\u{CB}" => 'E', "\u{CC}" => 'I', "\u{CD}" => 'I', "\u{CE}" => 'I', "\u{CF}" => 'I',
		"\u{D0}" => 'D', "\u{D1}" => 'N', "\u{D2}" => 'O', "\u{D3}" => 'O', "\u{D4}" => 'O', "\u{D5}" => 'O', "\u{D6}" => 'Oe', "\u{D8}" => 'Oe',
		"\u{D9}" => 'U', "\u{DA}" => 'U', "\u{DB}" => 'U', "\u{DC}" => 'Ue', "\u{DD}" => 'Y', "\u{DE}" => 'Th', "\u{DF}" => 'ss',
		"\u{E0}" => 'a', "\u{E1}" => 'a', "\u{E2}" => 'a', "\u{E3}" => 'a', "\u{E4}" => 'ae', "\u{E5}" => 'aa', "\u{E6}" => 'ae', "\u{E7}" => 'c',
		"\u{E8}" => 'e', "\u{E9}" => 'e', "\u{EA}" => 'e', "\u{EB}" => 'e', "\u{EC}" => 'i', "\u{ED}" => 'i', "\u{EE}" => 'i', "\u{EF}" => 'i',
		"\u{F0}" => 'd', "\u{F1}" => 'n', "\u{F2}" => 'o', "\u{F3}" => 'o', "\u{F4}" => 'o', "\u{F5}" => 'o', "\u{F6}" => 'oe', "\u{F8}" => 'oe',
		"\u{F9}" => 'u', "\u{FA}" => 'u', "\u{FB}" => 'u', "\u{FC}" => 'ue', "\u{FD}" => 'y', "\u{FE}" => 'th', "\u{FF}" => 'y',

		// Latin Extended-A.
		"\u{100}" => 'A', "\u{101}" => 'a', "\u{102}" => 'A', "\u{103}" => 'a', "\u{104}" => 'A', "\u{105}" => 'a',
		"\u{106}" => 'C', "\u{107}" => 'c', "\u{108}" => 'C', "\u{109}" => 'c', "\u{10A}" => 'C', "\u{10B}" => 'c', "\u{10C}" => 'C', "\u{10D}" => 'c',
		"\u{10E}" => 'D', "\u{10F}" => 'd', "\u{110}" => 'D', "\u{111}" => 'd',
		"\u{112}" => 'E', "\u{113}" => 'e', "\u{114}" => 'E', "\u{115}" => 'e', "\u{116}" => 'E', "\u{117}" => 'e', "\u{118}" => 'E', "\u{119}" => 'e', "\u{11A}" => 'E', "\u{11B}" => 'e',
		"\u{11C}" => 'G', "\u{11D}" => 'g', "\u{11E}" => 'G', "\u{11F}" => 'g', "\u{120}" => 'G', "\u{121}" => 'g', "\u{122}" => 'G', "\u{123}" => 'g',
		"\u{124}" => 'H', "\u{125}" => 'h', "\u{126}" => 'H', "\u{127}" => 'h',
		"\u{128}" => 'I', "\u{129}" => 'i', "\u{12A}" => 'I', "\u{12B}" => 'i', "\u{12C}" => 'I', "\u{12D}" => 'i', "\u{12E}" => 'I', "\u{12F}" => 'i', "\u{130}" => 'I', "\u{131}" => 'i',
		"\u{132}" => 'Ij', "\u{133}" => 'ij', "\u{134}" => 'J', "\u{135}" => 'j', "\u{136}" => 'K', "\u{137}" => 'k', "\u{138}" => 'k',
		"\u{139}" => 'L', "\u{13A}" => 'l', "\u{13B}" => 'L', "\u{13C}" => 'l', "\u{13D}" => 'L', "\u{13E}" => 'l', "\u{13F}" => 'L', "\u{140}" => 'l', "\u{141}" => 'L', "\u{142}" => 'l',
		"\u{143}" => 'N', "\u{144}" => 'n', "\u{145}" => 'N', "\u{146}" => 'n', "\u{147}" => 'N', "\u{148}" => 'n', "\u{149}" => 'n', "\u{14A}" => 'N', "\u{14B}" => 'n',
		"\u{14C}" => 'O', "\u{14D}" => 'o', "\u{14E}" => 'O', "\u{14F}" => 'o', "\u{150}" => 'O', "\u{151}" => 'o', "\u{152}" => 'Oe', "\u{153}" => 'oe',
		"\u{154}" => 'R', "\u{155}" => 'r', "\u{156}" => 'R', "\u{157}" => 'r', "\u{158}" => 'R', "\u{159}" => 'r',
		"\u{15A}" => 'S', "\u{15B}" => 's', "\u{15C}" => 'S', "\u{15D}" => 's', "\u{15E}" => 'S', "\u{15F}" => 's', "\u{160}" => 'S', "\u{161}" => 's',
		"\u{162}" => 'T', "\u{163}" => 't', "\u{164}" => 'T', "\u{165}" => 't', "\u{166}" => 'T', "\u{167}" => 't',
		"\u{168}" => 'U', "\u{169}" => 'u', "\u{16A}" => 'U', "\u{16B}" => 'u', "\u{16C}" => 'U', "\u{16D}" => 'u', "\u{16E}" => 'U', "\u{16F}" => 'u', "\u{170}" => 'U', "\u{171}" => 'u', "\u{172}" => 'U', "\u{173}" => 'u',
		"\u{174}" => 'W', "\u{175}" => 'w', "\u{176}" => 'Y', "\u{177}" => 'y', "\u{178}" => 'Y',
		"\u{179}" => 'Z', "\u{17A}" => 'z', "\u{17B}" => 'Z', "\u{17C}" => 'z', "\u{17D}" => 'Z', "\u{17E}" => 'z', "\u{17F}" => 's',

		// Latin Extended-B and Latin Extended Additional: Romanian comma below, capital sharp s.
		"\u{218}" => 'S', "\u{219}" => 's', "\u{21A}" => 'T', "\u{21B}" => 't', "\u{1E9E}" => 'Ss',

		// Decomposed letters with a diaeresis (U+0308) or a ring above (U+030A) that do not become
		// their base letter; the marks of all other decomposed letters are dropped after the map.
		"A\u{308}" => 'Ae', "a\u{308}" => 'ae', "O\u{308}" => 'Oe', "o\u{308}" => 'oe', "U\u{308}" => 'Ue', "u\u{308}" => 'ue',
		"A\u{30A}" => 'Aa', "a\u{30A}" => 'aa',
	];

	/** PCRE that matches the combining diacritical marks U+0300 to U+036F by their UTF-8 bytes. */
	private const string COMBINING_MARKS = '/\xCC[\x80-\xBF]|\xCD[\x80-\xAF]/';

	/** Text that replaces disallowed characters and joins a numeric suffix; never empty. */
	private string $separator;

	/** Whether ASCII letters in slugs must be lowercase. */
	private bool $lowercase;

	/** Maximum slug length in bytes, at least 1. */
	private int $maxLength;

	/** Whether letters are transliterated to ASCII before disallowed characters are replaced. */
	private bool $transliterate;

	/** @var array<array-key,true> Reserved slugs with ASCII letters lowercased, as keys. */
	private array $reserved;

	/** PCRE that matches the characters a slug must not contain. */
	private string $disallowedPattern;

	/** PCRE that matches two or more separators in a row. */
	private string $repeatedSeparators;

	/** PCRE that matches the separators at the start and at the end of a text. */
	private string $outerSeparators;


	// ----------------------------------------------------------------
	// Policy
	// ----------------------------------------------------------------

	/**
	 * Read and check the slug policy once.
	 *
	 * Behavior:
	 * - Each service option wins over the key of the same name in cfg slug.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\SluggerConfigException  When a policy value is invalid; its path names the option or the cfg key.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->slug;

		$pattern = $this->options['disallowed_pattern'] ?? $cfg->disallowed_pattern;
		// preg_match() returns false and warns for a pattern that does not compile; the check owns that outcome.
		if (!\is_string($pattern) || $pattern === '' || @\preg_match($pattern, '') !== 0) {
			throw new SluggerConfigException($this->path('disallowed_pattern'), 'a PCRE pattern that compiles and does not match the empty string', $pattern);
		}
		$this->disallowedPattern = $pattern;

		$separator = $this->options['separator'] ?? $cfg->separator;
		// false for a separator that a pattern with the u modifier cannot read counts as a match.
		if (!\is_string($separator) || $separator === '' || @\preg_match($this->disallowedPattern, $separator) !== 0) {
			throw new SluggerConfigException($this->path('separator'), 'a non-empty string without characters that ' . $this->path('disallowed_pattern') . ' matches', $separator);
		}
		$this->separator = $separator;
		$quoted = \preg_quote($separator, '/');
		$this->repeatedSeparators = '/(?:' . $quoted . '){2,}/';
		$this->outerSeparators = '/\A(?:' . $quoted . ')+|(?:' . $quoted . ')+\z/';

		$lowercase = $this->options['lowercase'] ?? $cfg->lowercase;
		if (!\is_bool($lowercase)) {
			throw new SluggerConfigException($this->path('lowercase'), 'a bool', $lowercase);
		}
		$this->lowercase = $lowercase;

		$maxLength = $this->options['max_length'] ?? $cfg->max_length;
		if (!\is_int($maxLength) || $maxLength < 1) {
			throw new SluggerConfigException($this->path('max_length'), 'an integer >= 1', $maxLength);
		}
		$this->maxLength = $maxLength;

		$transliterate = $this->options['transliterate'] ?? $cfg->transliterate;
		if (!\is_bool($transliterate)) {
			throw new SluggerConfigException($this->path('transliterate'), 'a bool', $transliterate);
		}
		$this->transliterate = $transliterate;

		// An empty list is a Cfg node, not an array.
		$reserved = $this->options['reserved'] ?? $cfg->reserved;
		$reserved = $reserved instanceof Cfg ? $reserved->toArray() : $reserved;
		if (!\is_array($reserved) || !\array_is_list($reserved)) {
			throw new SluggerConfigException($this->path('reserved'), 'a list of non-empty strings', $reserved);
		}
		$this->reserved = [];
		foreach ($reserved as $slug) {
			if (!\is_string($slug) || $slug === '') {
				throw new SluggerConfigException($this->path('reserved'), 'a list of non-empty strings', $slug);
			}
			$this->reserved[\strtolower($slug)] = true;
		}
	}


	// ----------------------------------------------------------------
	// Slugs
	// ----------------------------------------------------------------

	/**
	 * Make a slug from any text.
	 *
	 * Behavior:
	 * - Transliterates the trimmed text when transliterate is on: The letters of the Latin-1
	 *   Supplement and Latin Extended-A become ASCII, the Danish letters as ae, oe and aa and
	 *   the German umlauts as ae, oe and ue, also in decomposed form, and other combining marks
	 *   are dropped. Other characters stay.
	 * - Then gives the same result as normalize(), shortened with enforceLength() when it is
	 *   longer than max_length bytes.
	 * - Returns '' when no allowed character is left.
	 * - Does not check the reserved slugs; validate() does.
	 *
	 * Typical usage:
	 *   $slug = $this->app->slugger->slugify($title);
	 *
	 * @param  string  $source  UTF-8 text, such as a title.
	 * @return string
	 * @throws \InvalidArgumentException  When disallowed_pattern has the u modifier and the text is not valid UTF-8.
	 */
	public function slugify(string $source): string {
		$s = \trim($source);

		if ($this->transliterate) {
			$s = $this->replace(self::COMBINING_MARKS, '', \strtr($s, self::TRANSLITERATION));
		}

		$s = $this->normalize($s);

		return \strlen($s) > $this->maxLength ? $this->enforceLength($s, $this->maxLength) : $s;
	}


	/**
	 * Normalize a slug-like string for comparison, without transliterating or shortening it.
	 *
	 * Behavior:
	 * - Replaces each run of disallowed characters in the trimmed string with the separator,
	 *   collapses repeated separators into one, trims separators from both ends and lowercases
	 *   ASCII letters when lowercase is on, in that order.
	 * - Returns '' when no allowed character is left.
	 *
	 * @param  string  $slug
	 * @return string
	 * @throws \InvalidArgumentException  When disallowed_pattern has the u modifier and the string is not valid UTF-8.
	 */
	public function normalize(string $slug): string {
		$s = $this->replace($this->disallowedPattern, $this->separator, \trim($slug));
		$s = $this->replace($this->repeatedSeparators, $this->separator, $s);
		$s = $this->replace($this->outerSeparators, '', $s);

		return $this->lowercase ? \strtolower($s) : $s;
	}


	/**
	 * Check a slug against the policy.
	 *
	 * Behavior:
	 * - Checks the trimmed slug, so surrounding whitespace is accepted.
	 *
	 * @param  string  $slug
	 * @return void
	 * @throws \InvalidArgumentException  When the slug is empty, has a disallowed character, has an ASCII uppercase letter while lowercase is on, is longer than max_length bytes, is reserved, starts or ends with the separator, or repeats it; and when disallowed_pattern has the u modifier and the slug is not valid UTF-8.
	 */
	public function validate(string $slug): void {
		$s = \trim($slug);

		if ($s === '') {
			throw new \InvalidArgumentException('Slug cannot be empty.');
		}

		if ($this->matches($this->disallowedPattern, $s)) {
			throw new \InvalidArgumentException('Slug contains invalid characters.');
		}

		if ($this->lowercase && $s !== \strtolower($s)) {
			throw new \InvalidArgumentException('Slug must be lowercase.');
		}

		if (\strlen($s) > $this->maxLength) {
			throw new \InvalidArgumentException('Slug exceeds maximum length of ' . $this->maxLength . ' bytes.');
		}

		if ($this->isReserved($s)) {
			throw new \InvalidArgumentException('Slug is reserved.');
		}

		if ($this->matches($this->outerSeparators, $s)) {
			throw new \InvalidArgumentException('Slug cannot start or end with the separator.');
		}

		if ($this->matches($this->repeatedSeparators, $s)) {
			throw new \InvalidArgumentException('Slug cannot contain repeated separators.');
		}
	}


	/**
	 * Shorten a slug to at most $max bytes.
	 *
	 * Behavior:
	 * - Returns '' when $max is below 1, and the slug unchanged when it fits.
	 * - Otherwise takes the first $max bytes, without a UTF-8 character that would be cut, and
	 *   cuts it again at its last separator when that separator starts at a 0-based offset of
	 *   at least floor(0.6 * $max). Then trims separators from both ends.
	 * - Returns the first $max bytes, without a cut character and untrimmed, when trimming
	 *   leaves nothing.
	 *
	 * Notes:
	 * - Bytes that are not valid UTF-8 count as whole characters.
	 *
	 * @param  string  $slug  Normalized slug.
	 * @param  int  $max  Maximum length in bytes.
	 * @return string  At most $max bytes; '' also when the first character alone is longer.
	 */
	public function enforceLength(string $slug, int $max): string {
		if ($max < 1) {
			return '';
		}
		if (\strlen($slug) <= $max) {
			return $slug;
		}

		$head = self::head($slug, $max);
		$cut = $head;
		$pos = \strrpos($cut, $this->separator);
		if ($pos !== false && $pos >= (int)\floor($max * 0.6)) {
			$cut = \substr($cut, 0, $pos);
		}

		$cut = $this->replace($this->outerSeparators, '', $cut);
		return $cut === '' ? $head : $cut;
	}


	/**
	 * Check whether a slug is reserved, ignoring the case of ASCII letters.
	 *
	 * @param  string  $slug  Compared as given, without trimming.
	 * @return bool
	 */
	public function isReserved(string $slug): bool {
		return isset($this->reserved[\strtolower($slug)]);
	}


	/**
	 * Return the first free slug among a base slug and its numbered variants.
	 *
	 * Asks the caller whether each candidate is taken, in order: The normalized base, then base,
	 * separator and 2, 3 and so on up to $maxAttempts + 1. A numbered candidate longer than
	 * max_length bytes keeps its number and loses the end of the base.
	 *
	 * Behavior:
	 * - Calls $existsFn at most $maxAttempts + 1 times, and never for a candidate that fails
	 *   validate().
	 *
	 * Notes:
	 * - The check and the caller's later write are not atomic. Under concurrent writers, only a
	 *   unique constraint in storage prevents a duplicate.
	 *
	 * @param  callable(string):bool  $existsFn  Returns true when the candidate is already taken.
	 * @param  string  $baseSlug  Normalized with normalize() before use; make it with slugify() from raw text.
	 * @param  int  $maxAttempts  Number of numbered candidates to try, at least 1.
	 * @return string
	 * @throws \InvalidArgumentException  When $maxAttempts is below 1, the base is empty after normalizing, the base or a tried candidate fails validate(), or every candidate is taken.
	 * @see \CitOmni\Infrastructure\Service\Slugger::validate()  The checks every candidate must pass.
	 */
	public function ensureUnique(callable $existsFn, string $baseSlug, int $maxAttempts = 50): string {
		if ($maxAttempts < 1) {
			throw new \InvalidArgumentException('maxAttempts must be >= 1.');
		}

		$base = $this->normalize($baseSlug);
		if ($base === '') {
			throw new \InvalidArgumentException('Base slug cannot be empty after normalization.');
		}
		$this->validate($base);

		if (!$existsFn($base)) {
			return $base;
		}

		for ($i = 2; $i <= $maxAttempts + 1; $i++) {
			$candidate = $base . $this->separator . $i;
			if (\strlen($candidate) > $this->maxLength) {
				$candidate = $this->withSuffix($base, (string)$i);
			}
			$this->validate($candidate);

			if (!$existsFn($candidate)) {
				return $candidate;
			}
		}

		throw new \InvalidArgumentException('Could not find a unique slug within the attempt limit.');
	}


	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	/**
	 * Name where a policy value is set: the service option when one is set, else the cfg key.
	 *
	 * @param  string  $key  Policy key, such as max_length.
	 * @return string  Such as slugger.options.max_length or slug.max_length.
	 */
	private function path(string $key): string {
		return isset($this->options[$key]) ? 'slugger.options.' . $key : 'slug.' . $key;
	}


	/**
	 * Tell whether a pattern matches a text.
	 *
	 * @param  string  $pattern  PCRE pattern.
	 * @param  string  $subject
	 * @return bool
	 * @throws \InvalidArgumentException  When the pattern has the u modifier and the text is not valid UTF-8.
	 * @throws \RuntimeException  When PCRE fails otherwise, such as at its backtracking limit.
	 */
	private function matches(string $pattern, string $subject): bool {
		$result = \preg_match($pattern, $subject);

		return $result === false ? throw self::pcreFailure() : $result === 1;
	}


	/**
	 * Replace every match of a pattern in a text.
	 *
	 * @param  string  $pattern  PCRE pattern.
	 * @param  string  $replacement
	 * @param  string  $subject
	 * @return string
	 * @throws \InvalidArgumentException  When the pattern has the u modifier and the text is not valid UTF-8.
	 * @throws \RuntimeException  When PCRE fails otherwise, such as at its backtracking limit.
	 */
	private function replace(string $pattern, string $replacement, string $subject): string {
		return \preg_replace($pattern, $replacement, $subject) ?? throw self::pcreFailure();
	}


	/**
	 * Build the exception for the last PCRE failure.
	 *
	 * @return \InvalidArgumentException|\RuntimeException  \InvalidArgumentException for text that is not valid UTF-8.
	 */
	private static function pcreFailure(): \InvalidArgumentException|\RuntimeException {
		$error = \preg_last_error();

		// A result that depends on pcre.backtrack_limit and similar settings would differ between servers, so it fails.
		return $error === \PREG_BAD_UTF8_ERROR || $error === \PREG_BAD_UTF8_OFFSET_ERROR
			? new \InvalidArgumentException('Text is not valid UTF-8.')
			: new \RuntimeException('Slug pattern failed: ' . \preg_last_error_msg() . '.');
	}


	/**
	 * Return the first $max bytes of a longer text, without a UTF-8 character that the cut would split.
	 *
	 * Notes:
	 * - Bytes that are not valid UTF-8 count as whole characters.
	 *
	 * @param  string  $s  Text longer than $max bytes.
	 * @param  int  $max  At least 1.
	 * @return string
	 */
	private static function head(string $s, int $max): string {
		$end = $max;

		// A UTF-8 character has at most three continuation bytes after its lead byte.
		while ($end > $max - 3 && $end > 0 && (\ord($s[$end]) & 0xC0) === 0x80) {
			$end--;
		}

		// Only a lead byte starts a character that the cut would split; otherwise the bytes are no valid UTF-8 here.
		if ($end < $max && \ord($s[$end]) < 0xC0) {
			$end = $max;
		}

		return \substr($s, 0, $end);
	}


	/**
	 * Join a base and a numbered suffix within max_length bytes.
	 *
	 * Behavior:
	 * - Shortens the base with enforceLength() so that base, separator and suffix fit.
	 * - Returns the suffix alone when no part of the base fits.
	 *
	 * @param  string  $base  Slug without the suffix.
	 * @param  string  $suffix  Number, such as "2".
	 * @return string
	 */
	private function withSuffix(string $base, string $suffix): string {
		$tail = $this->separator . $suffix;
		$short = $this->enforceLength($base, \max(0, $this->maxLength - \strlen($tail)));

		return $this->replace($this->outerSeparators, '', $short === '' ? $suffix : $short . $tail);
	}

}
