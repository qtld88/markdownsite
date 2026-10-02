<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteShare;

class AccessService {
	public const OWNER = 'owner';
	public const EDITOR = 'editor';
	public const READER = 'reader';

	/**
	 * The viewer's role on $site: 'owner', else the strongest role among the
	 * shares to the user directly or to one of the user's groups ('editor'
	 * beats 'reader'), else null. Deliberately does NOT consult Files access.
	 *
	 * @param string[] $viewerGroups
	 * @param SiteShare[] $shares
	 * @return 'owner'|'editor'|'reader'|null
	 */
	public function roleFor(Site $site, string $viewerUid, array $viewerGroups, array $shares): ?string {
		if ($site->getOwnerUid() === $viewerUid) {
			return self::OWNER;
		}
		$role = null;
		foreach ($shares as $share) {
			$matches = ($share->getShareType() === 'user' && $share->getShareWith() === $viewerUid)
				|| ($share->getShareType() === 'group' && in_array($share->getShareWith(), $viewerGroups, true));
			if (!$matches) {
				continue;
			}
			if ($share->getRole() === self::EDITOR) {
				return self::EDITOR;
			}
			$role = self::READER;
		}
		return $role;
	}

	/**
	 * @param string[] $viewerGroups
	 * @param SiteShare[] $shares
	 */
	public function canView(Site $site, string $viewerUid, array $viewerGroups, array $shares): bool {
		return $this->roleFor($site, $viewerUid, $viewerGroups, $shares) !== null;
	}

	/**
	 * Owner or editor. Writing also needs a writable folder (see SiteContext).
	 *
	 * @param string[] $viewerGroups
	 * @param SiteShare[] $shares
	 */
	public function canEdit(Site $site, string $viewerUid, array $viewerGroups, array $shares): bool {
		return self::isEditorRole($this->roleFor($site, $viewerUid, $viewerGroups, $shares));
	}

	public static function isEditorRole(?string $role): bool {
		return $role === self::OWNER || $role === self::EDITOR;
	}
}
