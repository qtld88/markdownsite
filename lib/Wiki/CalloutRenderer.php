<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

class CalloutRenderer implements NodeRendererInterface {
	public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable {
		if (!$node instanceof Callout) {
			throw new \InvalidArgumentException('Incompatible node type: ' . $node::class);
		}

		$title = '';
		$body = [];
		foreach ($node->children() as $child) {
			if ($child instanceof CalloutTitle) {
				$title = $childRenderer->renderNodes($child->children());
			} else {
				$body[] = $child;
			}
		}

		$foldable = $node->getFold() !== Callout::FOLD_NONE;
		$titleInner = new HtmlElement('span', ['class' => 'mds-callout-icon', 'aria-hidden' => 'true'])
			. new HtmlElement('span', ['class' => 'mds-callout-title-text'], $title);
		$content = new HtmlElement(
			'div',
			['class' => 'mds-callout-content'],
			$childRenderer->renderNodes($body),
		);

		$attrs = ['class' => 'mds-callout', 'data-callout' => $node->getType()];
		if ($foldable) {
			if ($node->getFold() === Callout::FOLD_OPEN) {
				$attrs['open'] = '';
			}
			return new HtmlElement(
				'details',
				$attrs,
				new HtmlElement('summary', ['class' => 'mds-callout-title'], $titleInner) . "\n" . $content,
			);
		}

		return new HtmlElement(
			'div',
			$attrs,
			new HtmlElement('div', ['class' => 'mds-callout-title'], $titleInner) . "\n" . $content,
		);
	}
}
