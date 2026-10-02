<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class SiteContextResolverTest extends TestCase {
	private ?Site $site;
	/** @var SiteShare[] */
	private array $shares = [];
	private ?Folder $root;
	private ?string $uid = 'bob';

	protected function setUp(): void {
		$this->site = new Site();
		$this->site->setId(3);
		$this->site->setOwnerUid('alice');
		$this->root = $this->createMock(Folder::class);
	}

	private function resolver(): SiteContextResolver {
		$session = $this->createMock(IUserSession::class);
		if ($this->uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			$session->method('getUser')->willReturn($user);
		}
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		$sites = $this->createMock(SiteMapper::class);
		$sites->method('find')->willReturn($this->site);
		$shares = $this->createMock(SiteShareMapper::class);
		$shares->method('findBySite')->willReturn($this->shares);
		$content = $this->createMock(ContentService::class);
		$content->method('resolveRoot')->willReturn($this->root);
		return new SiteContextResolver($session, $groups, $sites, $shares, new AccessService(), $content);
	}

	private function share(string $role): SiteShare {
		$share = new SiteShare();
		$share->setShareType('user');
		$share->setShareWith('bob');
		$share->setRole($role);
		return $share;
	}

	private function assertFailure(int $status, string $error): void {
		try {
			$this->resolver()->resolve(3);
			$this->fail('expected SiteAccessException');
		} catch (SiteAccessException $e) {
			$this->assertSame([$status, $error], [$e->status, $e->error]);
			$this->assertSame(['error' => $error], $e->toResponse()->getData());
		}
	}

	public function testUnauthenticated(): void {
		$this->uid = null;
		$this->assertFailure(401, 'unauthenticated');
	}

	public function testUnknownSite(): void {
		$this->site = null;
		$this->assertFailure(404, 'site-not-found');
	}

	public function testNoShareIsForbidden(): void {
		$this->assertFailure(403, 'forbidden');
	}

	public function testMissingRootFolder(): void {
		$this->shares = [$this->share('reader')];
		$this->root = null;
		$this->assertFailure(404, 'site-not-found');
	}

	public function testResolvesRoleAndRoot(): void {
		$this->shares = [$this->share('editor')];
		$ctx = $this->resolver()->resolve(3);
		$this->assertSame('editor', $ctx->role);
		$this->assertSame($this->root, $ctx->root);
		$this->assertSame($this->site, $ctx->site);
		$this->assertTrue($ctx->canEdit());
	}

	public function testReadOnlyFolderIsNotWritable(): void {
		$this->uid = 'alice';
		$this->root->method('isUpdateable')->willReturn(false);
		$ctx = $this->resolver()->resolve(3);
		$this->assertSame('owner', $ctx->role);
		$this->assertFalse($ctx->isWritable());
	}
}
