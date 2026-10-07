<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\LinkScanner;
use OCA\MarkdownSite\Wiki\PathResolver;
use OCA\MarkdownSite\Wiki\PathTraversalException;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Finds the links of a site that point at no page, and the page each one
 * most likely meant. A suggestion is only made when exactly one page fits
 * (same name ignoring case, accents and punctuation, or a near-identical name).
 */
class LinkChecker {
	public const LIMIT = 500;
	private const MIN_SIMILARITY = 82.0;

	private PathResolver $paths;

	public function __construct(
		private ContentService $content,
		private IndexBuilder $indexBuilder,
		private LinkScanner $scanner,
	) {
		$this->paths = new PathResolver();
	}

	/**
	 * @return array{broken: list<array<string,mixed>>, pages: int, truncated: bool}
	 *   Each entry: page, line (1-based), offset, length, kind, target,
	 *   suggestion (new text for `target`, or null) and suggestionPage.
	 */
	public function check(Folder $root): array {
		$index = $this->indexBuilder->build($root);
		$names = $this->namePool($index);
		$broken = [];
		$truncated = false;
		foreach ($index->paths() as $path) {
			try {
				$markdown = $this->content->getPageContent($root, $path);
			} catch (\Throwable) {
				continue;
			}
			foreach ($this->brokenIn($markdown, $path, $index, $names) as $item) {
				if (count($broken) >= self::LIMIT) {
					$truncated = true;
					break 2;
				}
				$broken[] = $item;
			}
		}
		return ['broken' => $broken, 'pages' => count($index->paths()), 'truncated' => $truncated];
	}

	/**
	 * Applies the suggested replacement of each requested link. A link that is
	 * no longer broken, or has no suggestion, is left alone.
	 *
	 * @param list<array{page: string, line: int, offset: int}> $fixes line is 1-based
	 * @return array{fixed: int, failed: list<string>, pages: list<string>}
	 */
	public function repair(Folder $root, array $fixes): array {
		$index = $this->indexBuilder->build($root);
		$names = $this->namePool($index);
		$byPage = [];
		foreach ($fixes as $fix) {
			$byPage[$fix['page']][] = $fix;
		}
		$fixed = 0;
		$failed = [];
		$touched = [];
		foreach ($byPage as $page => $wanted) {
			$page = (string) $page;
			try {
				$node = $this->content->getChild($root, $page);
				if (!$node instanceof File) {
					throw new \RuntimeException($page);
				}
				$lines = preg_split('/(?<=\n)/', $node->getContent()) ?: [];
				$items = [];
				foreach ($this->brokenIn(implode('', $lines), $page, $index, $names) as $item) {
					$items[$item['line'] . ':' . $item['offset']] = $item;
				}
				$edits = [];
				foreach ($wanted as $fix) {
					$item = $items[$fix['line'] . ':' . $fix['offset']] ?? null;
					if ($item !== null && $item['suggestion'] !== null) {
						$edits[] = $item;
					}
				}
				// Last link of a line first, so earlier offsets stay valid.
				usort($edits, fn (array $a, array $b) => [$b['line'], $b['offset']] <=> [$a['line'], $a['offset']]);
				foreach ($edits as $item) {
					$at = $item['line'] - 1;
					$lines[$at] = substr_replace($lines[$at], (string) $item['suggestion'], $item['offset'], $item['length']);
				}
				if ($edits !== []) {
					$node->putContent(implode('', $lines));
					$fixed += count($edits);
					$touched[] = $page;
				}
			} catch (\Throwable) {
				$failed[] = $page;
			}
		}
		return ['fixed' => $fixed, 'failed' => $failed, 'pages' => $touched];
	}

	/**
	 * @param array<string,list<string>> $names normalised page name => pages
	 * @return list<array<string,mixed>>
	 */
	private function brokenIn(string $markdown, string $page, WikilinkIndex $index, array $names): array {
		$dir = LinkUpdater::dir($page);
		$known = null;
		$out = [];
		foreach ($this->scanner->scan($markdown) as $link) {
			if ($link['kind'] === 'wikilink') {
				if ($index->resolve($dir, $link['target']) !== null) {
					continue;
				}
				$wanted = $link['target'];
			} else {
				$known ??= array_fill_keys(array_map('mb_strtolower', $index->paths()), true);
				try {
					$resolved = $this->paths->resolve($dir, rawurldecode($link['target']));
				} catch (PathTraversalException) {
					$resolved = null;
				}
				if ($resolved !== null && isset($known[mb_strtolower($resolved)])) {
					continue;
				}
				$wanted = $resolved ?? rawurldecode($link['target']);
			}
			$target = $this->suggest($wanted, $names);
			$out[] = [
				'page' => $page,
				'line' => $link['line'] + 1,
				'offset' => $link['offset'],
				'length' => $link['length'],
				'kind' => $link['kind'],
				'target' => $link['target'],
				'suggestion' => $target === null ? null : $this->replacement($link, $target, $dir, $index),
				'suggestionPage' => $target,
			];
		}
		return $out;
	}

	/** The page that $wanted most likely meant, or null when none or several fit. */
	private function suggest(string $wanted, array $names): ?string {
		$name = self::normalise(basename(self::stripExt($wanted)));
		if (strlen($name) < 3) {
			return null;
		}
		if (isset($names[$name])) {
			$fits = $this->matchingDir($names[$name], $wanted);
			return count($fits) === 1 ? $fits[0] : null;
		}
		$best = null;
		$bestScore = 0.0;
		$tie = false;
		foreach ($names as $other => $pages) {
			similar_text($name, (string) $other, $score);
			if ($score > $bestScore) {
				[$best, $bestScore, $tie] = [$pages, $score, false];
			} elseif ($score === $bestScore) {
				$tie = true;
			}
		}
		return $best !== null && !$tie && $bestScore >= self::MIN_SIMILARITY && count($best) === 1 ? $best[0] : null;
	}

	/**
	 * Of pages sharing a name, those whose folders also end like the wanted path.
	 * @param list<string> $pages
	 * @return list<string>
	 */
	private function matchingDir(array $pages, string $wanted): array {
		if (count($pages) < 2 || !str_contains($wanted, '/')) {
			return $pages;
		}
		$tail = self::normalise(self::stripExt($wanted));
		return array_values(array_filter($pages, fn (string $p) => str_ends_with(self::normalise(self::stripExt($p)), $tail)));
	}

	/**
	 * New text for the link's target: the bare name when it finds the page
	 * unambiguously, else its path; a Markdown link gets a relative path.
	 *
	 * @param array{kind: string, target: string, angle: bool} $link
	 */
	private function replacement(array $link, string $page, string $dir, WikilinkIndex $index): string {
		if ($link['kind'] === 'link') {
			$path = str_starts_with($link['target'], '/') ? '/' . $page : LinkRewriter::relativePath($dir, $page);
			return $link['angle'] ? $path : str_replace(' ', '%20', $path);
		}
		$keepExt = (bool) preg_match('/\.md$/i', $link['target']);
		$noExt = self::stripExt($page);
		$bare = basename($noExt);
		$useBare = !str_contains($link['target'], '/') && $index->resolve($dir, $bare) === $page;
		return ($useBare ? $bare : $noExt) . ($keepExt ? '.md' : '');
	}

	/**
	 * @return array<string,list<string>> normalised name => pages
	 */
	private function namePool(WikilinkIndex $index): array {
		$pool = [];
		foreach ($index->paths() as $path) {
			$name = self::normalise(basename(self::stripExt($path)));
			if ($name !== '') {
				$pool[$name][] = $path;
			}
		}
		return $pool;
	}

	private static function stripExt(string $path): string {
		return preg_replace('/\.md$/i', '', $path) ?? $path;
	}

	/** Lower case, no accents, letters and digits only. */
	private static function normalise(string $text): string {
		$text = mb_strtolower($text);
		if (function_exists('transliterator_transliterate')) {
			$text = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $text) ?: $text;
		}
		return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
	}
}
