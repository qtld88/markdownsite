<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Controller;

use OCA\MarkdownSite\Controller\PreferencesController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class PreferencesControllerTest extends TestCase {
	/** @var array<string,string> stored values, keyed by config key */
	private array $store = [];

	private function controller(): PreferencesController {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = '') => $this->store[$key] ?? $default,
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, $value): void {
				$this->store[$key] = (string) $value;
			},
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new PreferencesController($this->createMock(IRequest::class), $config, $session);
	}

	public function testTocCollapsedDefaultsToFalse(): void {
		$data = $this->controller()->index()->getData();
		$this->assertFalse($data['tocCollapsed']);
	}

	public function testTocCollapsedIsSaved(): void {
		$data = $this->controller()->update('', true, true, true, true)->getData();
		$this->assertTrue($data['tocCollapsed']);
		$this->assertSame('1', $this->store['toc_collapsed']);
		$this->assertTrue($this->controller()->index()->getData()['tocCollapsed']);
	}

	public function testSearchScopeDefaultsToSite(): void {
		$this->assertSame('site', $this->controller()->index()->getData()['searchScope']);
	}

	public function testSearchScopeIsSavedAndValidated(): void {
		$this->assertSame('all', $this->controller()->update('', true, true, true, false, 'all')->getData()['searchScope']);
		$this->assertSame('site', $this->controller()->update('', true, true, true, false, 'everything')->getData()['searchScope']);
	}
}
