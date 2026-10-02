<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

/**
 * Validates names and paths typed by editors before anything is created or
 * moved. Paths are relative to the site root, '/'-separated.
 */
class NameValidator {
	/** Characters Nextcloud refuses in file names, plus control characters. */
	private const FORBIDDEN = '/[\\\\:*?"<>|\x00-\x1F\x7F]/';

	/**
	 * Normalised folder path: trimmed segments joined by '/'.
	 * @throws \InvalidArgumentException with a short reason
	 */
	public static function folderPath(string $path): string {
		$segments = explode('/', trim($path));
		foreach ($segments as $i => $segment) {
			$segments[$i] = self::segment($segment);
		}
		return implode('/', $segments);
	}

	/**
	 * Normalised page path, always ending in ".md" (added when missing).
	 * @throws \InvalidArgumentException
	 */
	public static function pagePath(string $path): string {
		$path = self::folderPath($path);
		return preg_match('/\.md$/i', $path) ? $path : $path . '.md';
	}

	private static function segment(string $segment): string {
		$segment = trim($segment);
		if ($segment === '') {
			throw new \InvalidArgumentException('empty-segment');
		}
		if ($segment === '..' || $segment === '.') {
			throw new \InvalidArgumentException('dot-segment');
		}
		if (str_starts_with($segment, '.')) {
			throw new \InvalidArgumentException('hidden-name');
		}
		if (preg_match(self::FORBIDDEN, $segment)) {
			throw new \InvalidArgumentException('forbidden-character');
		}
		if (strlen($segment) > 250) {
			throw new \InvalidArgumentException('name-too-long');
		}
		return $segment;
	}
}
