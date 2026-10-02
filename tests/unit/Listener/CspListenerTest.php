<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Listener;

use OCA\MarkdownSite\Listener\CspListener;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use PHPUnit\Framework\TestCase;

class CspListenerTest extends TestCase {
	public function testAddsImageSources(): void {
		$event = $this->createMock(AddContentSecurityPolicyEvent::class);
		$event->expects($this->once())
			->method('addPolicy')
			->with($this->callback(function (EmptyContentSecurityPolicy $policy): bool {
				$header = $policy->buildPolicy();
				return str_contains($header, 'img-src')
					&& str_contains($header, 'https:')
					&& str_contains($header, 'data:')
					&& str_contains($header, 'blob:');
			}));
		(new CspListener())->handle($event);
	}

	public function testIgnoresOtherEvents(): void {
		// Must not throw on an unrelated event.
		(new CspListener())->handle(new Event());
		$this->addToAssertionCount(1);
	}
}
