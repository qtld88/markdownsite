<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\LinkResolver;
use OCA\MarkdownSite\Wiki\PathResolver;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use PHPUnit\Framework\TestCase;

class LinkResolverTest extends TestCase {
	private function resolver(): LinkResolver {
		$index = new WikilinkIndex(
			['LEXIQUE/Bitwarden.md', 'OUTILS/Guide.md'],
			[],
		);
		return new LinkResolver(new PathResolver(), $index);
	}

	public function testRelativeMdLinkIsPage(): void {
		$t = $this->resolver()->resolveHref('OUTILS', '../LEXIQUE/Bitwarden.md');
		$this->assertSame('page', $t->kind);
		$this->assertSame('LEXIQUE/Bitwarden.md', $t->path);
	}

	public function testImageLinkIsAsset(): void {
		$t = $this->resolver()->resolveHref('OUTILS', 'img/shot.png');
		$this->assertSame('asset', $t->kind);
		$this->assertSame('OUTILS/img/shot.png', $t->path);
	}

	public function testExternalLinkUntouched(): void {
		$t = $this->resolver()->resolveHref('', 'https://example.org/x');
		$this->assertSame('external', $t->kind);
		$this->assertSame('https://example.org/x', $t->path);
	}

	public function testWikilinkResolvesToPage(): void {
		$t = $this->resolver()->resolveWikilink('OUTILS', 'Bitwarden');
		$this->assertSame('page', $t->kind);
		$this->assertSame('LEXIQUE/Bitwarden.md', $t->path);
	}

	public function testUnresolvedWikilinkIsBroken(): void {
		$t = $this->resolver()->resolveWikilink('', 'Ghost');
		$this->assertSame('broken', $t->kind);
	}

	public function testEmbedImageIsAsset(): void {
		$t = $this->resolver()->resolveEmbed('OUTILS', 'diagram.png');
		$this->assertSame('asset', $t->kind);
		$this->assertSame('OUTILS/diagram.png', $t->path);
	}

	public function testTraversalEscapeIsBroken(): void {
		$t = $this->resolver()->resolveHref('', '../../etc/passwd');
		$this->assertSame('broken', $t->kind);
	}
}
