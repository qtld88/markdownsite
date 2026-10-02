<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerHelper;

class MarkdownRenderer {
	/**
	 * Heading ids are "mds-<slug>": a bare slug such as "content" or "header"
	 * would collide with ids in Nextcloud's own page layout. Links and the
	 * TOC carry the bare slug; the frontend adds the prefix when it looks a
	 * heading up.
	 */
	public const HEADING_ID_PREFIX = 'mds';

	public function __construct(
		private LinkResolver $resolver,
		private UrlBuilder $urls,
	) {
	}

	/**
	 * @return array{html: string, meta: array<string,mixed>, toc: list<array{level: int, text: string, id: string}>}
	 */
	public function render(string $markdown, string $currentDir): array {
		$environment = new Environment([
			'html_input' => 'strip',
			'allow_unsafe_links' => false,
			'slug_normalizer' => ['instance' => $this->resolver->slugNormalizer()],
			'heading_permalink' => [
				'apply_id_to_heading' => true,
				'id_prefix' => self::HEADING_ID_PREFIX,
				'fragment_prefix' => '',
				'insert' => 'none',
			],
		]);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new FrontMatterExtension());
		$environment->addExtension(new TableExtension());
		$environment->addExtension(new TaskListExtension());
		$environment->addExtension(new StrikethroughExtension());
		$environment->addExtension(new HighlightExtension());
		$environment->addExtension(new HeadingPermalinkExtension());
		$environment->addExtension(new CalloutExtension($markdown));
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

		return [
			'html' => (string) $result->getContent(),
			'meta' => $meta,
			'toc' => $this->toc($result->getDocument()),
		];
	}

	/** @return list<array{level: int, text: string, id: string}> */
	private function toc(Document $document): array {
		$toc = [];
		$prefix = self::HEADING_ID_PREFIX . '-';
		foreach ($document->iterator(NodeIterator::FLAG_BLOCKS_ONLY) as $node) {
			if (!$node instanceof Heading) {
				continue;
			}
			$id = (string) $node->data->get('attributes/id', '');
			$toc[] = [
				'level' => $node->getLevel(),
				'text' => trim(StringContainerHelper::getChildText($node, [RawMarkupContainerInterface::class])),
				'id' => str_starts_with($id, $prefix) ? substr($id, strlen($prefix)) : $id,
			];
		}
		return $toc;
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
		$fragment = $t->fragment === '' ? '' : '#' . $t->fragment;
		return match ($t->kind) {
			'page' => $this->urls->page($t->path) . $fragment,
			'asset' => $this->urls->asset($t->path),
			'external' => $t->path,
			'anchor' => $fragment,
			default => '#',
		};
	}
}
