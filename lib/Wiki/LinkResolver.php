<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Normalizer\SlugNormalizer;
use League\CommonMark\Normalizer\TextNormalizerInterface;

class LinkResolver {
	private TextNormalizerInterface $slugs;

	public function __construct(
		private PathResolver $paths,
		private WikilinkIndex $index,
		?TextNormalizerInterface $slugs = null,
	) {
		$this->slugs = $slugs ?? new SlugNormalizer();
	}

	/**
	 * The normalizer that turns heading text into ids. MarkdownRenderer hands
	 * this same instance to CommonMark so link fragments and heading ids agree.
	 */
	public function slugNormalizer(): TextNormalizerInterface {
		return $this->slugs;
	}

	/** Standard markdown link/image href: [text](href) or ![alt](href). */
	public function resolveHref(string $currentDir, string $href): LinkTarget {
		$href = trim($href);
		if ($this->isExternal($href)) {
			return new LinkTarget('external', $href);
		}
		[$href, $fragment] = $this->splitFragment($href);
		try {
			$resolved = $this->paths->resolve($currentDir, rawurldecode($href));
		} catch (PathTraversalException) {
			return new LinkTarget('broken', '');
		}
		return $this->isMarkdown($resolved)
			? new LinkTarget('page', $resolved, $fragment)
			: new LinkTarget('asset', $resolved);
	}

	/** Obsidian wikilink [[target]], [[target#Heading]] or [[#Heading]]. */
	public function resolveWikilink(string $currentDir, string $target): LinkTarget {
		[$name, $heading] = $this->splitFragment(trim($target));
		$fragment = $this->headingSlug($heading);
		if (trim($name) === '') {
			return $fragment === ''
				? new LinkTarget('broken', '')
				: new LinkTarget('anchor', '', $fragment);
		}
		$resolved = $this->index->resolve($currentDir, $name);
		return $resolved === null
			? new LinkTarget('broken', '')
			: new LinkTarget('page', $resolved, $fragment);
	}

	/** Obsidian embed ![[target]] — a note embed or an image/attachment. */
	public function resolveEmbed(string $currentDir, string $target): LinkTarget {
		[$target] = $this->splitFragment(trim($target));
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

	/**
	 * Heading part of a wikilink as a heading id. `Heading#Sub` (Obsidian's
	 * nested form) targets the last heading; `^block` references have no
	 * matching element, so they yield no fragment.
	 */
	private function headingSlug(string $heading): string {
		$parts = explode('#', $heading);
		$last = trim(end($parts));
		if ($last === '' || str_starts_with($last, '^')) {
			return '';
		}
		return $this->slugs->normalize($last);
	}

	/** @return array{0: string, 1: string} [before '#', after '#'] */
	private function splitFragment(string $href): array {
		$pos = strpos($href, '#');
		return $pos === false ? [$href, ''] : [substr($href, 0, $pos), substr($href, $pos + 1)];
	}

	private function isExternal(string $href): bool {
		return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)
			|| str_starts_with($href, 'mailto:')
			|| str_starts_with($href, '#');
	}

	private function isMarkdown(string $path): bool {
		return (bool) preg_match('/\.md$/i', $path);
	}

	private function hasExtension(string $path): bool {
		return (bool) preg_match('/\.[a-z0-9]+$/i', basename($path));
	}
}
