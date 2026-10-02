<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Search;

/**
 * Scores candidate pages and cuts result snippets. Snippet offsets are in
 * characters (Unicode code points), not bytes.
 */
class Ranker {
	public const SNIPPET_LENGTH = 160;
	private const SNIPPET_LEAD = 40;

	private const POINTS_TITLE = 10;
	private const POINTS_ALIAS = 8;
	private const POINTS_HEADING = 5;
	private const BODY_MAX = 5;
	private const WORD_START_BONUS = 1.5;

	/**
	 * Sum over terms of: title 10, alias 8, heading 5, body 1 per occurrence
	 * (max 5); each field's points × 1.5 when an occurrence starts a word.
	 *
	 * @param list<string> $terms normalised terms
	 * @param array{title_norm: string, aliases_norm: string, headings_norm: string, body_norm: string} $row
	 */
	public static function score(array $terms, array $row): float {
		$score = 0.0;
		foreach ($terms as $term) {
			foreach ([
				[$row['title_norm'], self::POINTS_TITLE, 1],
				[$row['aliases_norm'], self::POINTS_ALIAS, 1],
				[$row['headings_norm'], self::POINTS_HEADING, 1],
				[$row['body_norm'], 1, self::BODY_MAX],
			] as [$text, $points, $maxCount]) {
				$count = 0;
				$wordStart = false;
				$offset = 0;
				// Byte offsets: both strings are UTF-8, so a match is a match.
				while (($pos = strpos($text, $term, $offset)) !== false) {
					$count++;
					$wordStart = $wordStart || self::startsWord($text, $pos);
					$offset = $pos + 1;
					if ($count >= $maxCount && $wordStart) {
						break;
					}
				}
				if ($count > 0) {
					$score += min($count, $maxCount) * $points * ($wordStart ? self::WORD_START_BONUS : 1);
				}
			}
		}
		return $score;
	}

	/**
	 * About SNIPPET_LENGTH characters of $body around the first match, cut on
	 * word boundaries, with "…" where text was cut, plus the [start, length]
	 * (in characters) of every match inside the snippet.
	 *
	 * @param list<string> $terms normalised terms
	 * @return array{snippet: string, highlights: list<array{0: int, 1: int}>}
	 */
	public static function snippet(string $body, string $bodyNorm, array $terms): array {
		$firstByte = null;
		foreach ($terms as $term) {
			$pos = strpos($bodyNorm, $term);
			if ($pos !== false && ($firstByte === null || $pos < $firstByte)) {
				$firstByte = $pos;
			}
		}
		// $body and $bodyNorm have the same characters at the same character
		// offsets (Normalizer is one-to-one), but not the same byte offsets.
		$first = $firstByte === null ? 0 : mb_strlen(substr($bodyNorm, 0, $firstByte));
		$start = max(0, $first - self::SNIPPET_LEAD);
		$windowLength = self::SNIPPET_LEAD + self::SNIPPET_LENGTH + 1;
		$norm = mb_substr($bodyNorm, $start, $windowLength);
		$text = mb_substr($body, $start, $windowLength);
		$atEnd = $start + mb_strlen($norm) >= mb_strlen($bodyNorm);

		$cutStart = 0;
		if ($start > 0) {
			// Begin after a space, so the snippet does not start mid-word.
			$space = self::spaceBetween($norm, 0, $first - $start);
			$cutStart = $space === null ? 0 : $space + 1;
		}
		$cutEnd = min(mb_strlen($norm), $cutStart + self::SNIPPET_LENGTH);
		$truncated = !$atEnd || $cutEnd < mb_strlen($norm);
		if ($truncated) {
			$space = self::spaceBefore($norm, $cutEnd, $first - $start);
			if ($space !== null) {
				$cutEnd = $space;
			}
		}

		$prefix = $start + $cutStart > 0 ? '…' : '';
		$suffix = $truncated ? '…' : '';
		// Whitespace (newlines included) becomes plain spaces: same length.
		$snippetText = preg_replace('/\s/u', ' ', mb_substr($text, $cutStart, $cutEnd - $cutStart)) ?? '';
		$snippetNorm = mb_substr($norm, $cutStart, $cutEnd - $cutStart);

		$highlights = [];
		foreach ($terms as $term) {
			$offset = 0;
			while (($pos = mb_strpos($snippetNorm, $term, $offset)) !== false) {
				$highlights[] = [$pos + mb_strlen($prefix), mb_strlen($term)];
				$offset = $pos + 1;
			}
		}
		usort($highlights, fn ($a, $b) => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);
		$merged = [];
		$reach = -1;
		foreach ($highlights as $h) {
			if ($h[0] >= $reach) {
				$merged[] = $h;
				$reach = $h[0] + $h[1];
			}
		}
		return ['snippet' => $prefix . $snippetText . $suffix, 'highlights' => $merged];
	}

	/** True when the character before byte offset $pos is not a letter or digit. */
	private static function startsWord(string $text, int $pos): bool {
		if ($pos === 0) {
			return true;
		}
		$i = $pos - 1;
		while ($i > 0 && (ord($text[$i]) & 0xC0) === 0x80) {
			$i--; // back to the first byte of the previous UTF-8 character
		}
		return !preg_match('/^[\p{L}\p{N}]$/u', substr($text, $i, $pos - $i));
	}

	/** Character offset of the first whitespace in [$from, $to), or null. */
	private static function spaceBetween(string $text, int $from, int $to): ?int {
		for ($i = $from; $i < $to; $i++) {
			if (preg_match('/^\s$/u', mb_substr($text, $i, 1))) {
				return $i;
			}
		}
		return null;
	}

	/** Character offset of the last whitespace in ($after, $from], or null. */
	private static function spaceBefore(string $text, int $from, int $after): ?int {
		for ($i = $from; $i > $after; $i--) {
			if (preg_match('/^\s$/u', mb_substr($text, $i, 1))) {
				return $i;
			}
		}
		return null;
	}
}
