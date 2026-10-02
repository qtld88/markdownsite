<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\NameValidator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/** Where files dropped or pasted into a page are stored, following Obsidian's setting. */
class AttachmentLocator {
	public const DEFAULT_FOLDER = 'attachments';

	/**
	 * Root-relative folder for attachments of the page at $pagePath, from
	 * `.obsidian/app.json` → `attachmentFolderPath`:
	 * `./` or `./sub` → relative to the page's folder; `Folder` → that folder
	 * from the root; absent, empty or `/` → `attachments`.
	 */
	public function folderFor(Folder $root, string $pagePath): string {
		$setting = $this->setting($root);
		$dir = dirname($pagePath);
		$pageDir = $dir === '.' ? '' : $dir;
		if ($setting === null || $setting === '' || $setting === '/') {
			return self::DEFAULT_FOLDER;
		}
		if ($setting === '.' || $setting === './') {
			return $pageDir;
		}
		$path = str_starts_with($setting, './')
			? trim($pageDir . '/' . substr($setting, 2), '/')
			: trim($setting, '/');
		try {
			return $path === '' ? '' : NameValidator::folderPath($path);
		} catch (\InvalidArgumentException) {
			return self::DEFAULT_FOLDER; // e.g. "../outside": never leave the site
		}
	}

	private function setting(Folder $root): ?string {
		try {
			$file = $root->get('.obsidian/app.json');
		} catch (NotFoundException) {
			return null;
		}
		if (!$file instanceof File) {
			return null;
		}
		$json = json_decode($file->getContent(), true);
		$value = is_array($json) ? ($json['attachmentFolderPath'] ?? null) : null;
		return is_string($value) ? trim($value) : null;
	}
}
