<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * Obsidian-style callout (`> [!note] Title`). First child is always a CalloutTitle.
 */
class Callout extends AbstractBlock {
	public const FOLD_NONE = '';
	public const FOLD_OPEN = '+';
	public const FOLD_CLOSED = '-';

	public function __construct(
		private string $type,
		private string $fold = self::FOLD_NONE,
	) {
		parent::__construct();
	}

	public function getType(): string {
		return $this->type;
	}

	public function getFold(): string {
		return $this->fold;
	}
}
