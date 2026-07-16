<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\Share\IShare;

class ShareeController extends Controller {
	public function __construct(
		IRequest $request,
		private ISearch $search,
	) {
		parent::__construct('markdownsite', $request);
	}

	/** @return array<int,array{type:string,id:string,label:string}> */
	#[NoAdminRequired]
	public function index(string $search = ''): JSONResponse {
		if (trim($search) === '') {
			return new JSONResponse([]);
		}
		[$result] = $this->search->search($search, [IShare::TYPE_USER, IShare::TYPE_GROUP], false, 20, 0);
		return new JSONResponse($this->flatten($result));
	}

	/** @return array<int,array{type:string,id:string,label:string}> */
	private function flatten(array $result): array {
		$out = [];
		$seen = [];
		$buckets = [
			['user', $result['exact']['users'] ?? []],
			['group', $result['exact']['groups'] ?? []],
			['user', $result['users'] ?? []],
			['group', $result['groups'] ?? []],
		];
		foreach ($buckets as [$type, $entries]) {
			foreach ($entries as $entry) {
				$id = $entry['value']['shareWith'] ?? null;
				if ($id === null || $id === '') {
					continue;
				}
				$key = $type . ':' . $id;
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$out[] = ['type' => $type, 'id' => $id, 'label' => $entry['label'] ?? $id];
			}
		}
		return $out;
	}
}
