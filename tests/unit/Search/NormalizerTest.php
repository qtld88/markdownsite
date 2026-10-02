<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Search;

use OCA\MarkdownSite\Search\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase {
	public function testLowercasesAndRemovesAccents(): void {
		$this->assertSame('ete a paris, deja vu', Normalizer::normalize('Été à Paris, Déjà vu'));
		$this->assertSame('ceske budejovice', Normalizer::normalize('České Budějovice'));
		$this->assertSame('lodz', Normalizer::normalize('Łódź'));
	}

	public function testFoldsLigaturesToOneLetter(): void {
		$this->assertSame('strase', Normalizer::normalize('Straße'));
		$this->assertSame('ouvre', Normalizer::normalize('Œuvre'));
		$this->assertSame('aon', Normalizer::normalize('Æon'));
	}

	public function testPreservesLength(): void {
		foreach (['Straße', 'İstanbul', 'Œuvre & Æsir', 'naïve café 😀 日本', "ĳ ŉ ſ"] as $text) {
			$this->assertSame(mb_strlen($text), mb_strlen(Normalizer::normalize($text)), $text);
		}
	}

	public function testLeavesOtherScriptsUnchanged(): void {
		$this->assertSame('日本語 😀 ελληνικά', Normalizer::normalize('日本語 😀 Ελληνικά'));
	}
}
