<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

interface UrlBuilder {
	/** URL for an internal wiki page (root-relative .md path). */
	public function page(string $path): string;

	/** URL for a raw asset (root-relative path). */
	public function asset(string $path): string;
}
