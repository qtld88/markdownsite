<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCP\IConfig;

class UserPreferences {
	private const APP = 'markdownsite';

	public function __construct(
		private IConfig $config,
	) {
	}

	/** @return array{linkColor:string,linkUnderline:bool,linkBold:bool,revealActive:bool,tocCollapsed:bool,searchScope:string} */
	public function read(string $uid): array {
		return [
			'linkColor' => $this->config->getUserValue($uid, self::APP, 'link_color', ''),
			'linkUnderline' => $this->config->getUserValue($uid, self::APP, 'link_underline', '1') === '1',
			'linkBold' => $this->config->getUserValue($uid, self::APP, 'link_bold', '1') === '1',
			'revealActive' => $this->config->getUserValue($uid, self::APP, 'reveal_active', '1') === '1',
			'tocCollapsed' => $this->config->getUserValue($uid, self::APP, 'toc_collapsed', '0') === '1',
			'searchScope' => $this->config->getUserValue($uid, self::APP, 'search_scope', 'site') === 'all' ? 'all' : 'site',
		];
	}
}
