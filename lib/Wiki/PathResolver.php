<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

class PathResolver {
	/**
	 * Resolve $target against $currentDir, returning a path relative to the
	 * site root (no leading slash). $currentDir is the directory of the current
	 * page relative to root ('' for root). A leading '/' on $target means
	 * root-relative. Throws if the result escapes the root.
	 */
	public function resolve(string $currentDir, string $target): string {
		$target = trim($target);
		if (str_starts_with($target, '/')) {
			$base = [];
			$target = ltrim($target, '/');
		} else {
			$base = $currentDir === '' ? [] : explode('/', trim($currentDir, '/'));
		}

		foreach (explode('/', $target) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				if (count($base) === 0) {
					throw new PathTraversalException('Path escapes site root: ' . $target);
				}
				array_pop($base);
				continue;
			}
			$base[] = $segment;
		}

		return implode('/', $base);
	}
}
