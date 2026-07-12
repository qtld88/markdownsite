<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\Folder;
use Symfony\Component\Yaml\Yaml;

class IndexBuilder {
	public function __construct(private ContentService $content) {
	}

	public function build(Folder $root): WikilinkIndex {
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
				$aliases[$path] = is_array($fm['aliases']) ? $fm['aliases'] : [(string) $fm['aliases']];
			}
		}
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
