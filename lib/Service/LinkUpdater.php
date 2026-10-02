<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\Folder;

/** Finds the pages of a site whose links change when pages or folders move. */
class LinkUpdater {
	public function __construct(
		private ContentService $content,
		private IndexBuilder $indexBuilder,
		private LinkRewriter $rewriter,
	) {
	}

	/**
	 * Every page whose text changes, keyed by its path BEFORE the moves.
	 * Must run before the files are moved.
	 *
	 * @param list<array{0: string, 1: string}> $moves [from, to] pairs, in order
	 * @return array<string, array{content: string, count: int}>
	 */
	public function plan(Folder $root, array $moves): array {
		$before = $this->indexBuilder->build($root);
		$needles = $this->needles($before, $moves);
		$plan = [];
		foreach ($before->paths() as $path) {
			$newPath = LinkRewriter::mapThrough($path, $moves);
			try {
				$markdown = $this->content->getPageContent($root, $path);
			} catch (\Throwable) {
				continue;
			}
			// Pages that stay put can only be affected if they mention a moved name.
			if ($newPath === $path && !$this->mentions($markdown, $needles)) {
				continue;
			}
			[$rewritten, $count] = $this->rewriter->rewriteAndCount($markdown, self::dir($path), $moves, $before, self::dir($newPath));
			if ($count > 0) {
				$plan[$path] = ['content' => $rewritten, 'count' => $count];
			}
		}
		return $plan;
	}

	public static function dir(string $path): string {
		$dir = dirname($path);
		return $dir === '.' ? '' : $dir;
	}

	/**
	 * Lower-cased strings one of which any affected link must contain:
	 * moved names (page basenames, folder names), their aliases, and the
	 * %20-encoded form used in Markdown links.
	 *
	 * @param list<array{0: string, 1: string}> $moves
	 * @return string[]
	 */
	private function needles(WikilinkIndex $before, array $moves): array {
		$names = [];
		foreach ($moves as [$from]) {
			$names[] = basename(preg_replace('/\.md$/i', '', $from) ?? $from);
		}
		$aliases = $before->aliases();
		foreach ($before->paths() as $path) {
			if (LinkRewriter::mapThrough($path, $moves) !== $path) {
				$names[] = basename(preg_replace('/\.md$/i', '', $path) ?? $path);
				foreach ($aliases[$path] ?? [] as $alias) {
					$names[] = $alias;
				}
			}
		}
		$needles = [];
		foreach ($names as $name) {
			$name = mb_strtolower(trim($name));
			if ($name !== '') {
				$needles[$name] = true;
				$needles[str_replace(' ', '%20', $name)] = true;
			}
		}
		return array_keys($needles);
	}

	/** @param string[] $needles */
	private function mentions(string $markdown, array $needles): bool {
		$lower = mb_strtolower($markdown);
		foreach ($needles as $needle) {
			if (str_contains($lower, $needle)) {
				return true;
			}
		}
		return false;
	}
}
