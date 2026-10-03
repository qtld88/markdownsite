<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Controller;

use OCA\MarkdownSite\Controller\SiteController;
use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SiteLister;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SiteControllerTest extends TestCase {
	private SiteMapper&MockObject $sites;
	private SiteShareMapper&MockObject $shares;
	private ContentService&MockObject $content;
	/** @var array<int,SiteShare[]> */
	private array $sharesBySite = [];
	private string $uid = 'bob';

	protected function setUp(): void {
		$this->sites = $this->createMock(SiteMapper::class);
		$this->shares = $this->createMock(SiteShareMapper::class);
		$this->shares->method('findBySite')->willReturnCallback(fn (int $id) => $this->sharesBySite[$id] ?? []);
		$this->content = $this->createMock(ContentService::class);
	}

	private function controller(): SiteController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		return new SiteController(
			$this->createMock(IRequest::class), $this->sites, $this->shares, $session,
			$this->createMock(IRootFolder::class), $this->createMock(SearchMapper::class), $this->content,
			new SiteLister($this->sites, $this->shares, $groups, new AccessService(), $this->content),
		);
	}

	private function site(int $id, string $owner): Site {
		$site = new Site();
		$site->setId($id);
		$site->setOwnerUid($owner);
		$site->setName("Site $id");
		return $site;
	}

	private function share(int $id, int $siteId, string $with, string $role): SiteShare {
		$share = new SiteShare();
		$share->setId($id);
		$share->setSiteId($siteId);
		$share->setShareType('user');
		$share->setShareWith($with);
		$share->setRole($role);
		return $share;
	}

	private function folder(bool $updateable): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('isUpdateable')->willReturn($updateable);
		return $folder;
	}

	public function testIndexAddsRoleAndWritable(): void {
		$this->sites->method('findVisible')->willReturn([
			$this->site(1, 'bob'), $this->site(2, 'alice'), $this->site(3, 'alice'), $this->site(4, 'alice'),
		]);
		$this->sharesBySite[2] = [$this->share(20, 2, 'bob', 'editor')];
		$this->sharesBySite[3] = [$this->share(30, 3, 'bob', 'reader')];
		$this->sharesBySite[4] = [$this->share(40, 4, 'bob', 'editor')];
		$this->content->method('resolveRoot')->willReturnCallback(
			fn (Site $s) => $this->folder($s->getId() !== 4), // site 4: owner only has read access
		);
		$data = $this->controller()->index()->getData();
		$this->assertSame(
			[[1, 'owner', true], [2, 'editor', true], [3, 'reader', false], [4, 'editor', false]],
			array_map(fn ($d) => [$d['id'], $d['role'], $d['writable']], $data),
		);
	}

	public function testShareStoresRoles(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$stored = [];
		$this->shares->method('insert')->willReturnCallback(function (SiteShare $s) use (&$stored) {
			$stored[] = [$s->getShareWith(), $s->getRole()];
			return $s;
		});
		$this->controller()->share(1, [
			['type' => 'user', 'with' => 'carol', 'role' => 'editor'],
			['type' => 'user', 'with' => 'dave'],
			['type' => 'group', 'with' => 'staff', 'role' => 'admin'],
		]);
		$this->assertSame([['carol', 'editor'], ['dave', 'reader'], ['staff', 'reader']], $stored);
	}

	public function testSharesListsIdAndRole(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$this->sharesBySite[1] = [$this->share(9, 1, 'carol', 'editor')];
		$this->assertSame(
			[['id' => 9, 'type' => 'user', 'with' => 'carol', 'role' => 'editor']],
			$this->controller()->shares(1)->getData(),
		);
	}

	public function testUpdateShareChangesRole(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$this->sharesBySite[1] = [$this->share(9, 1, 'carol', 'reader')];
		$this->shares->expects($this->once())->method('update')
			->with($this->callback(fn (SiteShare $s) => $s->getId() === 9 && $s->getRole() === 'editor'));
		$this->assertSame(['id' => 9, 'role' => 'editor'], $this->controller()->updateShare(1, 9, 'editor')->getData());
	}

	public function testOnlyTheOwnerChangesRoles(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'alice'));
		$this->shares->expects($this->never())->method('update');
		$this->assertSame(403, $this->controller()->updateShare(1, 9, 'editor')->getStatus());
	}

	public function testUnknownShareIs404(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$this->assertSame(404, $this->controller()->updateShare(1, 99, 'editor')->getStatus());
	}
}
