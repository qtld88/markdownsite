<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteShare;

class AccessService {
	/**
	 * True if $viewerUid may view $site: owner, or shared to the user
	 * directly, or shared to one of the user's groups. Deliberately does
	 * NOT consult Files access — that check (the "double-garde") is gone.
	 *
	 * @param string[] $viewerGroups
	 * @param SiteShare[] $shares
	 */
	public function canView(Site $site, string $viewerUid, array $viewerGroups, array $shares): bool {
		if ($site->getOwnerUid() === $viewerUid) {
			return true;
		}
		foreach ($shares as $share) {
			if ($share->getShareType() === 'user' && $share->getShareWith() === $viewerUid) {
				return true;
			}
			if ($share->getShareType() === 'group' && in_array($share->getShareWith(), $viewerGroups, true)) {
				return true;
			}
		}
		return false;
	}
}
