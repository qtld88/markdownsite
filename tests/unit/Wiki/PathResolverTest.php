<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\PathResolver;
use OCA\MarkdownSite\Wiki\PathTraversalException;
use PHPUnit\Framework\TestCase;

class PathResolverTest extends TestCase {
	private PathResolver $r;

	protected function setUp(): void {
		$this->r = new PathResolver();
	}

	public function testJoinsRelativeTargetWithCurrentDir(): void {
		$this->assertSame('A/LEXIQUE/foo.md', $this->r->resolve('A/B', '../LEXIQUE/foo.md'));
	}

	public function testSameDirTarget(): void {
		$this->assertSame('A/B/foo.md', $this->r->resolve('A/B', 'foo.md'));
	}

	public function testCollapsesDotSegments(): void {
		$this->assertSame('A/foo.md', $this->r->resolve('A/B', '.././foo.md'));
	}

	public function testRootRelativeTargetStartingWithSlash(): void {
		$this->assertSame('LEXIQUE/foo.md', $this->r->resolve('A/B', '/LEXIQUE/foo.md'));
	}

	public function testTargetFromRootDir(): void {
		$this->assertSame('foo.md', $this->r->resolve('', 'foo.md'));
	}

	public function testThrowsOnEscapeAboveRoot(): void {
		$this->expectException(PathTraversalException::class);
		$this->r->resolve('A', '../../secret.md');
	}
}
