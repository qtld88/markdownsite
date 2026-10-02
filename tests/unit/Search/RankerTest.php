<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Search;

use OCA\MarkdownSite\Search\Normalizer;
use OCA\MarkdownSite\Search\Ranker;
use PHPUnit\Framework\TestCase;

class RankerTest extends TestCase {
	private function row(string $title = '', string $aliases = '', string $headings = '', string $body = ''): array {
		return [
			'title_norm' => Normalizer::normalize($title),
			'aliases_norm' => Normalizer::normalize($aliases),
			'headings_norm' => Normalizer::normalize($headings),
			'body_norm' => Normalizer::normalize($body),
		];
	}

	public function testTitleBeatsBody(): void {
		$inTitle = Ranker::score(['vault'], $this->row(title: 'Vault'));
		$inBody = Ranker::score(['vault'], $this->row(body: 'vault vault vault vault vault vault vault'));
		$this->assertGreaterThan($inBody, $inTitle);
		$this->assertSame(15.0, $inTitle);   // 10 × 1.5 (starts a word)
		$this->assertSame(7.5, $inBody);     // capped at 5 occurrences × 1.5
	}

	public function testFieldPoints(): void {
		$this->assertSame(12.0, Ranker::score(['bw'], $this->row(aliases: 'bw vault')));
		$this->assertSame(7.5, Ranker::score(['setup'], $this->row(headings: "Intro\nSetup")));
	}

	public function testWordStartBonus(): void {
		$start = Ranker::score(['pass'], $this->row(body: 'password'));
		$inside = Ranker::score(['pass'], $this->row(body: 'bypass'));
		$this->assertSame(1.5, $start);
		$this->assertSame(1.0, $inside);
	}

	public function testTermsAreSummed(): void {
		$both = Ranker::score(['alpha', 'beta'], $this->row(title: 'Alpha', body: 'beta'));
		$this->assertSame(15.0 + 1.5, $both);
	}

	public function testSnippetAroundFirstMatchWithHighlights(): void {
		$body = str_repeat('lorem ipsum ', 20) . 'the Café crème recipe ' . str_repeat('dolor sit ', 20);
		$out = Ranker::snippet($body, Normalizer::normalize($body), ['cafe', 'creme']);
		$this->assertStringStartsWith('…', $out['snippet']);
		$this->assertStringEndsWith('…', $out['snippet']);
		$this->assertLessThanOrEqual(Ranker::SNIPPET_LENGTH + 2, mb_strlen($out['snippet']));
		$this->assertCount(2, $out['highlights']);
		[$start, $length] = $out['highlights'][0];
		$this->assertSame('Café', mb_substr($out['snippet'], $start, $length));
		[$start, $length] = $out['highlights'][1];
		$this->assertSame('crème', mb_substr($out['snippet'], $start, $length));
	}

	public function testSnippetWithoutBodyMatchStartsAtTheTop(): void {
		$out = Ranker::snippet("First line\nsecond line", "first line\nsecond line", ['title']);
		$this->assertSame('First line second line', $out['snippet']);
		$this->assertSame([], $out['highlights']);
	}

	public function testOverlappingHighlightsAreMerged(): void {
		$out = Ranker::snippet('password', 'password', ['pass', 'password']);
		$this->assertSame([[0, 8]], $out['highlights']);
	}
}
