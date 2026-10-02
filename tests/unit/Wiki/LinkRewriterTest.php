<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use PHPUnit\Framework\TestCase;

class LinkRewriterTest extends TestCase {
	private function index(): WikilinkIndex {
		return new WikilinkIndex([
			'Guides/Old.md',
			'Guides/Other.md',
			'Archive/Old.md',      // a homonym in another folder
			'Home.md',
			'Projects/Alpha.md',
			'Projects/Beta.md',
			'Elsewhere/Gamma.md',
		], ['Guides/Old.md' => ['Legacy']]);
	}

	private function rename(string $md, string $pageDir = 'Guides', string $to = 'Guides/New.md'): string {
		return (new LinkRewriter())->rewrite($md, $pageDir, 'Guides/Old.md', $to, $this->index());
	}

	public function testRewritesEachWikilinkForm(): void {
		$md = "[[Old]] [[Old|label]] [[Old#Setup]] [[Old#^abc]] ![[Old]] [[Guides/Old]] [[Old.md]]";
		$this->assertSame(
			"[[New]] [[New|label]] [[New#Setup]] [[New#^abc]] ![[New]] [[Guides/New]] [[New.md]]",
			$this->rename($md),
		);
	}

	public function testRewritesRelativeMarkdownLinks(): void {
		$this->assertSame('[x](../Guides/New.md#part)', $this->rename('[x](../Guides/Old.md#part)', 'Projects'));
		$this->assertSame('[x](New.md "title")', $this->rename('[x](Old.md "title")'));
		$this->assertSame('[x](/Guides/New.md)', $this->rename('[x](/Guides/Old.md)', 'Projects'));
	}

	public function testLeavesHomonymsAlone(): void {
		// From Archive/, [[Old]] means Archive/Old.md (nearest).
		$this->assertSame('[[Old]] [x](Old.md)', $this->rename('[[Old]] [x](Old.md)', 'Archive'));
	}

	public function testLeavesUnrelatedLinksAndAliasesAlone(): void {
		$md = "[[Other]] [[Legacy]] [[#Local]] [x](https://e.org/Old.md) [y](#Old)";
		$this->assertSame($md, $this->rename($md));
	}

	public function testIgnoresCode(): void {
		$md = "```\n[[Old]]\n```\n\n    [[Old]] indented\n\nInline `[[Old]]` and ``[x](Old.md)`` but [[Old]].\n~~~md\n[[Old]]\n~~~\n";
		$expected = "```\n[[Old]]\n```\n\n    [[Old]] indented\n\nInline `[[Old]]` and ``[x](Old.md)`` but [[New]].\n~~~md\n[[Old]]\n~~~\n";
		$this->assertSame($expected, $this->rename($md));
	}

	public function testBareNameGainsPathWhenItWouldFindAnotherPage(): void {
		// Guides/Old.md → Guides/Gamma.md. From Elsewhere/, a bare [[Gamma]]
		// would find Elsewhere/Gamma.md, so the link needs the full path.
		$this->assertSame('[[Guides/Gamma]]', $this->rename('[[Old]]', 'Elsewhere', 'Guides/Gamma.md'));
	}

	public function testUnchangedWhenTheSameTextStillFindsThePage(): void {
		// Moved next to the linking page: [[Old]] now finds the moved page first.
		$this->assertSame('[[Old]] [[Old|x]]', $this->rename('[[Old]] [[Old|x]]', 'Projects', 'Projects/Old.md'));
	}

	public function testMovedPageKeepsItsOwnRelativeLinksValid(): void {
		$md = "[a](../Home.md) [b](Other.md) ![img](img/pic.png) ![[logo.svg|200]] [[Other]] [c](https://x.y)";
		$out = (new LinkRewriter())->rewrite($md, 'Guides', 'Guides/Old.md', 'Projects/Deep/Old.md', $this->index(), 'Projects/Deep');
		$this->assertSame("[a](../../Home.md) [b](../../Guides/Other.md) ![img](../../Guides/img/pic.png) ![[../../Guides/logo.svg|200]] [[Other]] [c](https://x.y)", $out);
	}

	public function testImageEmbedsFollowAMovedFolder(): void {
		$out = (new LinkRewriter())->rewrite('![[Projects/pic.png]] ![[pic.png]]', '', 'Projects', 'Work', $this->index());
		$this->assertSame('![[Work/pic.png]] ![[pic.png]]', $out);
	}

	public function testFolderMoveRewritesLinksIntoTheFolder(): void {
		$rewriter = new LinkRewriter();
		$md = "[[Alpha]] [[Projects/Beta|b]] [x](Projects/Alpha.md) [[Gamma]]";
		$this->assertSame(
			"[[Alpha]] [[Work/Projects/Beta|b]] [x](Work/Projects/Alpha.md) [[Gamma]]",
			$rewriter->rewrite($md, '', 'Projects', 'Work/Projects', $this->index()),
		);
	}

	public function testPagesInsideAMovedFolderKeepRelativeLinksToEachOther(): void {
		$md = "[b](Beta.md) [h](../Home.md)";
		$out = (new LinkRewriter())->rewrite($md, 'Projects', 'Projects', 'Work/Projects', $this->index(), 'Work/Projects');
		$this->assertSame('[b](Beta.md) [h](../../Home.md)', $out);
	}

	public function testEncodesSpacesUnlessAngleBrackets(): void {
		$this->assertSame('[x](New%20name.md) [y](<New name.md>)',
			$this->rename('[x](Old.md) [y](<Old.md>)', 'Guides', 'Guides/New name.md'));
		$this->assertSame('[x](New%20name.md)', $this->rename('[x](Old.md)', 'Guides', 'Guides/New name.md'));
	}

	public function testCountAffected(): void {
		$md = "[[Old]] [[Other]] `[[Old]]` [x](Old.md) [[Legacy]]";
		$this->assertSame(2, (new LinkRewriter())->countAffected($md, 'Guides', [['Guides/Old.md', 'Guides/New.md']], $this->index()));
	}

	public function testSeveralMovesInOneRewrite(): void {
		// Folder Projects → Work, then its note Work/Projects.md → Work/Work.md.
		$index = new WikilinkIndex(['Projects/Projects.md', 'Projects/Alpha.md', 'Home.md']);
		$moves = [['Projects', 'Work'], ['Work/Projects.md', 'Work/Work.md']];
		$out = (new LinkRewriter())->rewriteMoves('[[Projects]] [[Alpha]] [n](Projects/Projects.md)', '', $moves, $index);
		$this->assertSame('[[Work]] [[Alpha]] [n](Work/Work.md)', $out);
	}

	public function testRelativePath(): void {
		$this->assertSame('b.md', LinkRewriter::relativePath('a', 'a/b.md'));
		$this->assertSame('../c/d.md', LinkRewriter::relativePath('a', 'c/d.md'));
		$this->assertSame('x/y.md', LinkRewriter::relativePath('', 'x/y.md'));
		$this->assertSame('../../z.md', LinkRewriter::relativePath('a/b', 'z.md'));
	}
}
