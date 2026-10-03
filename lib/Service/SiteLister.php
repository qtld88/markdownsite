<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCP\IGroupManager;
use OCP\IUser;

/** The sites a user sees, as the site list and the page's initial state give them. */
class SiteLister {
	public function __construct(
		private SiteMapper $sites,
		private SiteShareMapper $shares,
		private IGroupManager $groupManager,
		private AccessService $access,
		private ContentService $content,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function visibleTo(IUser $user): array {
		$uid = $user->getUID();
		$groups = $this->groupManager->getUserGroupIds($user);
		$out = [];
		foreach ($this->sites->findVisible($uid, $groups) as $site) {
			$dto = $site->toArray();
			$dto['isOwner'] = $site->getOwnerUid() === $uid;
			if (!$dto['isOwner']) {
				// Don't leak the owner's uid to a share recipient.
				unset($dto['ownerUid']);
			}
			$role = $this->access->roleFor($site, $uid, $groups, $this->shares->findBySite($site->getId()));
			$dto['role'] = $role;
			// Editing needs an editor role AND a folder the owner can write to.
			$root = AccessService::isEditorRole($role) ? $this->content->resolveRoot($site) : null;
			$dto['writable'] = $root !== null && $root->isUpdateable();
			$out[] = $dto;
		}
		return $out;
	}
}
