<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\WikilinkIndex;
use PHPUnit\Framework\TestCase;

class WikilinkIndexTest extends TestCase {
	private function index(): WikilinkIndex {
		return new WikilinkIndex(
			[
				'LEXIQUE/Bitwarden.md',
				'OUTILS/NEXTCLOUD/Guide Nextcloud.md',
				'FONDS/Readme.md',
				'GROUPES DE TRAVAIL/Readme.md',
			],
			['LEXIQUE/Bitwarden.md' => ['BW', 'Vault']],
		);
	}

	public function testResolvesByBasename(): void {
		$this->assertSame('LEXIQUE/Bitwarden.md', $this->index()->resolve('OUTILS', 'Bitwarden'));
	}

	public function testResolvesByAlias(): void {
		$this->assertSame('LEXIQUE/Bitwarden.md', $this->index()->resolve('', 'Vault'));
	}

	public function testResolvesBySubpath(): void {
		$this->assertSame(
			'OUTILS/NEXTCLOUD/Guide Nextcloud.md',
			$this->index()->resolve('', 'OUTILS/NEXTCLOUD/Guide Nextcloud'),
		);
	}

	public function testAmbiguousPrefersNearestThenShortest(): void {
		// Two "Readme" pages; from inside FONDS the nearest wins.
		$this->assertSame('FONDS/Readme.md', $this->index()->resolve('FONDS', 'Readme'));
	}

	public function testUnresolvedReturnsNull(): void {
		$this->assertNull($this->index()->resolve('', 'Nonexistent'));
	}
}
