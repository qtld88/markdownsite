<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Search;

/**
 * Folds text for matching: lower case, accents and ligatures reduced to their
 * base letter. Exactly one character in, one character out, so an offset in
 * the folded text is the same offset in the original.
 */
class Normalizer {
	/** Lower-case Latin-1 Supplement and Latin Extended-A letters => base letter. */
	private const BASE = [
		'ß' => 's', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a',
		'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
		'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 't', 'ÿ' => 'y',
		'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd',
		'đ' => 'd', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ĝ' => 'g', 'ğ' => 'g',
		'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i',
		'ı' => 'i', 'ĳ' => 'i', 'ĵ' => 'j', 'ķ' => 'k', 'ĸ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l',
		'ŀ' => 'l', 'ł' => 'l', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n', 'ŋ' => 'n', 'ō' => 'o',
		'ŏ' => 'o', 'ő' => 'o', 'œ' => 'o', 'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's',
		'ş' => 's', 'š' => 's', 'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u',
		'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ŵ' => 'w', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
		'ſ' => 's',
	];

	public static function normalize(string $s): string {
		// Simple case mapping is one code point to one code point (full
		// mapping would turn "İ" into two).
		return strtr(mb_convert_case($s, MB_CASE_LOWER_SIMPLE, 'UTF-8'), self::BASE);
	}
}
