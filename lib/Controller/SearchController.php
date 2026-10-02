<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Search\QueryParser;
use OCA\MarkdownSite\Search\Ranker;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class SearchController extends Controller {
	public const PAGE_SIZE = 50;

	public function __construct(
		IRequest $request,
		private SiteMapper $sites,
		private SiteShareMapper $shareMapper,
		private AccessService $access,
		private ContentService $content,
		private SearchIndexer $indexer,
		private SearchMapper $index,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct('markdownsite', $request);
	}

	/**
	 * Full-text search in one site ($site) or in every site the user can
	 * read (no $site). 50 results from $offset, best first.
	 */
	#[NoAdminRequired]
	public function search(string $q = '', ?int $site = null, int $offset = 0): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}
		$uid = $user->getUID();
		$groups = $this->groupManager->getUserGroupIds($user);

		if ($site !== null) {
			$one = $this->sites->find($site);
			if ($one === null) {
				return new JSONResponse(['error' => 'site-not-found'], 404);
			}
			$candidates = [$one];
		} else {
			$candidates = $this->sites->findVisible($uid, $groups);
		}
		// findVisible() already filters by share; checking again keeps one rule for both cases.
		$readable = array_values(array_filter(
			$candidates,
			fn (Site $s) => $this->access->canView($s, $uid, $groups, $this->shareMapper->findBySite($s->getId())),
		));
		if ($site !== null && $readable === []) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$terms = QueryParser::parse($q);
		if ($terms === []) {
			return new JSONResponse(['results' => [], 'total' => 0, 'offset' => 0]);
		}

		$byId = [];
		foreach ($readable as $s) {
			$root = $this->content->resolveRoot($s);
			if ($root === null) {
				continue; // folder deleted or no longer reachable
			}
			$this->indexer->refresh($s, $root);
			$byId[$s->getId()] = $s;
		}

		$ranked = [];
		foreach ($this->index->candidates(array_keys($byId), $terms) as $row) {
			$ranked[] = ['row' => $row, 'score' => Ranker::score($terms, [
				'title_norm' => (string) $row['title_norm'],
				'aliases_norm' => (string) ($row['aliases_norm'] ?? ''),
				'headings_norm' => (string) ($row['headings_norm'] ?? ''),
				'body_norm' => (string) ($row['body_norm'] ?? ''),
			])];
		}
		usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']
			?: strcasecmp((string) $a['row']['title'], (string) $b['row']['title']));

		$offset = max(0, $offset);
		$results = [];
		foreach (array_slice($ranked, $offset, self::PAGE_SIZE) as $item) {
			$row = $item['row'];
			$siteId = (int) $row['site_id'];
			$path = (string) $row['path'];
			$dir = dirname($path);
			$snippet = Ranker::snippet((string) ($row['body'] ?? ''), (string) ($row['body_norm'] ?? ''), $terms);
			$results[] = [
				'siteId' => $siteId,
				'siteName' => $byId[$siteId]->getName(),
				'path' => $path,
				'title' => (string) $row['title'],
				'folder' => $dir === '.' ? '' : $dir,
				'snippet' => $snippet['snippet'],
				'highlights' => $snippet['highlights'],
			];
		}
		return new JSONResponse(['results' => $results, 'total' => count($ranked), 'offset' => $offset]);
	}
}
