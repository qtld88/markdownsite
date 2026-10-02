<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCA\MarkdownSite\Service\LinkUpdater;
use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

class LinkUpdaterTest extends TestCase {
	/** @var array<string,string> */
	private array $pages = [
		'Home.md' => "See [[Old]] and [x](Guides/Old.md).",
		'Guides/Old.md' => "Back [home](../Home.md). [[Other]]",
		'Guides/Other.md' => "Nothing about it.",
		'Notes.md' => "Mentions old only in text, [[Other]].",
	];

	private function updater(): LinkUpdater {
		$content = $this->createMock(ContentService::class);
		$content->method('getPageContent')->willReturnCallback(fn ($root, string $p) => $this->pages[$p]);
		$index = $this->createMock(IndexBuilder::class);
		$index->method('build')->willReturn(new WikilinkIndex(array_keys($this->pages)));
		return new LinkUpdater($content, $index, new LinkRewriter());
	}

	public function testPlansIncomingLinksAndTheMovedPagesOwnLinks(): void {
		$plan = $this->updater()->plan($this->createMock(Folder::class), [['Guides/Old.md', 'Archive/2026/New.md']]);
		$this->assertSame(['Home.md', 'Guides/Old.md'], array_keys($plan));
		$this->assertSame(['content' => 'See [[New]] and [x](Archive/2026/New.md).', 'count' => 2], $plan['Home.md']);
		$this->assertSame(['content' => 'Back [home](../../Home.md). [[Other]]', 'count' => 1], $plan['Guides/Old.md']);
	}

	public function testRenameInPlaceLeavesTheMovedPageAlone(): void {
		$plan = $this->updater()->plan($this->createMock(Folder::class), [['Guides/Old.md', 'Guides/New.md']]);
		$this->assertSame(['Home.md'], array_keys($plan));
		$this->assertSame(2, $plan['Home.md']['count']);
	}

	public function testNothingToDo(): void {
		$this->assertSame([], $this->updater()->plan($this->createMock(Folder::class), [['Notes.md', 'Notes2.md']]));
	}
}
