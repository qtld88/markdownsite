<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;

/**
 * Turns `> [!type]± Title` block quotes into Callout nodes, as Obsidian does.
 */
class CalloutExtension implements ExtensionInterface {
	public function register(EnvironmentBuilderInterface $environment): void {
		$environment->addRenderer(Callout::class, new CalloutRenderer());
		$environment->addEventListener(DocumentParsedEvent::class, $this->onDocumentParsed(...), -50);
	}

	private function onDocumentParsed(DocumentParsedEvent $event): void {
		$quotes = [];
		foreach ($event->getDocument()->iterator() as $node) {
			if ($node instanceof BlockQuote) {
				$quotes[] = $node;
			}
		}
		foreach ($quotes as $quote) {
			$this->convert($quote);
		}
	}

	private function convert(BlockQuote $quote): void {
		$paragraph = $quote->firstChild();
		if (!$paragraph instanceof Paragraph) {
			return;
		}
		$first = $paragraph->firstChild();
		if (!$first instanceof Text
			|| !preg_match('/^\[!([A-Za-z0-9_-]+)\]([+-]?)[ \t]*/', $first->getLiteral(), $m)) {
			return;
		}

		$callout = new Callout(strtolower($m[1]), $m[2]);
		$title = new CalloutTitle();

		// Title = rest of the first line (everything up to the first line break).
		$first->setLiteral(substr($first->getLiteral(), strlen($m[0])));
		$node = $first;
		while ($node !== null && !$node instanceof Newline) {
			$next = $node->next();
			$title->appendChild($node);
			$node = $next;
		}
		if ($node instanceof Newline) {
			$node->detach();
		}
		if ($title->firstChild() === $title->lastChild() && ($only = $title->firstChild()) instanceof Text
			&& $only->getLiteral() === '') {
			$only->setLiteral(ucfirst($callout->getType()));
		} elseif ($title->firstChild() === null) {
			$title->appendChild(new Text(ucfirst($callout->getType())));
		}
		if ($paragraph->firstChild() === null) {
			$paragraph->detach();
		}

		$children = [];
		foreach ($quote->children() as $child) {
			$children[] = $child;
		}
		$callout->appendChild($title);
		foreach ($children as $child) {
			$callout->appendChild($child);
		}
		$quote->replaceWith($callout);
	}
}
