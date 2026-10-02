<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Db\SearchEntry;
use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchIndexerTest extends TestCase {
	private ContentService&MockObject $content;
	private SearchMapper&MockObject $index;
	private SiteMapper&MockObject $sites;
	private Folder&MockObject $root;
	private Site $site;

	protected function setUp(): void {
		$this->content = $this->createMock(ContentService::class);
		$this->index = $this->createMock(SearchMapper::class);
		$this->sites = $this->createMock(SiteMapper::class);
		$this->root = $this->createMock(Folder::class);
		$this->root->method('getEtag')->willReturn('root-2');
		$this->site = new Site();
		$this->site->setId(7);
		$this->site->setSearchEtag('root-1');
	}

	private function indexer(): SearchIndexer {
		return new SearchIndexer($this->content, $this->index, $this->sites);
	}

	private function file(string $content, string $etag): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getEtag')->willReturn($etag);
		return $file;
	}

	public function testUnchangedRootReadsNothing(): void {
		$this->site->setSearchEtag('root-2');
		$this->content->expects($this->never())->method('listMarkdownEtags');
		$this->content->expects($this->never())->method('getChild');
		$this->index->expects($this->never())->method('upsert');
		$this->indexer()->refresh($this->site, $this->root);
	}

	public function testIndexesAddedPageAndStoresRootEtag(): void {
		$this->content->method('listMarkdownEtags')->willReturn(['Guides/Café.md' => 'e1']);
		$this->index->method('etagsBySite')->with(7)->willReturn([]);
		$this->content->method('getChild')->with($this->root, 'Guides/Café.md')
			->willReturn($this->file("# Café crème\n\nUn **bon** café.", 'e1'));
		$this->index->expects($this->once())->method('upsert')->with($this->callback(
			fn (SearchEntry $e) => $e->getSiteId() === 7
				&& $e->getPath() === 'Guides/Café.md'
				&& $e->getEtag() === 'e1'
				&& $e->getTitle() === 'Café crème'
				&& $e->getTitleNorm() === 'cafe creme'
				&& $e->getHeadingsNorm() === 'cafe creme'
				&& $e->getBody() === "Café crème\n\nUn bon café."
				&& $e->getBodyNorm() === "cafe creme\n\nun bon cafe.",
		));
		$this->sites->expects($this->once())->method('update')->with($this->site);
		$this->indexer()->refresh($this->site, $this->root);
		$this->assertSame('root-2', $this->site->getSearchEtag());
	}

	public function testReindexesOnlyModifiedPages(): void {
		$this->content->method('listMarkdownEtags')->willReturn(['A.md' => 'same', 'B.md' => 'new']);
		$this->index->method('etagsBySite')->willReturn(['A.md' => 'same', 'B.md' => 'old']);
		$this->content->expects($this->once())->method('getChild')->with($this->root, 'B.md')
			->willReturn($this->file('B text', 'new'));
		$this->index->expects($this->once())->method('upsert');
		$this->index->expects($this->never())->method('deletePaths');
		$this->indexer()->refresh($this->site, $this->root);
	}

	public function testDeletesRowsOfRemovedPages(): void {
		$this->content->method('listMarkdownEtags')->willReturn(['A.md' => 'same']);
		$this->index->method('etagsBySite')->willReturn(['A.md' => 'same', 'Gone.md' => 'x']);
		$this->content->expects($this->never())->method('getChild');
		$this->index->expects($this->once())->method('deletePaths')->with(7, ['Gone.md']);
		$this->indexer()->refresh($this->site, $this->root);
	}

	public function testIndexPageRemovesAMissingPage(): void {
		$this->content->method('getChild')->willThrowException(new NotFoundException('x'));
		$this->index->expects($this->once())->method('deletePaths')->with(7, ['Old.md']);
		$this->indexer()->indexPage($this->site, $this->root, 'Old.md');
	}

	public function testTitleFallsBackToFileName(): void {
		$this->content->method('getChild')->willReturn($this->file('no heading here', 'e'));
		$this->index->expects($this->once())->method('upsert')->with($this->callback(
			fn (SearchEntry $e) => $e->getTitle() === 'Meeting notes',
		));
		$this->indexer()->indexPage($this->site, $this->root, 'Notes/Meeting notes.md');
	}
}
