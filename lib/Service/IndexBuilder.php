<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use Symfony\Component\Yaml\Yaml;

class IndexBuilder {
	private const TTL = 86400;

	private ICache $cache;

	public function __construct(
		private ContentService $content,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('markdownsite');
	}

	/**
	 * The site's link index, cached under the root folder's etag, which
	 * changes whenever any file below it changes. On a miss, only the pages
	 * whose etag changed since the last build are read again (for aliases):
	 * an edit costs one file read, not one per page.
	 */
	public function build(Folder $root): WikilinkIndex {
		$key = 'linkindex/' . $root->getId() . '/' . $root->getEtag();
		$cached = $this->cache->get($key);
		if (is_array($cached) && is_array($cached['paths'] ?? null) && is_array($cached['aliases'] ?? null)) {
			return new WikilinkIndex($cached['paths'], $cached['aliases']);
		}

		$filesKey = 'linkfiles/' . $root->getId();
		$known = $this->cache->get($filesKey);
		$known = is_array($known) ? $known : [];
		$files = [];
		$aliases = [];
		foreach ($this->content->listMarkdownEtags($root) as $path => $etag) {
			$path = (string) $path;
			$entry = $known[$path] ?? null;
			if (!is_array($entry) || ($entry['etag'] ?? null) !== $etag || !array_key_exists('aliases', $entry)) {
				try {
					$raw = $this->content->getPageContent($root, $path);
				} catch (\Throwable) {
					continue;
				}
				$entry = ['etag' => $etag, 'aliases' => $this->aliasesOf($raw)];
			}
			$files[$path] = $entry;
			if (is_array($entry['aliases'])) {
				$aliases[$path] = $entry['aliases'];
			}
		}
		$paths = array_map('strval', array_keys($files));
		$this->cache->set($filesKey, $files, self::TTL);
		$this->cache->set($key, ['paths' => $paths, 'aliases' => $aliases], self::TTL);
		return new WikilinkIndex($paths, $aliases);
	}

	/** @return list<string>|null null when the frontmatter has no `aliases` */
	private function aliasesOf(string $raw): ?array {
		$fm = $this->frontmatter($raw);
		if (!isset($fm['aliases'])) {
			return null;
		}
		return array_values(array_map('strval', is_array($fm['aliases']) ? $fm['aliases'] : [(string) $fm['aliases']]));
	}

	/** @return array<string,mixed> */
	private function frontmatter(string $raw): array {
		if (!str_starts_with($raw, "---")) {
			return [];
		}
		if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
			return [];
		}
		try {
			$data = Yaml::parse($m[1]);
			return is_array($data) ? $data : [];
		} catch (\Throwable) {
			return [];
		}
	}
}
