<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCP\AppFramework\Http\JSONResponse;

/** Why a site cannot be used, as the JSON error the API returns. */
class SiteAccessException extends \RuntimeException {
	public function __construct(
		public readonly int $status,
		public readonly string $error,
	) {
		parent::__construct($error, $status);
	}

	public function toResponse(): JSONResponse {
		return new JSONResponse(['error' => $this->error], $this->status);
	}
}
