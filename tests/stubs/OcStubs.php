<?php

declare(strict_types=1);

// Minimal stand-ins for server-internal (OC\) types that OCP interfaces
// reference. nextcloud/ocp ships only the public API, so PHPUnit could not
// mock those interfaces without these.

namespace OC\Hooks;

if (!interface_exists(Emitter::class)) {
	interface Emitter {
		public function listen($scope, $method, callable $callback);

		public function removeListener($scope = null, $method = null, ?callable $callback = null);
	}
}
