<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Service\ContentService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

class ContentServiceTest extends TestCase {
	private function site(): Site {
		$site = new Site();
		$site->setOwnerUid('alice');
		$site->setRootFileId(42);
		return $site;
	}

	private function service(?\OCP\Files\Node $found): ContentService {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->with(42)->willReturn($found);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
		return new ContentService($rootFolder);
	}

	public function testResolveRootReturnsFolder(): void {
		$folder = $this->createMock(Folder::class);
		$this->assertSame($folder, $this->service($folder)->resolveRoot($this->site()));
	}

	public function testResolveRootRejectsFile(): void {
		$file = $this->createMock(File::class);
		$this->assertNull($this->service($file)->resolveRoot($this->site()));
	}

	public function testResolveRootReturnsNullWhenGone(): void {
		$this->assertNull($this->service(null)->resolveRoot($this->site()));
	}

	/** A file mock named $name. */
	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	/**
	 * A folder mock named $name holding $children (File or Folder mocks).
	 * @param list<\OCP\Files\Node> $children
	 */
	private function folder(string $name, array $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	private function content(): ContentService {
		return new ContentService($this->createMock(IRootFolder::class));
	}

	public function testFolderNoteNamedLikeTheFolder(): void {
		$folder = $this->folder('Projects', [$this->file('Alpha.md'), $this->file('Projects.md')]);
		$this->assertSame('Projects.md', $this->content()->folderNote($folder));
	}

	public function testFolderNoteIndex(): void {
		$folder = $this->folder('Projects', [$this->file('index.md'), $this->file('Alpha.md')]);
		$this->assertSame('index.md', $this->content()->folderNote($folder));
	}

	public function testFolderNoteReadme(): void {
		$folder = $this->folder('Projects', [$this->file('README.md')]);
		$this->assertSame('README.md', $this->content()->folderNote($folder));
	}

	public function testFolderNotePriorityOrder(): void {
		$folder = $this->folder('Projects', [
			$this->file('README.md'), $this->file('index.md'), $this->file('Projects.md'),
		]);
		$this->assertSame('Projects.md', $this->content()->folderNote($folder));
		$folder = $this->folder('Projects', [$this->file('README.md'), $this->file('index.md')]);
		$this->assertSame('index.md', $this->content()->folderNote($folder));
	}

	public function testFolderNoteIsCaseInsensitiveAndKeepsRealName(): void {
		$folder = $this->folder('Projects', [$this->file('ReadMe.MD')]);
		$this->assertSame('ReadMe.MD', $this->content()->folderNote($folder));
		$folder = $this->folder('Projects', [$this->file('projects.md')]);
		$this->assertSame('projects.md', $this->content()->folderNote($folder));
	}

	public function testFolderNoteIgnoresSubfolderWithNoteName(): void {
		$folder = $this->folder('Projects', [$this->folder('index.md', [])]);
		$this->assertNull($this->content()->folderNote($folder));
	}

	public function testFolderNoteNone(): void {
		$folder = $this->folder('Projects', [$this->file('Alpha.md')]);
		$this->assertNull($this->content()->folderNote($folder));
	}

	public function testListTreeSetsNoteAndHidesItFromChildren(): void {
		$projects = $this->folder('Projects', [$this->file('Alpha.md'), $this->file('Projects.md')]);
		$empty = $this->folder('Empty', []);
		$root = $this->folder('Wiki', [$projects, $empty, $this->file('Home.md'), $this->file('.hidden.md')]);
		$this->assertSame([
			['name' => 'Empty', 'path' => 'Empty', 'type' => 'dir', 'children' => []],
			['name' => 'Projects', 'path' => 'Projects', 'type' => 'dir',
				'children' => [['name' => 'Alpha', 'path' => 'Projects/Alpha.md', 'type' => 'page']],
				'note' => 'Projects/Projects.md'],
			['name' => 'Home', 'path' => 'Home.md', 'type' => 'page'],
		], $this->content()->listTree($root));
	}

	public function testListTreeListsEachFolderOnce(): void {
		$sub = $this->createMock(Folder::class);
		$sub->method('getName')->willReturn('Sub');
		$sub->expects($this->once())->method('getDirectoryListing')->willReturn([$this->file('Sub.md'), $this->file('A.md')]);
		$root = $this->createMock(Folder::class);
		$root->method('getName')->willReturn('Wiki');
		$root->expects($this->once())->method('getDirectoryListing')->willReturn([$sub]);
		$tree = $this->content()->listTree($root);
		$this->assertSame('Sub/Sub.md', $tree[0]['note']);
	}

	public function testListMarkdownEtagsWalksFoldersWithoutReadingFiles(): void {
		$page = fn (string $name, string $etag) => $this->fileWithEtag($name, $etag);
		$deep = $this->folder('Deep', [$page('Inner.md', 'e3')]);
		$root = $this->folder('Wiki', [$page('Home.md', 'e1'), $deep, $page('image.png', 'e9'), $page('.draft.md', 'e8')]);
		$this->assertSame(['Home.md' => 'e1', 'Deep/Inner.md' => 'e3'], $this->content()->listMarkdownEtags($root));
		$this->assertSame(['Home.md', 'Deep/Inner.md'], $this->content()->listMarkdownPaths($root));
	}

	private function fileWithEtag(string $name, string $etag): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getEtag')->willReturn($etag);
		$file->expects($this->never())->method('getContent');
		return $file;
	}
}
