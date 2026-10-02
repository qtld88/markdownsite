<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Listener;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Lets wiki pages show images hosted elsewhere (https:), inline (data:) and
 * generated in the browser (blob:).
 *
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CspListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof AddContentSecurityPolicyEvent)) {
			return;
		}
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedImageDomain('https:');
		$policy->addAllowedImageDomain('data:');
		$policy->addAllowedImageDomain('blob:');
		$event->addPolicy($policy);
	}
}
