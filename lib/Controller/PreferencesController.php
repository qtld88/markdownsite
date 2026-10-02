<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class PreferencesController extends Controller {
	private const APP = 'markdownsite';

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private IUserSession $userSession,
	) {
		parent::__construct('markdownsite', $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}
		return new JSONResponse($this->read($user->getUID()));
	}

	#[NoAdminRequired]
	public function update(string $linkColor = '', bool $linkUnderline = true, bool $linkBold = true, bool $revealActive = true, bool $tocCollapsed = false): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}
		$uid = $user->getUID();
		// Accept only a hex colour or empty (= use theme colour).
		$color = preg_match('/^#[0-9a-fA-F]{6}$/', $linkColor) ? $linkColor : '';
		$this->config->setUserValue($uid, self::APP, 'link_color', $color);
		$this->config->setUserValue($uid, self::APP, 'link_underline', $linkUnderline ? '1' : '0');
		$this->config->setUserValue($uid, self::APP, 'link_bold', $linkBold ? '1' : '0');
		$this->config->setUserValue($uid, self::APP, 'reveal_active', $revealActive ? '1' : '0');
		$this->config->setUserValue($uid, self::APP, 'toc_collapsed', $tocCollapsed ? '1' : '0');
		return new JSONResponse($this->read($uid));
	}

	/** @return array{linkColor:string,linkUnderline:bool,linkBold:bool,revealActive:bool,tocCollapsed:bool} */
	private function read(string $uid): array {
		return [
			'linkColor' => $this->config->getUserValue($uid, self::APP, 'link_color', ''),
			'linkUnderline' => $this->config->getUserValue($uid, self::APP, 'link_underline', '1') === '1',
			'linkBold' => $this->config->getUserValue($uid, self::APP, 'link_bold', '1') === '1',
			'revealActive' => $this->config->getUserValue($uid, self::APP, 'reveal_active', '1') === '1',
			'tocCollapsed' => $this->config->getUserValue($uid, self::APP, 'toc_collapsed', '0') === '1',
		];
	}
}
