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

	public function testRendersTable(): void {
		$out = $this->renderer()->render("| A | B |\n|---|---|\n| 1 | 2 |", '');
		$this->assertStringContainsString('<table>', $out['html']);
		$this->assertStringContainsString('<td>2</td>', $out['html']);
	}

	public function testRendersTableInsideCallout(): void {
		$md = "> [!note]- Copy\n> Hello\n>\n> | A | B |\n> |---|---|\n> | 1 | 2 |";
		$out = $this->renderer()->render($md, '');
		$this->assertStringContainsString('<table>', $out['html']);
		$this->assertStringContainsString('<details', $out['html']);
	}

	public function testRendersTaskList(): void {
		$out = $this->renderer()->render("- [ ] todo\n- [x] done", '');
		$this->assertStringContainsString('<input disabled="" type="checkbox">', $out['html']);
		$this->assertStringContainsString('<input checked="" disabled="" type="checkbox">', $out['html']);
	}

	public function testRendersHighlight(): void {
		$out = $this->renderer()->render("Hi ==there==", '');
		$this->assertStringContainsString('<mark>there</mark>', $out['html']);
	}

	public function testCalloutWithTitleAndBody(): void {
		$out = $this->renderer()->render("> [!tip] My **title**\n> Body text", '');
		$html = $out['html'];
		$this->assertStringContainsString('class="mds-callout" data-callout="tip"', $html);
		$this->assertStringContainsString('<span class="mds-callout-title-text">My <strong>title</strong></span>', $html);
		$this->assertStringContainsString('<p>Body text</p>', $html);
		$this->assertStringNotContainsString('[!tip]', $html);
		$this->assertStringNotContainsString('<details', $html);
	}

	public function testCalloutDefaultTitleAndFold(): void {
		$out = $this->renderer()->render("> [!warning]+\n> Careful", '');
		$this->assertStringContainsString('<details', $out['html']);
		$this->assertStringContainsString(' open', $out['html']);
		$this->assertStringContainsString('>Warning<', $out['html']);
	}

	public function testPlainBlockquoteUntouched(): void {
		$out = $this->renderer()->render("> just a quote", '');
		$this->assertStringContainsString('<blockquote>', $out['html']);
		$this->assertStringNotContainsString('mds-callout', $out['html']);
	}
}
