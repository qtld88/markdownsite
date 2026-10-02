<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<SearchEntry> */
class SearchMapper extends QBMapper {
	/** Most candidate rows a query fetches before ranking. */
	public const MAX_CANDIDATES = 500;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'markdownsite_search', SearchEntry::class);
	}

	/**
	 * Stored etag per path, without loading page text.
	 * @return array<string,string> path => etag
	 */
	public function etagsBySite(int $siteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('path', 'etag')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		$out = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$out[(string) $row['path']] = (string) $row['etag'];
		}
		$result->closeCursor();
		return $out;
	}

	/** Inserts the page, or replaces the row already stored for its path. */
	public function upsert(SearchEntry $entry): void {
		$entry->setPathHash(sha1($entry->getPath()));
		$existing = $this->findId($entry->getSiteId(), $entry->getPathHash());
		if ($existing !== null) {
			$entry->setId($existing);
			$this->update($entry);
			return;
		}
		try {
			$this->insert($entry);
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// A concurrent request indexed the same page first: overwrite it.
			$entry->setId((int) $this->findId($entry->getSiteId(), $entry->getPathHash()));
			$this->update($entry);
		}
	}

	/** @param string[] $paths */
	public function deletePaths(int $siteId, array $paths): void {
		foreach (array_chunk($paths, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('path_hash', $qb->createNamedParameter(array_map('sha1', $chunk), IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deleteBySite(int $siteId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Rows of $siteIds where every term occurs in the title, an alias, a
	 * heading or the body (normalised columns). At most MAX_CANDIDATES rows.
	 *
	 * @param int[] $siteIds
	 * @param list<string> $terms normalised terms
	 * @return list<array<string,mixed>>
	 */
	public function candidates(array $siteIds, array $terms): array {
		if ($siteIds === [] || $terms === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('site_id', 'path', 'title', 'title_norm', 'aliases_norm', 'headings_norm', 'body', 'body_norm')
			->from($this->getTableName())
			->where($qb->expr()->in('site_id', $qb->createNamedParameter($siteIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->setMaxResults(self::MAX_CANDIDATES);
		foreach ($terms as $term) {
			$like = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%');
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->like('title_norm', $like),
				$qb->expr()->like('aliases_norm', $like),
				$qb->expr()->like('headings_norm', $like),
				$qb->expr()->like('body_norm', $like),
			));
		}
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function findId(int $siteId, string $pathHash): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter($pathHash)));
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id === false ? null : (int) $id;
	}
}
