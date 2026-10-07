<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCA\MarkdownSite\Service\LinkChecker;
use OCA\MarkdownSite\Wiki\LinkScanner;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

class LinkCheckerTest extends TestCase {
	/** @var array<string,string> */
	private array $pages = [
		'Home.md' => "Fine [[Guides/Setup]]. Typo [[Instalation guide]]. Moved [m](Old/Setup.md). Lost [[Nothing here]].\nAccent [[Résumé]] and [[Setupp]].",
		'Guides/Setup.md' => "Back [home](../Home.md).",
		'Docs/Installation Guide.md' => "Nothing.",
		'Docs/Resume.md' => "CV.",
	];

	private ?string $expectedWrite = null;

	private function checker(): LinkChecker {
		$content = $this->createMock(ContentService::class);
		$content->method('getPageContent')->willReturnCallback(fn ($root, string $p) => $this->pages[$p]);
		$content->method('getChild')->willReturnCallback(function ($root, string $p) {
			$file = $this->createMock(File::class);
			$file->method('getContent')->willReturn($this->pages[$p]);
			if ($this->expectedWrite === null) {
				$file->expects($this->never())->method('putContent');
			} else {
				$file->expects($this->once())->method('putContent')->with($this->expectedWrite);
			}
			return $file;
		});
		$index = $this->createMock(IndexBuilder::class);
		$index->method('build')->willReturn(new WikilinkIndex(array_keys($this->pages)));
		return new LinkChecker($content, $index, new LinkScanner());
	}

	/** @return array<string,array<string,mixed>> broken links of Home.md by target */
	private function broken(): array {
		$result = $this->checker()->check($this->createMock(Folder::class));
		$this->assertFalse($result['truncated']);
		return array_column($result['broken'], null, 'target');
	}

	public function testListsBrokenLinksWithSuggestions(): void {
		$broken = $this->broken();
		$this->assertSame(['Instalation guide', 'Old/Setup.md', 'Nothing here', 'Résumé', 'Setupp'], array_keys($broken));
		$this->assertSame('Docs/Installation Guide.md', $broken['Instalation guide']['suggestionPage']);
		$this->assertSame('Installation Guide', $broken['Instalation guide']['suggestion']);
		$this->assertSame('Resume', $broken['Résumé']['suggestion'], 'accents are ignored');
		$this->assertSame('Guides/Setup.md', $broken['Old/Setup.md']['suggestion'], 'a moved page is found by name');
		$this->assertSame('Setup', $broken['Setupp']['suggestion']);
		$this->assertNull($broken['Nothing here']['suggestion'], 'nothing is close enough');
		$this->assertSame([1, 2], [$broken['Instalation guide']['line'], $broken['Résumé']['line']]);
	}

	public function testTwoPagesWithTheSameNameGiveNoSuggestion(): void {
		$this->pages['Docs/Setup.md'] = 'Another setup page.';
		$broken = $this->broken();
		$this->assertNull($broken['Old/Setup.md']['suggestion']);
		$this->assertNull($broken['Setupp']['suggestion']);
	}

	public function testRepairAppliesOnlySuggestedReplacements(): void {
		$broken = $this->broken();
		$fixes = array_map(fn (string $t) => ['page' => 'Home.md', 'line' => $broken[$t]['line'], 'offset' => $broken[$t]['offset']],
			['Instalation guide', 'Old/Setup.md', 'Nothing here', 'Résumé']);

		$this->expectedWrite = "Fine [[Guides/Setup]]. Typo [[Installation Guide]]. Moved [m](Guides/Setup.md). Lost [[Nothing here]].\nAccent [[Resume]] and [[Setupp]].";
		$result = $this->checker()->repair($this->createMock(Folder::class), $fixes);
		$this->assertSame(['fixed' => 3, 'failed' => [], 'pages' => ['Home.md']], $result);
	}

	public function testRepairIgnoresALinkThatIsNotBroken(): void {
		$result = $this->checker()->repair($this->createMock(Folder::class), [['page' => 'Home.md', 'line' => 1, 'offset' => 7]]);
		$this->assertSame(['fixed' => 0, 'failed' => [], 'pages' => []], $result);
	}
}
