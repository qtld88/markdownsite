<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Search;

class QueryParser {
	/** Terms shorter than this are dropped. */
	public const MIN_TERM_LENGTH = 2;

	/**
	 * Normalised search terms. Whitespace separates terms; a "quoted span" is
	 * one term (an unclosed quote runs to the end). An empty list means "no
	 * search".
	 *
	 * @return list<string>
	 */
	public static function parse(string $q): array {
		preg_match_all('/"([^"]*)"?|(\S+)/u', $q, $matches, PREG_SET_ORDER);
		$terms = [];
		foreach ($matches as $m) {
			$raw = isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1];
			$term = trim(preg_replace('/\s+/u', ' ', Normalizer::normalize($raw)) ?? '');
			if (mb_strlen($term) >= self::MIN_TERM_LENGTH && !in_array($term, $terms, true)) {
				$terms[] = $term;
			}
		}
		return $terms;
	}
}
