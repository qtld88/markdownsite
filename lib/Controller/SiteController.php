<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class SiteController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteMapper $sites,
		private SiteShareMapper $shares,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IRootFolder $rootFolder,
		private SearchMapper $searchIndex,
		private AccessService $access,
		private ContentService $content,
	) {
		parent::__construct('markdownsite', $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}
		$uid = $user->getUID();
		$groups = $this->groupManager->getUserGroupIds($user);
		$out = [];
		foreach ($this->sites->findVisible($uid, $groups) as $site) {
			$dto = $site->toArray();
			$dto['isOwner'] = $site->getOwnerUid() === $uid;
			if (!$dto['isOwner']) {
				// Don't leak the owner's uid to a share recipient.
				unset($dto['ownerUid']);
			}
			$role = $this->access->roleFor($site, $uid, $groups, $this->shares->findBySite($site->getId()));
			$dto['role'] = $role;
			// Editing needs an editor role AND a folder the owner can write to.
			$root = AccessService::isEditorRole($role) ? $this->content->resolveRoot($site) : null;
			$dto['writable'] = $root !== null && $root->isUpdateable();
			$out[] = $dto;
		}
		return new JSONResponse($out);
	}

	#[NoAdminRequired]
	public function create(string $name, int $rootFileId, string $rootHintPath = '', ?string $icon = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}
		$uid = $user->getUID();
		// Validate the folder is accessible to the creator.
		$userFolder = $this->rootFolder->getUserFolder($uid);
		if (!($userFolder->getFirstNodeById($rootFileId) instanceof \OCP\Files\Folder)) {
			return new JSONResponse(['error' => 'folder-not-found'], 400);
		}
		$site = new Site();
		$site->setOwnerUid($uid);
		$site->setName($name);
		$site->setIcon($icon);
		$site->setRootFileId($rootFileId);
		$site->setRootHintPath($rootHintPath);
		$site->setCreatedAt(time());
		$site = $this->sites->insert($site);
		$dto = $site->toArray();
		// The creator is always the owner; index() derives this per-viewer,
		// but this response has no viewer context to derive it from.
		$dto['isOwner'] = true;
		$dto['role'] = AccessService::OWNER;
		$dto['writable'] = $this->content->resolveRoot($site)?->isUpdateable() ?? false;
		return new JSONResponse($dto);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		$site = $this->requireOwned($id);
		if ($site instanceof JSONResponse) {
			return $site;
		}
		$this->shares->deleteBySite($id);
		$this->searchIndex->deleteBySite($id);
		$this->sites->delete($site);
		return new JSONResponse(['ok' => true]);
	}

	/**
	 * Replace the share list for a site. `role` is 'reader' (default) or 'editor'.
	 * @param array<array{type:string,with:string,role?:string}> $shares
	 */
	#[NoAdminRequired]
	public function share(int $id, array $shares): JSONResponse {
		$site = $this->requireOwned($id);
		if ($site instanceof JSONResponse) {
			return $site;
		}
		$this->shares->deleteBySite($id);
		foreach ($shares as $s) {
			$share = new SiteShare();
			$share->setSiteId($id);
			$share->setShareType(($s['type'] ?? 'user') === 'group' ? 'group' : 'user');
			$share->setShareWith((string) ($s['with'] ?? ''));
			$share->setRole(self::role($s['role'] ?? null));
			$this->shares->insert($share);
		}
		return new JSONResponse(['ok' => true]);
	}

	/** @return array<int,array{id:int,type:string,with:string,role:string}> */
	#[NoAdminRequired]
	public function shares(int $id): JSONResponse {
		$site = $this->requireOwned($id);
		if ($site instanceof JSONResponse) {
			return $site;
		}
		$out = array_map(
			fn (SiteShare $s) => ['id' => $s->getId(), 'type' => $s->getShareType(), 'with' => $s->getShareWith(), 'role' => $s->getRole()],
			$this->shares->findBySite($id),
		);
		return new JSONResponse($out);
	}

	/** Change the role of one existing share. */
	#[NoAdminRequired]
	public function updateShare(int $id, int $shareId, string $role): JSONResponse {
		$site = $this->requireOwned($id);
		if ($site instanceof JSONResponse) {
			return $site;
		}
		foreach ($this->shares->findBySite($id) as $share) {
			if ($share->getId() === $shareId) {
				$share->setRole(self::role($role));
				$this->shares->update($share);
				return new JSONResponse(['id' => $shareId, 'role' => $share->getRole()]);
			}
		}
		return new JSONResponse(['error' => 'not-found'], 404);
	}

	/** Any value other than 'editor' means 'reader'. */
	private static function role(mixed $role): string {
		return $role === AccessService::EDITOR ? AccessService::EDITOR : AccessService::READER;
	}

	private function requireOwned(int $id): Site|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}
		$site = $this->sites->find($id);
		if ($site === null) {
			return new JSONResponse(['error' => 'not-found'], 404);
		}
		if ($site->getOwnerUid() !== $user->getUID()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}
		return $site;
	}
}
