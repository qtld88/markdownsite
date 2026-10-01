<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

class MarkdownRenderer {
	public function __construct(
		private LinkResolver $resolver,
		private UrlBuilder $urls,
	) {
	}

	/**
	 * @return array{html: string, meta: array<string,mixed>}
	 */
	public function render(string $markdown, string $currentDir): array {
		$environment = new Environment([
			'html_input' => 'strip',
			'allow_unsafe_links' => false,
		]);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new FrontMatterExtension());
		$environment->addExtension(new TableExtension());
		$environment->addExtension(new TaskListExtension());
		$environment->addExtension(new StrikethroughExtension());
		$environment->addExtension(new HighlightExtension());
		$environment->addExtension(new CalloutExtension());
		// Priority must beat CommonMarkCoreExtension's OpenBracketParser (20),
		// CloseBracketParser (30) and BangParser (10) — otherwise those consume
		// the leading '['/'!' before our regex-based parsers ever see them.
		$environment->addInlineParser(new EmbedInlineParser($this->resolver, $this->urls, $currentDir), 100);
		$environment->addInlineParser(new WikilinkInlineParser($this->resolver, $this->urls, $currentDir), 100);

		// Rewrite standard links/images (which commonmark already parsed) after
		// the document is fully parsed. $currentDir is captured directly by this
		// closure, so there's no need to thread it through node data.
		$environment->addEventListener(
			DocumentParsedEvent::class,
			function (DocumentParsedEvent $e) use ($currentDir): void {
				foreach ($e->getDocument()->iterator() as $node) {
					if ($node instanceof Link) {
						$this->rewriteLink($node, $currentDir);
					} elseif ($node instanceof Image) {
						$this->rewriteImage($node, $currentDir);
					}
				}
			},
			-100,
		);

		$converter = new MarkdownConverter($environment);
		$result = $converter->convert($markdown);

		$meta = [];
		if ($result instanceof RenderedContentWithFrontMatter) {
			$fm = $result->getFrontMatter();
			$meta = is_array($fm) ? $fm : [];
		}

		return ['html' => (string) $result->getContent(), 'meta' => $meta];
	}

	private function rewriteLink(Link $node, string $currentDir): void {
		// Nodes created by WikilinkInlineParser/EmbedInlineParser already have
		// a final app URL; only rewrite plain [text](href) links here.
		if ($node->data->get('markdownsite/resolved', false)) {
			return;
		}
		$target = $this->resolver->resolveHref($currentDir, $node->getUrl());
		$node->setUrl($this->targetUrl($target));
		if ($target->kind === 'broken') {
			$node->data->append('attributes/class', 'markdownsite-broken');
		}
	}

	private function rewriteImage(Image $node, string $currentDir): void {
		if ($node->data->get('markdownsite/resolved', false)) {
			return;
		}
		$target = $this->resolver->resolveHref($currentDir, $node->getUrl());
		$node->setUrl($this->targetUrl($target));
	}

	private function targetUrl(LinkTarget $t): string {
		return match ($t->kind) {
			'page' => $this->urls->page($t->path),
			'asset' => $this->urls->asset($t->path),
			'external' => $t->path,
			default => '#',
		};
	}
}
