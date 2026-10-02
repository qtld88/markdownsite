<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

/**
 * Rewrites the links of one Markdown page after a page or folder moved.
 *
 * Only links that resolved to the moved item (or, for a folder, to anything
 * inside it) are touched, using the link index from before the move. A link
 * whose text still resolves to the right page afterwards is left exactly as
 * it is. Code (fenced, indented, inline) is never modified.
 */
class LinkRewriter {
	private const WIKILINK = '/(!?)\[\[([^\]|]+)(\|[^\]]*)?\]\]/';
	// [text](destination "title") and ![alt](destination); destination may be <...>
	private const MDLINK = '/(!?\[(?:[^\[\]]|\[[^\]]*\])*\]\(\s*)(<[^>\n]*>|[^\s)<]+)/';

	private PathResolver $paths;

	public function __construct() {
		$this->paths = new PathResolver();
	}

	/**
	 * @param string $pageDir directory of the page holding $markdown, before the move
	 * @param string $from moved page (.md) or folder, before the move
	 * @param string $to its path after the move
	 * @param string|null $newPageDir the page's own directory after the move, when the page itself moved
	 * @return string rewritten markdown
	 */
	public function rewrite(string $markdown, string $pageDir, string $from, string $to, WikilinkIndex $before, ?string $newPageDir = null): string {
		return $this->rewriteMoves($markdown, $pageDir, [[$from, $to]], $before, $newPageDir);
	}

	/**
	 * Same as rewrite() for several moves done one after the other (a folder
	 * rename that also renames its folder note).
	 * @param list<array{0: string, 1: string}> $moves [from, to] pairs, in order
	 */
	public function rewriteMoves(string $markdown, string $pageDir, array $moves, WikilinkIndex $before, ?string $newPageDir = null): string {
		return $this->transform($markdown, $pageDir, $moves, $before, $newPageDir ?? $pageDir)[0];
	}

	/**
	 * Number of links rewriteMoves() would change.
	 * @param list<array{0: string, 1: string}> $moves
	 */
	public function countAffected(string $markdown, string $pageDir, array $moves, WikilinkIndex $before, ?string $newPageDir = null): int {
		return $this->transform($markdown, $pageDir, $moves, $before, $newPageDir ?? $pageDir)[1];
	}

	/**
	 * rewriteMoves() and countAffected() in one pass.
	 * @param list<array{0: string, 1: string}> $moves
	 * @return array{0: string, 1: int} [rewritten markdown, number of links changed]
	 */
	public function rewriteAndCount(string $markdown, string $pageDir, array $moves, WikilinkIndex $before, ?string $newPageDir = null): array {
		return $this->transform($markdown, $pageDir, $moves, $before, $newPageDir ?? $pageDir);
	}

	/** Path of $path after moving $from to $to (unchanged when unrelated). */
	public static function mapPath(string $path, string $from, string $to): string {
		if (strcasecmp($path, $from) === 0) {
			return $to;
		}
		if (stripos($path, $from . '/') === 0) {
			return $to . substr($path, strlen($from));
		}
		return $path;
	}

	/**
	 * Path of $path after all $moves.
	 * @param list<array{0: string, 1: string}> $moves
	 */
	public static function mapThrough(string $path, array $moves): string {
		foreach ($moves as [$from, $to]) {
			$path = self::mapPath($path, $from, $to);
		}
		return $path;
	}

	/**
	 * @param list<array{0: string, 1: string}> $moves
	 * @return array{0: string, 1: int}
	 */
	private function transform(string $markdown, string $pageDir, array $moves, WikilinkIndex $before, string $newPageDir): array {
		$after = $this->afterIndex($before, $moves);
		$count = 0;
		$out = [];
		$fence = null;
		$previousBlank = true;
		$inIndentedCode = false;
		foreach (preg_split('/(?<=\n)/', $markdown) ?: [] as $line) {
			$content = rtrim($line, "\r\n");
			if ($fence !== null) {
				if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $content)) {
					$fence = null;
				}
				$out[] = $line;
				continue;
			}
			if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $content, $m)) {
				$fence = $m[1];
				$out[] = $line;
				continue;
			}
			$isBlank = trim($content) === '';
			// Indented code: 4 spaces or a tab, after a blank line or more indented code.
			$inIndentedCode = !$isBlank && preg_match('/^(?: {4}|\t)/', $content) === 1 && ($previousBlank || $inIndentedCode);
			$previousBlank = $isBlank;
			if ($inIndentedCode) {
				$out[] = $line;
				continue;
			}
			$out[] = $this->rewriteLine($line, $pageDir, $moves, $before, $after, $newPageDir, $count);
		}
		return [implode('', $out), $count];
	}

	/** @param list<array{0: string, 1: string}> $moves */
	private function rewriteLine(string $line, string $pageDir, array $moves, WikilinkIndex $before, WikilinkIndex $after, string $newPageDir, int &$count): string {
		// Inline code spans (`…`, ``…``) are copied untouched.
		if (!preg_match_all('/(`+)(.+?)\1/', $line, $spans, PREG_OFFSET_CAPTURE)) {
			return $this->rewriteText($line, $pageDir, $moves, $before, $after, $newPageDir, $count);
		}
		$result = '';
		$offset = 0;
		foreach ($spans[0] as [$span, $at]) {
			$result .= $this->rewriteText(substr($line, $offset, $at - $offset), $pageDir, $moves, $before, $after, $newPageDir, $count) . $span;
			$offset = $at + strlen($span);
		}
		return $result . $this->rewriteText(substr($line, $offset), $pageDir, $moves, $before, $after, $newPageDir, $count);
	}

	/** @param list<array{0: string, 1: string}> $moves */
	private function rewriteText(string $text, string $pageDir, array $moves, WikilinkIndex $before, WikilinkIndex $after, string $newPageDir, int &$count): string {
		$text = preg_replace_callback(self::WIKILINK, function (array $m) use ($pageDir, $moves, $before, $after, $newPageDir, &$count): string {
			[$whole, $bang, $target] = [$m[0], $m[1], $m[2]];
			$hash = strpos($target, '#');
			$name = $hash === false ? $target : substr($target, 0, $hash);
			$rest = $hash === false ? '' : substr($target, $hash);
			if (trim($name) === '') {
				return $whole; // [[#Heading]] on the same page
			}
			if ($bang === '!' && preg_match('/\.(?!md$)[a-z0-9]+$/i', trim($name))) {
				return $this->rewriteAssetEmbed($m, trim($name), $rest, $pageDir, $moves, $newPageDir, $count);
			}
			$old = $before->resolve($pageDir, trim($name));
			if ($old === null) {
				return $whole;
			}
			$new = self::mapThrough($old, $moves);
			if ($new === $old || $after->resolve($newPageDir, trim($name)) === $new) {
				return $whole; // unrelated, or the same text still finds the page
			}
			$count++;
			return $bang . '[[' . $this->wikiName($name, $new, $newPageDir, $after) . $rest . ($m[3] ?? '') . ']]';
		}, $text) ?? $text;

		return preg_replace_callback(self::MDLINK, function (array $m) use ($pageDir, $moves, $newPageDir, &$count): string {
			$href = $m[2];
			$angle = str_starts_with($href, '<');
			$raw = $angle ? substr($href, 1, -1) : $href;
			if ($raw === '' || str_starts_with($raw, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) {
				return $m[0]; // same-page anchor, URL, mailto:
			}
			$hash = strpos($raw, '#');
			$pathPart = $hash === false ? $raw : substr($raw, 0, $hash);
			$fragment = $hash === false ? '' : substr($raw, $hash);
			try {
				$old = $this->paths->resolve($pageDir, rawurldecode($pathPart));
			} catch (PathTraversalException) {
				return $m[0];
			}
			$new = self::mapThrough($old, $moves);
			if ($new === $old && $newPageDir === $pageDir) {
				return $m[0];
			}
			$target = str_starts_with($pathPart, '/') ? '/' . $new : self::relativePath($newPageDir, $new);
			if ($target === rawurldecode($pathPart)) {
				return $m[0];
			}
			$count++;
			$encoded = $angle ? $target : str_replace(' ', '%20', $target);
			return $m[1] . ($angle ? '<' . $encoded . $fragment . '>' : $encoded . $fragment);
		}, $text) ?? $text;
	}

	/**
	 * ![[image.png]] resolves relative to the page (see LinkResolver::resolveEmbed),
	 * so it must follow the image when it moved, and follow the page when the
	 * page moved.
	 *
	 * @param list<array{0: string, 1: string}> $moves
	 */
	private function rewriteAssetEmbed(array $m, string $name, string $rest, string $pageDir, array $moves, string $newPageDir, int &$count): string {
		try {
			$old = $this->paths->resolve($pageDir, $name);
		} catch (PathTraversalException) {
			return $m[0];
		}
		$new = self::mapThrough($old, $moves);
		if ($new === $old && $newPageDir === $pageDir) {
			return $m[0];
		}
		$target = self::relativePath($newPageDir, $new);
		if ($target === $name) {
			return $m[0];
		}
		$count++;
		return '![[' . $target . $rest . ($m[3] ?? '') . ']]';
	}

	/**
	 * New target text: the bare name when it finds the page unambiguously,
	 * else the full path. The ".md" extension is kept if the link had one.
	 */
	private function wikiName(string $oldName, string $newPath, string $pageDir, WikilinkIndex $after): string {
		$withExt = (bool) preg_match('/\.md\s*$/i', $oldName);
		$noExt = preg_replace('/\.md$/i', '', $newPath) ?? $newPath;
		$bare = basename($noExt);
		if (!str_contains($oldName, '/') && $after->resolve($pageDir, $bare) === $newPath) {
			return $withExt ? $bare . '.md' : $bare;
		}
		return $withExt ? $noExt . '.md' : $noExt;
	}

	/**
	 * The link index as it will be after the moves.
	 * @param list<array{0: string, 1: string}> $moves
	 */
	private function afterIndex(WikilinkIndex $before, array $moves): WikilinkIndex {
		$paths = array_map(fn (string $p) => self::mapThrough($p, $moves), $before->paths());
		$aliases = [];
		foreach ($before->aliases() as $path => $list) {
			$aliases[self::mapThrough((string) $path, $moves)] = $list;
		}
		return new WikilinkIndex($paths, $aliases);
	}

	/** Relative path from directory $fromDir to $path (both root-relative). */
	public static function relativePath(string $fromDir, string $path): string {
		$from = $fromDir === '' ? [] : explode('/', $fromDir);
		$target = explode('/', $path);
		$i = 0;
		while ($i < count($from) && $i < count($target) - 1 && $from[$i] === $target[$i]) {
			$i++;
		}
		return str_repeat('../', count($from) - $i) . implode('/', array_slice($target, $i));
	}
}
