<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'markdownsite';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (AddContentSecurityPolicyEvent $event) {
			$policy = new ContentSecurityPolicy();
			$policy->addAllowedImageDomain('https:');
			$policy->addAllowedImageDomain('data:');
			$policy->addAllowedImageDomain('blob:');
			$event->addPolicy($policy);
		});
	}
}
