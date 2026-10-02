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
}
