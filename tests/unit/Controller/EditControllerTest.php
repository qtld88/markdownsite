<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Controller;

use OCA\MarkdownSite\Controller\EditController;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Service\AttachmentLocator;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\LinkUpdater;
use OCA\MarkdownSite\Service\PageRenderer;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCA\MarkdownSite\Service\SiteContext;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EditControllerTest extends TestCase {
	private Site $site;
	private Folder&MockObject $root;
	private ContentService&MockObject $content;
	private LinkUpdater&MockObject $links;
	private SearchIndexer&MockObject $search;
	private IRequest&MockObject $request;
	private string $role = 'editor';
	/** @var array<string,Node> path => node, as getChild() sees them */
	private array $nodes = [];

	protected function setUp(): void {
		$this->site = new Site();
		$this->site->setId(4);
		$this->root = $this->createMock(Folder::class);
		$this->root->method('isUpdateable')->willReturn(true);
		$this->root->method('getPath')->willReturn('/alice/files/Wiki');
		$this->content = $this->createMock(ContentService::class);
		$this->content->method('getChild')->willReturnCallback(function ($root, string $path): Node {
			return $this->nodes[trim($path, '/')] ?? throw new NotFoundException($path);
		});
		$this->links = $this->createMock(LinkUpdater::class);
		$this->search = $this->createMock(SearchIndexer::class);
		$this->request = $this->createMock(IRequest::class);
	}

	private function controller(): EditController {
		$resolver = $this->createMock(SiteContextResolver::class);
		$resolver->method('resolve')->willReturnCallback(fn () => new SiteContext($this->site, $this->root, $this->role));
		return new EditController(
			$this->request, $resolver, $this->content, $this->links, new AttachmentLocator(),
			$this->search, $this->createMock(PageRenderer::class),
		);
	}

	private function file(string $path, string $content = '', string $etag = 'e1'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getEtag')->willReturn($etag);
		return $this->nodes[$path] = $file;
	}

	private function folder(string $path): Folder&MockObject {
		return $this->nodes[$path] = $this->createMock(Folder::class);
	}

	public function testSaveWritesAndReturnsTheNewEtag(): void {
		$file = $this->createMock(File::class);
		$file->method('getEtag')->willReturnOnConsecutiveCalls('e1', 'e2');
		$file->expects($this->once())->method('putContent')->with('# New text');
		$this->nodes['Guides/Page.md'] = $file;
		$this->search->expects($this->once())->method('indexPage')->with($this->site, $this->root, 'Guides/Page.md');
		$response = $this->controller()->save(4, 'Guides/Page.md', '# New text', 'e1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['etag' => 'e2'], $response->getData());
	}

	public function testSaveWithStaleEtagIsAConflict(): void {
		$file = $this->file('Page.md', 'their text', 'e9');
		$file->expects($this->never())->method('putContent');
		$response = $this->controller()->save(4, 'Page.md', 'my text', 'e1');
		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'conflict', 'etag' => 'e9', 'content' => 'their text'], $response->getData());
	}

	public function testSaveOfADeletedPageIs404(): void {
		$this->assertSame(404, $this->controller()->save(4, 'Gone.md', 'x', 'e1')->getStatus());
	}

	public function testReaderIsRefused(): void {
		$this->role = 'reader';
		$file = $this->file('Page.md');
		$file->expects($this->never())->method('putContent');
		$response = $this->controller()->save(4, 'Page.md', 'x', 'e1');
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());
	}

	public function testReadOnlyFolderIsRefused(): void {
		$this->role = 'owner';
		$this->root = $this->createMock(Folder::class);
		$this->root->method('isUpdateable')->willReturn(false);
		$response = $this->controller()->createPage(4, 'New');
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'folder-read-only'], $response->getData());
	}

	public function testSourceReturnsTextAndEtag(): void {
		$this->file('Page.md', '# Hi', 'e5');
		$this->assertSame(['content' => '# Hi', 'etag' => 'e5'], $this->controller()->source(4, 'Page')->getData());
	}

	public function testCreatePageAddsMdAndRefusesExisting(): void {
		$created = $this->createMock(File::class);
		$created->method('getEtag')->willReturn('n1');
		$this->root->expects($this->once())->method('newFile')->with('Guides/Idea.md', '')->willReturn($created);
		$this->assertSame(['path' => 'Guides/Idea.md', 'etag' => 'n1'], $this->controller()->createPage(4, 'Guides/Idea')->getData());

		$this->file('Guides/Idea.md');
		$this->assertSame(409, $this->controller()->createPage(4, 'Guides/Idea.md')->getStatus());
	}

	public function testInvalidNamesAreRefused(): void {
		$response = $this->controller()->createFolder(4, '../outside');
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'invalid-name', 'reason' => 'dot-segment'], $response->getData());
	}

	public function testMoveIntoItselfOrADescendantIsRefused(): void {
		$projects = $this->folder('Projects');
		$this->folder('Projects/Sub');
		$projects->expects($this->never())->method('move');
		$response = $this->controller()->move(4, 'Projects', 'Projects/Sub/Projects');
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'into-itself'], $response->getData());
		$this->assertSame(400, $this->controller()->move(4, 'Projects', 'Projects')->getStatus());
		$this->assertSame(400, $this->controller()->move(4, '', 'Elsewhere')->getStatus());
	}

	public function testMoveOntoAnExistingNameIsAConflict(): void {
		$this->file('A.md');
		$this->file('B.md');
		$this->assertSame(409, $this->controller()->move(4, 'A.md', 'B')->getStatus());
	}

	public function testMoveRewritesLinksAndReindexes(): void {
		$old = $this->file('Guides/Old.md');
		$this->folder('Archive');
		$old->expects($this->once())->method('move')->willReturnCallback(function (string $target) use ($old) {
			$this->assertSame('/alice/files/Wiki/Archive/New.md', $target);
			$this->nodes['Archive/New.md'] = $old;
			unset($this->nodes['Guides/Old.md']);
			return $old;
		});
		$home = $this->file('Home.md');
		$home->expects($this->once())->method('putContent')->with('[[New]]');
		$this->links->method('plan')->with($this->root, [['Guides/Old.md', 'Archive/New.md']])
			->willReturn(['Home.md' => ['content' => '[[New]]', 'count' => 1]]);
		$this->search->expects($this->once())->method('removePage')->with($this->site, 'Guides/Old.md');
		$indexed = [];
		$this->search->method('indexPage')->willReturnCallback(function ($site, $root, string $path) use (&$indexed) {
			$indexed[] = $path;
		});
		$response = $this->controller()->move(4, 'Guides/Old.md', 'Archive/New', true);
		$this->assertSame(['path' => 'Archive/New.md', 'updated' => 1, 'failed' => []], $response->getData());
		$this->assertSame(['Archive/New.md', 'Home.md'], $indexed);
	}

	public function testMoveWithoutLinkUpdateRewritesNothing(): void {
		$old = $this->file('Old.md');
		$old->method('move')->willReturn($old);
		$this->links->expects($this->never())->method('plan');
		$this->assertSame(['path' => 'New.md', 'updated' => 0, 'failed' => []], $this->controller()->move(4, 'Old.md', 'New.md', false)->getData());
	}

	public function testRenamingAFolderAlsoRenamesItsFolderNote(): void {
		$folder = $this->folder('Projects');
		$this->content->method('folderNote')->with($folder)->willReturn('Projects.md');
		$note = $this->createMock(File::class);
		$moves = [];
		$folder->method('move')->willReturnCallback(function (string $target) use (&$moves, $folder, $note) {
			$moves[] = $target;
			$this->nodes['Work'] = $folder;
			$this->nodes['Work/Projects.md'] = $note;
			return $folder;
		});
		$note->method('move')->willReturnCallback(function (string $target) use (&$moves, $note) {
			$moves[] = $target;
			return $note;
		});
		$this->content->method('listMarkdownEtags')->willReturn(['Projects/Projects.md' => 'e']);
		$this->links->method('plan')->with($this->root, [['Projects', 'Work'], ['Work/Projects.md', 'Work/Work.md']])->willReturn([]);
		$response = $this->controller()->move(4, 'Projects', 'Work');
		$this->assertSame(['/alice/files/Wiki/Work', '/alice/files/Wiki/Work/Work.md'], $moves);
		$this->assertSame('Work', $response->getData()['path']);
	}

	public function testBacklinksCountsLinksAndPages(): void {
		$this->file('Old.md');
		$this->links->method('plan')->willReturn([
			'Home.md' => ['content' => '', 'count' => 2],
			'Notes.md' => ['content' => '', 'count' => 1],
		]);
		$this->assertSame(['count' => 3, 'pages' => ['Home.md', 'Notes.md']], $this->controller()->backlinks(4, 'Old.md', 'New.md')->getData());
	}

	public function testDeleteFolderRemovesItsPagesFromSearch(): void {
		$folder = $this->folder('Projects');
		$folder->expects($this->once())->method('delete');
		$this->content->method('listMarkdownEtags')->with($this->root, 'Projects')
			->willReturn(['Projects/A.md' => 'e', 'Projects/B.md' => 'e']);
		$removed = [];
		$this->search->method('removePage')->willReturnCallback(function ($site, string $path) use (&$removed) {
			$removed[] = $path;
		});
		$this->assertSame(['ok' => true], $this->controller()->delete(4, 'Projects')->getData());
		$this->assertSame(['Projects/A.md', 'Projects/B.md'], $removed);
	}

	public function testUploadStoresUnderAFreeNameAndReturnsTheEmbed(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'mds');
		file_put_contents($tmp, 'PNG');
		$this->request->method('getUploadedFile')->with('file')
			->willReturn(['name' => 'image.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]);
		$this->root->method('get')->willThrowException(new NotFoundException('.obsidian/app.json'));
		$attachments = $this->createMock(Folder::class);
		$this->root->method('nodeExists')->with('attachments')->willReturn(false);
		$this->root->method('newFolder')->with('attachments')->willReturn($attachments);
		$attachments->method('nodeExists')->willReturnCallback(fn (string $n) => $n === 'image.png');
		$attachments->expects($this->once())->method('newFile')->with('image 1.png', $this->isType('resource'));
		$data = $this->controller()->upload(4, 'Guides/Page.md')->getData();
		$this->assertSame(['path' => 'attachments/image 1.png', 'embed' => '![[../attachments/image 1.png]]'], $data);
		unlink($tmp);
	}

	public function testUploadWithoutAFileIsRefused(): void {
		$this->request->method('getUploadedFile')->willReturn(null);
		$this->assertSame(400, $this->controller()->upload(4, 'Page.md')->getStatus());
	}
}
