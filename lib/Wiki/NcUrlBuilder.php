<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use OCP\IURLGenerator;

class NcUrlBuilder implements UrlBuilder {
	public function __construct(
		private IURLGenerator $urls,
		private int $siteId,
	) {
	}

	public function page(string $path): string {
		return $this->urls->linkToRoute('markdownsite.page.page', [
			'siteId' => $this->siteId,
			'path' => $path,
		]);
	}

	public function asset(string $path): string {
		return $this->urls->linkToRoute('markdownsite.asset.file', [
			'siteId' => $this->siteId,
			'path' => $path,
		]);
	}
}
