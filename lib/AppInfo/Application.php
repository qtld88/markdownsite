<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\AppInfo;

use OCA\MarkdownSite\Listener\CspListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'markdownsite';

	public function __construct() {
		parent::__construct(self::APP_ID);

		// Nextcloud does not auto-load an app's Composer autoloader, so the
		// bundled third-party libraries (league/commonmark, symfony/yaml) are
		// otherwise invisible at runtime. Register it explicitly.
		$autoload = __DIR__ . '/../../vendor/autoload.php';
		if (is_file($autoload)) {
			require_once $autoload;
		}
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
