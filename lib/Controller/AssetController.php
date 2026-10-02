<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\File;
use OCP\IRequest;

class AssetController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteContextResolver $contexts,
		private ContentService $content,
	) {
		parent::__construct('markdownsite', $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function file(int $siteId, string $path): Http\Response {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		try {
			$node = $this->content->getChild($ctx->root, $path);
		} catch (\OCP\Files\NotFoundException | \OCP\Files\InvalidPathException | \OCP\Files\NotPermittedException) {
			return new NotFoundResponse();
		}
		if (!($node instanceof File)) {
			return new NotFoundResponse();
		}
		$response = new DataDownloadResponse(
			$node->getContent(),
			$node->getName(),
			$node->getMimeType(),
		);
		// Serve inline so images render in <img> and PDFs/text preview in-browser
		// (DataDownloadResponse defaults to attachment/download).
		$response->addHeader(
			'Content-Disposition',
			'inline; filename="' . str_replace('"', '', $node->getName()) . '"',
		);
		return $response;
	}
}
