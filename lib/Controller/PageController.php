<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCA\MarkdownSite\Service\PageRenderer;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\Folder;
use OCP\IRequest;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteContextResolver $contexts,
		private ContentService $content,
		private PageRenderer $renderer,
		private IndexBuilder $indexBuilder,
	) {
		parent::__construct('markdownsite', $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse('markdownsite', 'main');
	}

	#[NoAdminRequired]
	public function tree(int $siteId): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		$index = $this->indexBuilder->build($ctx->root);
		$aliases = $index->aliases();
		return new JSONResponse([
			'tree' => $this->content->listTree($ctx->root),
			'home' => $this->homePath($ctx->root),
			// For [[ completion in the editor.
			'pages' => array_map(fn (string $path) => [
				'path' => $path,
				'title' => basename(preg_replace('/\.md$/i', '', $path) ?? $path),
				'aliases' => $aliases[$path] ?? [],
			], $index->paths()),
		]);
	}

	#[NoAdminRequired]
	public function page(int $siteId, string $path): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		if (!preg_match('/\.md$/i', $path)) {
			$path .= '.md';
		}
		try {
			$raw = $this->content->getPageContent($ctx->root, $path);
		} catch (\OCP\Files\NotFoundException | \OCP\Files\InvalidPathException | \OCP\Files\NotPermittedException) {
			return new JSONResponse(['error' => 'page-not-found', 'path' => $path], 404);
		}
		$rendered = $this->renderer->render($ctx, $raw, $path);
		return new JSONResponse([
			'path' => $path,
			'html' => $rendered['html'],
			'meta' => $rendered['meta'],
			'toc' => $rendered['toc'],
		]);
	}

	/** The root's folder note (`<RootName>.md`, `index.md`, `README.md`), else the first page. */
	private function homePath(Folder $root): ?string {
		$note = $this->content->folderNote($root);
		if ($note !== null) {
			return $note;
		}
		$md = $this->content->listMarkdownPaths($root);
		return $md[0] ?? null;
	}
}
