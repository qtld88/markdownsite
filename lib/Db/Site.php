<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $v)
 * @method string getName()
 * @method void setName(string $v)
 * @method string|null getIcon()
 * @method void setIcon(?string $v)
 * @method int getRootFileId()
 * @method void setRootFileId(int $v)
 * @method string|null getRootHintPath()
 * @method void setRootHintPath(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method string|null getSearchEtag()
 * @method void setSearchEtag(?string $v)
 */
class Site extends Entity {
	protected string $ownerUid = '';
	protected string $name = '';
	protected ?string $icon = null;
	protected int $rootFileId = 0;
	protected ?string $rootHintPath = null;
	protected int $createdAt = 0;
	protected ?string $searchEtag = null;

	public function __construct() {
		$this->addType('ownerUid', 'string');
		$this->addType('name', 'string');
		$this->addType('icon', 'string');
		$this->addType('rootFileId', 'integer');
		$this->addType('rootHintPath', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('searchEtag', 'string');
	}

	public function toArray(): array {
		return [
			'id' => $this->getId(),
			'ownerUid' => $this->getOwnerUid(),
			'name' => $this->getName(),
			'icon' => $this->getIcon(),
			'rootFileId' => $this->getRootFileId(),
			'rootHintPath' => $this->getRootHintPath(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
