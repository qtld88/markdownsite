<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<SiteShare> */
class SiteShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'markdownsite_shares', SiteShare::class);
	}

	/** @return SiteShare[] */
	public function findBySite(int $siteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function deleteBySite(int $siteId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
