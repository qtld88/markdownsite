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
	 * The site's link index. Building it reads every page (for aliases), so
	 * it is cached under the root folder's etag, which changes whenever any
	 * file below it changes: an edit invalidates the cache by itself.
	 */
	public function build(Folder $root): WikilinkIndex {
		$key = 'linkindex/' . $root->getId() . '/' . $root->getEtag();
		$cached = $this->cache->get($key);
		if (is_array($cached) && is_array($cached['paths'] ?? null) && is_array($cached['aliases'] ?? null)) {
			return new WikilinkIndex($cached['paths'], $cached['aliases']);
		}

		$paths = $this->content->listMarkdownPaths($root);
		$aliases = [];
		foreach ($paths as $path) {
			try {
				$raw = $this->content->getPageContent($root, $path);
			} catch (\Throwable) {
				continue;
			}
			$fm = $this->frontmatter($raw);
			if (isset($fm['aliases'])) {
				$aliases[$path] = array_map('strval', is_array($fm['aliases']) ? $fm['aliases'] : [(string) $fm['aliases']]);
			}
		}
		$this->cache->set($key, ['paths' => $paths, 'aliases' => $aliases], self::TTL);
		return new WikilinkIndex($paths, $aliases);
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
