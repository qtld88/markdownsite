<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Controller;

use OCA\MarkdownSite\Controller\SearchController;
use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Search\Normalizer;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchControllerTest extends TestCase {
	private SiteMapper&MockObject $sites;
	private SiteShareMapper&MockObject $shares;
	private ContentService&MockObject $content;
	private SearchIndexer&MockObject $indexer;
	private SearchMapper&MockObject $index;
	/** @var array<int,SiteShare[]> */
	private array $sharesBySite = [];

	protected function setUp(): void {
		$this->sites = $this->createMock(SiteMapper::class);
		$this->shares = $this->createMock(SiteShareMapper::class);
		$this->shares->method('findBySite')->willReturnCallback(fn (int $id) => $this->sharesBySite[$id] ?? []);
		$this->content = $this->createMock(ContentService::class);
		$this->content->method('resolveRoot')->willReturn($this->createMock(Folder::class));
		$this->indexer = $this->createMock(SearchIndexer::class);
		$this->index = $this->createMock(SearchMapper::class);
	}

	private function controller(): SearchController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['staff']);
		return new SearchController(
			$this->createMock(IRequest::class), $this->sites, $this->shares, new AccessService(),
			$this->content, $this->indexer, $this->index, $session, $groups,
		);
	}

	private function site(int $id, string $owner, string $name = 'Wiki'): Site {
		$site = new Site();
		$site->setId($id);
		$site->setOwnerUid($owner);
		$site->setName($name);
		return $site;
	}

	private function share(int $siteId, string $type, string $with): SiteShare {
		$share = new SiteShare();
		$share->setSiteId($siteId);
		$share->setShareType($type);
		$share->setShareWith($with);
		return $share;
	}

	/** A candidate row as SearchMapper::candidates() returns it. */
	private function row(int $siteId, string $path, string $title, string $body): array {
		return [
			'site_id' => $siteId, 'path' => $path, 'title' => $title,
			'title_norm' => Normalizer::normalize($title), 'aliases_norm' => '', 'headings_norm' => '',
			'body' => $body, 'body_norm' => Normalizer::normalize($body),
		];
	}

	public function testAllMySitesNeverIncludesASiteWithoutMatchingShare(): void {
		$own = $this->site(1, 'bob');
		$shared = $this->site(2, 'alice');
		$this->sharesBySite[2] = [$this->share(2, 'group', 'staff')];
		$stranger = $this->site(3, 'carol');
		$this->sharesBySite[3] = [$this->share(3, 'user', 'dave')];
		$this->sites->method('findVisible')->willReturn([$own, $shared, $stranger]);
		$this->index->expects($this->once())->method('candidates')->with([1, 2], ['vault'])->willReturn([]);
		$this->indexer->expects($this->exactly(2))->method('refresh');
		$this->controller()->search('vault');
	}

	public function testOneSiteWithoutAccessIsForbidden(): void {
		$this->sites->method('find')->with(3)->willReturn($this->site(3, 'carol'));
		$this->index->expects($this->never())->method('candidates');
		$response = $this->controller()->search('vault', 3);
		$this->assertSame(403, $response->getStatus());
	}

	public function testTooShortQueryReturnsNothingWithoutIndexing(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$this->indexer->expects($this->never())->method('refresh');
		$response = $this->controller()->search('a', 1);
		$this->assertSame(['results' => [], 'total' => 0, 'offset' => 0], $response->getData());
	}

	public function testResultsAreRankedAndShaped(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob', 'Docs'));
		$this->index->method('candidates')->willReturn([
			$this->row(1, 'Notes/Misc.md', 'Misc', 'The vault is mentioned here.'),
			$this->row(1, 'Vault.md', 'Vault', 'All about it.'),
		]);
		$data = $this->controller()->search('Vault', 1)->getData();
		$this->assertSame(2, $data['total']);
		$this->assertSame('Vault.md', $data['results'][0]['path']);
		$this->assertSame([
			'siteId' => 1,
			'siteName' => 'Docs',
			'path' => 'Notes/Misc.md',
			'title' => 'Misc',
			'folder' => 'Notes',
			'snippet' => 'The vault is mentioned here.',
			'highlights' => [[4, 5]],
		], $data['results'][1]);
	}

	public function testPagesOfFifty(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$rows = [];
		for ($i = 0; $i < 60; $i++) {
			$rows[] = $this->row(1, "P$i.md", sprintf('Page %02d', $i), 'vault');
		}
		$this->index->method('candidates')->willReturn($rows);
		$first = $this->controller()->search('vault', 1)->getData();
		$this->assertCount(50, $first['results']);
		$this->assertSame(60, $first['total']);
		$second = $this->controller()->search('vault', 1, 50)->getData();
		$this->assertCount(10, $second['results']);
		$this->assertSame('Page 50', $second['results'][0]['title']);
	}
}
