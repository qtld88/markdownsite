<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\Site;
use OCP\Files\Folder;

/** A site, its root folder in the owner's mount, and the current user's role on it. */
class SiteContext {
	/** @param 'owner'|'editor'|'reader' $role */
	public function __construct(
		public readonly Site $site,
		public readonly Folder $root,
		public readonly string $role,
	) {
	}

	public function canEdit(): bool {
		return AccessService::isEditorRole($this->role);
	}

	/** Owner or editor, and the owner can write to the folder. */
	public function isWritable(): bool {
		return $this->canEdit() && $this->root->isUpdateable();
	}
}
