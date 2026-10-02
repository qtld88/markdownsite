<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Service\AttachmentLocator;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\LinkUpdater;
use OCA\MarkdownSite\Service\PageRenderer;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContext;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\NameValidator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

/**
 * Editing and file management for owners and editors. Every write goes
 * through the owner's mount (the site root); nothing outside it is reachable.
 */
class EditController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteContextResolver $contexts,
		private ContentService $content,
		private LinkUpdater $links,
		private AttachmentLocator $attachments,
		private SearchIndexer $search,
		private PageRenderer $renderer,
	) {
		parent::__construct('markdownsite', $request);
	}

	/** Raw Markdown and etag of a page. */
	#[NoAdminRequired]
	public function source(int $siteId, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path): JSONResponse {
			$file = $this->file($ctx, self::pagePath($path));
			return new JSONResponse(['content' => $file->getContent(), 'etag' => $file->getEtag()]);
		});
	}

	/** Saves a page. A stale etag gets 409 with the server's current text. */
	#[NoAdminRequired]
	public function save(int $siteId, string $path, string $content, string $etag = ''): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path, $content, $etag): JSONResponse {
			$path = self::pagePath($path);
			$file = $this->file($ctx, $path);
			if ($file->getEtag() !== $etag) {
				return new JSONResponse(['error' => 'conflict', 'etag' => $file->getEtag(), 'content' => $file->getContent()], 409);
			}
			$file->putContent($content);
			$this->search->indexPage($ctx->site, $ctx->root, $path);
			return new JSONResponse(['etag' => $this->file($ctx, $path)->getEtag()]);
		});
	}

	/** Creates an empty page. */
	#[NoAdminRequired]
	public function createPage(int $siteId, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path): JSONResponse {
			$path = NameValidator::pagePath($path);
			$this->assertFree($ctx, $path);
			$file = $ctx->root->newFile($path, '');
			$this->search->indexPage($ctx->site, $ctx->root, $path);
			return new JSONResponse(['path' => $path, 'etag' => $file->getEtag()]);
		});
	}

	#[NoAdminRequired]
	public function createFolder(int $siteId, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path): JSONResponse {
			$path = NameValidator::folderPath($path);
			$this->assertFree($ctx, $path);
			$ctx->root->newFolder($path);
			return new JSONResponse(['path' => $path]);
		});
	}

	/**
	 * Links that moving $path to $to would rewrite: {count, pages}. A folder
	 * counts the links to every page inside it.
	 */
	#[NoAdminRequired]
	public function backlinks(int $siteId, string $path, string $to): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path, $to): JSONResponse {
			$moves = $this->moves($ctx, trim($path, '/'), $to);
			$plan = $this->links->plan($ctx->root, $moves);
			return new JSONResponse([
				'count' => array_sum(array_column($plan, 'count')),
				'pages' => array_keys($plan),
			]);
		});
	}

	/**
	 * Renames or moves a page or folder, then (when $updateLinks) rewrites
	 * the links to it. A page that could not be rewritten is listed in
	 * `failed`; the move is not rolled back.
	 */
	#[NoAdminRequired]
	public function move(int $siteId, string $from, string $to, bool $updateLinks = true): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($from, $to, $updateLinks): JSONResponse {
			$from = trim($from, '/');
			$moves = $this->moves($ctx, $from, $to);
			$plan = $updateLinks ? $this->links->plan($ctx->root, $moves) : [];
			$node = $this->content->getChild($ctx->root, $from);
			$movedPages = $node instanceof Folder ? array_keys($this->content->listMarkdownEtags($ctx->root, $from)) : [$from];

			foreach ($moves as [$a, $b]) {
				$this->content->getChild($ctx->root, $a)->move($ctx->root->getPath() . '/' . $b);
			}

			$failed = [];
			foreach ($plan as $oldPath => $change) {
				$newPath = LinkRewriter::mapThrough((string) $oldPath, $moves);
				try {
					$file = $this->content->getChild($ctx->root, $newPath);
					if (!$file instanceof File) {
						throw new NotFoundException($newPath);
					}
					$file->putContent($change['content']);
				} catch (\Throwable) {
					$failed[] = $newPath;
				}
			}

			foreach ($movedPages as $old) {
				$this->search->removePage($ctx->site, (string) $old);
			}
			$reindex = array_map(fn ($p) => LinkRewriter::mapThrough((string) $p, $moves), array_merge($movedPages, array_keys($plan)));
			foreach (array_unique($reindex) as $path) {
				$this->search->indexPage($ctx->site, $ctx->root, $path);
			}
			return new JSONResponse([
				'path' => LinkRewriter::mapThrough($from, $moves),
				'updated' => count($plan) - count($failed),
				'failed' => $failed,
			]);
		});
	}

	/** Moves a page or folder (with everything in it) to the trash bin. */
	#[NoAdminRequired]
	public function delete(int $siteId, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path): JSONResponse {
			$path = trim($path, '/');
			if ($path === '') {
				return new JSONResponse(['error' => 'site-root'], 400);
			}
			$node = $this->content->getChild($ctx->root, $path);
			$pages = $node instanceof Folder ? array_keys($this->content->listMarkdownEtags($ctx->root, $path)) : [$path];
			$node->delete();
			foreach ($pages as $page) {
				$this->search->removePage($ctx->site, (string) $page);
			}
			return new JSONResponse(['ok' => true]);
		});
	}

	/**
	 * Stores an uploaded file (multipart field "file") in the attachment
	 * folder of $page and returns the embed to insert.
	 */
	#[NoAdminRequired]
	public function upload(int $siteId, string $page): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($page): JSONResponse {
			$upload = $this->request->getUploadedFile('file');
			if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
				$code = is_array($upload) ? (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
				$tooBig = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE;
				return new JSONResponse(['error' => 'upload-failed', 'message' => $tooBig ? 'File is too large' : 'No file received'], $tooBig ? 413 : 400);
			}
			$name = NameValidator::folderPath(basename((string) ($upload['name'] ?? 'file')));
			$page = self::pagePath($page);
			$dir = $this->attachments->folderFor($ctx->root, $page);
			$folder = $this->ensureFolder($ctx->root, $dir);
			$name = self::freeName($folder, $name);
			$stream = fopen($upload['tmp_name'], 'rb');
			try {
				$folder->newFile($name, $stream);
			} catch (\Throwable $e) {
				// Quota exceeded, storage full, …: show the server's reason.
				return new JSONResponse(['error' => 'upload-failed', 'message' => $e->getMessage()], 507);
			} finally {
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
			$path = $dir === '' ? $name : $dir . '/' . $name;
			return new JSONResponse([
				'path' => $path,
				'embed' => '![[' . LinkRewriter::relativePath(LinkUpdater::dir($page), $path) . ']]',
			]);
		});
	}

	/** Renders a Markdown fragment as it would appear on the page at $path (editor widgets). */
	#[NoAdminRequired]
	public function render(int $siteId, string $markdown, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($markdown, $path): JSONResponse {
			return new JSONResponse(['html' => $this->renderer->render($ctx, $markdown, self::pagePath($path))['html']]);
		});
	}

	/**
	 * Runs $action for a user who may edit a writable site, mapping failures
	 * to JSON errors.
	 */
	private function guard(int $siteId, callable $action): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		if (!$ctx->canEdit()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}
		if (!$ctx->root->isUpdateable()) {
			return new JSONResponse(['error' => 'folder-read-only'], 403);
		}
		try {
			return $action($ctx);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'invalid-name', 'reason' => $e->getMessage()], 400);
		} catch (MoveRefused $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode());
		} catch (NotFoundException | InvalidPathException) {
			return new JSONResponse(['error' => 'not-found'], 404);
		} catch (NotPermittedException) {
			return new JSONResponse(['error' => 'not-permitted'], 403);
		}
	}

	/**
	 * [from, to] steps for moving $from to $to. Renaming folder X whose note
	 * is X/X.md also renames the note after the folder.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function moves(SiteContext $ctx, string $from, string $to): array {
		if ($from === '') {
			throw new MoveRefused('site-root', 400);
		}
		$node = $this->content->getChild($ctx->root, $from);
		$to = $node instanceof Folder ? NameValidator::folderPath($to) : NameValidator::pagePath($to);
		if ($to === $from) {
			throw new MoveRefused('same-path', 400);
		}
		if ($node instanceof Folder && stripos($to . '/', $from . '/') === 0) {
			throw new MoveRefused('into-itself', 400);
		}
		if (strcasecmp($to, $from) !== 0) {
			$this->assertFree($ctx, $to);
		}
		$parent = LinkUpdater::dir($to);
		if ($parent !== '' && !($this->tryChild($ctx, $parent) instanceof Folder)) {
			throw new MoveRefused('parent-not-found', 404);
		}
		$moves = [[$from, $to]];
		$oldName = basename($from);
		$newName = basename($to);
		if ($node instanceof Folder && $oldName !== $newName
			&& strcasecmp((string) $this->content->folderNote($node), $oldName . '.md') === 0) {
			$note = (string) $this->content->folderNote($node);
			$moves[] = [$to . '/' . $note, $to . '/' . $newName . '.md'];
		}
		return $moves;
	}

	private function assertFree(SiteContext $ctx, string $path): void {
		if ($this->tryChild($ctx, $path) !== null) {
			throw new MoveRefused('exists', 409);
		}
	}

	private function tryChild(SiteContext $ctx, string $path): ?\OCP\Files\Node {
		try {
			return $this->content->getChild($ctx->root, $path);
		} catch (NotFoundException) {
			return null;
		}
	}

	private function file(SiteContext $ctx, string $path): File {
		$node = $this->content->getChild($ctx->root, $path);
		if (!$node instanceof File) {
			throw new NotFoundException($path);
		}
		return $node;
	}

	private function ensureFolder(Folder $root, string $path): Folder {
		$folder = $root;
		foreach ($path === '' ? [] : explode('/', $path) as $segment) {
			$folder = $folder->nodeExists($segment) ? $folder->get($segment) : $folder->newFolder($segment);
			if (!$folder instanceof Folder) {
				throw new MoveRefused('exists', 409);
			}
		}
		return $folder;
	}

	/** "image.png", else "image 1.png", "image 2.png", … */
	private static function freeName(Folder $folder, string $name): string {
		$dot = strrpos($name, '.');
		$stem = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
		$ext = $dot === false || $dot === 0 ? '' : substr($name, $dot);
		$candidate = $name;
		for ($i = 1; $folder->nodeExists($candidate); $i++) {
			$candidate = $stem . ' ' . $i . $ext;
		}
		return $candidate;
	}

	private static function pagePath(string $path): string {
		$path = trim($path, '/');
		return preg_match('/\.md$/i', $path) ? $path : $path . '.md';
	}
}
