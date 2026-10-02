<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

class LinkTarget {
	public function __construct(
		public string $kind,   // 'page' | 'asset' | 'external' | 'anchor' | 'broken'
		public string $path,   // root-relative path (page/asset), original href (external), or '' (anchor/broken)
		public string $fragment = '', // heading id without '#' (page/anchor), or ''
	) {
	}
}
