<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\LinkScanner;
use PHPUnit\Framework\TestCase;

class LinkScannerTest extends TestCase {
	public function testFindsWikilinksAndMarkdownLinksToPages(): void {
		$md = "See [[Old Page#Intro|the page]] and [x](Guides/Old%20Page.md#top).\n![[photo.png]] [web](https://a.b/c.md) [[#Here]]\n";
		$first = explode("\n", $md)[0];
		$found = (new LinkScanner())->scan($md);
		$this->assertCount(2, $found);
		$this->assertSame(['wikilink', 'Old Page', 0], [$found[0]['kind'], $found[0]['target'], $found[0]['line']]);
		$this->assertSame('Old Page', substr($first, $found[0]['offset'], $found[0]['length']));
		$this->assertSame(['link', 'Guides/Old%20Page.md'], [$found[1]['kind'], $found[1]['target']]);
		$this->assertSame('Guides/Old%20Page.md', substr($first, $found[1]['offset'], $found[1]['length']));
	}

	public function testSkipsCode(): void {
		$md = "`[[Inline]]` then [[Real]]\n\n```\n[[Fenced]]\n```\n\n    [[Indented]]\n";
		$this->assertSame(['Real'], array_column((new LinkScanner())->scan($md), 'target'));
	}

	public function testAngleBracketDestination(): void {
		$found = (new LinkScanner())->scan("[x](<My Notes/A b.md>)\n");
		$this->assertCount(1, $found);
		$this->assertTrue($found[0]['angle']);
		$this->assertSame('My Notes/A b.md', $found[0]['target']);
	}
}
