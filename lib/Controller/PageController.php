<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Service\AccessService;
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
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteMapper $sites,
		private SiteShareMapper $shareMapper,
		private AccessService $access,
		private ContentService $content,
		private IndexBuilder $indexBuilder,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
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
		} catch (\OCP\Files\NotFoundException | \OCP\Files\InvalidPathException | \OCP\Files\NotPermittedException) {
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
			'toc' => $rendered['toc'],
		]);
	}

	/** The root's folder note (`<RootName>.md`, `index.md`, `README.md`), else the first page. */
	private function homePath(\OCP\Files\Folder $root): ?string {
		$note = $this->content->folderNote($root);
		if ($note !== null) {
			return $note;
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
		$uid = $user->getUID();
		$groups = $this->groupManager->getUserGroupIds($user);
		$shares = $this->shareMapper->findBySite($siteId);
		if (!$this->access->canView($site, $uid, $groups, $shares)) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}
		$root = $this->content->resolveRoot($site);
		if ($root === null) {
			return new JSONResponse(['error' => 'site-not-found'], 404);
		}
		return $root;
	}
}
