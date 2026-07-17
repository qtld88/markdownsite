<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getSiteId()
 * @method void setSiteId(int $v)
 * @method string getShareType()
 * @method void setShareType(string $v)
 * @method string getShareWith()
 * @method void setShareWith(string $v)
 */
class SiteShare extends Entity {
	protected int $siteId = 0;
	protected string $shareType = '';   // 'user' | 'group'
	protected string $shareWith = '';

	public function __construct() {
		$this->addType('siteId', 'integer');
		$this->addType('shareType', 'string');
		$this->addType('shareWith', 'string');
	}
}
