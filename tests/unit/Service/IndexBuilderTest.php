<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

class IndexBuilderTest extends TestCase {
	/** @var array<string,mixed> */
	private array $store = [];
	private int $reads = 0;
	/** @var array<string,string> */
	private array $etags = ['A.md' => 'a1', 'B.md' => 'b1'];

	private function builder(): IndexBuilder {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->store[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->with('markdownsite')->willReturn($cache);
		$content = $this->createMock(ContentService::class);
		$content->method('listMarkdownEtags')->willReturnCallback(fn () => $this->etags);
		$content->method('getPageContent')->willReturnCallback(function ($root, string $path): string {
			$this->reads++;
			return $path === 'A.md' ? "---\naliases: [Alpha, First]\n---\nbody" : 'no frontmatter';
		});
		return new IndexBuilder($content, $factory);
	}

	private function root(string $etag): Folder {
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(5);
		$root->method('getEtag')->willReturn($etag);
		return $root;
	}

	public function testBuildsPathsAndAliases(): void {
		$index = $this->builder()->build($this->root('e1'));
		$this->assertSame(['A.md', 'B.md'], $index->paths());
		$this->assertSame(['A.md' => ['Alpha', 'First']], $index->aliases());
		$this->assertSame('A.md', $index->resolve('', 'first'));
	}

	public function testSameEtagReadsNothingTheSecondTime(): void {
		$builder = $this->builder();
		$builder->build($this->root('e1'));
		$this->assertSame(2, $this->reads);
		$index = $builder->build($this->root('e1'));
		$this->assertSame(2, $this->reads);
		$this->assertSame('A.md', $index->resolve('', 'Alpha'));
	}

	public function testNewRootEtagRereadsOnlyChangedPages(): void {
		$builder = $this->builder();
		$builder->build($this->root('e1'));
		$this->etags['B.md'] = 'b2';
		$index = $builder->build($this->root('e2'));
		$this->assertSame(3, $this->reads);
		$this->assertSame(['A.md' => ['Alpha', 'First']], $index->aliases());
	}

	public function testNewPageIsReadAndRemovedPageDropped(): void {
		$builder = $this->builder();
		$builder->build($this->root('e1'));
		$this->etags = ['A.md' => 'a1', 'C.md' => 'c1'];
		$index = $builder->build($this->root('e2'));
		$this->assertSame(3, $this->reads);
		$this->assertSame(['A.md', 'C.md'], $index->paths());
	}
}
