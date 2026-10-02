<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\LinkResolver;
use OCA\MarkdownSite\Wiki\MarkdownRenderer;
use OCA\MarkdownSite\Wiki\NcUrlBuilder;
use OCA\MarkdownSite\Wiki\PathResolver;
use OCP\IURLGenerator;

/** Renders Markdown as the page at $path of a site would show it. */
class PageRenderer {
	public function __construct(
		private IndexBuilder $indexBuilder,
		private IURLGenerator $urlGenerator,
	) {
	}

	/** @return array{html: string, meta: array<string,mixed>, toc: list<array{level: int, text: string, id: string}>} */
	public function render(SiteContext $ctx, string $markdown, string $path): array {
		$dir = dirname($path);
		$currentDir = $dir === '.' ? '' : trim($dir, '/');
		$resolver = new LinkResolver(new PathResolver(), $this->indexBuilder->build($ctx->root));
		$urls = new NcUrlBuilder($this->urlGenerator, $ctx->site->getId());
		return (new MarkdownRenderer($resolver, $urls))->render($markdown, $currentDir);
	}
}
