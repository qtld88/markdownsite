<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\NameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameValidatorTest extends TestCase {
	public function testForcesMdOnPages(): void {
		$this->assertSame('Guides/New page.md', NameValidator::pagePath(' Guides / New page '));
		$this->assertSame('Notes.MD', NameValidator::pagePath('Notes.MD'));
	}

	public function testFolderPathKeepsNames(): void {
		$this->assertSame('Projects/2026 plans', NameValidator::folderPath('Projects/2026 plans'));
	}

	public static function invalid(): array {
		return [
			'parent segment' => ['../escape', 'dot-segment'],
			'inner parent' => ['a/../b', 'dot-segment'],
			'current dir' => ['./a', 'dot-segment'],
			'empty segment' => ['a//b', 'empty-segment'],
			'empty' => ['', 'empty-segment'],
			'leading slash' => ['/a', 'empty-segment'],
			'leading dot' => ['a/.hidden', 'hidden-name'],
			'backslash' => ['a\\b', 'forbidden-character'],
			'colon' => ['a:b', 'forbidden-character'],
			'question mark' => ['what?', 'forbidden-character'],
			'pipe' => ['a|b', 'forbidden-character'],
			'newline' => ["a\nb", 'forbidden-character'],
		];
	}

	#[DataProvider('invalid')]
	public function testRejects(string $path, string $reason): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($reason);
		NameValidator::pagePath($path);
	}
}
