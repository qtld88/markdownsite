<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

class WikilinkInlineParser implements InlineParserInterface {
	public function __construct(
		private LinkResolver $resolver,
		private UrlBuilder $urls,
		private string $currentDir,
	) {
	}

	public function getMatchDefinition(): InlineParserMatch {
		return InlineParserMatch::regex('\[\[([^\]|]+)(?:\|([^\]]+))?\]\]');
	}

	public function parse(InlineParserContext $ctx): bool {
		$matches = $ctx->getSubMatches();
		$ctx->getCursor()->advanceBy($ctx->getFullMatchLength());

		$targetRaw = trim($matches[0] ?? '');
		$label = isset($matches[1]) && $matches[1] !== '' ? trim($matches[1]) : $targetRaw;

		$target = $this->resolver->resolveWikilink($this->currentDir, $targetRaw);
		$url = $target->kind === 'page' ? $this->urls->page($target->path) : '#';

		$link = new Link($url, $label);
		// Mark as already-resolved so the post-parse link-rewrite pass in
		// MarkdownRenderer (which handles plain [text](href) links) leaves
		// this node's URL alone.
		$link->data->set('markdownsite/resolved', true);
		if ($target->kind === 'broken') {
			$link->data->append('attributes/class', 'markdownsite-broken');
		}
		$ctx->getContainer()->appendChild($link);
		return true;
	}
}
