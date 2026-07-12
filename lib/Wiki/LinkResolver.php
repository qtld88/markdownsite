<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

class LinkResolver {
	public function __construct(
		private PathResolver $paths,
		private WikilinkIndex $index,
	) {
	}

	/** Standard markdown link/image href: [text](href) or ![alt](href). */
	public function resolveHref(string $currentDir, string $href): LinkTarget {
		$href = trim($href);
		if ($this->isExternal($href)) {
			return new LinkTarget('external', $href);
		}
		$href = $this->stripFragment($href);
		try {
			$resolved = $this->paths->resolve($currentDir, rawurldecode($href));
		} catch (PathTraversalException) {
			return new LinkTarget('broken', '');
		}
		return new LinkTarget($this->isMarkdown($resolved) ? 'page' : 'asset', $resolved);
	}

	/** Obsidian wikilink [[target]] or [[target|alias]]. */
	public function resolveWikilink(string $currentDir, string $target): LinkTarget {
		$resolved = $this->index->resolve($currentDir, $this->stripFragment(trim($target)));
		return $resolved === null
			? new LinkTarget('broken', '')
			: new LinkTarget('page', $resolved);
	}

	/** Obsidian embed ![[target]] — a note embed or an image/attachment. */
	public function resolveEmbed(string $currentDir, string $target): LinkTarget {
		$target = $this->stripFragment(trim($target));
		if ($this->isMarkdown($target) || !$this->hasExtension($target)) {
			$resolved = $this->index->resolve($currentDir, $target);
			return $resolved === null
				? new LinkTarget('broken', '')
				: new LinkTarget('page', $resolved);
		}
		// image / attachment embed: resolve like a path, first by index basename then relative
		$byIndex = $this->index->resolve($currentDir, $target);
		if ($byIndex !== null) {
			return new LinkTarget('asset', $byIndex);
		}
		try {
			$resolved = $this->paths->resolve($currentDir, $target);
		} catch (PathTraversalException) {
			return new LinkTarget('broken', '');
		}
		return new LinkTarget('asset', $resolved);
	}

	private function isExternal(string $href): bool {
		return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)
			|| str_starts_with($href, 'mailto:')
			|| str_starts_with($href, '#');
	}

	private function stripFragment(string $href): string {
		$pos = strpos($href, '#');
		return $pos === false ? $href : substr($href, 0, $pos);
	}

	private function isMarkdown(string $path): bool {
		return (bool) preg_match('/\.md$/i', $path);
	}

	private function hasExtension(string $path): bool {
		return (bool) preg_match('/\.[a-z0-9]+$/i', basename($path));
	}
}
