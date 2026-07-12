<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Site> */
class SiteMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'markdownsite_sites', Site::class);
	}

	public function find(int $id): ?Site {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return Site[] */
	public function findOwned(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)))
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Sites owned by $uid or shared to $uid / any of $groupIds.
	 * @param string[] $groupIds
	 * @return Site[]
	 */
	public function findVisible(string $uid, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('s.*')
			->from($this->getTableName(), 's')
			->leftJoin('s', 'markdownsite_shares', 'sh', 's.id = sh.site_id');

		$own = $qb->expr()->eq('s.owner_uid', $qb->createNamedParameter($uid));
		$sharedUser = $qb->expr()->andX(
			$qb->expr()->eq('sh.share_type', $qb->createNamedParameter('user')),
			$qb->expr()->eq('sh.share_with', $qb->createNamedParameter($uid)),
		);
		$ors = [$own, $sharedUser];
		if (count($groupIds) > 0) {
			$ors[] = $qb->expr()->andX(
				$qb->expr()->eq('sh.share_type', $qb->createNamedParameter('group')),
				$qb->expr()->in('sh.share_with', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}
		$qb->where($qb->expr()->orX(...$ors))->orderBy('s.name', 'ASC');
		return $this->findEntities($qb);
	}
}
