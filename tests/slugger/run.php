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

namespace CitOmni\Infrastructure\Tests\Slugger;

use CitOmni\Infrastructure\Boot\Registry;
use CitOmni\Infrastructure\Exception\SluggerConfigException;
use CitOmni\Infrastructure\Service\Slugger;
use CitOmni\Infrastructure\Tests\Support\App;

use function CitOmni\Infrastructure\Tests\Support\expect;
use function CitOmni\Infrastructure\Tests\Support\export;
use function CitOmni\Infrastructure\Tests\Support\runChecks;
use function CitOmni\Infrastructure\Tests\Support\same;
use function CitOmni\Infrastructure\Tests\Support\thrown;

/*
 * Standalone suite for \CitOmni\Infrastructure\Service\Slugger: the policy it reads from cfg
 * slug and the service options, and the whole public contract of slugify(), normalize(),
 * validate(), enforceLength(), isReserved() and ensureUnique().
 *
 * The real service runs on the kernel doubles, without Composer, on the cfg baseline that
 * Registry::CFG_COMMON ships, with the changes of each check on top. Non-ASCII input is
 * written with \u{...} escapes, so composed and decomposed letters cannot be confused.
 *
 * Usage:
 *   php tests/slugger/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; like the production ErrorHandler, leave
// diagnostics silenced with @ to PHP.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/../support/doubles.php';
require __DIR__ . '/../support/checks.php';

// CFG_COMMON derives the log path from CITOMNI_APP_PATH; nothing here writes below it.
\define('CITOMNI_APP_PATH', \sys_get_temp_dir());

require __DIR__ . '/../../src/Boot/Registry.php';
require __DIR__ . '/../../src/Exception/SluggerConfigException.php';
require __DIR__ . '/../../src/Service/Slugger.php';


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/**
 * A Slugger on the shipped slug baseline with $slug on top, and the given service options.
 *
 * @param  array<string,mixed>  $slug  Cfg keys of slug that replace the baseline's.
 * @param  array<string,mixed>  $options  Service map options.
 * @return Slugger
 */
function slugger(array $slug = [], array $options = []): Slugger {
	return new Slugger(new App(['slug' => \array_replace(Registry::CFG_COMMON['slug'], $slug)]), $options);
}


/**
 * Run ensureUnique() against a set of taken slugs and record every candidate it asks about.
 *
 * @param  Slugger  $slugger
 * @param  list<string>  $taken
 * @param  string  $base
 * @param  int  $maxAttempts
 * @return array{0: string, 1: list<string>}  The slug and the candidates asked about, in order.
 */
function unique(Slugger $slugger, array $taken, string $base, int $maxAttempts = 50): array {
	$asked = [];
	$slug = $slugger->ensureUnique(static function (string $candidate) use ($taken, &$asked): bool {
		$asked[] = $candidate;
		return \in_array($candidate, $taken, true);
	}, $base, $maxAttempts);

	return [$slug, $asked];
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	// -- Policy ---------------------------------------------------------

	'the shipped baseline is a valid policy: dash, lowercase, 140 bytes, transliteration, no reserved slugs' => static function (): void {
		$slugger = slugger();
		same('aeroe-oel', $slugger->slugify("\u{C6}r\u{F8} \u{D8}l"), 'transliterated and lowercased');
		same(\str_repeat('a', 140), $slugger->slugify(\str_repeat('a', 141)), 'shortened to 140 bytes');
		expect(!$slugger->isReserved('admin'), 'a slug is reserved without reserved slugs');
	},

	'each service option wins over the cfg key of the same name' => static function (): void {
		$slugger = slugger(['reserved' => ['blog']], [
			'separator'          => '_',
			'lowercase'          => false,
			'max_length'         => 12,
			'transliterate'      => false,
			'reserved'           => ['Admin'],
			'disallowed_pattern' => '/[^a-z0-9_]+/i',
		]);
		same('Hello_World', $slugger->slugify('Hello, World'), 'separator and case');
		same('r_l', $slugger->slugify("\u{C6}r\u{F8} \u{D8}l"), 'no transliteration');
		same('Hello_Big', $slugger->slugify('Hello Big Wide World'), 'max_length');
		expect($slugger->isReserved('admin') && !$slugger->isReserved('blog'), 'reserved from the option, not from cfg');
	},

	'an invalid policy value throws SluggerConfigException naming the cfg key or the option' => static function (): void {
		$cases = [
			'slug.separator'          => [['separator' => ''], ['separator' => 5], ['separator' => '_'], ['separator' => '- -']],
			'slug.lowercase'          => [['lowercase' => 1], ['lowercase' => 'yes'], ['lowercase' => null]],
			'slug.max_length'         => [['max_length' => 0], ['max_length' => -1], ['max_length' => '140'], ['max_length' => 1.5]],
			'slug.transliterate'      => [['transliterate' => 0], ['transliterate' => 'on']],
			'slug.reserved'           => [['reserved' => 'admin'], ['reserved' => ['a' => 'admin']], ['reserved' => ['']], ['reserved' => [404]], ['reserved' => null]],
			'slug.disallowed_pattern' => [['disallowed_pattern' => ''], ['disallowed_pattern' => 'abc'], ['disallowed_pattern' => '/[^a-z/'], ['disallowed_pattern' => '/[^a-z]*/'], ['disallowed_pattern' => 7]],
		];
		foreach ($cases as $path => $values) {
			foreach ($values as $slug) {
				$e = thrown(SluggerConfigException::class, static fn() => slugger($slug), export($slug));
				same($path, $e->path, 'path for ' . export($slug));
			}
		}

		$e = thrown(SluggerConfigException::class, static fn() => slugger([], ['max_length' => 0]), 'max_length option 0');
		same('slugger.options.max_length', $e->path, 'path of an option');
		same('Slug policy slugger.options.max_length must be an integer >= 1, got 0.', $e->getMessage(), 'message');

		// A separator must survive disallowed_pattern, also when the pattern comes from an option.
		$e = thrown(SluggerConfigException::class, static fn() => slugger(['separator' => '_'], ['disallowed_pattern' => '/[^a-z0-9\-]+/']), 'separator that the option pattern matches');
		same('slug.separator', $e->path, 'path of the separator');
		expect(\str_contains($e->getMessage(), 'slugger.options.disallowed_pattern'), 'message does not name the pattern option: ' . $e->getMessage());
	},

	'a reserved slug is compared without the case of ASCII letters, numeric ones included' => static function (): void {
		$slugger = slugger(['reserved' => ['Admin', '404', 'login']]);
		expect($slugger->isReserved('admin') && $slugger->isReserved('ADMIN') && $slugger->isReserved('404'), 'a reserved slug was not found');
		expect(!$slugger->isReserved('admins') && !$slugger->isReserved(' admin') && !$slugger->isReserved('405'), 'an other slug was reserved');
		thrown(\InvalidArgumentException::class, static fn() => $slugger->validate('404'), 'validate() of a reserved numeric slug');
		thrown(\InvalidArgumentException::class, static fn() => $slugger->validate(' login '), 'validate() of a padded reserved slug');
	},

	// -- slugify() ------------------------------------------------------

	'slugify() replaces each run of disallowed characters with one separator, trims separators and lowercases' => static function (): void {
		$slugger = slugger();
		$cases = [
			'  Hello,   World!  ' => 'hello-world',
			'--a--b--'            => 'a-b',
			'a - b'               => 'a-b',
			'Version 2.0 (beta)'  => 'version-2-0-beta',
			"tab\tand\nnewline"   => 'tab-and-newline',
			''                    => '',
			'   '                 => '',
			'!!! ???'             => '',
		];
		foreach ($cases as $source => $slug) {
			same($slug, $slugger->slugify($source), export($source));
		}
	},

	'slugify() transliterates Danish, German and accented Latin letters, composed or decomposed' => static function (): void {
		$slugger = slugger();
		$cases = [
			"\u{C6}r\u{F8} \u{D8}l, l\u{F8}besko & \u{C5}lborg"     => 'aeroe-oel-loebesko-aalborg',
			"Sm\u{F8}rrebr\u{F8}d p\u{E5} \u{E6}blegr\u{F8}d"       => 'smoerrebroed-paa-aeblegroed',
			"Stra\u{DF}e D\u{FC}sseldorf K\u{F6}ln B\u{E4}r \u{1E9E}" => 'strasse-duesseldorf-koeln-baer-ss',
			"Cr\u{E8}me br\u{FB}l\u{E9}e \u{E0} la fran\u{E7}aise"  => 'creme-brulee-a-la-francaise',
			"\u{141}\u{F3}d\u{17A} \u{17B}\u{F3}\u{142}\u{107}"     => 'lodz-zolc',
			"\u{152}uvre \u{DE}\u{F3}r \u{D0}a\u{F0} \u{218}\u{21A}" => 'oeuvre-thor-dad-st',
			"\u{C4}rger \u{D6}l \u{DC}ber"                         => 'aerger-oel-ueber',
			"A\u{30A}rhus Mu\u{308}nchen Cafe\u{301} Re\u{301}sume\u{301}" => 'aarhus-muenchen-cafe-resume',
			"A\u{308} a\u{308} O\u{308} o\u{308} U\u{308} u\u{308} A\u{30A} a\u{30A}" => 'ae-ae-oe-oe-ue-ue-aa-aa',
		];
		foreach ($cases as $source => $slug) {
			same($slug, $slugger->slugify($source), export($source));
		}
	},

	'slugify() gives the same slug under every locale' => static function (): void {
		$source = "\u{C6}r\u{F8} \u{D8}l, l\u{F8}besko & \u{C5}lborg";
		$previous = \setlocale(\LC_ALL, '0');
		try {
			foreach (['C', 'POSIX', 'C.UTF-8', 'C.utf8', 'da_DK.UTF-8', 'de_DE.UTF-8'] as $locale) {
				if (\setlocale(\LC_ALL, $locale) === false) {
					continue;
				}
				same('aeroe-oel-loebesko-aalborg', slugger()->slugify($source), 'slug under ' . $locale);
			}
		} finally {
			\setlocale(\LC_ALL, (string)$previous);
		}
	},

	'slugify() leaves characters outside the map to disallowed_pattern' => static function (): void {
		same('123', slugger()->slugify("\u{41F}\u{440}\u{438}\u{432}\u{435}\u{442} 123"), 'Cyrillic');
		same('i-php', slugger()->slugify("I \u{2665} PHP"), 'symbol');
		same('a-b', slugger()->slugify("a\xFFb"), 'a byte that is not UTF-8');

		$unicode = slugger(['lowercase' => false, 'transliterate' => false, 'disallowed_pattern' => '/[^\p{L}\p{N}\-]+/u']);
		same("\u{C6}r\u{F8}-\u{D8}l", $unicode->slugify("\u{C6}r\u{F8} \u{D8}l!"), 'letters that the pattern allows');
		thrown(\InvalidArgumentException::class, static fn() => $unicode->slugify("a\xFFb"), 'slugify() of text that is not UTF-8 with a u pattern');
		thrown(\InvalidArgumentException::class, static fn() => $unicode->validate("a\xFFb"), 'validate() of text that is not UTF-8 with a u pattern');
	},

	'slugify() without transliteration leaves the letters to disallowed_pattern' => static function (): void {
		same('r-l', slugger(['transliterate' => false])->slugify("\u{C6}r\u{F8} \u{D8}l"), 'letters replaced, not transliterated');
	},

	'slugify() shortens a long slug at a separator with enforceLength()' => static function (): void {
		$slugger = slugger(['max_length' => 20]);
		same('the-quick-brown-fox', $slugger->slugify('The quick brown fox jumps over'), 'cut at the last separator');
		same(\str_repeat('a', 20), $slugger->slugify(\str_repeat('a', 30)), 'cut without a separator');
	},

	// -- normalize(), validate() ------------------------------------------

	'normalize() cleans like slugify() without transliterating or shortening' => static function (): void {
		$slugger = slugger(['max_length' => 5]);
		same('hello-world', $slugger->normalize('  Hello,  World  '), 'cleaned');
		same('r', $slugger->normalize("\u{C6}r\u{F8}"), 'not transliterated');
		same('abcdefghij', $slugger->normalize('abcdefghij'), 'not shortened');
		same('', $slugger->normalize(' -- '), 'nothing left');
		same('Hello', slugger(['lowercase' => false])->normalize('Hello'), 'case kept with lowercase off');
	},

	'validate() accepts a slug of the policy, trimmed, and rejects everything else' => static function (): void {
		$slugger = slugger(['max_length' => 20, 'reserved' => ['admin']]);
		foreach (['hello-world', ' hello-world ', 'a', '2026', \str_repeat('a', 20)] as $slug) {
			$slugger->validate($slug);
		}
		foreach (['', '   ', 'hello world', 'hello_world', "h\u{E6}", 'Hello', \str_repeat('a', 21), 'admin', 'ADMIN', '-hello', 'hello-', 'hello--world'] as $slug) {
			thrown(\InvalidArgumentException::class, static fn() => $slugger->validate($slug), export($slug));
		}
		slugger(['lowercase' => false])->validate('Hello-World');
	},

	'a separator of several characters is replaced, collapsed, trimmed and checked as a whole' => static function (): void {
		$slugger = slugger(['separator' => '~~', 'disallowed_pattern' => '/[^a-z0-9~]+/i']);
		same('a~~b', $slugger->slugify('a  b'), 'replaced');
		same('a~~b', $slugger->slugify('~~~~a ~~~~ b~~~~'), 'collapsed and trimmed');
		same('a~~~b', $slugger->normalize('a~~~b'), 'three characters are one separator and a tilde');
		$slugger->validate('a~~b');
		foreach (['~~a', 'a~~', 'a~~~~b'] as $slug) {
			thrown(\InvalidArgumentException::class, static fn() => $slugger->validate($slug), export($slug));
		}
	},

	// -- enforceLength() --------------------------------------------------

	'enforceLength() cuts at the last separator from 60 percent of the length, else at the length' => static function (): void {
		$slugger = slugger();
		$cases = [
			['abcdefghij', 0, ''],
			['abcdefghij', -1, ''],
			['abcdefghij', 10, 'abcdefghij'],
			['abcdef-ghijk', 10, 'abcdef'],
			['abcde-fghijk', 10, 'abcde-fghi'],
			['abcdefghi-jk', 10, 'abcdefghi'],
			['abcdefghij-klm', 11, 'abcdefghij'],
			['abc-defghijkl-mn', 13, 'abc-defghijkl'],
			['----------', 4, '----'],
		];
		foreach ($cases as [$slug, $max, $expected]) {
			same($expected, $slugger->enforceLength($slug, $max), export($slug) . ' to ' . $max);
		}
	},

	'enforceLength() never cuts inside a UTF-8 character' => static function (): void {
		$slugger = slugger();
		same("\u{E6}", $slugger->enforceLength("\u{E6}\u{F8}\u{E5}", 3), 'two-byte letters');
		same("\u{E6}\u{F8}", $slugger->enforceLength("\u{E6}\u{F8}\u{E5}", 5), 'two-byte letters, one more byte');
		same('', $slugger->enforceLength("\u{E6}x", 1), 'a first letter longer than the limit');
		same('a', $slugger->enforceLength("a\u{1F600}", 4), 'a four-byte character');
		same("a\x80\x80", $slugger->enforceLength("a\x80\x80\x80\x80", 3), 'bytes that are not UTF-8 are cut as they are');
	},

	// -- ensureUnique() ---------------------------------------------------

	'ensureUnique() returns the normalized base when it is free, else the first free numbered variant' => static function (): void {
		$slugger = slugger();
		same(['hello-world', ['hello-world']], unique($slugger, [], ' Hello  World '), 'free base');
		same(['hello-3', ['hello', 'hello-2', 'hello-3']], unique($slugger, ['hello', 'hello-2'], 'hello'), 'third candidate');
		same(['hello-4', ['hello', 'hello-2', 'hello-3', 'hello-4']], unique($slugger, ['hello', 'hello-2', 'hello-3'], 'hello', 3), 'last candidate');
	},

	'ensureUnique() keeps the number of a candidate longer than max_length and shortens the base' => static function (): void {
		same(['abcdefgh-2', ['abcdefghij', 'abcdefgh-2']], unique(slugger(['max_length' => 10]), ['abcdefghij'], 'abcdefghij'), 'base shortened');
		same(['abcd-efgh-2', ['abcd-efghij', 'abcd-efgh-2']], unique(slugger(['max_length' => 11]), ['abcd-efghij'], 'abcd-efghij'), 'base cut at the limit, since its separator lies before 60 percent');
		same(['2', ['ab', '2']], unique(slugger(['max_length' => 2]), ['ab'], 'ab'), 'number alone when no part of the base fits');
	},

	'ensureUnique() rejects an invalid limit, an empty or invalid base, a candidate that fails validate() and a full set' => static function (): void {
		$slugger = slugger(['reserved' => ['admin', 'news-2']]);
		thrown(\InvalidArgumentException::class, static fn() => unique($slugger, [], 'hello', 0), 'maxAttempts 0');
		thrown(\InvalidArgumentException::class, static fn() => unique($slugger, [], ' !!! '), 'empty base');
		thrown(\InvalidArgumentException::class, static fn() => unique($slugger, [], 'admin'), 'reserved base');
		thrown(\InvalidArgumentException::class, static fn() => unique(slugger(['max_length' => 5]), [], 'abcdefgh'), 'base longer than max_length');
		thrown(\InvalidArgumentException::class, static fn() => unique($slugger, ['news'], 'news'), 'reserved candidate');
		thrown(\InvalidArgumentException::class, static fn() => unique($slugger, ['a', 'a-2', 'a-3'], 'a', 2), 'every candidate taken');
	},

];


exit(runChecks($checks));
