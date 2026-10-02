<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\Site;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

class ContentService {
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/** Resolve the site root in its owner's mount, or null if the folder is gone. */
	public function resolveRoot(Site $site): ?Folder {
		$ownerFolder = $this->rootFolder->getUserFolder($site->getOwnerUid());
		$node = $ownerFolder->getFirstNodeById($site->getRootFileId());
		return $node instanceof Folder ? $node : null;
	}

	/** Raw markdown of a page. Throws NotFoundException if missing or outside root. */
	public function getPageContent(Folder $root, string $relPath): string {
		$node = $this->getChild($root, $relPath);
		if ($node instanceof File) {
			return $node->getContent();
		}
		throw new NotFoundException($relPath);
	}

	public function getMtime(Folder $root, string $relPath): int {
		return $this->getChild($root, $relPath)->getMTime();
	}

	/** A node inside root, guarding against traversal outside root. */
	public function getChild(Folder $root, string $relPath): Node {
		$relPath = trim($relPath, '/');
		$node = $relPath === '' ? $root : $root->get($relPath);
		// Guard: node must be inside root.
		$rootPath = rtrim($root->getPath(), '/');
		$nodePath = $node->getPath();
		if ($nodePath !== $rootPath && !str_starts_with($nodePath, $rootPath . '/')) {
			throw new NotFoundException($relPath);
		}
		return $node;
	}

	/**
	 * Recursive tree of folders and .md files, paths relative to root. A
	 * folder with a folder note (see folderNote()) gets `note` = the note's
	 * path, and the note is left out of its children.
	 * @return array<int,array{name:string,path:string,type:string,children?:array,note?:string}>
	 */
	public function listTree(Folder $root, string $rel = ''): array {
		$base = $rel === '' ? $root : $root->get($rel);
		if (!($base instanceof Folder)) {
			return [];
		}
		return $this->listFolder($base, $rel, null);
	}

	/**
	 * File name of $folder's folder note, or null. Looked up case-insensitively
	 * in this order: `<FolderName>.md`, `index.md`, `README.md`.
	 */
	public function folderNote(Folder $folder): ?string {
		$files = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$files[mb_strtolower($node->getName())] ??= $node->getName();
			}
		}
		foreach ([mb_strtolower($folder->getName()) . '.md', 'index.md', 'readme.md'] as $candidate) {
			if (isset($files[$candidate])) {
				return $files[$candidate];
			}
		}
		return null;
	}

	/** @return array<int,array{name:string,path:string,type:string,children?:array,note?:string}> */
	private function listFolder(Folder $base, string $rel, ?string $skip): array {
		$out = [];
		foreach ($base->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (str_starts_with($name, '.') || $name === $skip) {
				continue;
			}
			$path = $rel === '' ? $name : $rel . '/' . $name;
			if ($node instanceof Folder) {
				$note = $this->folderNote($node);
				$entry = ['name' => $name, 'path' => $path, 'type' => 'dir',
					'children' => $this->listFolder($node, $path, $note)];
				if ($note !== null) {
					$entry['note'] = $path . '/' . $note;
				}
				$out[] = $entry;
			} elseif (preg_match('/\.md$/i', $name)) {
				$out[] = ['name' => preg_replace('/\.md$/i', '', $name), 'path' => $path, 'type' => 'page'];
			}
		}
		usort($out, function ($a, $b) {
			if ($a['type'] !== $b['type']) {
				return $a['type'] === 'dir' ? -1 : 1;
			}
			return strcasecmp($a['name'], $b['name']);
		});
		return $out;
	}

	/** All .md paths under root, relative to root. @return string[] */
	public function listMarkdownPaths(Folder $root, string $rel = ''): array {
		return array_map('strval', array_keys($this->listMarkdownEtags($root, $rel)));
	}

	/**
	 * All .md files under root with their etag, read from file metadata: no
	 * file is opened. @return array<string,string> path relative to root => etag
	 */
	public function listMarkdownEtags(Folder $root, string $rel = ''): array {
		$base = $rel === '' ? $root : $root->get($rel);
		if (!($base instanceof Folder)) {
			return [];
		}
		return $this->collectMarkdown($base, $rel);
	}

	/** @return array<string,string> */
	private function collectMarkdown(Folder $base, string $rel): array {
		$out = [];
		foreach ($base->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (str_starts_with($name, '.')) {
				continue;
			}
			$path = $rel === '' ? $name : $rel . '/' . $name;
			if ($node instanceof Folder) {
				$out += $this->collectMarkdown($node, $path);
			} elseif (preg_match('/\.md$/i', $name)) {
				$out[$path] = $node->getEtag();
			}
		}
		return $out;
	}
}
