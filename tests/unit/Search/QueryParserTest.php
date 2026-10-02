<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Search;

use OCA\MarkdownSite\Search\QueryParser;
use PHPUnit\Framework\TestCase;

class QueryParserTest extends TestCase {
	public function testSplitsWordsAndNormalises(): void {
		$this->assertSame(['cafe', 'creme'], QueryParser::parse('  Café   CRÈME '));
	}

	public function testQuotedPhraseIsOneTerm(): void {
		$this->assertSame(['mot de passe', 'oublie'], QueryParser::parse('"Mot  de passe" oublié'));
	}

	public function testUnclosedQuoteRunsToTheEnd(): void {
		$this->assertSame(['nextcloud', 'talk app'], QueryParser::parse('nextcloud "talk app'));
	}

	public function testDropsShortTermsAndDuplicates(): void {
		$this->assertSame(['ab', 'vault'], QueryParser::parse('a ab "" x vault Vault'));
	}

	public function testEmptyMeansNoSearch(): void {
		$this->assertSame([], QueryParser::parse(''));
		$this->assertSame([], QueryParser::parse('a "b"'));
	}
}
