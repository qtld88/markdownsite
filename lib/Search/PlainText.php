<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Search;

use Symfony\Component\Yaml\Yaml;

/** Reduces an Obsidian Markdown page to the plain text that search reads. */
class PlainText {
	/**
	 * @param string $fallbackTitle used when there is neither a frontmatter
	 *                              title nor an h1 (normally the file name)
	 * @return array{title: string, aliases: list<string>, headings: list<string>, body: string}
	 */
	public static function fromMarkdown(string $md, string $fallbackTitle = ''): array {
		$md = str_replace(["\r\n", "\r"], "\n", $md);
		$frontmatter = [];
		if (preg_match('/\A---[ \t]*\n(.*?)\n---[ \t]*(?:\n|\z)/s', $md, $m)) {
			$md = substr($md, strlen($m[0]));
			try {
				$data = Yaml::parse($m[1]);
				$frontmatter = is_array($data) ? $data : [];
			} catch (\Throwable) {
			}
		}

		$lines = [];
		$headings = [];
		$firstH1 = null;
		$fence = null;
		foreach (explode("\n", $md) as $line) {
			if ($fence !== null) {
				if (preg_match('/^\s*' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $line)) {
					$fence = null;
				} else {
					$lines[] = $line; // code stays searchable, verbatim
				}
				continue;
			}
			if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $f)) {
				$fence = $f[1];
				continue;
			}
			$line = preg_replace('/^\s*(?:>\s*)+\[![\w-]+\][+-]?\s*/', '', $line) ?? $line;
			$line = preg_replace('/^\s*(?:>\s?)+/', '', $line) ?? $line;
			if (preg_match('/^\s*(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $h)) {
				$text = self::inline($h[2]);
				$headings[] = $text;
				if ($firstH1 === null && strlen($h[1]) === 1) {
					$firstH1 = $text;
				}
				$lines[] = $text;
				continue;
			}
			$line = preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?/', '', $line) ?? $line;
			$lines[] = self::inline($line);
		}

		$aliases = $frontmatter['aliases'] ?? [];
		$aliases = array_values(array_filter(
			array_map(fn ($a) => trim((string) $a), is_array($aliases) ? $aliases : [$aliases]),
			fn (string $a) => $a !== '',
		));
		$title = is_scalar($frontmatter['title'] ?? null) && trim((string) $frontmatter['title']) !== ''
			? trim((string) $frontmatter['title'])
			: ($firstH1 ?? $fallbackTitle);
		$body = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');

		return ['title' => $title, 'aliases' => $aliases, 'headings' => $headings, 'body' => $body];
	}

	/** Inline Markdown to plain text. */
	private static function inline(string $s): string {
		$s = preg_replace('/!\[\[[^\]]*\]\]/', '', $s) ?? $s;                          // ![[embed]]
		$s = preg_replace('/\[\[[^\]|]*\|([^\]]*)\]\]/', '$1', $s) ?? $s;              // [[target|label]]
		$s = preg_replace_callback('/\[\[([^\]]*)\]\]/', fn ($m) => trim(str_replace('#', ' ', $m[1])), $s) ?? $s; // [[target#heading]]
		$s = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $s) ?? $s;                     // ![alt](src)
		$s = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s) ?? $s;                  // [text](url)
		$s = preg_replace('/<[^>]+>/', '', $s) ?? $s;                                   // HTML tags
		$s = str_replace(['**', '__', '==', '~~', '`'], '', $s);
		$s = preg_replace('/(?<![\p{L}\p{N}])[*_]+|[*_]+(?![\p{L}\p{N}])/u', '', $s) ?? $s; // *em* _em_
		return trim($s);
	}
}
