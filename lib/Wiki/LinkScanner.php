<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

/**
 * Finds the links of a Markdown page that point at another page: wikilinks
 * and Markdown links ending in `.md`. Code (fenced, indented, inline) is
 * skipped, as in LinkRewriter. External URLs, anchors and attachments are
 * not reported.
 */
class LinkScanner {
	/**
	 * `line` is the 0-based index in preg_split('/(?<=\n)/'); `offset` and
	 * `length` locate `target` (the page name or path, without heading or
	 * label) inside that line, in bytes.
	 *
	 * @return list<array{line: int, offset: int, length: int, kind: 'wikilink'|'link', target: string, angle: bool}>
	 */
	public function scan(string $markdown): array {
		$found = [];
		$fence = null;
		$previousBlank = true;
		$inIndentedCode = false;
		foreach (preg_split('/(?<=\n)/', $markdown) ?: [] as $index => $line) {
			$content = rtrim($line, "\r\n");
			if ($fence !== null) {
				if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $content)) {
					$fence = null;
				}
				continue;
			}
			if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $content, $m)) {
				$fence = $m[1];
				continue;
			}
			$isBlank = trim($content) === '';
			$inIndentedCode = !$isBlank && preg_match('/^(?: {4}|\t)/', $content) === 1 && ($previousBlank || $inIndentedCode);
			$previousBlank = $isBlank;
			if ($inIndentedCode) {
				continue;
			}
			foreach ($this->textSegments($line) as [$text, $base]) {
				foreach ($this->scanText($text) as $link) {
					$link['line'] = $index;
					$link['offset'] += $base;
					$found[] = $link;
				}
			}
		}
		return $found;
	}

	/**
	 * The parts of a line outside inline code spans, with their byte offsets.
	 * @return list<array{0: string, 1: int}>
	 */
	private function textSegments(string $line): array {
		if (!preg_match_all('/(`+)(.+?)\1/', $line, $spans, PREG_OFFSET_CAPTURE)) {
			return [[$line, 0]];
		}
		$segments = [];
		$offset = 0;
		foreach ($spans[0] as [$span, $at]) {
			$segments[] = [substr($line, $offset, $at - $offset), $offset];
			$offset = $at + strlen($span);
		}
		$segments[] = [substr($line, $offset), $offset];
		return $segments;
	}

	/** @return list<array{offset: int, length: int, kind: 'wikilink'|'link', target: string, angle: bool}> */
	private function scanText(string $text): array {
		$found = [];
		if (preg_match_all(LinkRewriter::WIKILINK, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
			foreach ($all as $m) {
				$bang = $m[1][0];
				[$inner, $innerAt] = $m[2];
				$hash = strpos($inner, '#');
				$name = $hash === false ? $inner : substr($inner, 0, $hash);
				if (trim($name) === '' || ($bang === '!' && preg_match('/\.(?!md$)[a-z0-9]+$/i', trim($name)))) {
					continue; // same-page heading, or an attachment embed
				}
				$lead = strlen($name) - strlen(ltrim($name));
				$trimmed = trim($name);
				$found[] = ['offset' => $innerAt + $lead, 'length' => strlen($trimmed), 'kind' => 'wikilink', 'target' => $trimmed, 'angle' => false];
			}
		}
		if (preg_match_all(LinkRewriter::MDLINK, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
			foreach ($all as $m) {
				[$href, $hrefAt] = $m[2];
				$angle = str_starts_with($href, '<');
				$raw = $angle ? substr($href, 1, -1) : $href;
				if ($raw === '' || str_starts_with($raw, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) {
					continue;
				}
				$hash = strpos($raw, '#');
				$path = $hash === false ? $raw : substr($raw, 0, $hash);
				if (!preg_match('/\.md$/i', rawurldecode($path))) {
					continue;
				}
				$found[] = ['offset' => $hrefAt + ($angle ? 1 : 0), 'length' => strlen($path), 'kind' => 'link', 'target' => $path, 'angle' => $angle];
			}
		}
		usort($found, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);
		return $found;
	}
}
