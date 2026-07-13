<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Service\ContentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

class AssetController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteMapper $sites,
		private ContentService $content,
		private IUserSession $userSession,
	) {
		parent::__construct('markdownsite', $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function file(int $siteId, string $path): Http\Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new NotFoundResponse();
		}
		$site = $this->sites->find($siteId);
		if ($site === null) {
			return new NotFoundResponse();
		}
		$root = $this->content->resolveRoot($site, $user->getUID());
		if ($root === null) {
			return new NotFoundResponse();
		}
		try {
			$node = $this->content->getChild($root, $path);
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
