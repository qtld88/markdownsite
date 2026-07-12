<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCA\MarkdownSite\Wiki\LinkResolver;
use OCA\MarkdownSite\Wiki\MarkdownRenderer;
use OCA\MarkdownSite\Wiki\NcUrlBuilder;
use OCA\MarkdownSite\Wiki\PathResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteMapper $sites,
		private ContentService $content,
		private IndexBuilder $indexBuilder,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
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
		$root = $this->root($siteId);
		if ($root instanceof JSONResponse) {
			return $root;
		}
		return new JSONResponse([
			'tree' => $this->content->listTree($root),
			'home' => $this->homePath($root),
		]);
	}

	#[NoAdminRequired]
	public function page(int $siteId, string $path): JSONResponse {
		$root = $this->root($siteId);
		if ($root instanceof JSONResponse) {
			return $root;
		}
		if (!preg_match('/\.md$/i', $path)) {
			$path .= '.md';
		}
		try {
			$raw = $this->content->getPageContent($root, $path);
		} catch (NotFoundException) {
			return new JSONResponse(['error' => 'page-not-found', 'path' => $path], 404);
		}
		$currentDir = trim(dirname($path) === '.' ? '' : dirname($path), '/');
		$index = $this->indexBuilder->build($root);
		$resolver = new LinkResolver(new PathResolver(), $index);
		$urls = new NcUrlBuilder($this->urlGenerator, $siteId);
		$renderer = new MarkdownRenderer($resolver, $urls);
		$rendered = $renderer->render($raw, $currentDir);
		return new JSONResponse([
			'path' => $path,
			'html' => $rendered['html'],
			'meta' => $rendered['meta'],
		]);
	}

	private function homePath(\OCP\Files\Folder $root): ?string {
		foreach (['Readme.md', 'README.md', 'readme.md', 'index.md', 'Index.md'] as $name) {
			try {
				$root->get($name);
				return $name;
			} catch (NotFoundException) {
			}
		}
		$md = $this->content->listMarkdownPaths($root);
		return $md[0] ?? null;
	}

	private function root(int $siteId): \OCP\Files\Folder|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}
		$site = $this->sites->find($siteId);
		if ($site === null) {
			return new JSONResponse(['error' => 'site-not-found'], 404);
		}
		$root = $this->content->resolveRoot($site, $user->getUID());
		if ($root === null) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}
		return $root;
	}
}
