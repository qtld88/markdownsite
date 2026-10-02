<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCP\IGroupManager;
use OCP\IUserSession;

/** user → site → shares → role → root folder, shared by every per-site controller. */
class SiteContextResolver {
	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private SiteMapper $sites,
		private SiteShareMapper $shareMapper,
		private AccessService $access,
		private ContentService $content,
	) {
	}

	/** @throws SiteAccessException 401 unauthenticated, 404 site-not-found, 403 forbidden */
	public function resolve(int $siteId): SiteContext {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new SiteAccessException(401, 'unauthenticated');
		}
		$site = $this->sites->find($siteId);
		if ($site === null) {
			throw new SiteAccessException(404, 'site-not-found');
		}
		$role = $this->access->roleFor(
			$site,
			$user->getUID(),
			$this->groupManager->getUserGroupIds($user),
			$this->shareMapper->findBySite($siteId),
		);
		if ($role === null) {
			throw new SiteAccessException(403, 'forbidden');
		}
		$root = $this->content->resolveRoot($site);
		if ($root === null) {
			throw new SiteAccessException(404, 'site-not-found');
		}
		return new SiteContext($site, $root, $role);
	}
}
