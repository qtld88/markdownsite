<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

class LinkTarget {
	public function __construct(
		public string $kind,   // 'page' | 'asset' | 'external' | 'broken'
		public string $path,   // root-relative path (page/asset), original href (external), or '' (broken)
	) {
	}
}
