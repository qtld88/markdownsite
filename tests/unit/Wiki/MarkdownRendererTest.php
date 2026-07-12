<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\LinkResolver;
use OCA\MarkdownSite\Wiki\MarkdownRenderer;
use OCA\MarkdownSite\Wiki\PathResolver;
use OCA\MarkdownSite\Wiki\UrlBuilder;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use PHPUnit\Framework\TestCase;

class MarkdownRendererTest extends TestCase {
	private function renderer(): MarkdownRenderer {
		$index = new WikilinkIndex(['LEXIQUE/Bitwarden.md', 'OUTILS/Guide.md'], []);
		$resolver = new LinkResolver(new PathResolver(), $index);
		$urls = new class implements UrlBuilder {
			public function page(string $path): string { return '/PAGE/' . $path; }
			public function asset(string $path): string { return '/FILE/' . $path; }
		};
		return new MarkdownRenderer($resolver, $urls);
	}

	public function testRewritesRelativeMdLink(): void {
		$out = $this->renderer()->render("[BW](../LEXIQUE/Bitwarden.md)", 'OUTILS');
		$this->assertStringContainsString('href="/PAGE/LEXIQUE/Bitwarden.md"', $out['html']);
	}

	public function testRewritesWikilink(): void {
		$out = $this->renderer()->render("See [[Bitwarden]].", 'OUTILS');
		$this->assertStringContainsString('href="/PAGE/LEXIQUE/Bitwarden.md"', $out['html']);
		$this->assertStringContainsString('>Bitwarden<', $out['html']);
	}

	public function testWikilinkAlias(): void {
		$out = $this->renderer()->render("[[Bitwarden|the vault]]", 'OUTILS');
		$this->assertStringContainsString('href="/PAGE/LEXIQUE/Bitwarden.md"', $out['html']);
		$this->assertStringContainsString('>the vault<', $out['html']);
	}

	public function testRewritesImageAsset(): void {
		$out = $this->renderer()->render("![shot](img/shot.png)", 'OUTILS');
		$this->assertStringContainsString('src="/FILE/OUTILS/img/shot.png"', $out['html']);
	}

	public function testRewritesEmbedImage(): void {
		$out = $this->renderer()->render("![[diagram.png]]", 'OUTILS');
		$this->assertStringContainsString('src="/FILE/OUTILS/diagram.png"', $out['html']);
	}

	public function testBrokenWikilinkGetsClass(): void {
		$out = $this->renderer()->render("[[Ghost]]", '');
		$this->assertStringContainsString('markdownsite-broken', $out['html']);
	}

	public function testExtractsFrontmatter(): void {
		$md = "---\ntitle: Hello\naliases: [Hi]\n---\n\n# Body";
		$out = $this->renderer()->render($md, '');
		$this->assertSame('Hello', $out['meta']['title']);
		$this->assertStringContainsString('<h1>Body</h1>', $out['html']);
	}
}
