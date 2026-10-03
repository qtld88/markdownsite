<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCA\MarkdownSite\Service\PageRenderer;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCA\MarkdownSite\Service\SiteLister;
use OCA\MarkdownSite\Service\UserPreferences;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;

class PageController extends Controller {
	private const TTL = 86400;

	private ICache $cache;

	public function __construct(
		IRequest $request,
		private SiteContextResolver $contexts,
		private ContentService $content,
		private PageRenderer $renderer,
		private IndexBuilder $indexBuilder,
		private IInitialState $initialState,
		private IUserSession $userSession,
		private SiteLister $siteLister,
		private UserPreferences $prefs,
		ICacheFactory $cacheFactory,
	) {
		parent::__construct('markdownsite', $request);
		$this->cache = $cacheFactory->createDistributed('markdownsite');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		// Sent with the page: the app starts without waiting for two requests.
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$this->initialState->provideInitialState('sites', $this->siteLister->visibleTo($user));
			$this->initialState->provideInitialState('prefs', $this->prefs->read($user->getUID()));
		}
		return new TemplateResponse('markdownsite', 'main');
	}

	/**
	 * Tree and rendered pages are cached under the root folder's etag, which
	 * changes whenever any file below it changes: an edit invalidates them.
	 */
	#[NoAdminRequired]
	public function tree(int $siteId): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		$key = 'tree/' . $ctx->root->getId() . '/' . $ctx->root->getEtag();
		$data = $this->cache->get($key);
		if (!is_array($data)) {
			$index = $this->indexBuilder->build($ctx->root);
			$aliases = $index->aliases();
			$data = [
				'tree' => $this->content->listTree($ctx->root),
				'home' => $this->content->folderNote($ctx->root) ?? ($index->paths()[0] ?? null),
				// For [[ completion in the editor.
				'pages' => array_map(fn (string $path) => [
					'path' => $path,
					'title' => basename(preg_replace('/\.md$/i', '', $path) ?? $path),
					'aliases' => $aliases[$path] ?? [],
				], $index->paths()),
			];
			$this->cache->set($key, $data, self::TTL);
		}
		return new JSONResponse($data);
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
		// Links in the HTML carry the site id: two sites on one folder differ.
		$key = 'page/' . $siteId . '/' . $ctx->root->getEtag() . '/' . md5($path);
		$data = $this->cache->get($key);
		if (is_array($data)) {
			return new JSONResponse($data);
		}
		try {
			$raw = $this->content->getPageContent($ctx->root, $path);
		} catch (\OCP\Files\NotFoundException | \OCP\Files\InvalidPathException | \OCP\Files\NotPermittedException) {
			return new JSONResponse(['error' => 'page-not-found', 'path' => $path], 404);
		}
		$rendered = $this->renderer->render($ctx, $raw, $path);
		$data = [
			'path' => $path,
			'html' => $rendered['html'],
			'meta' => $rendered['meta'],
			'toc' => $rendered['toc'],
		];
		$this->cache->set($key, $data, self::TTL);
		return new JSONResponse($data);
	}
}
