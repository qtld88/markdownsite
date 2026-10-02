<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Search;

use OCA\MarkdownSite\Search\PlainText;
use PHPUnit\Framework\TestCase;

class PlainTextTest extends TestCase {
	public function testFrontmatterGivesTitleAndAliases(): void {
		$md = "---\ntitle: Password manager\naliases: [BW, Vault]\ntags: x\n---\n# Bitwarden\n\nBody";
		$out = PlainText::fromMarkdown($md, 'Bitwarden');
		$this->assertSame('Password manager', $out['title']);
		$this->assertSame(['BW', 'Vault'], $out['aliases']);
		$this->assertSame('Bitwarden' . "\n\n" . 'Body', $out['body']);
	}

	public function testSingleAliasString(): void {
		$out = PlainText::fromMarkdown("---\naliases: BW\n---\ntext");
		$this->assertSame(['BW'], $out['aliases']);
	}

	public function testTitleFallsBackToFirstH1ThenFileName(): void {
		$this->assertSame('First', PlainText::fromMarkdown("## Sub\n# First\n# Second", 'File')['title']);
		$this->assertSame('File', PlainText::fromMarkdown("## Only a sub", 'File')['title']);
	}

	public function testHeadingsAreCollectedWithoutMarkers(): void {
		$out = PlainText::fromMarkdown("# Guide\n\n## Install *now* ##\n\ntext\n\n### See [[Notes|the notes]]", '');
		$this->assertSame(['Guide', 'Install now', 'See the notes'], $out['headings']);
	}

	public function testLinkForms(): void {
		$md = "See [[Target|label]], [[Other Page]], [[Guide#Install]], [[#Usage]], ![[pic.png]], [text](http://x.y/z) and ![alt](img.png).";
		$this->assertSame('See label, Other Page, Guide Install, Usage, , text and .', PlainText::fromMarkdown($md)['body']);
	}

	public function testRemovesFormattingAndMarkers(): void {
		$md = "> [!note]- Title here\n> **bold** _em_ ==hi== ~~del~~ `code` <b>tag</b>\n\n- [ ] task\n1. one\n* star";
		$this->assertSame(
			"Title here\nbold em hi del code tag\n\ntask\none\nstar",
			PlainText::fromMarkdown($md)['body'],
		);
	}

	public function testKeepsSnakeCaseWords(): void {
		$this->assertSame('use file_name and 2*3', PlainText::fromMarkdown('use file_name and 2*3')['body']);
	}

	public function testCodeIsKeptButFencesDropped(): void {
		$md = "Intro\n\n```bash\n# not a heading\necho **raw**\n```\n\nOutro";
		$out = PlainText::fromMarkdown($md);
		$this->assertSame("Intro\n\n# not a heading\necho **raw**\n\nOutro", $out['body']);
		$this->assertSame([], $out['headings']);
	}
}
