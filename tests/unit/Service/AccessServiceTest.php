<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Service\AccessService;
use PHPUnit\Framework\TestCase;

class AccessServiceTest extends TestCase {
	private AccessService $access;

	protected function setUp(): void {
		$this->access = new AccessService();
	}

	private function site(string $ownerUid): Site {
		$site = new Site();
		$site->setOwnerUid($ownerUid);
		$site->setName('Test');
		$site->setRootFileId(1);
		$site->setCreatedAt(time());
		return $site;
	}

	private function userShare(string $uid): SiteShare {
		$s = new SiteShare();
		$s->setSiteId(1);
		$s->setShareType('user');
		$s->setShareWith($uid);
		return $s;
	}

	private function groupShare(string $gid): SiteShare {
		$s = new SiteShare();
		$s->setSiteId(1);
		$s->setShareType('group');
		$s->setShareWith($gid);
		return $s;
	}

	public function testOwnerCanAlwaysView(): void {
		$site = $this->site('alice');
		$this->assertTrue($this->access->canView($site, 'alice', [], []));
	}

	public function testUserSharedToCanView(): void {
		$site = $this->site('alice');
		$shares = [$this->userShare('bob')];
		$this->assertTrue($this->access->canView($site, 'bob', [], $shares));
	}

	public function testGroupSharedToCanView(): void {
		$site = $this->site('alice');
		$shares = [$this->groupShare('staff')];
		$this->assertTrue($this->access->canView($site, 'bob', ['staff', 'other'], $shares));
	}

	public function testUnrelatedUserCannotView(): void {
		$site = $this->site('alice');
		$shares = [$this->userShare('bob'), $this->groupShare('staff')];
		$this->assertFalse($this->access->canView($site, 'carol', ['visitors'], $shares));
	}

	public function testNoSharesMeansOnlyOwnerCanView(): void {
		$site = $this->site('alice');
		$this->assertFalse($this->access->canView($site, 'bob', [], []));
	}

	public function testGroupShareDoesNotMatchOnUserUid(): void {
		$site = $this->site('alice');
		// share_with = 'bob' but as a GROUP share; the user 'bob' should only
		// match if 'bob' is also one of his own group ids.
		$shares = [$this->groupShare('bob')];
		$this->assertFalse($this->access->canView($site, 'bob', [], $shares));
		$this->assertTrue($this->access->canView($site, 'bob', ['bob'], $shares));
	}
}
