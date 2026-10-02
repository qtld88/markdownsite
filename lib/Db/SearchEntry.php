<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One indexed page.
 *
 * @method int getSiteId()
 * @method void setSiteId(int $v)
 * @method string getPath()
 * @method void setPath(string $v)
 * @method string getPathHash()
 * @method void setPathHash(string $v)
 * @method string getEtag()
 * @method void setEtag(string $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method string getTitleNorm()
 * @method void setTitleNorm(string $v)
 * @method string|null getAliasesNorm()
 * @method void setAliasesNorm(?string $v)
 * @method string|null getHeadingsNorm()
 * @method void setHeadingsNorm(?string $v)
 * @method string|null getBody()
 * @method void setBody(?string $v)
 * @method string|null getBodyNorm()
 * @method void setBodyNorm(?string $v)
 */
class SearchEntry extends Entity {
	protected int $siteId = 0;
	protected string $path = '';
	protected string $pathHash = '';
	protected string $etag = '';
	protected string $title = '';
	protected string $titleNorm = '';
	protected ?string $aliasesNorm = null;
	protected ?string $headingsNorm = null;
	protected ?string $body = null;
	protected ?string $bodyNorm = null;

	public function __construct() {
		$this->addType('siteId', 'integer');
		$this->addType('path', 'string');
		$this->addType('pathHash', 'string');
		$this->addType('etag', 'string');
		$this->addType('title', 'string');
		$this->addType('titleNorm', 'string');
		$this->addType('aliasesNorm', 'string');
		$this->addType('headingsNorm', 'string');
		$this->addType('body', 'string');
		$this->addType('bodyNorm', 'string');
	}
}
