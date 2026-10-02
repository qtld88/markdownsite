<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Service\AttachmentLocator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

class AttachmentLocatorTest extends TestCase {
	private function root(?string $appJson): Folder {
		$root = $this->createMock(Folder::class);
		if ($appJson === null) {
			$root->method('get')->willThrowException(new NotFoundException('.obsidian/app.json'));
		} else {
			$file = $this->createMock(File::class);
			$file->method('getContent')->willReturn($appJson);
			$root->method('get')->with('.obsidian/app.json')->willReturn($file);
		}
		return $root;
	}

	private function folderFor(?string $appJson, string $page = 'Guides/Page.md'): string {
		return (new AttachmentLocator())->folderFor($this->root($appJson), $page);
	}

	public function testNoAppJson(): void {
		$this->assertSame('attachments', $this->folderFor(null));
	}

	public function testEmptyOrRootSetting(): void {
		$this->assertSame('attachments', $this->folderFor('{"attachmentFolderPath": ""}'));
		$this->assertSame('attachments', $this->folderFor('{"attachmentFolderPath": "/"}'));
		$this->assertSame('attachments', $this->folderFor('{"theme": "obsidian"}'));
		$this->assertSame('attachments', $this->folderFor('not json'));
	}

	public function testFixedFolder(): void {
		$this->assertSame('Assets/Images', $this->folderFor('{"attachmentFolderPath": "Assets/Images"}'));
	}

	public function testPageFolder(): void {
		$this->assertSame('Guides', $this->folderFor('{"attachmentFolderPath": "./"}'));
		$this->assertSame('', $this->folderFor('{"attachmentFolderPath": "./"}', 'Home.md'));
	}

	public function testSubfolderOfPageFolder(): void {
		$this->assertSame('Guides/img', $this->folderFor('{"attachmentFolderPath": "./img"}'));
		$this->assertSame('img', $this->folderFor('{"attachmentFolderPath": "./img"}', 'Home.md'));
	}

	public function testRefusesToLeaveTheSite(): void {
		$this->assertSame('attachments', $this->folderFor('{"attachmentFolderPath": "../outside"}'));
	}
}
