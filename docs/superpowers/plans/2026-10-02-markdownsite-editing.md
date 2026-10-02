# In-App Editing & File Management (E) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let site owners and editors edit pages in place (Obsidian-style live preview, autosave) and manage pages and folders from the tree (create, rename, move with link updates, delete, attachments), without ever degrading an Obsidian vault.

**Architecture:** Server: a per-share role (`reader`/`editor`) and `AccessService::roleFor()`; a `SiteContextResolver` shared by every per-site controller; pure classes for name validation (`NameValidator`) and link rewriting (`LinkRewriter`); `LinkUpdater` plans rewrites across a site; `EditController` exposes save (with etag conflicts), create, move, delete, backlinks, upload and fragment rendering; the link index is cached under the root folder's etag. Client: CodeMirror 6 loaded as a lazy chunk (`src/editor/`), an autosave state machine, block widgets rendered by the server, `PageEditor.vue`, an editable `PageTree.vue` with dialogs, and role selectors in sharing settings.

**Tech Stack:** PHP 8.1, Nextcloud 31 OCP (Files API, `ICacheFactory`, migrations), PHPUnit 10; CodeMirror 6 (`@codemirror/state` 6.7, `view` 6.43, `commands` 6.11, `autocomplete` 6.20, `language` 6.12, `lang-markdown` 6.5), `@lezer/markdown` 1.7, `@lezer/highlight` 1.2; Vue 3.5, @nextcloud/vue 9.8 (`spawnDialog`), @nextcloud/dialogs 7; Vitest 3.2 + jsdom.

**Spec:** `docs/superpowers/specs/2026-10-02-markdownsite-editing-design.md`
**Depends on:** plans A–D: OCP 31 test stubs, folder notes and `PageTree`/`WikiView` from B, lazy chunks, `codeHighlight.js`, `mermaid.js` and `clipboard.js` from C, `SearchIndexer` and `SearchPanel` from D.

---

## Assumptions and spec corrections

1. **`SearchController` (plan D) switches to `roleFor()`**, as plan D announced. `AccessService::canView()` stays as a thin helper.
2. **`SiteContextResolver` throws `SiteAccessException`** (status + error code) that controllers turn into the existing JSON errors. `AssetController` now answers access failures with the same JSON errors (it used to send a bare 404 for "not logged in" and "unknown site"); a missing file is still a plain 404.
3. **A `PageRenderer` service is extracted** from `PageController::page()` so `/render` renders fragments exactly like the reading view.
4. **Link index cache key = root folder id + root etag** instead of site id + root etag. Same invalidation; two sites on the same folder share one entry, which is correct.
5. **`LinkRewriter` works on a list of moves.** `rewrite()` keeps the spec's signature (plus an optional new page directory); `rewriteMoves()`, `countAffected()` and `rewriteAndCount()` take `[[from, to], …]`. Renaming folder `X` whose note is `X/X.md` is two moves (`X→Y`, then `Y/X.md→Y/Y.md`) applied in one rewrite.
6. **"Shortest unambiguous path" is the full path.** `WikilinkIndex` resolves full root-relative paths and basenames (nearest wins), not partial paths. A link is left untouched when its text still finds the moved page afterwards (this also keeps alias links); otherwise a bare name is used if it finds the page from that directory, else the full path without `.md`.
7. **Image embeds and image links are rewritten too.** `![[pic.png]]` resolves relative to the page in this app (`LinkResolver::resolveEmbed()`), so moving a page or a folder would break them; the rewriter relocates them like relative Markdown links. Found while testing a real move on `nextcloud:31`.
8. **`GET /backlinks/{path}` takes `?to=`.** What changes depends on the destination (a bare link may stay valid; a moved page's own relative links). The count includes the moved page's own links, and they are only rewritten when the user accepts, like incoming links.
9. **Finding affected pages reads every page of the site** (the index has no link graph); pages that stay where they are are only parsed if they mention a moved name or alias. On the 4,247-page vault a rename may take a few seconds.
10. **Upload embed is a path relative to the page** (`![[../attachments/pic.png]]`), not `![[pic.png]]`: the renderer resolves asset embeds relative to the page, and Obsidian accepts relative paths. When the attachment folder is the page's own folder, the embed is `![[pic.png]]`.
11. **Name collisions show a toast "Already exists"** rather than inline text: `NcAppNavigationItem`'s rename field has no error slot.
12. **Frontmatter widget is built client-side** (a "properties" box with the YAML): the server renders frontmatter as nothing.
13. **Content styles become global** (`src/styles/content.css`, imported by `main.js`), moved out of `WikiView`'s scoped CSS, so editor widgets look like the reading view (the spec's "share the `.mds-content` styles"). The editor host itself is not `.mds-content` (its `img` rule would style CodeMirror's cursor helpers).
14. **Nextcloud's core CSS gives every `div[contenteditable]` a 130 px width, a border and a focus ring**; the editor theme resets them (found in Chromium).
15. **Block widgets ask CodeMirror to re-measure after async rendering** (Mermaid, images), and Ctrl/Cmd+click finds its position from the clicked element: without both, clicks below a diagram landed on the wrong line (found in Chromium).
16. **Losing edit rights (403)** leaves edit mode and opens a dialog with "Copy my text"; **page deleted elsewhere (404)** shows "Recreate with my text", which recreates the page and resumes autosave (`autosave.resume()`).
17. **Bundle size.** The editor code is a 10 KB chunk plus a 519 KB (minified, not gzipped) CodeMirror vendor chunk, loaded only on the first edit.
18. **Extra guards:** `NameValidator` rejects names over 250 bytes; uploads report 413 (too large for PHP) and 507 with the server's message (quota, storage).
19. **The app description changes**: `info.xml` and `README.md` no longer say "read-only".

Verified while writing this plan on `nextcloud:31` (fresh install of 2.0.0, SQLite) with curl and headless Chromium: roles and `writable` in `/sites`, a reader refused (403) then promoted to editor, save, stale etag → 409, create twice → 409, backlinks + move with link rewrite, folder rename renaming its folder note, move into itself refused, `/render`, upload de-duplication (`pic 1.png`), delete; in the browser: `E` opens the editor, callout rendered as a widget, `[[` completion, autosave "Saved", Escape back to the rendered page, conflict banner + "Keep mine", Ctrl+click following a wikilink, new page from the "+" button opening in edit mode, inline rename with the "links will be updated" dialog, drag-and-drop into a folder (route follows), delete with confirmation (back to the home page), Reader/Editor selector.

## File map

| File | Change |
|---|---|
| `lib/Migration/Version2000Date20261002000000.php` | New: `role` column |
| `lib/Db/SiteShare.php`, `lib/Service/AccessService.php` + test | Roles, `roleFor()`, `canEdit()` |
| `lib/Service/SiteContext.php`, `SiteAccessException.php`, `SiteContextResolver.php` + test | New |
| `lib/Service/PageRenderer.php` | New |
| `lib/Controller/PageController.php`, `AssetController.php`, `SearchController.php` | Use the resolver / `roleFor()`; tree gets `pages` |
| `lib/Controller/SiteController.php` + test, `appinfo/routes.php` | `role`, `writable`, `PUT /sites/{id}/shares/{shareId}` |
| `lib/Wiki/NameValidator.php` + test | New |
| `lib/Wiki/WikilinkIndex.php`, `lib/Wiki/LinkRewriter.php` + test | Accessors; new rewriter |
| `lib/Service/AttachmentLocator.php` + test | New |
| `lib/Service/IndexBuilder.php` + test | Cache |
| `lib/Service/LinkUpdater.php` + test | New |
| `lib/Controller/EditController.php`, `MoveRefused.php` + test | New |
| `package.json`, `package-lock.json` | CodeMirror packages |
| `src/editor/*.js` + `tests/js/editorSyntax.test.js`, `tests/js/autosave.test.js` | New |
| `src/services/api.js` | Edit calls |
| `src/components/PageEditor.vue`, `NameDialog.vue`, `MoveDialog.vue` | New |
| `src/services/fileOps.js` + `tests/js/fileOps.test.js` | New |
| `src/stores/tree.js`, `src/components/PageTree.vue` | Drag state; editable tree |
| `src/components/SharingSettings.vue` | Role selector |
| `src/styles/content.css`, `src/main.js`, `src/views/WikiView.vue` | Global content styles; edit mode |
| `appinfo/info.xml`, `README.md`, `CHANGELOG.md`, `js/*` | 2.0.0 |

---

### Task 1: Share roles and `roleFor()`

**Files:**
- Create: `lib/Migration/Version2000Date20261002000000.php`
- Modify: `lib/Db/SiteShare.php`
- Replace: `lib/Service/AccessService.php`, `tests/unit/Service/AccessServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Replace `tests/unit/Service/AccessServiceTest.php` with (the first six tests are unchanged):

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Service\AccessService;
use PHPUnit\Framework\TestCase;

class AccessServiceTest extends TestCase {
	private AccessService $access;

	protected function setUp(): void {
		$this->access = new AccessService();
	}

	private function site(string $ownerUid): Site {
		$site = new Site();
		$site->setOwnerUid($ownerUid);
		$site->setName('Test');
		$site->setRootFileId(1);
		$site->setCreatedAt(time());
		return $site;
	}

	private function userShare(string $uid, string $role = 'reader'): SiteShare {
		$s = new SiteShare();
		$s->setSiteId(1);
		$s->setShareType('user');
		$s->setShareWith($uid);
		$s->setRole($role);
		return $s;
	}

	private function groupShare(string $gid, string $role = 'reader'): SiteShare {
		$s = new SiteShare();
		$s->setSiteId(1);
		$s->setShareType('group');
		$s->setShareWith($gid);
		$s->setRole($role);
		return $s;
	}

	public function testOwnerCanAlwaysView(): void {
		$site = $this->site('alice');
		$this->assertTrue($this->access->canView($site, 'alice', [], []));
	}

	public function testUserSharedToCanView(): void {
		$site = $this->site('alice');
		$shares = [$this->userShare('bob')];
		$this->assertTrue($this->access->canView($site, 'bob', [], $shares));
	}

	public function testGroupSharedToCanView(): void {
		$site = $this->site('alice');
		$shares = [$this->groupShare('staff')];
		$this->assertTrue($this->access->canView($site, 'bob', ['staff', 'other'], $shares));
	}

	public function testUnrelatedUserCannotView(): void {
		$site = $this->site('alice');
		$shares = [$this->userShare('bob'), $this->groupShare('staff')];
		$this->assertFalse($this->access->canView($site, 'carol', ['visitors'], $shares));
	}

	public function testNoSharesMeansOnlyOwnerCanView(): void {
		$site = $this->site('alice');
		$this->assertFalse($this->access->canView($site, 'bob', [], []));
	}

	public function testGroupShareDoesNotMatchOnUserUid(): void {
		$site = $this->site('alice');
		// share_with = 'bob' but as a GROUP share; the user 'bob' should only
		// match if 'bob' is also one of his own group ids.
		$shares = [$this->groupShare('bob')];
		$this->assertFalse($this->access->canView($site, 'bob', [], $shares));
		$this->assertTrue($this->access->canView($site, 'bob', ['bob'], $shares));
	}

	public function testRoleForOwner(): void {
		$this->assertSame('owner', $this->access->roleFor($this->site('alice'), 'alice', [], [$this->userShare('alice')]));
	}

	public function testRoleForEditorAndReader(): void {
		$site = $this->site('alice');
		$this->assertSame('editor', $this->access->roleFor($site, 'bob', [], [$this->userShare('bob', 'editor')]));
		$this->assertSame('reader', $this->access->roleFor($site, 'bob', [], [$this->userShare('bob')]));
	}

	public function testUserAndGroupSharesGiveTheStrongestRole(): void {
		$site = $this->site('alice');
		$shares = [$this->userShare('bob'), $this->groupShare('staff', 'editor')];
		$this->assertSame('editor', $this->access->roleFor($site, 'bob', ['staff'], $shares));
		$shares = [$this->userShare('bob', 'editor'), $this->groupShare('staff')];
		$this->assertSame('editor', $this->access->roleFor($site, 'bob', ['staff'], $shares));
	}

	public function testRoleForNoMatchIsNull(): void {
		$shares = [$this->userShare('bob', 'editor'), $this->groupShare('staff', 'editor')];
		$this->assertNull($this->access->roleFor($this->site('alice'), 'carol', ['visitors'], $shares));
	}

	public function testCanEdit(): void {
		$site = $this->site('alice');
		$this->assertTrue($this->access->canEdit($site, 'alice', [], []));
		$this->assertTrue($this->access->canEdit($site, 'bob', [], [$this->userShare('bob', 'editor')]));
		$this->assertFalse($this->access->canEdit($site, 'bob', [], [$this->userShare('bob')]));
		$this->assertFalse($this->access->canEdit($site, 'carol', [], [$this->userShare('bob', 'editor')]));
	}
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --filter AccessServiceTest`
Expected: 9 errors — `Call to undefined method OCA\MarkdownSite\Service\AccessService::roleFor()` (and `canEdit()`).

- [ ] **Step 3: Migration**

`lib/Migration/Version2000Date20261002000000.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Per-share role, "reader" or "editor" (2.0.0). Existing shares become readers. */
class Version2000Date20261002000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$shares = $schema->getTable('markdownsite_shares');
		if (!$shares->hasColumn('role')) {
			$shares->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'reader']);
		}
		return $schema;
	}
}
```

- [ ] **Step 4: Role on `SiteShare`**

In `lib/Db/SiteShare.php`:

After ` * @method void setShareWith(string $v)` add

```php
 * @method string getRole()
 * @method void setRole(string $v)
```

After `	protected string $shareWith = '';` add

```php
	protected string $role = 'reader'; // 'reader' | 'editor'
```

After `		$this->addType('shareWith', 'string');` add

```php
		$this->addType('role', 'string');
```

- [ ] **Step 5: `roleFor()`**

Replace `lib/Service/AccessService.php` with:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteShare;

class AccessService {
	public const OWNER = 'owner';
	public const EDITOR = 'editor';
	public const READER = 'reader';

	/**
	 * The viewer's role on $site: 'owner', else the strongest role among the
	 * shares to the user directly or to one of the user's groups ('editor'
	 * beats 'reader'), else null. Deliberately does NOT consult Files access.
	 *
	 * @param string[] $viewerGroups
	 * @param SiteShare[] $shares
	 * @return 'owner'|'editor'|'reader'|null
	 */
	public function roleFor(Site $site, string $viewerUid, array $viewerGroups, array $shares): ?string {
		if ($site->getOwnerUid() === $viewerUid) {
			return self::OWNER;
		}
		$role = null;
		foreach ($shares as $share) {
			$matches = ($share->getShareType() === 'user' && $share->getShareWith() === $viewerUid)
				|| ($share->getShareType() === 'group' && in_array($share->getShareWith(), $viewerGroups, true));
			if (!$matches) {
				continue;
			}
			if ($share->getRole() === self::EDITOR) {
				return self::EDITOR;
			}
			$role = self::READER;
		}
		return $role;
	}

	/**
	 * @param string[] $viewerGroups
	 * @param SiteShare[] $shares
	 */
	public function canView(Site $site, string $viewerUid, array $viewerGroups, array $shares): bool {
		return $this->roleFor($site, $viewerUid, $viewerGroups, $shares) !== null;
	}

	/**
	 * Owner or editor. Writing also needs a writable folder (see SiteContext).
	 *
	 * @param string[] $viewerGroups
	 * @param SiteShare[] $shares
	 */
	public function canEdit(Site $site, string $viewerUid, array $viewerGroups, array $shares): bool {
		return self::isEditorRole($this->roleFor($site, $viewerUid, $viewerGroups, $shares));
	}

	public static function isEditorRole(?string $role): bool {
		return $role === self::OWNER || $role === self::EDITOR;
	}
}
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (112 tests, …)`.

- [ ] **Step 7: Commit**

```bash
git add lib/Migration/Version2000Date20261002000000.php lib/Db/SiteShare.php lib/Service/AccessService.php tests/unit/Service/AccessServiceTest.php
git commit -m "feat: reader and editor roles on shares"
```

---

### Task 2: One site-context resolver for every controller

**Files:**
- Create: `lib/Service/SiteContext.php`, `lib/Service/SiteAccessException.php`, `lib/Service/SiteContextResolver.php`, `lib/Service/PageRenderer.php`, `tests/unit/Service/SiteContextResolverTest.php`
- Replace: `lib/Controller/PageController.php`, `lib/Controller/AssetController.php`
- Modify: `lib/Controller/SearchController.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Service/SiteContextResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class SiteContextResolverTest extends TestCase {
	private ?Site $site;
	/** @var SiteShare[] */
	private array $shares = [];
	private ?Folder $root;
	private ?string $uid = 'bob';

	protected function setUp(): void {
		$this->site = new Site();
		$this->site->setId(3);
		$this->site->setOwnerUid('alice');
		$this->root = $this->createMock(Folder::class);
	}

	private function resolver(): SiteContextResolver {
		$session = $this->createMock(IUserSession::class);
		if ($this->uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			$session->method('getUser')->willReturn($user);
		}
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		$sites = $this->createMock(SiteMapper::class);
		$sites->method('find')->willReturn($this->site);
		$shares = $this->createMock(SiteShareMapper::class);
		$shares->method('findBySite')->willReturn($this->shares);
		$content = $this->createMock(ContentService::class);
		$content->method('resolveRoot')->willReturn($this->root);
		return new SiteContextResolver($session, $groups, $sites, $shares, new AccessService(), $content);
	}

	private function share(string $role): SiteShare {
		$share = new SiteShare();
		$share->setShareType('user');
		$share->setShareWith('bob');
		$share->setRole($role);
		return $share;
	}

	private function assertFailure(int $status, string $error): void {
		try {
			$this->resolver()->resolve(3);
			$this->fail('expected SiteAccessException');
		} catch (SiteAccessException $e) {
			$this->assertSame([$status, $error], [$e->status, $e->error]);
			$this->assertSame(['error' => $error], $e->toResponse()->getData());
		}
	}

	public function testUnauthenticated(): void {
		$this->uid = null;
		$this->assertFailure(401, 'unauthenticated');
	}

	public function testUnknownSite(): void {
		$this->site = null;
		$this->assertFailure(404, 'site-not-found');
	}

	public function testNoShareIsForbidden(): void {
		$this->assertFailure(403, 'forbidden');
	}

	public function testMissingRootFolder(): void {
		$this->shares = [$this->share('reader')];
		$this->root = null;
		$this->assertFailure(404, 'site-not-found');
	}

	public function testResolvesRoleAndRoot(): void {
		$this->shares = [$this->share('editor')];
		$ctx = $this->resolver()->resolve(3);
		$this->assertSame('editor', $ctx->role);
		$this->assertSame($this->root, $ctx->root);
		$this->assertSame($this->site, $ctx->site);
		$this->assertTrue($ctx->canEdit());
	}

	public function testReadOnlyFolderIsNotWritable(): void {
		$this->uid = 'alice';
		$this->root->method('isUpdateable')->willReturn(false);
		$ctx = $this->resolver()->resolve(3);
		$this->assertSame('owner', $ctx->role);
		$this->assertFalse($ctx->isWritable());
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter SiteContextResolverTest`
Expected: 6 errors, `Class "OCA\MarkdownSite\Service\SiteContextResolver" not found`.

- [ ] **Step 3: Context classes**

`lib/Service/SiteContext.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\Site;
use OCP\Files\Folder;

/** A site, its root folder in the owner's mount, and the current user's role on it. */
class SiteContext {
	/** @param 'owner'|'editor'|'reader' $role */
	public function __construct(
		public readonly Site $site,
		public readonly Folder $root,
		public readonly string $role,
	) {
	}

	public function canEdit(): bool {
		return AccessService::isEditorRole($this->role);
	}

	/** Owner or editor, and the owner can write to the folder. */
	public function isWritable(): bool {
		return $this->canEdit() && $this->root->isUpdateable();
	}
}
```

`lib/Service/SiteAccessException.php`:

```php
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
```

`lib/Service/SiteContextResolver.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCP\IGroupManager;
use OCP\IUserSession;

/** user → site → shares → role → root folder, shared by every per-site controller. */
class SiteContextResolver {
	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private SiteMapper $sites,
		private SiteShareMapper $shareMapper,
		private AccessService $access,
		private ContentService $content,
	) {
	}

	/** @throws SiteAccessException 401 unauthenticated, 404 site-not-found, 403 forbidden */
	public function resolve(int $siteId): SiteContext {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new SiteAccessException(401, 'unauthenticated');
		}
		$site = $this->sites->find($siteId);
		if ($site === null) {
			throw new SiteAccessException(404, 'site-not-found');
		}
		$role = $this->access->roleFor(
			$site,
			$user->getUID(),
			$this->groupManager->getUserGroupIds($user),
			$this->shareMapper->findBySite($siteId),
		);
		if ($role === null) {
			throw new SiteAccessException(403, 'forbidden');
		}
		$root = $this->content->resolveRoot($site);
		if ($root === null) {
			throw new SiteAccessException(404, 'site-not-found');
		}
		return new SiteContext($site, $root, $role);
	}
}
```

- [ ] **Step 4: `PageRenderer`**

`lib/Service/PageRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\LinkResolver;
use OCA\MarkdownSite\Wiki\MarkdownRenderer;
use OCA\MarkdownSite\Wiki\NcUrlBuilder;
use OCA\MarkdownSite\Wiki\PathResolver;
use OCP\IURLGenerator;

/** Renders Markdown as the page at $path of a site would show it. */
class PageRenderer {
	public function __construct(
		private IndexBuilder $indexBuilder,
		private IURLGenerator $urlGenerator,
	) {
	}

	/** @return array{html: string, meta: array<string,mixed>, toc: list<array{level: int, text: string, id: string}>} */
	public function render(SiteContext $ctx, string $markdown, string $path): array {
		$dir = dirname($path);
		$currentDir = $dir === '.' ? '' : trim($dir, '/');
		$resolver = new LinkResolver(new PathResolver(), $this->indexBuilder->build($ctx->root));
		$urls = new NcUrlBuilder($this->urlGenerator, $ctx->site->getId());
		return (new MarkdownRenderer($resolver, $urls))->render($markdown, $currentDir);
	}
}
```

- [ ] **Step 5: Use them in the controllers**

Replace `lib/Controller/PageController.php` with:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\PageRenderer;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\Folder;
use OCP\IRequest;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteContextResolver $contexts,
		private ContentService $content,
		private PageRenderer $renderer,
	) {
		parent::__construct('markdownsite', $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse('markdownsite', 'main');
	}

	#[NoAdminRequired]
	public function tree(int $siteId): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		return new JSONResponse([
			'tree' => $this->content->listTree($ctx->root),
			'home' => $this->homePath($ctx->root),
		]);
	}

	#[NoAdminRequired]
	public function page(int $siteId, string $path): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		if (!preg_match('/\.md$/i', $path)) {
			$path .= '.md';
		}
		try {
			$raw = $this->content->getPageContent($ctx->root, $path);
		} catch (\OCP\Files\NotFoundException | \OCP\Files\InvalidPathException | \OCP\Files\NotPermittedException) {
			return new JSONResponse(['error' => 'page-not-found', 'path' => $path], 404);
		}
		$rendered = $this->renderer->render($ctx, $raw, $path);
		return new JSONResponse([
			'path' => $path,
			'html' => $rendered['html'],
			'meta' => $rendered['meta'],
			'toc' => $rendered['toc'],
		]);
	}

	/** The root's folder note (`<RootName>.md`, `index.md`, `README.md`), else the first page. */
	private function homePath(Folder $root): ?string {
		$note = $this->content->folderNote($root);
		if ($note !== null) {
			return $note;
		}
		$md = $this->content->listMarkdownPaths($root);
		return $md[0] ?? null;
	}
}
```

Replace `lib/Controller/AssetController.php` with:

```php
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
```

In `lib/Controller/SearchController.php`, replace

```php
			fn (Site $s) => $this->access->canView($s, $uid, $groups, $this->shareMapper->findBySite($s->getId())),
```

with

```php
			fn (Site $s) => $this->access->roleFor($s, $uid, $groups, $this->shareMapper->findBySite($s->getId())) !== null,
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit && composer run lint`
Expected: `OK (118 tests, …)`, lint exit code 0.

- [ ] **Step 7: Commit**

```bash
git add lib/Service/SiteContext.php lib/Service/SiteAccessException.php lib/Service/SiteContextResolver.php lib/Service/PageRenderer.php lib/Controller/PageController.php lib/Controller/AssetController.php lib/Controller/SearchController.php tests/unit/Service/SiteContextResolverTest.php
git commit -m "refactor: share the user-site-role-root chain between controllers"
```

---

### Task 3: Roles in the sites API

**Files:**
- Replace: `lib/Controller/SiteController.php`
- Create: `tests/unit/Controller/SiteControllerTest.php`
- Modify: `appinfo/routes.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Controller/SiteControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Controller;

use OCA\MarkdownSite\Controller\SiteController;
use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SiteControllerTest extends TestCase {
	private SiteMapper&MockObject $sites;
	private SiteShareMapper&MockObject $shares;
	private ContentService&MockObject $content;
	/** @var array<int,SiteShare[]> */
	private array $sharesBySite = [];
	private string $uid = 'bob';

	protected function setUp(): void {
		$this->sites = $this->createMock(SiteMapper::class);
		$this->shares = $this->createMock(SiteShareMapper::class);
		$this->shares->method('findBySite')->willReturnCallback(fn (int $id) => $this->sharesBySite[$id] ?? []);
		$this->content = $this->createMock(ContentService::class);
	}

	private function controller(): SiteController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		return new SiteController(
			$this->createMock(IRequest::class), $this->sites, $this->shares, $session, $groups,
			$this->createMock(IRootFolder::class), $this->createMock(SearchMapper::class),
			new AccessService(), $this->content,
		);
	}

	private function site(int $id, string $owner): Site {
		$site = new Site();
		$site->setId($id);
		$site->setOwnerUid($owner);
		$site->setName("Site $id");
		return $site;
	}

	private function share(int $id, int $siteId, string $with, string $role): SiteShare {
		$share = new SiteShare();
		$share->setId($id);
		$share->setSiteId($siteId);
		$share->setShareType('user');
		$share->setShareWith($with);
		$share->setRole($role);
		return $share;
	}

	private function folder(bool $updateable): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('isUpdateable')->willReturn($updateable);
		return $folder;
	}

	public function testIndexAddsRoleAndWritable(): void {
		$this->sites->method('findVisible')->willReturn([
			$this->site(1, 'bob'), $this->site(2, 'alice'), $this->site(3, 'alice'), $this->site(4, 'alice'),
		]);
		$this->sharesBySite[2] = [$this->share(20, 2, 'bob', 'editor')];
		$this->sharesBySite[3] = [$this->share(30, 3, 'bob', 'reader')];
		$this->sharesBySite[4] = [$this->share(40, 4, 'bob', 'editor')];
		$this->content->method('resolveRoot')->willReturnCallback(
			fn (Site $s) => $this->folder($s->getId() !== 4), // site 4: owner only has read access
		);
		$data = $this->controller()->index()->getData();
		$this->assertSame(
			[[1, 'owner', true], [2, 'editor', true], [3, 'reader', false], [4, 'editor', false]],
			array_map(fn ($d) => [$d['id'], $d['role'], $d['writable']], $data),
		);
	}

	public function testShareStoresRoles(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$stored = [];
		$this->shares->method('insert')->willReturnCallback(function (SiteShare $s) use (&$stored) {
			$stored[] = [$s->getShareWith(), $s->getRole()];
			return $s;
		});
		$this->controller()->share(1, [
			['type' => 'user', 'with' => 'carol', 'role' => 'editor'],
			['type' => 'user', 'with' => 'dave'],
			['type' => 'group', 'with' => 'staff', 'role' => 'admin'],
		]);
		$this->assertSame([['carol', 'editor'], ['dave', 'reader'], ['staff', 'reader']], $stored);
	}

	public function testSharesListsIdAndRole(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$this->sharesBySite[1] = [$this->share(9, 1, 'carol', 'editor')];
		$this->assertSame(
			[['id' => 9, 'type' => 'user', 'with' => 'carol', 'role' => 'editor']],
			$this->controller()->shares(1)->getData(),
		);
	}

	public function testUpdateShareChangesRole(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$this->sharesBySite[1] = [$this->share(9, 1, 'carol', 'reader')];
		$this->shares->expects($this->once())->method('update')
			->with($this->callback(fn (SiteShare $s) => $s->getId() === 9 && $s->getRole() === 'editor'));
		$this->assertSame(['id' => 9, 'role' => 'editor'], $this->controller()->updateShare(1, 9, 'editor')->getData());
	}

	public function testOnlyTheOwnerChangesRoles(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'alice'));
		$this->shares->expects($this->never())->method('update');
		$this->assertSame(403, $this->controller()->updateShare(1, 9, 'editor')->getStatus());
	}

	public function testUnknownShareIs404(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$this->assertSame(404, $this->controller()->updateShare(1, 99, 'editor')->getStatus());
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter SiteControllerTest`
Expected: FAIL — `Call to undefined method …SiteController::updateShare()` and `Failed asserting that two arrays are identical.` (no `role`/`id` yet).

- [ ] **Step 3: Replace the controller**

Replace `lib/Controller/SiteController.php` with:

```php
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
```

- [ ] **Step 4: Route**

In `appinfo/routes.php`, after the `site#shares` line add:

```php
		['name' => 'site#updateShare', 'url' => '/sites/{id}/shares/{shareId}', 'verb' => 'PUT'],
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (124 tests, …)`.

- [ ] **Step 6: Commit**

```bash
git add lib/Controller/SiteController.php tests/unit/Controller/SiteControllerTest.php appinfo/routes.php
git commit -m "feat: expose roles and writability in the sites API"
```

---

### Task 4: `NameValidator`

**Files:**
- Create: `tests/unit/Wiki/NameValidatorTest.php`, `lib/Wiki/NameValidator.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Wiki/NameValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\NameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameValidatorTest extends TestCase {
	public function testForcesMdOnPages(): void {
		$this->assertSame('Guides/New page.md', NameValidator::pagePath(' Guides / New page '));
		$this->assertSame('Notes.MD', NameValidator::pagePath('Notes.MD'));
	}

	public function testFolderPathKeepsNames(): void {
		$this->assertSame('Projects/2026 plans', NameValidator::folderPath('Projects/2026 plans'));
	}

	public static function invalid(): array {
		return [
			'parent segment' => ['../escape', 'dot-segment'],
			'inner parent' => ['a/../b', 'dot-segment'],
			'current dir' => ['./a', 'dot-segment'],
			'empty segment' => ['a//b', 'empty-segment'],
			'empty' => ['', 'empty-segment'],
			'leading slash' => ['/a', 'empty-segment'],
			'leading dot' => ['a/.hidden', 'hidden-name'],
			'backslash' => ['a\\b', 'forbidden-character'],
			'colon' => ['a:b', 'forbidden-character'],
			'question mark' => ['what?', 'forbidden-character'],
			'pipe' => ['a|b', 'forbidden-character'],
			'newline' => ["a\nb", 'forbidden-character'],
		];
	}

	#[DataProvider('invalid')]
	public function testRejects(string $path, string $reason): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($reason);
		NameValidator::pagePath($path);
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter NameValidatorTest`
Expected: FAIL — 14 errors or failures, all caused by `Class "OCA\MarkdownSite\Wiki\NameValidator" not found`.

- [ ] **Step 3: Write the class**

`lib/Wiki/NameValidator.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

/**
 * Validates names and paths typed by editors before anything is created or
 * moved. Paths are relative to the site root, '/'-separated.
 */
class NameValidator {
	/** Characters Nextcloud refuses in file names, plus control characters. */
	private const FORBIDDEN = '/[\\\\:*?"<>|\x00-\x1F\x7F]/';

	/**
	 * Normalised folder path: trimmed segments joined by '/'.
	 * @throws \InvalidArgumentException with a short reason
	 */
	public static function folderPath(string $path): string {
		$segments = explode('/', trim($path));
		foreach ($segments as $i => $segment) {
			$segments[$i] = self::segment($segment);
		}
		return implode('/', $segments);
	}

	/**
	 * Normalised page path, always ending in ".md" (added when missing).
	 * @throws \InvalidArgumentException
	 */
	public static function pagePath(string $path): string {
		$path = self::folderPath($path);
		return preg_match('/\.md$/i', $path) ? $path : $path . '.md';
	}

	private static function segment(string $segment): string {
		$segment = trim($segment);
		if ($segment === '') {
			throw new \InvalidArgumentException('empty-segment');
		}
		if ($segment === '..' || $segment === '.') {
			throw new \InvalidArgumentException('dot-segment');
		}
		if (str_starts_with($segment, '.')) {
			throw new \InvalidArgumentException('hidden-name');
		}
		if (preg_match(self::FORBIDDEN, $segment)) {
			throw new \InvalidArgumentException('forbidden-character');
		}
		if (strlen($segment) > 250) {
			throw new \InvalidArgumentException('name-too-long');
		}
		return $segment;
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (138 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Wiki/NameValidator.php tests/unit/Wiki/NameValidatorTest.php
git commit -m "feat: validate page and folder names"
```

---

### Task 5: `LinkRewriter`

**Files:**
- Modify: `lib/Wiki/WikilinkIndex.php`
- Create: `tests/unit/Wiki/LinkRewriterTest.php`, `lib/Wiki/LinkRewriter.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Wiki/LinkRewriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Wiki;

use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use PHPUnit\Framework\TestCase;

class LinkRewriterTest extends TestCase {
	private function index(): WikilinkIndex {
		return new WikilinkIndex([
			'Guides/Old.md',
			'Guides/Other.md',
			'Archive/Old.md',      // a homonym in another folder
			'Home.md',
			'Projects/Alpha.md',
			'Projects/Beta.md',
			'Elsewhere/Gamma.md',
		], ['Guides/Old.md' => ['Legacy']]);
	}

	private function rename(string $md, string $pageDir = 'Guides', string $to = 'Guides/New.md'): string {
		return (new LinkRewriter())->rewrite($md, $pageDir, 'Guides/Old.md', $to, $this->index());
	}

	public function testRewritesEachWikilinkForm(): void {
		$md = "[[Old]] [[Old|label]] [[Old#Setup]] [[Old#^abc]] ![[Old]] [[Guides/Old]] [[Old.md]]";
		$this->assertSame(
			"[[New]] [[New|label]] [[New#Setup]] [[New#^abc]] ![[New]] [[Guides/New]] [[New.md]]",
			$this->rename($md),
		);
	}

	public function testRewritesRelativeMarkdownLinks(): void {
		$this->assertSame('[x](../Guides/New.md#part)', $this->rename('[x](../Guides/Old.md#part)', 'Projects'));
		$this->assertSame('[x](New.md "title")', $this->rename('[x](Old.md "title")'));
		$this->assertSame('[x](/Guides/New.md)', $this->rename('[x](/Guides/Old.md)', 'Projects'));
	}

	public function testLeavesHomonymsAlone(): void {
		// From Archive/, [[Old]] means Archive/Old.md (nearest).
		$this->assertSame('[[Old]] [x](Old.md)', $this->rename('[[Old]] [x](Old.md)', 'Archive'));
	}

	public function testLeavesUnrelatedLinksAndAliasesAlone(): void {
		$md = "[[Other]] [[Legacy]] [[#Local]] [x](https://e.org/Old.md) [y](#Old)";
		$this->assertSame($md, $this->rename($md));
	}

	public function testIgnoresCode(): void {
		$md = "```\n[[Old]]\n```\n\n    [[Old]] indented\n\nInline `[[Old]]` and ``[x](Old.md)`` but [[Old]].\n~~~md\n[[Old]]\n~~~\n";
		$expected = "```\n[[Old]]\n```\n\n    [[Old]] indented\n\nInline `[[Old]]` and ``[x](Old.md)`` but [[New]].\n~~~md\n[[Old]]\n~~~\n";
		$this->assertSame($expected, $this->rename($md));
	}

	public function testBareNameGainsPathWhenItWouldFindAnotherPage(): void {
		// Guides/Old.md → Guides/Gamma.md. From Elsewhere/, a bare [[Gamma]]
		// would find Elsewhere/Gamma.md, so the link needs the full path.
		$this->assertSame('[[Guides/Gamma]]', $this->rename('[[Old]]', 'Elsewhere', 'Guides/Gamma.md'));
	}

	public function testUnchangedWhenTheSameTextStillFindsThePage(): void {
		// Moved next to the linking page: [[Old]] now finds the moved page first.
		$this->assertSame('[[Old]] [[Old|x]]', $this->rename('[[Old]] [[Old|x]]', 'Projects', 'Projects/Old.md'));
	}

	public function testMovedPageKeepsItsOwnRelativeLinksValid(): void {
		$md = "[a](../Home.md) [b](Other.md) ![img](img/pic.png) ![[logo.svg|200]] [[Other]] [c](https://x.y)";
		$out = (new LinkRewriter())->rewrite($md, 'Guides', 'Guides/Old.md', 'Projects/Deep/Old.md', $this->index(), 'Projects/Deep');
		$this->assertSame("[a](../../Home.md) [b](../../Guides/Other.md) ![img](../../Guides/img/pic.png) ![[../../Guides/logo.svg|200]] [[Other]] [c](https://x.y)", $out);
	}

	public function testImageEmbedsFollowAMovedFolder(): void {
		$out = (new LinkRewriter())->rewrite('![[Projects/pic.png]] ![[pic.png]]', '', 'Projects', 'Work', $this->index());
		$this->assertSame('![[Work/pic.png]] ![[pic.png]]', $out);
	}

	public function testFolderMoveRewritesLinksIntoTheFolder(): void {
		$rewriter = new LinkRewriter();
		$md = "[[Alpha]] [[Projects/Beta|b]] [x](Projects/Alpha.md) [[Gamma]]";
		$this->assertSame(
			"[[Alpha]] [[Work/Projects/Beta|b]] [x](Work/Projects/Alpha.md) [[Gamma]]",
			$rewriter->rewrite($md, '', 'Projects', 'Work/Projects', $this->index()),
		);
	}

	public function testPagesInsideAMovedFolderKeepRelativeLinksToEachOther(): void {
		$md = "[b](Beta.md) [h](../Home.md)";
		$out = (new LinkRewriter())->rewrite($md, 'Projects', 'Projects', 'Work/Projects', $this->index(), 'Work/Projects');
		$this->assertSame('[b](Beta.md) [h](../../Home.md)', $out);
	}

	public function testEncodesSpacesUnlessAngleBrackets(): void {
		$this->assertSame('[x](New%20name.md) [y](<New name.md>)',
			$this->rename('[x](Old.md) [y](<Old.md>)', 'Guides', 'Guides/New name.md'));
		$this->assertSame('[x](New%20name.md)', $this->rename('[x](Old.md)', 'Guides', 'Guides/New name.md'));
	}

	public function testCountAffected(): void {
		$md = "[[Old]] [[Other]] `[[Old]]` [x](Old.md) [[Legacy]]";
		$this->assertSame(2, (new LinkRewriter())->countAffected($md, 'Guides', [['Guides/Old.md', 'Guides/New.md']], $this->index()));
	}

	public function testSeveralMovesInOneRewrite(): void {
		// Folder Projects → Work, then its note Work/Projects.md → Work/Work.md.
		$index = new WikilinkIndex(['Projects/Projects.md', 'Projects/Alpha.md', 'Home.md']);
		$moves = [['Projects', 'Work'], ['Work/Projects.md', 'Work/Work.md']];
		$out = (new LinkRewriter())->rewriteMoves('[[Projects]] [[Alpha]] [n](Projects/Projects.md)', '', $moves, $index);
		$this->assertSame('[[Work]] [[Alpha]] [n](Work/Work.md)', $out);
	}

	public function testRelativePath(): void {
		$this->assertSame('b.md', LinkRewriter::relativePath('a', 'a/b.md'));
		$this->assertSame('../c/d.md', LinkRewriter::relativePath('a', 'c/d.md'));
		$this->assertSame('x/y.md', LinkRewriter::relativePath('', 'x/y.md'));
		$this->assertSame('../../z.md', LinkRewriter::relativePath('a/b', 'z.md'));
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter LinkRewriterTest`
Expected: 15 errors, `Class "OCA\MarkdownSite\Wiki\LinkRewriter" not found`.

- [ ] **Step 3: Index accessors**

In `lib/Wiki/WikilinkIndex.php`:

After the `private array $byPath = [];` line add

```php
	/** @var array<string,string[]> path => alias list, as given */
	private array $aliases;
```

After `		$this->paths = $mdPaths;` add

```php
		$this->aliases = $aliases;
```

Before `	/** @param string[] $candidates */` add

```php
	/** @return string[] all .md paths relative to root */
	public function paths(): array {
		return $this->paths;
	}

	/** @return array<string,string[]> path => aliases */
	public function aliases(): array {
		return $this->aliases;
	}

```

- [ ] **Step 4: Write the rewriter**

`lib/Wiki/LinkRewriter.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

/**
 * Rewrites the links of one Markdown page after a page or folder moved.
 *
 * Only links that resolved to the moved item (or, for a folder, to anything
 * inside it) are touched, using the link index from before the move. A link
 * whose text still resolves to the right page afterwards is left exactly as
 * it is. Code (fenced, indented, inline) is never modified.
 */
class LinkRewriter {
	private const WIKILINK = '/(!?)\[\[([^\]|]+)(\|[^\]]*)?\]\]/';
	// [text](destination "title") and ![alt](destination); destination may be <...>
	private const MDLINK = '/(!?\[(?:[^\[\]]|\[[^\]]*\])*\]\(\s*)(<[^>\n]*>|[^\s)<]+)/';

	private PathResolver $paths;

	public function __construct() {
		$this->paths = new PathResolver();
	}

	/**
	 * @param string $pageDir directory of the page holding $markdown, before the move
	 * @param string $from moved page (.md) or folder, before the move
	 * @param string $to its path after the move
	 * @param string|null $newPageDir the page's own directory after the move, when the page itself moved
	 * @return string rewritten markdown
	 */
	public function rewrite(string $markdown, string $pageDir, string $from, string $to, WikilinkIndex $before, ?string $newPageDir = null): string {
		return $this->rewriteMoves($markdown, $pageDir, [[$from, $to]], $before, $newPageDir);
	}

	/**
	 * Same as rewrite() for several moves done one after the other (a folder
	 * rename that also renames its folder note).
	 * @param list<array{0: string, 1: string}> $moves [from, to] pairs, in order
	 */
	public function rewriteMoves(string $markdown, string $pageDir, array $moves, WikilinkIndex $before, ?string $newPageDir = null): string {
		return $this->transform($markdown, $pageDir, $moves, $before, $newPageDir ?? $pageDir)[0];
	}

	/**
	 * Number of links rewriteMoves() would change.
	 * @param list<array{0: string, 1: string}> $moves
	 */
	public function countAffected(string $markdown, string $pageDir, array $moves, WikilinkIndex $before, ?string $newPageDir = null): int {
		return $this->transform($markdown, $pageDir, $moves, $before, $newPageDir ?? $pageDir)[1];
	}

	/**
	 * rewriteMoves() and countAffected() in one pass.
	 * @param list<array{0: string, 1: string}> $moves
	 * @return array{0: string, 1: int} [rewritten markdown, number of links changed]
	 */
	public function rewriteAndCount(string $markdown, string $pageDir, array $moves, WikilinkIndex $before, ?string $newPageDir = null): array {
		return $this->transform($markdown, $pageDir, $moves, $before, $newPageDir ?? $pageDir);
	}

	/** Path of $path after moving $from to $to (unchanged when unrelated). */
	public static function mapPath(string $path, string $from, string $to): string {
		if (strcasecmp($path, $from) === 0) {
			return $to;
		}
		if (stripos($path, $from . '/') === 0) {
			return $to . substr($path, strlen($from));
		}
		return $path;
	}

	/**
	 * Path of $path after all $moves.
	 * @param list<array{0: string, 1: string}> $moves
	 */
	public static function mapThrough(string $path, array $moves): string {
		foreach ($moves as [$from, $to]) {
			$path = self::mapPath($path, $from, $to);
		}
		return $path;
	}

	/**
	 * @param list<array{0: string, 1: string}> $moves
	 * @return array{0: string, 1: int}
	 */
	private function transform(string $markdown, string $pageDir, array $moves, WikilinkIndex $before, string $newPageDir): array {
		$after = $this->afterIndex($before, $moves);
		$count = 0;
		$out = [];
		$fence = null;
		$previousBlank = true;
		$inIndentedCode = false;
		foreach (preg_split('/(?<=\n)/', $markdown) ?: [] as $line) {
			$content = rtrim($line, "\r\n");
			if ($fence !== null) {
				if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $content)) {
					$fence = null;
				}
				$out[] = $line;
				continue;
			}
			if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $content, $m)) {
				$fence = $m[1];
				$out[] = $line;
				continue;
			}
			$isBlank = trim($content) === '';
			// Indented code: 4 spaces or a tab, after a blank line or more indented code.
			$inIndentedCode = !$isBlank && preg_match('/^(?: {4}|\t)/', $content) === 1 && ($previousBlank || $inIndentedCode);
			$previousBlank = $isBlank;
			if ($inIndentedCode) {
				$out[] = $line;
				continue;
			}
			$out[] = $this->rewriteLine($line, $pageDir, $moves, $before, $after, $newPageDir, $count);
		}
		return [implode('', $out), $count];
	}

	/** @param list<array{0: string, 1: string}> $moves */
	private function rewriteLine(string $line, string $pageDir, array $moves, WikilinkIndex $before, WikilinkIndex $after, string $newPageDir, int &$count): string {
		// Inline code spans (`…`, ``…``) are copied untouched.
		if (!preg_match_all('/(`+)(.+?)\1/', $line, $spans, PREG_OFFSET_CAPTURE)) {
			return $this->rewriteText($line, $pageDir, $moves, $before, $after, $newPageDir, $count);
		}
		$result = '';
		$offset = 0;
		foreach ($spans[0] as [$span, $at]) {
			$result .= $this->rewriteText(substr($line, $offset, $at - $offset), $pageDir, $moves, $before, $after, $newPageDir, $count) . $span;
			$offset = $at + strlen($span);
		}
		return $result . $this->rewriteText(substr($line, $offset), $pageDir, $moves, $before, $after, $newPageDir, $count);
	}

	/** @param list<array{0: string, 1: string}> $moves */
	private function rewriteText(string $text, string $pageDir, array $moves, WikilinkIndex $before, WikilinkIndex $after, string $newPageDir, int &$count): string {
		$text = preg_replace_callback(self::WIKILINK, function (array $m) use ($pageDir, $moves, $before, $after, $newPageDir, &$count): string {
			[$whole, $bang, $target] = [$m[0], $m[1], $m[2]];
			$hash = strpos($target, '#');
			$name = $hash === false ? $target : substr($target, 0, $hash);
			$rest = $hash === false ? '' : substr($target, $hash);
			if (trim($name) === '') {
				return $whole; // [[#Heading]] on the same page
			}
			if ($bang === '!' && preg_match('/\.(?!md$)[a-z0-9]+$/i', trim($name))) {
				return $this->rewriteAssetEmbed($m, trim($name), $rest, $pageDir, $moves, $newPageDir, $count);
			}
			$old = $before->resolve($pageDir, trim($name));
			if ($old === null) {
				return $whole;
			}
			$new = self::mapThrough($old, $moves);
			if ($new === $old || $after->resolve($newPageDir, trim($name)) === $new) {
				return $whole; // unrelated, or the same text still finds the page
			}
			$count++;
			return $bang . '[[' . $this->wikiName($name, $new, $newPageDir, $after) . $rest . ($m[3] ?? '') . ']]';
		}, $text) ?? $text;

		return preg_replace_callback(self::MDLINK, function (array $m) use ($pageDir, $moves, $newPageDir, &$count): string {
			$href = $m[2];
			$angle = str_starts_with($href, '<');
			$raw = $angle ? substr($href, 1, -1) : $href;
			if ($raw === '' || str_starts_with($raw, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) {
				return $m[0]; // same-page anchor, URL, mailto:
			}
			$hash = strpos($raw, '#');
			$pathPart = $hash === false ? $raw : substr($raw, 0, $hash);
			$fragment = $hash === false ? '' : substr($raw, $hash);
			try {
				$old = $this->paths->resolve($pageDir, rawurldecode($pathPart));
			} catch (PathTraversalException) {
				return $m[0];
			}
			$new = self::mapThrough($old, $moves);
			if ($new === $old && $newPageDir === $pageDir) {
				return $m[0];
			}
			$target = str_starts_with($pathPart, '/') ? '/' . $new : self::relativePath($newPageDir, $new);
			if ($target === rawurldecode($pathPart)) {
				return $m[0];
			}
			$count++;
			$encoded = $angle ? $target : str_replace(' ', '%20', $target);
			return $m[1] . ($angle ? '<' . $encoded . $fragment . '>' : $encoded . $fragment);
		}, $text) ?? $text;
	}

	/**
	 * ![[image.png]] resolves relative to the page (see LinkResolver::resolveEmbed),
	 * so it must follow the image when it moved, and follow the page when the
	 * page moved.
	 *
	 * @param list<array{0: string, 1: string}> $moves
	 */
	private function rewriteAssetEmbed(array $m, string $name, string $rest, string $pageDir, array $moves, string $newPageDir, int &$count): string {
		try {
			$old = $this->paths->resolve($pageDir, $name);
		} catch (PathTraversalException) {
			return $m[0];
		}
		$new = self::mapThrough($old, $moves);
		if ($new === $old && $newPageDir === $pageDir) {
			return $m[0];
		}
		$target = self::relativePath($newPageDir, $new);
		if ($target === $name) {
			return $m[0];
		}
		$count++;
		return '![[' . $target . $rest . ($m[3] ?? '') . ']]';
	}

	/**
	 * New target text: the bare name when it finds the page unambiguously,
	 * else the full path. The ".md" extension is kept if the link had one.
	 */
	private function wikiName(string $oldName, string $newPath, string $pageDir, WikilinkIndex $after): string {
		$withExt = (bool) preg_match('/\.md\s*$/i', $oldName);
		$noExt = preg_replace('/\.md$/i', '', $newPath) ?? $newPath;
		$bare = basename($noExt);
		if (!str_contains($oldName, '/') && $after->resolve($pageDir, $bare) === $newPath) {
			return $withExt ? $bare . '.md' : $bare;
		}
		return $withExt ? $noExt . '.md' : $noExt;
	}

	/**
	 * The link index as it will be after the moves.
	 * @param list<array{0: string, 1: string}> $moves
	 */
	private function afterIndex(WikilinkIndex $before, array $moves): WikilinkIndex {
		$paths = array_map(fn (string $p) => self::mapThrough($p, $moves), $before->paths());
		$aliases = [];
		foreach ($before->aliases() as $path => $list) {
			$aliases[self::mapThrough((string) $path, $moves)] = $list;
		}
		return new WikilinkIndex($paths, $aliases);
	}

	/** Relative path from directory $fromDir to $path (both root-relative). */
	public static function relativePath(string $fromDir, string $path): string {
		$from = $fromDir === '' ? [] : explode('/', $fromDir);
		$target = explode('/', $path);
		$i = 0;
		while ($i < count($from) && $i < count($target) - 1 && $from[$i] === $target[$i]) {
			$i++;
		}
		return str_repeat('../', count($from) - $i) . implode('/', array_slice($target, $i));
	}
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (153 tests, …)`.

- [ ] **Step 6: Commit**

```bash
git add lib/Wiki/WikilinkIndex.php lib/Wiki/LinkRewriter.php tests/unit/Wiki/LinkRewriterTest.php
git commit -m "feat: rewrite links after a page or folder moves"
```

---

### Task 6: `AttachmentLocator`

**Files:**
- Create: `tests/unit/Service/AttachmentLocatorTest.php`, `lib/Service/AttachmentLocator.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Service/AttachmentLocatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Service\AttachmentLocator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

class AttachmentLocatorTest extends TestCase {
	private function root(?string $appJson): Folder {
		$root = $this->createMock(Folder::class);
		if ($appJson === null) {
			$root->method('get')->willThrowException(new NotFoundException('.obsidian/app.json'));
		} else {
			$file = $this->createMock(File::class);
			$file->method('getContent')->willReturn($appJson);
			$root->method('get')->with('.obsidian/app.json')->willReturn($file);
		}
		return $root;
	}

	private function folderFor(?string $appJson, string $page = 'Guides/Page.md'): string {
		return (new AttachmentLocator())->folderFor($this->root($appJson), $page);
	}

	public function testNoAppJson(): void {
		$this->assertSame('attachments', $this->folderFor(null));
	}

	public function testEmptyOrRootSetting(): void {
		$this->assertSame('attachments', $this->folderFor('{"attachmentFolderPath": ""}'));
		$this->assertSame('attachments', $this->folderFor('{"attachmentFolderPath": "/"}'));
		$this->assertSame('attachments', $this->folderFor('{"theme": "obsidian"}'));
		$this->assertSame('attachments', $this->folderFor('not json'));
	}

	public function testFixedFolder(): void {
		$this->assertSame('Assets/Images', $this->folderFor('{"attachmentFolderPath": "Assets/Images"}'));
	}

	public function testPageFolder(): void {
		$this->assertSame('Guides', $this->folderFor('{"attachmentFolderPath": "./"}'));
		$this->assertSame('', $this->folderFor('{"attachmentFolderPath": "./"}', 'Home.md'));
	}

	public function testSubfolderOfPageFolder(): void {
		$this->assertSame('Guides/img', $this->folderFor('{"attachmentFolderPath": "./img"}'));
		$this->assertSame('img', $this->folderFor('{"attachmentFolderPath": "./img"}', 'Home.md'));
	}

	public function testRefusesToLeaveTheSite(): void {
		$this->assertSame('attachments', $this->folderFor('{"attachmentFolderPath": "../outside"}'));
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter AttachmentLocatorTest`
Expected: 6 errors, `Class "OCA\MarkdownSite\Service\AttachmentLocator" not found`.

- [ ] **Step 3: Write the class**

`lib/Service/AttachmentLocator.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\NameValidator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/** Where files dropped or pasted into a page are stored, following Obsidian's setting. */
class AttachmentLocator {
	public const DEFAULT_FOLDER = 'attachments';

	/**
	 * Root-relative folder for attachments of the page at $pagePath, from
	 * `.obsidian/app.json` → `attachmentFolderPath`:
	 * `./` or `./sub` → relative to the page's folder; `Folder` → that folder
	 * from the root; absent, empty or `/` → `attachments`.
	 */
	public function folderFor(Folder $root, string $pagePath): string {
		$setting = $this->setting($root);
		$dir = dirname($pagePath);
		$pageDir = $dir === '.' ? '' : $dir;
		if ($setting === null || $setting === '' || $setting === '/') {
			return self::DEFAULT_FOLDER;
		}
		if ($setting === '.' || $setting === './') {
			return $pageDir;
		}
		$path = str_starts_with($setting, './')
			? trim($pageDir . '/' . substr($setting, 2), '/')
			: trim($setting, '/');
		try {
			return $path === '' ? '' : NameValidator::folderPath($path);
		} catch (\InvalidArgumentException) {
			return self::DEFAULT_FOLDER; // e.g. "../outside": never leave the site
		}
	}

	private function setting(Folder $root): ?string {
		try {
			$file = $root->get('.obsidian/app.json');
		} catch (NotFoundException) {
			return null;
		}
		if (!$file instanceof File) {
			return null;
		}
		$json = json_decode($file->getContent(), true);
		$value = is_array($json) ? ($json['attachmentFolderPath'] ?? null) : null;
		return is_string($value) ? trim($value) : null;
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (159 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Service/AttachmentLocator.php tests/unit/Service/AttachmentLocatorTest.php
git commit -m "feat: follow Obsidian's attachment folder setting"
```

---

### Task 7: Cache the link index

**Files:**
- Create: `tests/unit/Service/IndexBuilderTest.php`
- Replace: `lib/Service/IndexBuilder.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Service/IndexBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

class IndexBuilderTest extends TestCase {
	/** @var array<string,mixed> */
	private array $store = [];
	private int $reads = 0;

	private function builder(): IndexBuilder {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->store[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->with('markdownsite')->willReturn($cache);
		$content = $this->createMock(ContentService::class);
		$content->method('listMarkdownPaths')->willReturn(['A.md', 'B.md']);
		$content->method('getPageContent')->willReturnCallback(function ($root, string $path): string {
			$this->reads++;
			return $path === 'A.md' ? "---\naliases: [Alpha, First]\n---\nbody" : 'no frontmatter';
		});
		return new IndexBuilder($content, $factory);
	}

	private function root(string $etag): Folder {
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(5);
		$root->method('getEtag')->willReturn($etag);
		return $root;
	}

	public function testBuildsPathsAndAliases(): void {
		$index = $this->builder()->build($this->root('e1'));
		$this->assertSame(['A.md', 'B.md'], $index->paths());
		$this->assertSame(['A.md' => ['Alpha', 'First']], $index->aliases());
		$this->assertSame('A.md', $index->resolve('', 'first'));
	}

	public function testSameEtagReadsNothingTheSecondTime(): void {
		$builder = $this->builder();
		$builder->build($this->root('e1'));
		$this->assertSame(2, $this->reads);
		$index = $builder->build($this->root('e1'));
		$this->assertSame(2, $this->reads);
		$this->assertSame('A.md', $index->resolve('', 'Alpha'));
	}

	public function testNewEtagRebuilds(): void {
		$builder = $this->builder();
		$builder->build($this->root('e1'));
		$builder->build($this->root('e2'));
		$this->assertSame(4, $this->reads);
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter IndexBuilderTest`
Expected: FAIL — 1 failure: the second build reads the pages again (`Failed asserting that 4 is identical to 2.`).

- [ ] **Step 3: Replace `lib/Service/IndexBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\Folder;
use OCP\ICache;
use OCP\ICacheFactory;
use Symfony\Component\Yaml\Yaml;

class IndexBuilder {
	private const TTL = 86400;

	private ICache $cache;

	public function __construct(
		private ContentService $content,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('markdownsite');
	}

	/**
	 * The site's link index. Building it reads every page (for aliases), so
	 * it is cached under the root folder's etag, which changes whenever any
	 * file below it changes: an edit invalidates the cache by itself.
	 */
	public function build(Folder $root): WikilinkIndex {
		$key = 'linkindex/' . $root->getId() . '/' . $root->getEtag();
		$cached = $this->cache->get($key);
		if (is_array($cached) && is_array($cached['paths'] ?? null) && is_array($cached['aliases'] ?? null)) {
			return new WikilinkIndex($cached['paths'], $cached['aliases']);
		}

		$paths = $this->content->listMarkdownPaths($root);
		$aliases = [];
		foreach ($paths as $path) {
			try {
				$raw = $this->content->getPageContent($root, $path);
			} catch (\Throwable) {
				continue;
			}
			$fm = $this->frontmatter($raw);
			if (isset($fm['aliases'])) {
				$aliases[$path] = array_map('strval', is_array($fm['aliases']) ? $fm['aliases'] : [(string) $fm['aliases']]);
			}
		}
		$this->cache->set($key, ['paths' => $paths, 'aliases' => $aliases], self::TTL);
		return new WikilinkIndex($paths, $aliases);
	}

	/** @return array<string,mixed> */
	private function frontmatter(string $raw): array {
		if (!str_starts_with($raw, "---")) {
			return [];
		}
		if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
			return [];
		}
		try {
			$data = Yaml::parse($m[1]);
			return is_array($data) ? $data : [];
		} catch (\Throwable) {
			return [];
		}
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (162 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Service/IndexBuilder.php tests/unit/Service/IndexBuilderTest.php
git commit -m "perf: cache the link index under the root folder's etag"
```

---

### Task 8: `LinkUpdater`

**Files:**
- Create: `tests/unit/Service/LinkUpdaterTest.php`, `lib/Service/LinkUpdater.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Service/LinkUpdaterTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCA\MarkdownSite\Service\LinkUpdater;
use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

class LinkUpdaterTest extends TestCase {
	/** @var array<string,string> */
	private array $pages = [
		'Home.md' => "See [[Old]] and [x](Guides/Old.md).",
		'Guides/Old.md' => "Back [home](../Home.md). [[Other]]",
		'Guides/Other.md' => "Nothing about it.",
		'Notes.md' => "Mentions old only in text, [[Other]].",
	];

	private function updater(): LinkUpdater {
		$content = $this->createMock(ContentService::class);
		$content->method('getPageContent')->willReturnCallback(fn ($root, string $p) => $this->pages[$p]);
		$index = $this->createMock(IndexBuilder::class);
		$index->method('build')->willReturn(new WikilinkIndex(array_keys($this->pages)));
		return new LinkUpdater($content, $index, new LinkRewriter());
	}

	public function testPlansIncomingLinksAndTheMovedPagesOwnLinks(): void {
		$plan = $this->updater()->plan($this->createMock(Folder::class), [['Guides/Old.md', 'Archive/2026/New.md']]);
		$this->assertSame(['Home.md', 'Guides/Old.md'], array_keys($plan));
		$this->assertSame(['content' => 'See [[New]] and [x](Archive/2026/New.md).', 'count' => 2], $plan['Home.md']);
		$this->assertSame(['content' => 'Back [home](../../Home.md). [[Other]]', 'count' => 1], $plan['Guides/Old.md']);
	}

	public function testRenameInPlaceLeavesTheMovedPageAlone(): void {
		$plan = $this->updater()->plan($this->createMock(Folder::class), [['Guides/Old.md', 'Guides/New.md']]);
		$this->assertSame(['Home.md'], array_keys($plan));
		$this->assertSame(2, $plan['Home.md']['count']);
	}

	public function testNothingToDo(): void {
		$this->assertSame([], $this->updater()->plan($this->createMock(Folder::class), [['Notes.md', 'Notes2.md']]));
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter LinkUpdaterTest`
Expected: 3 errors, `Class "OCA\MarkdownSite\Service\LinkUpdater" not found`.

- [ ] **Step 3: Write the class**

`lib/Service/LinkUpdater.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\WikilinkIndex;
use OCP\Files\Folder;

/** Finds the pages of a site whose links change when pages or folders move. */
class LinkUpdater {
	public function __construct(
		private ContentService $content,
		private IndexBuilder $indexBuilder,
		private LinkRewriter $rewriter,
	) {
	}

	/**
	 * Every page whose text changes, keyed by its path BEFORE the moves.
	 * Must run before the files are moved.
	 *
	 * @param list<array{0: string, 1: string}> $moves [from, to] pairs, in order
	 * @return array<string, array{content: string, count: int}>
	 */
	public function plan(Folder $root, array $moves): array {
		$before = $this->indexBuilder->build($root);
		$needles = $this->needles($before, $moves);
		$plan = [];
		foreach ($before->paths() as $path) {
			$newPath = LinkRewriter::mapThrough($path, $moves);
			try {
				$markdown = $this->content->getPageContent($root, $path);
			} catch (\Throwable) {
				continue;
			}
			// Pages that stay put can only be affected if they mention a moved name.
			if ($newPath === $path && !$this->mentions($markdown, $needles)) {
				continue;
			}
			[$rewritten, $count] = $this->rewriter->rewriteAndCount($markdown, self::dir($path), $moves, $before, self::dir($newPath));
			if ($count > 0) {
				$plan[$path] = ['content' => $rewritten, 'count' => $count];
			}
		}
		return $plan;
	}

	public static function dir(string $path): string {
		$dir = dirname($path);
		return $dir === '.' ? '' : $dir;
	}

	/**
	 * Lower-cased strings one of which any affected link must contain:
	 * moved names (page basenames, folder names), their aliases, and the
	 * %20-encoded form used in Markdown links.
	 *
	 * @param list<array{0: string, 1: string}> $moves
	 * @return string[]
	 */
	private function needles(WikilinkIndex $before, array $moves): array {
		$names = [];
		foreach ($moves as [$from]) {
			$names[] = basename(preg_replace('/\.md$/i', '', $from) ?? $from);
		}
		$aliases = $before->aliases();
		foreach ($before->paths() as $path) {
			if (LinkRewriter::mapThrough($path, $moves) !== $path) {
				$names[] = basename(preg_replace('/\.md$/i', '', $path) ?? $path);
				foreach ($aliases[$path] ?? [] as $alias) {
					$names[] = $alias;
				}
			}
		}
		$needles = [];
		foreach ($names as $name) {
			$name = mb_strtolower(trim($name));
			if ($name !== '') {
				$needles[$name] = true;
				$needles[str_replace(' ', '%20', $name)] = true;
			}
		}
		return array_keys($needles);
	}

	/** @param string[] $needles */
	private function mentions(string $markdown, array $needles): bool {
		$lower = mb_strtolower($markdown);
		foreach ($needles as $needle) {
			if (str_contains($lower, $needle)) {
				return true;
			}
		}
		return false;
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (165 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Service/LinkUpdater.php tests/unit/Service/LinkUpdaterTest.php
git commit -m "feat: plan link updates across a site"
```

---

### Task 9: `EditController`

**Files:**
- Create: `tests/unit/Controller/EditControllerTest.php`, `lib/Controller/EditController.php`, `lib/Controller/MoveRefused.php`
- Modify: `appinfo/routes.php`, `lib/Controller/PageController.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Controller/EditControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Controller;

use OCA\MarkdownSite\Controller\EditController;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Service\AttachmentLocator;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\LinkUpdater;
use OCA\MarkdownSite\Service\PageRenderer;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCA\MarkdownSite\Service\SiteContext;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EditControllerTest extends TestCase {
	private Site $site;
	private Folder&MockObject $root;
	private ContentService&MockObject $content;
	private LinkUpdater&MockObject $links;
	private SearchIndexer&MockObject $search;
	private IRequest&MockObject $request;
	private string $role = 'editor';
	/** @var array<string,Node> path => node, as getChild() sees them */
	private array $nodes = [];

	protected function setUp(): void {
		$this->site = new Site();
		$this->site->setId(4);
		$this->root = $this->createMock(Folder::class);
		$this->root->method('isUpdateable')->willReturn(true);
		$this->root->method('getPath')->willReturn('/alice/files/Wiki');
		$this->content = $this->createMock(ContentService::class);
		$this->content->method('getChild')->willReturnCallback(function ($root, string $path): Node {
			return $this->nodes[trim($path, '/')] ?? throw new NotFoundException($path);
		});
		$this->links = $this->createMock(LinkUpdater::class);
		$this->search = $this->createMock(SearchIndexer::class);
		$this->request = $this->createMock(IRequest::class);
	}

	private function controller(): EditController {
		$resolver = $this->createMock(SiteContextResolver::class);
		$resolver->method('resolve')->willReturnCallback(fn () => new SiteContext($this->site, $this->root, $this->role));
		return new EditController(
			$this->request, $resolver, $this->content, $this->links, new AttachmentLocator(),
			$this->search, $this->createMock(PageRenderer::class),
		);
	}

	private function file(string $path, string $content = '', string $etag = 'e1'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getEtag')->willReturn($etag);
		return $this->nodes[$path] = $file;
	}

	private function folder(string $path): Folder&MockObject {
		return $this->nodes[$path] = $this->createMock(Folder::class);
	}

	public function testSaveWritesAndReturnsTheNewEtag(): void {
		$file = $this->createMock(File::class);
		$file->method('getEtag')->willReturnOnConsecutiveCalls('e1', 'e2');
		$file->expects($this->once())->method('putContent')->with('# New text');
		$this->nodes['Guides/Page.md'] = $file;
		$this->search->expects($this->once())->method('indexPage')->with($this->site, $this->root, 'Guides/Page.md');
		$response = $this->controller()->save(4, 'Guides/Page.md', '# New text', 'e1');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['etag' => 'e2'], $response->getData());
	}

	public function testSaveWithStaleEtagIsAConflict(): void {
		$file = $this->file('Page.md', 'their text', 'e9');
		$file->expects($this->never())->method('putContent');
		$response = $this->controller()->save(4, 'Page.md', 'my text', 'e1');
		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'conflict', 'etag' => 'e9', 'content' => 'their text'], $response->getData());
	}

	public function testSaveOfADeletedPageIs404(): void {
		$this->assertSame(404, $this->controller()->save(4, 'Gone.md', 'x', 'e1')->getStatus());
	}

	public function testReaderIsRefused(): void {
		$this->role = 'reader';
		$file = $this->file('Page.md');
		$file->expects($this->never())->method('putContent');
		$response = $this->controller()->save(4, 'Page.md', 'x', 'e1');
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());
	}

	public function testReadOnlyFolderIsRefused(): void {
		$this->role = 'owner';
		$this->root = $this->createMock(Folder::class);
		$this->root->method('isUpdateable')->willReturn(false);
		$response = $this->controller()->createPage(4, 'New');
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'folder-read-only'], $response->getData());
	}

	public function testSourceReturnsTextAndEtag(): void {
		$this->file('Page.md', '# Hi', 'e5');
		$this->assertSame(['content' => '# Hi', 'etag' => 'e5'], $this->controller()->source(4, 'Page')->getData());
	}

	public function testCreatePageAddsMdAndRefusesExisting(): void {
		$created = $this->createMock(File::class);
		$created->method('getEtag')->willReturn('n1');
		$this->root->expects($this->once())->method('newFile')->with('Guides/Idea.md', '')->willReturn($created);
		$this->assertSame(['path' => 'Guides/Idea.md', 'etag' => 'n1'], $this->controller()->createPage(4, 'Guides/Idea')->getData());

		$this->file('Guides/Idea.md');
		$this->assertSame(409, $this->controller()->createPage(4, 'Guides/Idea.md')->getStatus());
	}

	public function testInvalidNamesAreRefused(): void {
		$response = $this->controller()->createFolder(4, '../outside');
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'invalid-name', 'reason' => 'dot-segment'], $response->getData());
	}

	public function testMoveIntoItselfOrADescendantIsRefused(): void {
		$projects = $this->folder('Projects');
		$this->folder('Projects/Sub');
		$projects->expects($this->never())->method('move');
		$response = $this->controller()->move(4, 'Projects', 'Projects/Sub/Projects');
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'into-itself'], $response->getData());
		$this->assertSame(400, $this->controller()->move(4, 'Projects', 'Projects')->getStatus());
		$this->assertSame(400, $this->controller()->move(4, '', 'Elsewhere')->getStatus());
	}

	public function testMoveOntoAnExistingNameIsAConflict(): void {
		$this->file('A.md');
		$this->file('B.md');
		$this->assertSame(409, $this->controller()->move(4, 'A.md', 'B')->getStatus());
	}

	public function testMoveRewritesLinksAndReindexes(): void {
		$old = $this->file('Guides/Old.md');
		$this->folder('Archive');
		$old->expects($this->once())->method('move')->willReturnCallback(function (string $target) use ($old) {
			$this->assertSame('/alice/files/Wiki/Archive/New.md', $target);
			$this->nodes['Archive/New.md'] = $old;
			unset($this->nodes['Guides/Old.md']);
			return $old;
		});
		$home = $this->file('Home.md');
		$home->expects($this->once())->method('putContent')->with('[[New]]');
		$this->links->method('plan')->with($this->root, [['Guides/Old.md', 'Archive/New.md']])
			->willReturn(['Home.md' => ['content' => '[[New]]', 'count' => 1]]);
		$this->search->expects($this->once())->method('removePage')->with($this->site, 'Guides/Old.md');
		$indexed = [];
		$this->search->method('indexPage')->willReturnCallback(function ($site, $root, string $path) use (&$indexed) {
			$indexed[] = $path;
		});
		$response = $this->controller()->move(4, 'Guides/Old.md', 'Archive/New', true);
		$this->assertSame(['path' => 'Archive/New.md', 'updated' => 1, 'failed' => []], $response->getData());
		$this->assertSame(['Archive/New.md', 'Home.md'], $indexed);
	}

	public function testMoveWithoutLinkUpdateRewritesNothing(): void {
		$old = $this->file('Old.md');
		$old->method('move')->willReturn($old);
		$this->links->expects($this->never())->method('plan');
		$this->assertSame(['path' => 'New.md', 'updated' => 0, 'failed' => []], $this->controller()->move(4, 'Old.md', 'New.md', false)->getData());
	}

	public function testRenamingAFolderAlsoRenamesItsFolderNote(): void {
		$folder = $this->folder('Projects');
		$this->content->method('folderNote')->with($folder)->willReturn('Projects.md');
		$note = $this->createMock(File::class);
		$moves = [];
		$folder->method('move')->willReturnCallback(function (string $target) use (&$moves, $folder, $note) {
			$moves[] = $target;
			$this->nodes['Work'] = $folder;
			$this->nodes['Work/Projects.md'] = $note;
			return $folder;
		});
		$note->method('move')->willReturnCallback(function (string $target) use (&$moves, $note) {
			$moves[] = $target;
			return $note;
		});
		$this->content->method('listMarkdownEtags')->willReturn(['Projects/Projects.md' => 'e']);
		$this->links->method('plan')->with($this->root, [['Projects', 'Work'], ['Work/Projects.md', 'Work/Work.md']])->willReturn([]);
		$response = $this->controller()->move(4, 'Projects', 'Work');
		$this->assertSame(['/alice/files/Wiki/Work', '/alice/files/Wiki/Work/Work.md'], $moves);
		$this->assertSame('Work', $response->getData()['path']);
	}

	public function testBacklinksCountsLinksAndPages(): void {
		$this->file('Old.md');
		$this->links->method('plan')->willReturn([
			'Home.md' => ['content' => '', 'count' => 2],
			'Notes.md' => ['content' => '', 'count' => 1],
		]);
		$this->assertSame(['count' => 3, 'pages' => ['Home.md', 'Notes.md']], $this->controller()->backlinks(4, 'Old.md', 'New.md')->getData());
	}

	public function testDeleteFolderRemovesItsPagesFromSearch(): void {
		$folder = $this->folder('Projects');
		$folder->expects($this->once())->method('delete');
		$this->content->method('listMarkdownEtags')->with($this->root, 'Projects')
			->willReturn(['Projects/A.md' => 'e', 'Projects/B.md' => 'e']);
		$removed = [];
		$this->search->method('removePage')->willReturnCallback(function ($site, string $path) use (&$removed) {
			$removed[] = $path;
		});
		$this->assertSame(['ok' => true], $this->controller()->delete(4, 'Projects')->getData());
		$this->assertSame(['Projects/A.md', 'Projects/B.md'], $removed);
	}

	public function testUploadStoresUnderAFreeNameAndReturnsTheEmbed(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'mds');
		file_put_contents($tmp, 'PNG');
		$this->request->method('getUploadedFile')->with('file')
			->willReturn(['name' => 'image.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]);
		$this->root->method('get')->willThrowException(new NotFoundException('.obsidian/app.json'));
		$attachments = $this->createMock(Folder::class);
		$this->root->method('nodeExists')->with('attachments')->willReturn(false);
		$this->root->method('newFolder')->with('attachments')->willReturn($attachments);
		$attachments->method('nodeExists')->willReturnCallback(fn (string $n) => $n === 'image.png');
		$attachments->expects($this->once())->method('newFile')->with('image 1.png', $this->isType('resource'));
		$data = $this->controller()->upload(4, 'Guides/Page.md')->getData();
		$this->assertSame(['path' => 'attachments/image 1.png', 'embed' => '![[../attachments/image 1.png]]'], $data);
		unlink($tmp);
	}

	public function testUploadWithoutAFileIsRefused(): void {
		$this->request->method('getUploadedFile')->willReturn(null);
		$this->assertSame(400, $this->controller()->upload(4, 'Page.md')->getStatus());
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter EditControllerTest`
Expected: 17 errors, `Class "OCA\MarkdownSite\Controller\EditController" not found`.

- [ ] **Step 3: Write the controller**

`lib/Controller/MoveRefused.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

/** A file operation refused for a known reason; message = error code, code = HTTP status. */
class MoveRefused extends \RuntimeException {
}
```

`lib/Controller/EditController.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Service\AttachmentLocator;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\LinkUpdater;
use OCA\MarkdownSite\Service\PageRenderer;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContext;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCA\MarkdownSite\Wiki\LinkRewriter;
use OCA\MarkdownSite\Wiki\NameValidator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

/**
 * Editing and file management for owners and editors. Every write goes
 * through the owner's mount (the site root); nothing outside it is reachable.
 */
class EditController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteContextResolver $contexts,
		private ContentService $content,
		private LinkUpdater $links,
		private AttachmentLocator $attachments,
		private SearchIndexer $search,
		private PageRenderer $renderer,
	) {
		parent::__construct('markdownsite', $request);
	}

	/** Raw Markdown and etag of a page. */
	#[NoAdminRequired]
	public function source(int $siteId, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path): JSONResponse {
			$file = $this->file($ctx, self::pagePath($path));
			return new JSONResponse(['content' => $file->getContent(), 'etag' => $file->getEtag()]);
		});
	}

	/** Saves a page. A stale etag gets 409 with the server's current text. */
	#[NoAdminRequired]
	public function save(int $siteId, string $path, string $content, string $etag = ''): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path, $content, $etag): JSONResponse {
			$path = self::pagePath($path);
			$file = $this->file($ctx, $path);
			if ($file->getEtag() !== $etag) {
				return new JSONResponse(['error' => 'conflict', 'etag' => $file->getEtag(), 'content' => $file->getContent()], 409);
			}
			$file->putContent($content);
			$this->search->indexPage($ctx->site, $ctx->root, $path);
			return new JSONResponse(['etag' => $this->file($ctx, $path)->getEtag()]);
		});
	}

	/** Creates an empty page. */
	#[NoAdminRequired]
	public function createPage(int $siteId, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path): JSONResponse {
			$path = NameValidator::pagePath($path);
			$this->assertFree($ctx, $path);
			$file = $ctx->root->newFile($path, '');
			$this->search->indexPage($ctx->site, $ctx->root, $path);
			return new JSONResponse(['path' => $path, 'etag' => $file->getEtag()]);
		});
	}

	#[NoAdminRequired]
	public function createFolder(int $siteId, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path): JSONResponse {
			$path = NameValidator::folderPath($path);
			$this->assertFree($ctx, $path);
			$ctx->root->newFolder($path);
			return new JSONResponse(['path' => $path]);
		});
	}

	/**
	 * Links that moving $path to $to would rewrite: {count, pages}. A folder
	 * counts the links to every page inside it.
	 */
	#[NoAdminRequired]
	public function backlinks(int $siteId, string $path, string $to): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path, $to): JSONResponse {
			$moves = $this->moves($ctx, trim($path, '/'), $to);
			$plan = $this->links->plan($ctx->root, $moves);
			return new JSONResponse([
				'count' => array_sum(array_column($plan, 'count')),
				'pages' => array_keys($plan),
			]);
		});
	}

	/**
	 * Renames or moves a page or folder, then (when $updateLinks) rewrites
	 * the links to it. A page that could not be rewritten is listed in
	 * `failed`; the move is not rolled back.
	 */
	#[NoAdminRequired]
	public function move(int $siteId, string $from, string $to, bool $updateLinks = true): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($from, $to, $updateLinks): JSONResponse {
			$from = trim($from, '/');
			$moves = $this->moves($ctx, $from, $to);
			$plan = $updateLinks ? $this->links->plan($ctx->root, $moves) : [];
			$node = $this->content->getChild($ctx->root, $from);
			$movedPages = $node instanceof Folder ? array_keys($this->content->listMarkdownEtags($ctx->root, $from)) : [$from];

			foreach ($moves as [$a, $b]) {
				$this->content->getChild($ctx->root, $a)->move($ctx->root->getPath() . '/' . $b);
			}

			$failed = [];
			foreach ($plan as $oldPath => $change) {
				$newPath = LinkRewriter::mapThrough((string) $oldPath, $moves);
				try {
					$file = $this->content->getChild($ctx->root, $newPath);
					if (!$file instanceof File) {
						throw new NotFoundException($newPath);
					}
					$file->putContent($change['content']);
				} catch (\Throwable) {
					$failed[] = $newPath;
				}
			}

			foreach ($movedPages as $old) {
				$this->search->removePage($ctx->site, (string) $old);
			}
			$reindex = array_map(fn ($p) => LinkRewriter::mapThrough((string) $p, $moves), array_merge($movedPages, array_keys($plan)));
			foreach (array_unique($reindex) as $path) {
				$this->search->indexPage($ctx->site, $ctx->root, $path);
			}
			return new JSONResponse([
				'path' => LinkRewriter::mapThrough($from, $moves),
				'updated' => count($plan) - count($failed),
				'failed' => $failed,
			]);
		});
	}

	/** Moves a page or folder (with everything in it) to the trash bin. */
	#[NoAdminRequired]
	public function delete(int $siteId, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($path): JSONResponse {
			$path = trim($path, '/');
			if ($path === '') {
				return new JSONResponse(['error' => 'site-root'], 400);
			}
			$node = $this->content->getChild($ctx->root, $path);
			$pages = $node instanceof Folder ? array_keys($this->content->listMarkdownEtags($ctx->root, $path)) : [$path];
			$node->delete();
			foreach ($pages as $page) {
				$this->search->removePage($ctx->site, (string) $page);
			}
			return new JSONResponse(['ok' => true]);
		});
	}

	/**
	 * Stores an uploaded file (multipart field "file") in the attachment
	 * folder of $page and returns the embed to insert.
	 */
	#[NoAdminRequired]
	public function upload(int $siteId, string $page): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($page): JSONResponse {
			$upload = $this->request->getUploadedFile('file');
			if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
				$code = is_array($upload) ? (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
				$tooBig = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE;
				return new JSONResponse(['error' => 'upload-failed', 'message' => $tooBig ? 'File is too large' : 'No file received'], $tooBig ? 413 : 400);
			}
			$name = NameValidator::folderPath(basename((string) ($upload['name'] ?? 'file')));
			$page = self::pagePath($page);
			$dir = $this->attachments->folderFor($ctx->root, $page);
			$folder = $this->ensureFolder($ctx->root, $dir);
			$name = self::freeName($folder, $name);
			$stream = fopen($upload['tmp_name'], 'rb');
			try {
				$folder->newFile($name, $stream);
			} catch (\Throwable $e) {
				// Quota exceeded, storage full, …: show the server's reason.
				return new JSONResponse(['error' => 'upload-failed', 'message' => $e->getMessage()], 507);
			} finally {
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
			$path = $dir === '' ? $name : $dir . '/' . $name;
			return new JSONResponse([
				'path' => $path,
				'embed' => '![[' . LinkRewriter::relativePath(LinkUpdater::dir($page), $path) . ']]',
			]);
		});
	}

	/** Renders a Markdown fragment as it would appear on the page at $path (editor widgets). */
	#[NoAdminRequired]
	public function render(int $siteId, string $markdown, string $path): JSONResponse {
		return $this->guard($siteId, function (SiteContext $ctx) use ($markdown, $path): JSONResponse {
			return new JSONResponse(['html' => $this->renderer->render($ctx, $markdown, self::pagePath($path))['html']]);
		});
	}

	/**
	 * Runs $action for a user who may edit a writable site, mapping failures
	 * to JSON errors.
	 */
	private function guard(int $siteId, callable $action): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		if (!$ctx->canEdit()) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}
		if (!$ctx->root->isUpdateable()) {
			return new JSONResponse(['error' => 'folder-read-only'], 403);
		}
		try {
			return $action($ctx);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'invalid-name', 'reason' => $e->getMessage()], 400);
		} catch (MoveRefused $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode());
		} catch (NotFoundException | InvalidPathException) {
			return new JSONResponse(['error' => 'not-found'], 404);
		} catch (NotPermittedException) {
			return new JSONResponse(['error' => 'not-permitted'], 403);
		}
	}

	/**
	 * [from, to] steps for moving $from to $to. Renaming folder X whose note
	 * is X/X.md also renames the note after the folder.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function moves(SiteContext $ctx, string $from, string $to): array {
		if ($from === '') {
			throw new MoveRefused('site-root', 400);
		}
		$node = $this->content->getChild($ctx->root, $from);
		$to = $node instanceof Folder ? NameValidator::folderPath($to) : NameValidator::pagePath($to);
		if ($to === $from) {
			throw new MoveRefused('same-path', 400);
		}
		if ($node instanceof Folder && stripos($to . '/', $from . '/') === 0) {
			throw new MoveRefused('into-itself', 400);
		}
		if (strcasecmp($to, $from) !== 0) {
			$this->assertFree($ctx, $to);
		}
		$parent = LinkUpdater::dir($to);
		if ($parent !== '' && !($this->tryChild($ctx, $parent) instanceof Folder)) {
			throw new MoveRefused('parent-not-found', 404);
		}
		$moves = [[$from, $to]];
		$oldName = basename($from);
		$newName = basename($to);
		if ($node instanceof Folder && $oldName !== $newName
			&& strcasecmp((string) $this->content->folderNote($node), $oldName . '.md') === 0) {
			$note = (string) $this->content->folderNote($node);
			$moves[] = [$to . '/' . $note, $to . '/' . $newName . '.md'];
		}
		return $moves;
	}

	private function assertFree(SiteContext $ctx, string $path): void {
		if ($this->tryChild($ctx, $path) !== null) {
			throw new MoveRefused('exists', 409);
		}
	}

	private function tryChild(SiteContext $ctx, string $path): ?\OCP\Files\Node {
		try {
			return $this->content->getChild($ctx->root, $path);
		} catch (NotFoundException) {
			return null;
		}
	}

	private function file(SiteContext $ctx, string $path): File {
		$node = $this->content->getChild($ctx->root, $path);
		if (!$node instanceof File) {
			throw new NotFoundException($path);
		}
		return $node;
	}

	private function ensureFolder(Folder $root, string $path): Folder {
		$folder = $root;
		foreach ($path === '' ? [] : explode('/', $path) as $segment) {
			$folder = $folder->nodeExists($segment) ? $folder->get($segment) : $folder->newFolder($segment);
			if (!$folder instanceof Folder) {
				throw new MoveRefused('exists', 409);
			}
		}
		return $folder;
	}

	/** "image.png", else "image 1.png", "image 2.png", … */
	private static function freeName(Folder $folder, string $name): string {
		$dot = strrpos($name, '.');
		$stem = $dot === false || $dot === 0 ? $name : substr($name, 0, $dot);
		$ext = $dot === false || $dot === 0 ? '' : substr($name, $dot);
		$candidate = $name;
		for ($i = 1; $folder->nodeExists($candidate); $i++) {
			$candidate = $stem . ' ' . $i . $ext;
		}
		return $candidate;
	}

	private static function pagePath(string $path): string {
		$path = trim($path, '/');
		return preg_match('/\.md$/i', $path) ? $path : $path . '.md';
	}
}
```

- [ ] **Step 4: Routes**

In `appinfo/routes.php`, after the `asset#file` route (the two lines ending with `'requirements' => ['path' => '.+']],`), add:

```php
		['name' => 'edit#source', 'url' => '/s/{siteId}/source/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '.+']],
		['name' => 'edit#save', 'url' => '/s/{siteId}/page/{path}', 'verb' => 'PUT',
			'requirements' => ['path' => '.+']],
		['name' => 'edit#createPage', 'url' => '/s/{siteId}/pages', 'verb' => 'POST'],
		['name' => 'edit#createFolder', 'url' => '/s/{siteId}/folders', 'verb' => 'POST'],
		['name' => 'edit#backlinks', 'url' => '/s/{siteId}/backlinks/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '.+']],
		['name' => 'edit#move', 'url' => '/s/{siteId}/move', 'verb' => 'POST'],
		['name' => 'edit#delete', 'url' => '/s/{siteId}/node/{path}', 'verb' => 'DELETE',
			'requirements' => ['path' => '.+']],
		['name' => 'edit#upload', 'url' => '/s/{siteId}/attachments', 'verb' => 'POST'],
		['name' => 'edit#render', 'url' => '/s/{siteId}/render', 'verb' => 'POST'],
```

Run: `php -r '$r = require "appinfo/routes.php"; echo count($r["routes"]), PHP_EOL;'`
Expected: `23`

- [ ] **Step 5: Pages for `[[` completion**

Replace `lib/Controller/PageController.php` with:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\IndexBuilder;
use OCA\MarkdownSite\Service\PageRenderer;
use OCA\MarkdownSite\Service\SiteAccessException;
use OCA\MarkdownSite\Service\SiteContextResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\Folder;
use OCP\IRequest;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private SiteContextResolver $contexts,
		private ContentService $content,
		private PageRenderer $renderer,
		private IndexBuilder $indexBuilder,
	) {
		parent::__construct('markdownsite', $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse('markdownsite', 'main');
	}

	#[NoAdminRequired]
	public function tree(int $siteId): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		$index = $this->indexBuilder->build($ctx->root);
		$aliases = $index->aliases();
		return new JSONResponse([
			'tree' => $this->content->listTree($ctx->root),
			'home' => $this->homePath($ctx->root),
			// For [[ completion in the editor.
			'pages' => array_map(fn (string $path) => [
				'path' => $path,
				'title' => basename(preg_replace('/\.md$/i', '', $path) ?? $path),
				'aliases' => $aliases[$path] ?? [],
			], $index->paths()),
		]);
	}

	#[NoAdminRequired]
	public function page(int $siteId, string $path): JSONResponse {
		try {
			$ctx = $this->contexts->resolve($siteId);
		} catch (SiteAccessException $e) {
			return $e->toResponse();
		}
		if (!preg_match('/\.md$/i', $path)) {
			$path .= '.md';
		}
		try {
			$raw = $this->content->getPageContent($ctx->root, $path);
		} catch (\OCP\Files\NotFoundException | \OCP\Files\InvalidPathException | \OCP\Files\NotPermittedException) {
			return new JSONResponse(['error' => 'page-not-found', 'path' => $path], 404);
		}
		$rendered = $this->renderer->render($ctx, $raw, $path);
		return new JSONResponse([
			'path' => $path,
			'html' => $rendered['html'],
			'meta' => $rendered['meta'],
			'toc' => $rendered['toc'],
		]);
	}

	/** The root's folder note (`<RootName>.md`, `index.md`, `README.md`), else the first page. */
	private function homePath(Folder $root): ?string {
		$note = $this->content->folderNote($root);
		if ($note !== null) {
			return $note;
		}
		$md = $this->content->listMarkdownPaths($root);
		return $md[0] ?? null;
	}
}
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit && composer run lint`
Expected: `OK (182 tests, …)`, lint exit code 0.

- [ ] **Step 7: Commit**

```bash
git add lib/Controller/EditController.php lib/Controller/MoveRefused.php tests/unit/Controller/EditControllerTest.php appinfo/routes.php lib/Controller/PageController.php
git commit -m "feat: editing and file management endpoints"
```

---

### Task 10: CodeMirror and the Obsidian syntax extension

**Files:**
- Modify: `package.json`, `package-lock.json`
- Create: `tests/js/editorSyntax.test.js`, `src/editor/syntax.js`

- [ ] **Step 1: Install CodeMirror**

```bash
npm install @codemirror/state@^6.7.6 @codemirror/view@^6.43.13 @codemirror/commands@^6.11.1 @codemirror/autocomplete@^6.20.3 @codemirror/lang-markdown@^6.5.2 @codemirror/language@^6.12.4 @lezer/markdown@^1.7.2 @lezer/highlight@^1.2.5
```

Run: `grep -c '"@codemirror/\|"@lezer/' package.json`
Expected: `8`

- [ ] **Step 2: Write the failing test**

`tests/js/editorSyntax.test.js`:

```js
import { describe, expect, it } from 'vitest'
import { parser as baseParser, GFM } from '@lezer/markdown'
import { obsidianSyntax } from '../../src/editor/syntax.js'

const parser = baseParser.configure([GFM, obsidianSyntax])

/** "Name[from,to]" for every node inside the paragraph, in document order. */
function nodes(text) {
	const out = []
	parser.parse(text).iterate({
		enter(node) {
			if (!['Document', 'Paragraph'].includes(node.name)) {
				out.push(`${node.name}[${text.slice(node.from, node.to)}]`)
			}
		},
	})
	return out
}

describe('obsidianSyntax', () => {
	it('parses a wikilink', () => {
		expect(nodes('see [[Page]] now')).toEqual([
			'Wikilink[[[Page]]]', 'WikilinkMark[[[]', 'WikilinkTarget[Page]', 'WikilinkMark[]]]',
		])
	})

	it('parses a label', () => {
		expect(nodes('[[Page|the label]]')).toEqual([
			'Wikilink[[[Page|the label]]]', 'WikilinkMark[[[]', 'WikilinkTarget[Page]',
			'WikilinkMark[|]', 'WikilinkLabel[the label]', 'WikilinkMark[]]]',
		])
	})

	it('keeps a heading in the target', () => {
		expect(nodes('[[Page#Setup]]')).toContain('WikilinkTarget[Page#Setup]')
	})

	it('parses an embed', () => {
		expect(nodes('![[pic.png]]')).toEqual([
			'Embed[![[pic.png]]]', 'WikilinkMark[![[]', 'WikilinkTarget[pic.png]', 'WikilinkMark[]]]',
		])
	})

	it('parses ==highlight==', () => {
		expect(nodes('a ==marked== b')).toEqual([
			'Highlight[==marked==]', 'HighlightMark[==]', 'HighlightMark[==]',
		])
	})

	it('leaves an unclosed wikilink and lone == alone', () => {
		expect(nodes('[[Page and a == b')).toEqual([])
	})

	it('does not treat a normal link as a wikilink', () => {
		expect(nodes('[text](url)')[0]).toBe('Link[[text](url)]')
	})
})
```

- [ ] **Step 3: Run it to see it fail**

Run: `npm test`
Expected: FAIL — `Cannot find module '../../src/editor/syntax.js'`.

- [ ] **Step 4: Write the extension**

`src/editor/syntax.js`:

```js
import { tags } from '@lezer/highlight'

const OPEN_BRACKET = 91 // [
const CLOSE_BRACKET = 93 // ]
const BANG = 33 // !
const PIPE = 124 // |
const EQUALS = 61 // =
const NEWLINE = 10

/** Wikilink or embed starting at `pos`: [[target]], [[target|label]], ![[target]]. */
function parseWikilink(cx, next, pos) {
	const embed = next === BANG
	const open = embed ? pos + 1 : pos
	if (cx.char(open) !== OPEN_BRACKET || cx.char(open + 1) !== OPEN_BRACKET) {
		return -1
	}
	const start = open + 2
	let pipe = -1
	for (let i = start; i < cx.end; i++) {
		const ch = cx.char(i)
		if (ch === NEWLINE || (ch === OPEN_BRACKET && cx.char(i + 1) === OPEN_BRACKET)) {
			return -1
		}
		if (ch === PIPE && pipe < 0) {
			pipe = i
		}
		if (ch === CLOSE_BRACKET && cx.char(i + 1) === CLOSE_BRACKET) {
			const targetEnd = pipe < 0 ? i : pipe
			if (targetEnd === start) {
				return -1
			}
			const children = [
				cx.elt('WikilinkMark', pos, start),
				cx.elt('WikilinkTarget', start, targetEnd),
			]
			if (pipe >= 0) {
				children.push(cx.elt('WikilinkMark', pipe, pipe + 1))
				children.push(cx.elt('WikilinkLabel', pipe + 1, i))
			}
			children.push(cx.elt('WikilinkMark', i, i + 2))
			return cx.addElement(cx.elt(embed ? 'Embed' : 'Wikilink', pos, i + 2, children))
		}
	}
	return -1
}

const HighlightDelim = { resolve: 'Highlight', mark: 'HighlightMark' }

/**
 * @lezer/markdown extension for Obsidian syntax: [[wikilinks]],
 * [[target|label]], ![[embeds]] and ==highlight==.
 */
export const obsidianSyntax = {
	defineNodes: [
		{ name: 'Wikilink', style: tags.link },
		{ name: 'Embed', style: tags.link },
		{ name: 'WikilinkMark', style: tags.processingInstruction },
		{ name: 'WikilinkTarget', style: tags.link },
		{ name: 'WikilinkLabel', style: tags.link },
		{ name: 'Highlight', style: { 'Highlight/...': tags.special(tags.content) } },
		{ name: 'HighlightMark', style: tags.processingInstruction },
	],
	parseInline: [
		{ name: 'Wikilink', parse: parseWikilink, before: 'Link' },
		{
			name: 'Highlight',
			parse(cx, next, pos) {
				if (next !== EQUALS || cx.char(pos + 1) !== EQUALS || cx.char(pos + 2) === EQUALS) {
					return -1
				}
				const before = cx.slice(pos - 1, pos)
				const after = cx.slice(pos + 2, pos + 3)
				const spaceBefore = /\s|^$/.test(before)
				const spaceAfter = /\s|^$/.test(after)
				return cx.addDelimiter(HighlightDelim, pos, pos + 2, !spaceAfter, !spaceBefore)
			},
			after: 'Emphasis',
		},
	],
}
```

- [ ] **Step 5: Run the tests**

Run: `npm test && npm run lint`
Expected: `Tests  49 passed (49)`, lint exit code 0.

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json tests/js/editorSyntax.test.js src/editor/syntax.js
git commit -m "feat: parse wikilinks, embeds and highlights in the editor"
```

---

### Task 11: Autosave state machine

**Files:**
- Create: `tests/js/autosave.test.js`, `src/editor/autosave.js`

- [ ] **Step 1: Write the failing test**

`tests/js/autosave.test.js`:

```js
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createAutosave, readDraft } from '../../src/editor/autosave.js'

function memoryStorage() {
	const data = new Map()
	return {
		getItem: k => (data.has(k) ? data.get(k) : null),
		setItem: (k, v) => data.set(k, String(v)),
		removeItem: k => data.delete(k),
	}
}

const ok = etag => ({ status: 200, etag })

describe('autosave', () => {
	let storage
	let states
	beforeEach(() => {
		vi.useFakeTimers()
		storage = memoryStorage()
		states = []
	})
	afterEach(() => vi.useRealTimers())

	function make(save, etag = 'e0') {
		return createAutosave({
			save, etag, storage, draftKey: 'k', onState: (s, info) => states.push([s, info?.reason ?? null]),
		})
	}

	it('saves 1.5 s after the last keystroke, once', async () => {
		const save = vi.fn().mockResolvedValue(ok('e1'))
		const auto = make(save)
		auto.change('a')
		await vi.advanceTimersByTimeAsync(1000)
		auto.change('ab')
		await vi.advanceTimersByTimeAsync(1499)
		expect(save).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		expect(save).toHaveBeenCalledTimes(1)
		expect(save).toHaveBeenCalledWith('ab', 'e0')
		expect(auto.state).toBe('saved')
		expect(auto.etag).toBe('e1')
		expect(states.map(s => s[0])).toEqual(['dirty', 'saving', 'saved'])
	})

	it('flush saves immediately and waits for the result', async () => {
		const save = vi.fn().mockResolvedValue(ok('e1'))
		const auto = make(save)
		auto.change('text')
		await auto.flush()
		expect(save).toHaveBeenCalledWith('text', 'e0')
		expect(auto.state).toBe('saved')
		await vi.advanceTimersByTimeAsync(5000)
		expect(save).toHaveBeenCalledTimes(1)
	})

	it('flush does nothing when nothing changed', async () => {
		const save = vi.fn()
		await make(save).flush()
		expect(save).not.toHaveBeenCalled()
	})

	it('saves again when typing continued during a save', async () => {
		let resolve
		const save = vi.fn()
			.mockImplementationOnce(() => new Promise(r => { resolve = r }))
			.mockResolvedValueOnce(ok('e2'))
		const auto = make(save)
		auto.change('one')
		await vi.advanceTimersByTimeAsync(1500)
		auto.change('one two')
		resolve(ok('e1'))
		await vi.advanceTimersByTimeAsync(1500)
		expect(save).toHaveBeenLastCalledWith('one two', 'e1')
		expect(auto.state).toBe('saved')
	})

	it('pauses on a conflict until the user chooses', async () => {
		const save = vi.fn()
			.mockResolvedValueOnce({ status: 409, etag: 'e9', content: 'theirs' })
			.mockResolvedValueOnce(ok('e10'))
		const auto = make(save)
		auto.change('mine')
		await vi.advanceTimersByTimeAsync(1500)
		expect(auto.state).toBe('conflict')
		expect(auto.conflict).toEqual({ etag: 'e9', content: 'theirs' })
		auto.change('mine 2')
		await vi.advanceTimersByTimeAsync(5000)
		expect(save).toHaveBeenCalledTimes(1)
		await auto.keepMine()
		expect(save).toHaveBeenLastCalledWith('mine 2', 'e9')
		expect(auto.state).toBe('saved')
	})

	it('reload takes the server text and etag', async () => {
		const save = vi.fn().mockResolvedValueOnce({ status: 409, etag: 'e9', content: 'theirs' })
		const auto = make(save)
		auto.change('mine')
		await vi.advanceTimersByTimeAsync(1500)
		expect(auto.reload()).toBe('theirs')
		expect(auto.etag).toBe('e9')
		expect(auto.state).toBe('saved')
		expect(readDraft(storage, 'k')).toBeNull()
	})

	it('retries a network failure 3 times (2 s, 5 s, 15 s), then stays in error', async () => {
		const save = vi.fn().mockRejectedValue(new Error('offline'))
		const auto = make(save)
		auto.change('text')
		await vi.advanceTimersByTimeAsync(1500)
		expect(auto.state).toBe('error')
		await vi.advanceTimersByTimeAsync(2000)
		expect(save).toHaveBeenCalledTimes(2)
		await vi.advanceTimersByTimeAsync(5000)
		expect(save).toHaveBeenCalledTimes(3)
		await vi.advanceTimersByTimeAsync(15000)
		expect(save).toHaveBeenCalledTimes(4)
		await vi.advanceTimersByTimeAsync(60000)
		expect(save).toHaveBeenCalledTimes(4)
		expect(auto.state).toBe('error')
		expect(states.at(-1)).toEqual(['error', 'network'])
	})

	it('recovers when a retry succeeds', async () => {
		const save = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(ok('e1'))
		const auto = make(save)
		auto.change('text')
		await vi.advanceTimersByTimeAsync(1500 + 2000)
		expect(auto.state).toBe('saved')
	})

	it('reports lost rights and deleted pages without retrying', async () => {
		const forbidden = make(vi.fn().mockResolvedValue({ status: 403 }))
		forbidden.change('x')
		await forbidden.flush()
		expect([forbidden.state, states.at(-1)[1]]).toEqual(['error', 'forbidden'])

		const save = vi.fn().mockResolvedValue({ status: 404 })
		const deleted = make(save)
		deleted.change('x')
		await deleted.flush()
		expect(states.at(-1)).toEqual(['error', 'deleted'])
		await vi.advanceTimersByTimeAsync(60000)
		expect(save).toHaveBeenCalledTimes(1)
	})

	it('mirrors unsaved text to storage and clears it once saved', async () => {
		const auto = make(vi.fn().mockResolvedValue(ok('e1')))
		auto.change('draft text')
		expect(readDraft(storage, 'k')).toEqual({ text: 'draft text', etag: 'e0' })
		await auto.flush()
		expect(readDraft(storage, 'k')).toBeNull()
	})

	it('knows whether leaving would lose text', async () => {
		const auto = make(vi.fn().mockResolvedValue(ok('e1')))
		expect(auto.hasUnsaved()).toBe(false)
		auto.change('x')
		expect(auto.hasUnsaved()).toBe(true)
		await auto.flush()
		expect(auto.hasUnsaved()).toBe(false)
	})

	it('resumes after the page was recreated', async () => {
		const save = vi.fn().mockResolvedValueOnce({ status: 404 }).mockResolvedValueOnce(ok('e2'))
		const auto = make(save)
		auto.change('my text')
		await auto.flush()
		expect(auto.state).toBe('error')
		await auto.resume('e1')
		expect(save).toHaveBeenLastCalledWith('my text', 'e1')
		expect(auto.state).toBe('saved')
	})
})
```

- [ ] **Step 2: Run it to see it fail**

Run: `npm test`
Expected: FAIL — `Cannot find module '../../src/editor/autosave.js'`.

- [ ] **Step 3: Write the state machine**

`src/editor/autosave.js`:

```js
/**
 * Autosave state machine for one page: idle → dirty → saving → saved, plus
 * error (network, forbidden, deleted) and conflict. Independent of
 * CodeMirror and of the network layer so it can be tested on its own.
 */

export const DEBOUNCE_MS = 1500
export const RETRY_DELAYS_MS = [2000, 5000, 15000]

/** The draft mirrored in sessionStorage, or null. */
export function readDraft(storage, key) {
	try {
		const raw = storage?.getItem(key)
		return raw ? JSON.parse(raw) : null
	} catch (e) {
		return null
	}
}

/**
 * @param {object} options
 * @param {Function} options.save (text, etag) => Promise<{status, etag?, content?}>; rejects on network failure
 * @param {string} options.etag etag of the text the editor started from
 * @param {Storage} [options.storage] where unsaved text is mirrored (sessionStorage)
 * @param {string} [options.draftKey] storage key, e.g. markdownsite:draft:{siteId}:{path}
 * @param {Function} [options.onState] (state, {reason}) on every state change
 */
export function createAutosave({ save, etag, storage = null, draftKey = '', onState = () => {} }) {
	const api = {
		state: 'idle',
		etag,
		conflict: null,
		text: null,
		change,
		flush,
		keepMine,
		reload,
		resume,
		hasUnsaved,
		dispose,
	}
	let timer = null
	let retries = 0
	let inFlight = null
	let pendingText = null // text not yet confirmed by the server

	function setState(state, info = {}) {
		if (state === api.state && state === 'dirty') {
			return // typing keeps the editor dirty; report it once
		}
		api.state = state
		onState(state, info)
	}

	function mirror() {
		if (!storage || !draftKey) {
			return
		}
		try {
			if (pendingText === null) {
				storage.removeItem(draftKey)
			} else {
				storage.setItem(draftKey, JSON.stringify({ text: pendingText, etag: api.etag }))
			}
		} catch (e) {
			// storage full or disabled: the editor still works
		}
	}

	function schedule(delay) {
		clearTimeout(timer)
		timer = setTimeout(() => { run() }, delay)
	}

	function change(text) {
		api.text = text
		pendingText = text
		mirror()
		if (api.state === 'conflict' || (api.state === 'error' && api.reason !== 'network')) {
			return // paused: the user has to decide first
		}
		retries = 0
		setState('dirty')
		schedule(DEBOUNCE_MS)
	}

	async function run() {
		clearTimeout(timer)
		if (inFlight) {
			await inFlight
		}
		if (pendingText === null || api.state === 'conflict') {
			return
		}
		const text = pendingText
		setState('saving')
		inFlight = (async () => {
			let result
			try {
				result = await save(text, api.etag)
			} catch (e) {
				api.reason = 'network'
				if (retries < RETRY_DELAYS_MS.length) {
					schedule(RETRY_DELAYS_MS[retries++])
				}
				setState('error', { reason: 'network' })
				return
			}
			if (result.status === 409) {
				api.conflict = { etag: result.etag, content: result.content }
				setState('conflict')
				return
			}
			if (result.status === 403 || result.status === 404) {
				api.reason = result.status === 403 ? 'forbidden' : 'deleted'
				setState('error', { reason: api.reason })
				return
			}
			api.etag = result.etag
			api.reason = null
			retries = 0
			if (pendingText === text) {
				pendingText = null
				mirror()
				setState('saved')
			} else {
				mirror()
				setState('dirty')
				schedule(DEBOUNCE_MS)
			}
		})()
		await inFlight
		inFlight = null
	}

	/** Saves now (page change, leaving edit mode, Ctrl+click). */
	async function flush() {
		clearTimeout(timer)
		if (inFlight) {
			await inFlight
		}
		if (pendingText !== null && api.state !== 'conflict') {
			await run()
		}
	}

	/** Conflict: overwrite the server's version with the local text. */
	async function keepMine() {
		if (!api.conflict) {
			return
		}
		api.etag = api.conflict.etag
		api.conflict = null
		setState('dirty')
		await run()
	}

	/** Conflict: drop the local text; returns the server's text to show. */
	function reload() {
		const server = api.conflict
		if (!server) {
			return null
		}
		api.etag = server.etag
		api.conflict = null
		pendingText = null
		api.text = server.content
		mirror()
		setState('saved')
		return server.content
	}

	/** After an error (page recreated, rights restored): save again with this etag. */
	async function resume(newEtag) {
		api.etag = newEtag
		api.reason = null
		api.conflict = null
		retries = 0
		setState('dirty')
		await run()
	}

	function hasUnsaved() {
		return pendingText !== null
	}

	function dispose() {
		clearTimeout(timer)
	}

	return api
}
```

- [ ] **Step 4: Run the tests**

Run: `npm test && npm run lint`
Expected: `Tests  61 passed (61)`, lint exit code 0.

- [ ] **Step 5: Commit**

```bash
git add tests/js/autosave.test.js src/editor/autosave.js
git commit -m "feat: autosave with conflict, retry and draft handling"
```

---

### Task 12: Editor modules

**Files:**
- Create: `src/editor/livePreview.js`, `src/editor/blockWidgets.js`, `src/editor/wikilinkComplete.js`, `src/editor/attachments.js`, `src/editor/keymap.js`, `src/editor/index.js`

- [ ] **Step 1: Live preview**

`src/editor/livePreview.js`:

```js
import { RangeSetBuilder } from '@codemirror/state'
import { Decoration, ViewPlugin } from '@codemirror/view'
import { syntaxTree } from '@codemirror/language'

const hidden = Decoration.replace({})

// Inline elements whose markers are hidden while the selection is elsewhere.
const MARKERS = {
	Emphasis: ['EmphasisMark'],
	StrongEmphasis: ['EmphasisMark'],
	Strikethrough: ['StrikethroughMark'],
	Highlight: ['HighlightMark'],
	InlineCode: ['CodeMark'],
}

const LINE_CLASSES = {
	ATXHeading1: 'cm-mds-h1',
	ATXHeading2: 'cm-mds-h2',
	ATXHeading3: 'cm-mds-h3',
	ATXHeading4: 'cm-mds-h4',
	ATXHeading5: 'cm-mds-h5',
	ATXHeading6: 'cm-mds-h6',
}

/** True when any selection range touches [from, to]. */
export function touches(selection, from, to) {
	return selection.ranges.some(r => r.from <= to && r.to >= from)
}

/** Ranges to hide (and line classes to add) for the visible part of the document. */
function decorations(view) {
	const { state } = view
	const ranges = []
	for (const { from, to } of view.visibleRanges) {
		syntaxTree(state).iterate({
			from,
			to,
			enter(node) {
				const name = node.name
				if (LINE_CLASSES[name]) {
					const line = state.doc.lineAt(node.from)
					ranges.push([line.from, line.from, Decoration.line({ class: LINE_CLASSES[name] })])
				}
				const active = touches(state.selection, node.from, node.to)
				if (active) {
					return
				}
				if (MARKERS[name]) {
					for (let child = node.node.firstChild; child; child = child.nextSibling) {
						if (MARKERS[name].includes(child.name)) {
							ranges.push([child.from, child.to, hidden])
						}
					}
				} else if (LINE_CLASSES[name]) {
					const mark = node.node.getChild('HeaderMark')
					if (mark) {
						const end = state.doc.sliceString(mark.to, mark.to + 1) === ' ' ? mark.to + 1 : mark.to
						ranges.push([mark.from, end, hidden])
					}
				} else if (name === 'Link') {
					// [text](url "title"): keep only "text".
					const marks = node.node.getChildren('LinkMark')
					if (marks.length >= 2) {
						ranges.push([marks[0].from, marks[0].to, hidden])
						ranges.push([marks[1].from, node.to, hidden])
					}
				} else if (name === 'Wikilink') {
					// [[target]] shows "target"; [[target|label]] shows "label".
					const label = node.node.getChild('WikilinkLabel')
					const marks = node.node.getChildren('WikilinkMark')
					if (label) {
						ranges.push([node.from, label.from, hidden])
					} else if (marks.length) {
						ranges.push([marks[0].from, marks[0].to, hidden])
					}
					if (marks.length) {
						const last = marks[marks.length - 1]
						ranges.push([last.from, last.to, hidden])
					}
					ranges.push([node.from, node.to, Decoration.mark({ class: 'cm-mds-wikilink' })])
				}
			},
		})
	}
	// RangeSetBuilder needs ranges sorted by start, then by end-side.
	ranges.sort((a, b) => a[0] - b[0] || a[2].startSide - b[2].startSide || a[1] - b[1])
	const builder = new RangeSetBuilder()
	for (const [from, to, deco] of ranges) {
		builder.add(from, to, deco)
	}
	return builder.finish()
}

/**
 * Obsidian-style live preview: Markdown markers (`**`, `_`, `~~`, `==`,
 * heading `#`, backticks, link syntax, wikilink brackets) are hidden unless
 * the selection touches their element. The text itself is never changed.
 */
export const livePreview = ViewPlugin.fromClass(class {
	constructor(view) {
		this.decorations = decorations(view)
	}

	update(update) {
		if (update.docChanged || update.selectionSet || update.viewportChanged) {
			this.decorations = decorations(update.view)
		}
	}
}, { decorations: v => v.decorations })
```

- [ ] **Step 2: Block widgets**

`src/editor/blockWidgets.js`:

```js
import { StateEffect, StateField } from '@codemirror/state'
import { Decoration, EditorView, ViewPlugin, WidgetType } from '@codemirror/view'
import { syntaxTree } from '@codemirror/language'
import { decorateCallouts } from '../services/calloutCopy.js'
import { decorateCode } from '../services/codeHighlight.js'
import { renderDiagrams } from '../services/mermaid.js'
import { touches } from './livePreview.js'

const rendered = StateEffect.define()

/** Rendered HTML per block source: string, null (failed), or a pending promise. */
const cache = new Map()

class RenderedBlock extends WidgetType {
	constructor(source, html, kind) {
		super()
		this.source = source
		this.html = html
		this.kind = kind
	}

	eq(other) {
		return other.source === this.source && other.html === this.html
	}

	toDOM(view) {
		const el = document.createElement('div')
		// mds-content: the same styles as the reading view (src/styles/content.css).
		el.className = `mds-block-widget mds-block-widget--${this.kind} mds-content`
		if (this.kind === 'frontmatter') {
			// The server renders frontmatter as nothing: show the properties instead.
			const pre = document.createElement('pre')
			pre.className = 'mds-frontmatter'
			pre.textContent = this.source.replace(/^---\n|\n---\s*$/g, '')
			el.appendChild(pre)
			return el
		}
		el.innerHTML = this.html
		decorateCallouts(el)
		// Diagrams and images change the block's height once ready: tell the
		// editor, or clicks below would land on the wrong line.
		const remeasure = () => view.requestMeasure()
		Promise.all([decorateCode(el), renderDiagrams(el)]).then(remeasure, remeasure)
		el.querySelectorAll('img').forEach(img => img.addEventListener('load', remeasure, { once: true }))
		return el
	}

	ignoreEvent(event) {
		return event.type !== 'mousedown'
	}
}

/** Blocks shown rendered while the cursor is outside them: [{from, to, kind}]. */
export function findBlocks(state) {
	const blocks = []
	const doc = state.doc
	const text = doc.toString()
	const front = /^---\n[\s\S]*?\n---[ \t]*(?:\n|$)/.exec(text)
	if (front) {
		blocks.push({ from: 0, to: front[0].endsWith('\n') ? front[0].length - 1 : front[0].length, kind: 'frontmatter' })
	}
	const tree = syntaxTree(state)
	for (let node = tree.topNode.firstChild; node; node = node.nextSibling) {
		if (front && node.from < blocks[0].to) {
			continue
		}
		let kind = null
		if (node.name === 'Table') {
			kind = 'table'
		} else if (node.name === 'Blockquote' && /^\s*>\s*\[!/.test(doc.lineAt(node.from).text)) {
			kind = 'callout'
		} else if (node.name === 'FencedCode' && /^mermaid\b/i.test(doc.sliceString(node.getChild('CodeInfo')?.from ?? node.from, node.getChild('CodeInfo')?.to ?? node.from))) {
			kind = 'mermaid'
		} else if (node.name === 'Paragraph') {
			const only = node.firstChild
			if (only && !only.nextSibling && only.from === node.from && only.to === node.to && (only.name === 'Embed' || only.name === 'Image')) {
				kind = 'embed'
			}
		}
		if (kind) {
			blocks.push({ from: doc.lineAt(node.from).from, to: doc.lineAt(node.to).to, kind })
		}
	}
	return blocks
}

function build(state, render, dispatch) {
	const decorations = []
	for (const block of findBlocks(state)) {
		if (touches(state.selection, block.from, block.to)) {
			continue
		}
		const source = state.doc.sliceString(block.from, block.to)
		let html = ''
		if (block.kind !== 'frontmatter') {
			html = cache.get(source)
			if (html === undefined) {
				const pending = render(source).then(
					(result) => { cache.set(source, result) },
					() => { cache.set(source, null) }, // failure: stay raw text, no message
				).then(() => dispatch())
				cache.set(source, pending)
				continue
			}
			if (typeof html !== 'string') {
				continue // still loading, or failed
			}
		}
		decorations.push(Decoration.replace({ widget: new RenderedBlock(source, html, block.kind), block: true }).range(block.from, block.to))
	}
	return Decoration.set(decorations, true)
}

/**
 * Callouts, tables, embeds, images, Mermaid diagrams and frontmatter are
 * shown as the reading view renders them (HTML from `render(markdown)`)
 * while the cursor is outside; clicking one reveals its Markdown.
 *
 * @param {Function} render (markdown) => Promise<string> html
 */
export function blockWidgets(render) {
	let view = null
	const refresh = () => view?.dispatch({ effects: rendered.of(null) })
	const field = StateField.define({
		create: state => build(state, render, refresh),
		update(value, tr) {
			if (tr.docChanged || tr.selection || tr.effects.some(e => e.is(rendered))) {
				return build(tr.state, render, refresh)
			}
			return value
		},
		provide: f => EditorView.decorations.from(f),
	})
	// Remembers the view so a finished render can trigger a redraw.
	const capture = ViewPlugin.define((v) => {
		view = v
		return { destroy() { view = null } }
	})
	const reveal = EditorView.domEventHandlers({
		mousedown(event, v) {
			const widget = event.target.closest?.('.mds-block-widget')
			if (!widget || event.target.closest('a, button, summary')) {
				return false
			}
			const pos = v.posAtDOM(widget)
			v.dispatch({ selection: { anchor: pos } })
			v.focus()
			event.preventDefault()
			return true
		},
	})
	return [field, capture, reveal]
}
```

- [ ] **Step 3: Completion, attachments, keymap**

`src/editor/wikilinkComplete.js`:

```js
/**
 * `[[` completion over the site's pages and aliases.
 *
 * @param {Function} getPages () => [{path, title, aliases}] (from GET /tree)
 */
export function wikilinkCompletion(getPages) {
	return (context) => {
		const match = context.matchBefore(/!?\[\[[^\]|#\n]*$/)
		if (!match) {
			return null
		}
		const from = match.from + match.text.indexOf('[[') + 2
		const pages = getPages() || []
		const titleCount = new Map()
		for (const page of pages) {
			const key = page.title.toLowerCase()
			titleCount.set(key, (titleCount.get(key) || 0) + 1)
		}
		const options = []
		for (const page of pages) {
			const folder = page.path.includes('/') ? page.path.slice(0, page.path.lastIndexOf('/')) : ''
			// A title shared by several pages needs the path to stay unambiguous.
			const target = titleCount.get(page.title.toLowerCase()) > 1 ? page.path.replace(/\.md$/i, '') : page.title
			options.push({ label: page.title, detail: folder, apply: applyLink(target), type: 'text' })
			for (const alias of page.aliases || []) {
				options.push({ label: alias, detail: `→ ${page.title}`, apply: applyLink(alias), type: 'text' })
			}
		}
		return { from, options, validFor: /^[^\]|#\n]*$/ }
	}
}

/** Inserts the target and closing brackets (unless they are already there). */
export function applyLink(target) {
	return (view, completion, from, to) => {
		const closed = view.state.doc.sliceString(to, to + 2) === ']]'
		const insert = closed ? target : target + ']]'
		view.dispatch({
			changes: { from, to, insert },
			selection: { anchor: from + target.length + 2 },
		})
	}
}
```

`src/editor/attachments.js`:

```js
import { EditorView } from '@codemirror/view'

/**
 * Dropping or pasting files uploads them and inserts the returned embed.
 *
 * @param {Function} upload (File) => Promise<{embed: string}>
 * @param {Function} onError (error) => void
 */
export function attachments(upload, onError) {
	async function insert(view, files, pos) {
		for (const file of files) {
			try {
				const { embed } = await upload(file)
				const text = embed + '\n'
				view.dispatch({ changes: { from: pos, insert: text }, selection: { anchor: pos + text.length } })
				pos += text.length
			} catch (error) {
				onError(error)
			}
		}
	}
	return EditorView.domEventHandlers({
		drop(event, view) {
			const files = [...(event.dataTransfer?.files || [])]
			if (!files.length) {
				return false
			}
			event.preventDefault()
			const pos = view.posAtCoords({ x: event.clientX, y: event.clientY }) ?? view.state.selection.main.head
			insert(view, files, pos)
			return true
		},
		paste(event, view) {
			const files = [...(event.clipboardData?.files || [])]
			if (!files.length) {
				return false
			}
			event.preventDefault()
			insert(view, files, view.state.selection.main.head)
			return true
		},
	})
}
```

`src/editor/keymap.js`:

```js
import { EditorSelection } from '@codemirror/state'
import { keymap } from '@codemirror/view'

/** Wraps each selection in `marker` (or removes it when already wrapped). */
export function toggleWrap(marker) {
	return (view) => {
		view.dispatch(view.state.changeByRange((range) => {
			const text = view.state.sliceDoc(range.from, range.to)
			const n = marker.length
			if (text.length >= 2 * n && text.startsWith(marker) && text.endsWith(marker)) {
				return {
					changes: { from: range.from, to: range.to, insert: text.slice(n, -n) },
					range: EditorSelection.range(range.from, range.to - 2 * n),
				}
			}
			return {
				changes: { from: range.from, to: range.to, insert: marker + text + marker },
				range: EditorSelection.range(range.from + n, range.to + n),
			}
		}))
		return true
	}
}

/** [selection](|) with the cursor between the parentheses. */
export function insertLink(view) {
	view.dispatch(view.state.changeByRange((range) => {
		const text = view.state.sliceDoc(range.from, range.to)
		const insert = `[${text}]()`
		return { changes: { from: range.from, to: range.to, insert }, range: EditorSelection.cursor(range.from + insert.length - 1) }
	}))
	return true
}

/** Cmd/Ctrl+B, I, K and Escape (leave edit mode). */
export function editorKeymap({ onExit }) {
	return keymap.of([
		{ key: 'Mod-b', run: toggleWrap('**') },
		{ key: 'Mod-i', run: toggleWrap('*') },
		{ key: 'Mod-k', run: insertLink },
		{ key: 'Escape', run: () => { onExit(); return true } },
	])
}
```

- [ ] **Step 4: Editor factory (the lazy chunk's entry)**

`src/editor/index.js`:

```js
// Loaded as a lazy chunk the first time a user enters edit mode: readers
// never download CodeMirror.
import { EditorState } from '@codemirror/state'
import { EditorView, keymap } from '@codemirror/view'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { autocompletion } from '@codemirror/autocomplete'
import { HighlightStyle, syntaxHighlighting, syntaxTree } from '@codemirror/language'
import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { tags } from '@lezer/highlight'
import { obsidianSyntax } from './syntax.js'
import { livePreview } from './livePreview.js'
import { blockWidgets } from './blockWidgets.js'
import { wikilinkCompletion } from './wikilinkComplete.js'
import { attachments } from './attachments.js'
import { editorKeymap } from './keymap.js'

const markdownStyle = HighlightStyle.define([
	{ tag: tags.strong, fontWeight: '700' },
	{ tag: tags.emphasis, fontStyle: 'italic' },
	{ tag: tags.strikethrough, textDecoration: 'line-through', color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.special(tags.content), backgroundColor: 'rgba(255, 208, 0, 0.35)', borderRadius: '3px' },
	{ tag: tags.link, color: 'var(--color-primary-element)' },
	{ tag: tags.url, color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.monospace, fontFamily: 'monospace', backgroundColor: 'var(--color-background-dark)', borderRadius: '4px' },
	{ tag: tags.processingInstruction, color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.quote, color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.heading, fontWeight: '700' },
])

const theme = EditorView.theme({
	'&': { backgroundColor: 'transparent', color: 'var(--color-main-text)' },
	'&.cm-focused': { outline: 'none' },
	'.cm-scroller': { fontFamily: 'inherit', lineHeight: '1.6' },
	// Nextcloud's core CSS gives every div[contenteditable] a fixed width, a
	// border and a focus ring; the editor and its widgets must not get them.
	'.cm-content': {
		width: 'auto', minHeight: '0', margin: '0', padding: '0', border: 'none', borderRadius: '0',
		boxShadow: 'none', background: 'transparent', caretColor: 'var(--color-main-text)',
	},
	'.cm-content:focus, .cm-content:focus-visible': { outline: 'none', boxShadow: 'none' },
	'.cm-line': { padding: '0' },
	'.cm-mds-h1': { fontSize: '1.9em', fontWeight: '700' },
	'.cm-mds-h2': { fontSize: '1.5em', fontWeight: '700' },
	'.cm-mds-h3': { fontSize: '1.2em', fontWeight: '600' },
	'.cm-mds-h4, .cm-mds-h5, .cm-mds-h6': { fontWeight: '600' },
	'.cm-mds-wikilink': { color: 'var(--mds-link-color, var(--color-primary-element))' },
	'.mds-block-widget': {
		width: 'auto', maxWidth: 'none', minHeight: '0', margin: '0', padding: '0', border: 'none',
		boxShadow: 'none', background: 'transparent', cursor: 'pointer', borderRadius: 'var(--border-radius-large, 8px)',
	},
	'.mds-block-widget:hover': { outline: '1px dashed var(--color-border-dark, var(--color-border))' },
	'.mds-frontmatter': { margin: '0', fontSize: '0.9em', color: 'var(--color-text-maxcontrast)' },
})

/** Name or URL of the link under a Ctrl/Cmd+click, or null. */
function linkAt(view, pos) {
	for (let node = syntaxTree(view.state).resolveInner(pos, 1); node; node = node.parent) {
		if (node.name === 'Wikilink' || node.name === 'Embed') {
			const target = node.getChild('WikilinkTarget')
			return target ? { wikilink: view.state.sliceDoc(target.from, target.to) } : null
		}
		if (node.name === 'Link') {
			const url = node.getChild('URL')
			return url ? { href: view.state.sliceDoc(url.from, url.to) } : null
		}
	}
	return null
}

/**
 * Creates the Markdown editor inside `parent`.
 *
 * @param {object} options
 * @param {HTMLElement} options.parent
 * @param {string} options.doc initial Markdown
 * @param {Function} options.getPages () => pages for [[ completion
 * @param {Function} options.render (markdown) => Promise<string> HTML for block widgets
 * @param {Function} options.upload (File) => Promise<{embed}>
 * @param {Function} options.onChange (text) on every edit
 * @param {Function} options.onExit Escape
 * @param {Function} options.onFollow ({wikilink}|{href}) on Ctrl/Cmd+click of a link
 * @param {Function} options.onError (error) for failed uploads
 * @return {EditorView}
 */
export function createEditor({ parent, doc, getPages, render, upload, onChange, onExit, onFollow, onError }) {
	return new EditorView({
		parent,
		state: EditorState.create({
			doc,
			extensions: [
				history(),
				markdown({ base: markdownLanguage, extensions: [obsidianSyntax] }),
				syntaxHighlighting(markdownStyle),
				livePreview,
				blockWidgets(render),
				autocompletion({ override: [wikilinkCompletion(getPages)] }),
				attachments(upload, onError),
				keymap.of([...defaultKeymap, ...historyKeymap]),
				editorKeymap({ onExit }),
				EditorView.lineWrapping,
				theme,
				EditorView.updateListener.of((update) => {
					if (update.docChanged) {
						onChange(update.state.doc.toString())
					}
				}),
				EditorView.domEventHandlers({
					mousedown(event, view) {
						if (!(event.ctrlKey || event.metaKey)) {
							return false
						}
						// The clicked element is more reliable than coordinates.
						let pos = null
						try {
							pos = view.posAtDOM(event.target)
						} catch (e) {
							pos = view.posAtCoords({ x: event.clientX, y: event.clientY })
						}
						const link = pos === null ? null : linkAt(view, pos)
						if (!link) {
							return false
						}
						event.preventDefault()
						onFollow(link)
						return true
					},
				}),
			],
		}),
	})
}

/** Replaces the whole document (after "Reload" on a conflict). */
export function setDocument(view, text) {
	view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } })
}
```

- [ ] **Step 5: Lint and test**

Run: `npm run lint && npm test`
Expected: lint exit code 0, `Tests  61 passed (61)`.

- [ ] **Step 6: Commit**

```bash
git add src/editor
git commit -m "feat: CodeMirror live preview, block widgets, completion and shortcuts"
```

---

### Task 13: Editing API calls

**Files:**
- Modify: `src/services/api.js`

- [ ] **Step 1: Append to `src/services/api.js`**

```js

// Editing (owners and editors). Statuses the editor handles itself
// (403, 404, 409) resolve with {status, ...data}; network failures reject.
const handled = (e, statuses) => {
	if (e.response && statuses.includes(e.response.status)) {
		return { status: e.response.status, ...e.response.data }
	}
	throw e
}
export const getSource = (siteId, path) => axios.get(base(`/s/${siteId}/source/${encPath(path)}`)).then(r => r.data)
export const savePage = (siteId, path, content, etag) => axios.put(base(`/s/${siteId}/page/${encPath(path)}`), { content, etag })
	.then(r => ({ status: r.status, ...r.data }), e => handled(e, [403, 404, 409]))
export const createPage = (siteId, path) => axios.post(base(`/s/${siteId}/pages`), { path }).then(r => r.data)
export const createFolder = (siteId, path) => axios.post(base(`/s/${siteId}/folders`), { path }).then(r => r.data)
export const getBacklinks = (siteId, path, to) => axios.get(base(`/s/${siteId}/backlinks/${encPath(path)}`), { params: { to } }).then(r => r.data)
export const moveNode = (siteId, from, to, updateLinks) => axios.post(base(`/s/${siteId}/move`), { from, to, updateLinks }).then(r => r.data)
export const deleteNode = (siteId, path) => axios.delete(base(`/s/${siteId}/node/${encPath(path)}`)).then(r => r.data)
export const uploadAttachment = (siteId, page, file) => {
	const form = new FormData()
	form.append('file', file)
	form.append('page', page)
	return axios.post(base(`/s/${siteId}/attachments`), form).then(r => r.data)
}
export const renderMarkdown = (siteId, path, markdown) => axios.post(base(`/s/${siteId}/render`), { markdown, path }).then(r => r.data.html)
export const updateShareRole = (siteId, shareId, role) => axios.put(base(`/sites/${siteId}/shares/${shareId}`), { role }).then(r => r.data)
```

- [ ] **Step 2: Lint and commit**

Run: `npm run lint`
Expected: exit code 0.

```bash
git add src/services/api.js
git commit -m "feat: client calls for editing and file management"
```

---

### Task 14: `PageEditor`

**Files:**
- Create: `src/components/PageEditor.vue`

- [ ] **Step 1: Write the component**

```vue
<template>
	<div class="mds-editor">
		<div class="mds-editor-bar">
			<span class="mds-editor-status" :class="'is-' + state">{{ statusText }}</span>
		</div>

		<div v-if="draft" class="mds-editor-banner">
			<span>{{ t('markdownsite', 'This page has unsaved text from your last visit.') }}</span>
			<NcButton variant="primary" @click="restoreDraft">{{ t('markdownsite', 'Restore it') }}</NcButton>
			<NcButton variant="tertiary" @click="discardDraft">{{ t('markdownsite', 'Discard') }}</NcButton>
		</div>
		<div v-if="state === 'conflict'" class="mds-editor-banner mds-editor-banner--warning">
			<span>{{ t('markdownsite', 'Modified elsewhere. Autosave is paused.') }}</span>
			<NcButton @click="reloadFromServer">{{ t('markdownsite', 'Reload') }}</NcButton>
			<NcButton variant="primary" @click="keepMine">{{ t('markdownsite', 'Keep mine') }}</NcButton>
		</div>
		<div v-if="reason === 'deleted'" class="mds-editor-banner mds-editor-banner--warning">
			<span>{{ t('markdownsite', 'Page deleted elsewhere.') }}</span>
			<NcButton variant="primary" @click="recreate">{{ t('markdownsite', 'Recreate with my text') }}</NcButton>
		</div>
		<p v-if="loadError" class="mds-editor-banner mds-editor-banner--error">
			{{ t('markdownsite', 'The editor could not be opened.') }}
		</p>

		<div v-if="loading" class="mds-editor-loading"><NcLoadingIcon :size="28" /></div>
		<div ref="host" class="mds-editor-host" :style="cssVars" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { translate as t } from '@nextcloud/l10n'
import { createPage, getSource, renderMarkdown, savePage, uploadAttachment } from '../services/api.js'
import { loadChunk } from '../services/chunks.js'
import { createAutosave, readDraft } from '../editor/autosave.js'

export default {
	name: 'PageEditor',
	components: { NcButton, NcLoadingIcon },
	props: {
		siteId: { type: [String, Number], required: true },
		path: { type: String, required: true },
		pages: { type: Array, default: () => [] },
		cssVars: { type: Object, default: () => ({}) },
	},
	emits: ['exit', 'follow', 'forbidden', 'saved'],
	setup() { return { t } },
	data() {
		return { loading: true, loadError: false, state: 'idle', reason: null, draft: null }
	},
	computed: {
		draftKey() { return `markdownsite:draft:${this.siteId}:${this.path}` },
		statusText() {
			switch (this.state) {
			case 'dirty': return t('markdownsite', 'Unsaved changes')
			case 'saving': return t('markdownsite', 'Saving…')
			case 'saved': return t('markdownsite', 'Saved')
			case 'conflict': return t('markdownsite', 'Modified elsewhere')
			case 'error': return this.reason === 'network' ? t('markdownsite', 'Offline: retrying…') : t('markdownsite', 'Not saved')
			default: return ''
			}
		},
	},
	async mounted() {
		this.onBeforeUnload = (event) => {
			if (this.autosave?.hasUnsaved()) {
				event.preventDefault()
				event.returnValue = ''
			}
		}
		window.addEventListener('beforeunload', this.onBeforeUnload)
		const [editor, source] = await Promise.all([
			loadChunk(() => import(/* webpackChunkName: "editor" */ '../editor/index.js'), 'the editor'),
			getSource(this.siteId, this.path).catch(() => null),
		])
		this.loading = false
		if (!editor || !source || !this.$refs.host) {
			this.loadError = true
			return
		}
		this.editorModule = editor
		const draft = readDraft(window.sessionStorage, this.draftKey)
		if (draft && draft.text !== source.content) {
			this.draft = draft
		}
		this.autosave = createAutosave({
			save: (text, etag) => savePage(this.siteId, this.path, text, etag),
			etag: source.etag,
			storage: window.sessionStorage,
			draftKey: this.draftKey,
			onState: (state, info) => {
				this.state = state
				this.reason = info?.reason ?? null
				if (state === 'saved') {
					this.$emit('saved')
				}
				if (info?.reason === 'forbidden') {
					this.$emit('forbidden', this.autosave.text ?? '')
				}
			},
		})
		this.view = editor.createEditor({
			parent: this.$refs.host,
			doc: source.content,
			getPages: () => this.pages,
			render: markdown => renderMarkdown(this.siteId, this.path, markdown),
			upload: file => uploadAttachment(this.siteId, this.path, file),
			onChange: (text) => {
				if (!this.applyingServerText) {
					this.autosave.change(text)
				}
			},
			onExit: () => this.$emit('exit'),
			onFollow: link => this.$emit('follow', link),
			onError: (error) => this.showError(error),
		})
		this.view.focus()
	},
	beforeUnmount() {
		window.removeEventListener('beforeunload', this.onBeforeUnload)
		this.autosave?.flush()
		this.autosave?.dispose()
		this.view?.destroy()
	},
	methods: {
		/** Saves now; resolves when the server answered. */
		async flush() {
			await this.autosave?.flush()
		},
		restoreDraft() {
			this.editorModule.setDocument(this.view, this.draft.text)
			this.draft = null
		},
		discardDraft() {
			window.sessionStorage.removeItem(this.draftKey)
			this.draft = null
		},
		reloadFromServer() {
			const text = this.autosave.reload()
			if (text !== null) {
				// The server's own text: showing it is not an edit to save.
				this.applyingServerText = true
				this.editorModule.setDocument(this.view, text)
				this.applyingServerText = false
			}
		},
		keepMine() {
			this.autosave.keepMine()
		},
		async recreate() {
			try {
				const { etag } = await createPage(this.siteId, this.path)
				await this.autosave.resume(etag)
			} catch (e) {
				this.showError(e)
			}
		},
		async showError(error) {
			const { showError } = await import('@nextcloud/dialogs')
			showError(error?.response?.data?.message || t('markdownsite', 'Upload failed'))
		},
	},
}
</script>

<style scoped>
.mds-editor { position: relative; }
.mds-editor-bar { display: flex; justify-content: flex-end; min-height: 20px; font-size: 0.85em; color: var(--color-text-maxcontrast); }
.mds-editor-status.is-error, .mds-editor-status.is-conflict { color: var(--color-error); }
.mds-editor-banner {
	display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 8px 0; padding: 8px 12px;
	border-radius: var(--border-radius-large, 8px); background: var(--color-background-dark);
}
.mds-editor-banner--warning { background: rgba(236, 117, 0, 0.12); border: 1px solid rgba(236, 117, 0, 0.4); }
.mds-editor-banner--error { color: var(--color-error); }
.mds-editor-loading { display: flex; justify-content: center; padding: 32px; }
.mds-editor-host { max-width: 760px; min-height: 50vh; margin: 0 auto; padding-top: 16px; }
</style>
```

- [ ] **Step 2: Lint and commit**

Run: `npm run lint`
Expected: exit code 0.

```bash
git add src/components/PageEditor.vue
git commit -m "feat: page editor component"
```

---

### Task 15: Dialogs and file operations

**Files:**
- Create: `src/components/NameDialog.vue`, `src/components/MoveDialog.vue`, `src/services/fileOps.js`, `tests/js/fileOps.test.js`

- [ ] **Step 1: Write the failing test**

`tests/js/fileOps.test.js`:

```js
// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('../../src/components/MoveDialog.vue', () => ({ default: {} }))
vi.mock('../../src/components/NameDialog.vue', () => ({ default: {} }))

const { canDrop, countPages, join, movedPath, parentOf } = await import('../../src/services/fileOps.js')

describe('fileOps path helpers', () => {
	it('joins and splits paths', () => {
		expect(join('', 'A.md')).toBe('A.md')
		expect(join('Guides', 'A.md')).toBe('Guides/A.md')
		expect(parentOf('Guides/Deep/A.md')).toBe('Guides/Deep')
		expect(parentOf('A.md')).toBe('')
	})

	it('follows a moved page or folder', () => {
		expect(movedPath('Old.md', 'Old.md', 'New.md')).toBe('New.md')
		expect(movedPath('Projects/Alpha.md', 'Projects', 'Work/Projects')).toBe('Work/Projects/Alpha.md')
		expect(movedPath('Projects2/A.md', 'Projects', 'Work')).toBe('Projects2/A.md')
	})

	it('refuses drops into itself, a descendant or the current folder', () => {
		expect(canDrop('Projects', 'Projects')).toBe(false)
		expect(canDrop('Projects', 'Projects/Sub')).toBe(false)
		expect(canDrop('Projects/A.md', 'Projects')).toBe(false)
		expect(canDrop('A.md', '')).toBe(false)
		expect(canDrop('Projects/A.md', '')).toBe(true)
		expect(canDrop('Projects', 'Archive')).toBe(true)
	})

	it('counts the pages a folder deletion takes with it', () => {
		const tree = { type: 'dir', note: 'X/X.md', children: [
			{ type: 'page' }, { type: 'dir', children: [{ type: 'page' }, { type: 'page' }] },
		] }
		expect(countPages(tree)).toBe(4)
	})
})
```

- [ ] **Step 2: Run it to see it fail**

Run: `npm test`
Expected: FAIL — `Failed to resolve import "../../src/services/fileOps.js" from "tests/js/fileOps.test.js"`.

- [ ] **Step 3: Dialogs**

`src/components/NameDialog.vue`:

```vue
<template>
	<NcDialog :name="title" size="small" @update:open="v => !v && $emit('close', null)">
		<form class="mds-name-dialog" @submit.prevent="submit">
			<NcTextField ref="field" v-model="value" :label="label" :error="!!error" :helper-text="error" />
		</form>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close', null)">{{ t('markdownsite', 'Cancel') }}</NcButton>
			<NcButton variant="primary" :disabled="!value.trim()" @click="submit">{{ confirmLabel }}</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'

/** Asks for a name. Closes with the trimmed name, or null when cancelled. */
export default {
	name: 'NameDialog',
	components: { NcButton, NcDialog, NcTextField },
	props: {
		title: { type: String, required: true },
		label: { type: String, required: true },
		confirmLabel: { type: String, required: true },
		initial: { type: String, default: '' },
	},
	emits: ['close'],
	setup() { return { t } },
	data() { return { value: this.initial, error: '' } },
	mounted() { this.$nextTick(() => this.$refs.field?.focus()) },
	methods: {
		submit() {
			const name = this.value.trim()
			if (!name) {
				return
			}
			if (/[/\\:*?"<>|]/.test(name) || name.startsWith('.')) {
				this.error = t('markdownsite', 'A name cannot start with a dot or contain / \\ : * ? " < > |')
				return
			}
			this.$emit('close', name)
		},
	},
}
</script>

<style scoped>
.mds-name-dialog { padding: 8px 0; }
</style>
```

`src/components/MoveDialog.vue`:

```vue
<template>
	<NcDialog :name="t('markdownsite', 'Update links?')" size="small" @update:open="v => !v && $emit('close', null)">
		<p class="mds-move-text">
			{{ n('markdownsite', '%n link', '%n links', count) }}
			{{ n('markdownsite', 'in %n page will be updated.', 'in %n pages will be updated.', pages.length) }}
		</p>
		<NcCheckboxRadioSwitch v-model="updateLinks">
			{{ t('markdownsite', 'Update the links') }}
		</NcCheckboxRadioSwitch>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close', null)">{{ t('markdownsite', 'Cancel') }}</NcButton>
			<NcButton variant="primary" @click="$emit('close', { updateLinks })">{{ t('markdownsite', 'Confirm') }}</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/** Confirms a rename or move that affects links. Closes with {updateLinks} or null. */
export default {
	name: 'MoveDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog },
	props: {
		count: { type: Number, required: true },
		pages: { type: Array, required: true },
	},
	emits: ['close'],
	setup() { return { t, n } },
	data() { return { updateLinks: true } },
}
</script>

<style scoped>
.mds-move-text { margin: 4px 0 12px; }
</style>
```

- [ ] **Step 4: File operations**

`src/services/fileOps.js`:

```js
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { createFolder, createPage, deleteNode, getBacklinks, moveNode } from './api.js'
import MoveDialog from '../components/MoveDialog.vue'
import NameDialog from '../components/NameDialog.vue'

/** `dir/name`, or `name` at the root. */
export const join = (dir, name) => (dir ? `${dir}/${name}` : name)

/** Parent folder of a path ('' at the root). */
export const parentOf = path => (path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '')

/** Where `path` ends up after `from` moved to `to` (unchanged when unrelated). */
export function movedPath(path, from, to) {
	if (path === from) {
		return to
	}
	return path.startsWith(from + '/') ? to + path.slice(from.length) : path
}

/** A drop of `source` into folder `target` ('' = root) that would do something. */
export function canDrop(source, target) {
	return !!source && source !== target && !target.startsWith(source + '/') && parentOf(source) !== target
}

async function toast(kind, text) {
	const dialogs = await import('@nextcloud/dialogs')
	dialogs[kind](text)
}

async function reportError(error) {
	const status = error?.response?.status
	if (status === 409) {
		await toast('showError', t('markdownsite', 'Already exists'))
	} else if (status === 400 && error.response.data?.error === 'invalid-name') {
		await toast('showError', t('markdownsite', 'This name is not allowed'))
	} else {
		await toast('showError', error?.response?.data?.message || t('markdownsite', 'The operation failed'))
	}
}

const askName = props => spawnDialog(NameDialog, props)

/** New page in `dir`: asks a name, creates `Name.md`. Returns its path, or null. */
export async function newPage(siteId, dir) {
	const name = await askName({
		title: t('markdownsite', 'New page'),
		label: t('markdownsite', 'Page name'),
		confirmLabel: t('markdownsite', 'Create'),
	})
	if (!name) {
		return null
	}
	try {
		return (await createPage(siteId, join(dir, name))).path
	} catch (e) {
		await reportError(e)
		return null
	}
}

/** New folder in `dir`. Returns its path, or null. */
export async function newFolder(siteId, dir) {
	const name = await askName({
		title: t('markdownsite', 'New folder'),
		label: t('markdownsite', 'Folder name'),
		confirmLabel: t('markdownsite', 'Create'),
	})
	if (!name) {
		return null
	}
	try {
		return (await createFolder(siteId, join(dir, name))).path
	} catch (e) {
		await reportError(e)
		return null
	}
}

/** Creates `X/X.md` for folder X. Returns its path, or null. */
export async function createFolderNote(siteId, folder) {
	const name = folder.split('/').pop()
	try {
		return (await createPage(siteId, `${folder}/${name}.md`)).path
	} catch (e) {
		await reportError(e)
		return null
	}
}

/**
 * Moves `from` to `to`. When links point at it, asks first ("N links in M
 * pages will be updated", checked by default). Returns the move result, or
 * null when cancelled or failed.
 */
export async function moveTo(siteId, from, to) {
	try {
		const { count, pages } = await getBacklinks(siteId, from, to)
		let updateLinks = false
		if (count > 0) {
			const answer = await spawnDialog(MoveDialog, { count, pages })
			if (!answer) {
				return null
			}
			updateLinks = answer.updateLinks
		}
		const result = await moveNode(siteId, from, to, updateLinks)
		if (result.failed.length) {
			await toast('showWarning', n('markdownsite', '%n page not updated: {pages}', '%n pages not updated: {pages}',
				result.failed.length, { pages: result.failed.join(', ') }))
		}
		return result
	} catch (e) {
		await reportError(e)
		return null
	}
}

/** Renames a page or folder in place (a page keeps its .md). */
export function rename(siteId, node, name) {
	const to = join(parentOf(node.path), node.type === 'page' ? `${name}.md` : name)
	return to === node.path ? Promise.resolve(null) : moveTo(siteId, node.path, to)
}

/** Asks, then moves a page or folder to the trash. Returns true when deleted. */
export async function remove(siteId, node) {
	const pages = countPages(node)
	const { showConfirmation } = await import('@nextcloud/dialogs')
	const ok = await showConfirmation({
		name: t('markdownsite', 'Delete'),
		text: node.type === 'dir'
			? n('markdownsite', 'Move "{name}" to the trash? (+ %n page)', 'Move "{name}" to the trash? (+ %n pages)', pages, { name: node.name })
			: t('markdownsite', 'Move "{name}" to the trash?', { name: node.name }),
	})
	if (!ok) {
		return false
	}
	try {
		await deleteNode(siteId, node.path)
		return true
	} catch (e) {
		await reportError(e)
		return false
	}
}

/** Pages inside a tree node (a folder note counts). */
export function countPages(node) {
	if (node.type === 'page') {
		return 1
	}
	return (node.note ? 1 : 0) + (node.children || []).reduce((sum, child) => sum + countPages(child), 0)
}
```

- [ ] **Step 5: Run the tests**

Run: `npm test && npm run lint`
Expected: `Test Files  13 passed (13)`, `Tests  65 passed (65)`, lint exit code 0.

- [ ] **Step 6: Commit**

```bash
git add src/components/NameDialog.vue src/components/MoveDialog.vue src/services/fileOps.js tests/js/fileOps.test.js
git commit -m "feat: create, rename, move and delete flows with link updates"
```

---

### Task 16: Editable tree

**Files:**
- Modify: `src/stores/tree.js`
- Replace: `src/components/PageTree.vue`

- [ ] **Step 1: Drag state**

In `src/stores/tree.js`, replace

```js
	state: () => ({ openMap: {} }),
```

with

```js
	// dragging: path of the page or folder being dragged (editors only)
	state: () => ({ openMap: {}, dragging: null }),
```

- [ ] **Step 2: Replace `src/components/PageTree.vue`**

```vue
<template>
	<ul class="mds-tree">
		<li v-if="editable && isRoot && tree.dragging"
			class="mds-tree-rootdrop"
			:class="{ 'is-over': overPath === '' }"
			@dragover="onDragOver($event, '')"
			@dragleave="overPath = null"
			@drop="onDrop($event, '')">
			{{ t('markdownsite', 'Drop here to move to the top level') }}
		</li>
		<NcAppNavigationItem
			v-for="node in nodes"
			:key="node.path"
			:name="node.name"
			:allow-collapse="node.type === 'dir'"
			:open="isOpen(node)"
			:active="isActive(node)"
			:data-mds-active="isActive(node) || null"
			:data-mds-path="node.path"
			:class="{ 'mds-tree-drop': overPath === node.path }"
			:to="routeFor(node)"
			:editable="editable"
			:edit-label="t('markdownsite', 'Rename')"
			:draggable="editable ? 'true' : null"
			@update:name="name => onRename(node, name)"
			@update:open="v => tree.setOpen(node.path, v)"
			@click="ev => onItemClick(node, ev)"
			@dragstart="onDragStart($event, node)"
			@dragend="tree.dragging = null; overPath = null"
			@dragover="node.type === 'dir' && onDragOver($event, node.path)"
			@dragleave="overPath = null"
			@drop="node.type === 'dir' && onDrop($event, node.path)">
			<template #icon>
				<NcIconSvgWrapper v-if="node.type === 'dir'" :path="mdiFolder" :size="20" />
				<NcIconSvgWrapper v-else :path="mdiFileDocumentOutline" :size="20" />
			</template>
			<template v-if="editable" #actions>
				<template v-if="node.type === 'dir'">
					<NcActionButton :close-after-click="true" @click="onNewPage(node.path)">
						<template #icon><NcIconSvgWrapper :path="mdiFileDocumentPlusOutline" :size="20" /></template>
						{{ t('markdownsite', 'New page here') }}
					</NcActionButton>
					<NcActionButton :close-after-click="true" @click="onNewFolder(node.path)">
						<template #icon><NcIconSvgWrapper :path="mdiFolderPlusOutline" :size="20" /></template>
						{{ t('markdownsite', 'New folder') }}
					</NcActionButton>
					<NcActionButton v-if="!node.note" :close-after-click="true" @click="onFolderNote(node)">
						<template #icon><NcIconSvgWrapper :path="mdiFileDocumentEditOutline" :size="20" /></template>
						{{ t('markdownsite', 'Create folder note') }}
					</NcActionButton>
				</template>
				<NcActionButton :close-after-click="true" @click="onDelete(node)">
					<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
					{{ t('markdownsite', 'Delete') }}
				</NcActionButton>
			</template>
			<template v-if="node.type === 'dir'" #default>
				<PageTree :nodes="node.children || []" :site-id="siteId" :active-path="activePath"
					:editable="editable" :is-root="false"
					@changed="e => $emit('changed', e)" />
			</template>
		</NcAppNavigationItem>
	</ul>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiDelete, mdiFileDocumentEditOutline, mdiFileDocumentOutline, mdiFileDocumentPlusOutline, mdiFolder, mdiFolderPlusOutline } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import { canDrop, createFolderNote, join, moveTo, newFolder, newPage, remove, rename } from '../services/fileOps.js'
import { useTreeStore } from '../stores/tree.js'

const DRAG_TYPE = 'application/x-markdownsite-path'

/**
 * The site's pages and folders. With `editable` (owners and editors), each
 * entry gets a menu (new page, new folder, folder note, rename, delete) and
 * can be dragged onto a folder. Every change is reported with `changed`:
 * { edit?: path to open in edit mode, moved?: {from, to}, deleted?: path }.
 */
export default {
	name: 'PageTree',
	components: { NcActionButton, NcAppNavigationItem, NcIconSvgWrapper },
	props: {
		nodes: { type: Array, default: () => [] },
		siteId: { type: [String, Number], required: true },
		// Path of the currently open page. When set, ancestor folders of
		// this path default to open. Pass '' to disable reveal entirely
		// (the "always show current open file" preference, off).
		activePath: { type: String, default: '' },
		editable: { type: Boolean, default: false },
		isRoot: { type: Boolean, default: true },
	},
	emits: ['changed'],
	setup() {
		return {
			tree: useTreeStore(),
			t,
			mdiDelete, mdiFileDocumentEditOutline, mdiFileDocumentOutline, mdiFileDocumentPlusOutline, mdiFolder, mdiFolderPlusOutline,
		}
	},
	data() { return { overPath: null } },
	computed: {
		activeAncestors() {
			if (!this.activePath) { return new Set() }
			const parts = this.activePath.split('/').slice(0, -1)
			const set = new Set()
			let acc = ''
			for (const part of parts) {
				acc = acc ? `${acc}/${part}` : part
				set.add(acc)
			}
			return set
		},
	},
	methods: {
		isOpen(node) {
			// An explicit toggle (user click, breadcrumb reveal) wins over the reveal default.
			if (node.path in this.tree.openMap) { return this.tree.openMap[node.path] }
			return this.activeAncestors.has(node.path)
		},
		// A folder whose note is open counts as the active entry.
		isActive(node) {
			return !!this.activePath && (node.path === this.activePath || node.note === this.activePath)
		},
		routeFor(node) {
			const path = node.type === 'page' ? node.path : node.note
			return path ? { name: 'page', params: { siteId: this.siteId, path } } : undefined
		},
		onItemClick(node, event) {
			if (node.type !== 'dir') { return }
			if (!node.note) {
				// The entry is an <a href="#">: following it would open the site's home page.
				event?.preventDefault()
			}
			// A folder with a note opens the note (via `to`) and expands;
			// a folder without one toggles, like the chevron.
			this.tree.setOpen(node.path, node.note ? true : !this.isOpen(node))
		},
		async onNewPage(dir) {
			const path = await newPage(this.siteId, dir)
			if (path) {
				this.tree.reveal(dir)
				this.$emit('changed', { edit: path })
			}
		},
		async onNewFolder(dir) {
			const path = await newFolder(this.siteId, dir)
			if (path) {
				this.tree.reveal(path)
				this.$emit('changed', {})
			}
		},
		async onFolderNote(node) {
			const path = await createFolderNote(this.siteId, node.path)
			if (path) {
				this.$emit('changed', { edit: path })
			}
		},
		async onRename(node, name) {
			const result = await rename(this.siteId, node, name.trim())
			if (result) {
				this.$emit('changed', { moved: { from: node.path, to: result.path } })
			}
		},
		async onDelete(node) {
			if (await remove(this.siteId, node)) {
				this.$emit('changed', { deleted: node.path })
			}
		},
		onDragStart(event, node) {
			if (!this.editable) { return }
			event.stopPropagation()
			event.dataTransfer.setData(DRAG_TYPE, node.path)
			event.dataTransfer.effectAllowed = 'move'
			this.tree.dragging = node.path
		},
		onDragOver(event, folder) {
			if (!canDrop(this.tree.dragging, folder)) { return }
			event.preventDefault()
			event.stopPropagation()
			event.dataTransfer.dropEffect = 'move'
			this.overPath = folder
		},
		async onDrop(event, folder) {
			const source = event.dataTransfer.getData(DRAG_TYPE) || this.tree.dragging
			this.overPath = null
			this.tree.dragging = null
			if (!canDrop(source, folder)) { return }
			event.preventDefault()
			event.stopPropagation()
			const name = source.split('/').pop()
			const result = await moveTo(this.siteId, source, join(folder, name))
			if (result) {
				if (folder) { this.tree.reveal(folder) }
				this.$emit('changed', { moved: { from: source, to: result.path } })
			}
		},
	},
}
</script>

<style scoped>
.mds-tree { list-style: none; margin: 0; padding: 0; }
.mds-tree-drop :deep(.app-navigation-entry) { outline: 2px dashed var(--color-primary-element); outline-offset: -2px; }
.mds-tree-rootdrop {
	margin: 4px 8px; padding: 8px; border: 2px dashed var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large, 8px); color: var(--color-text-maxcontrast); text-align: center; font-size: 0.9em;
}
.mds-tree-rootdrop.is-over { border-color: var(--color-primary-element); color: var(--color-main-text); }
</style>
```

- [ ] **Step 3: Lint and commit**

Run: `npm run lint`
Expected: exit code 0.

```bash
git add src/stores/tree.js src/components/PageTree.vue
git commit -m "feat: file management in the page tree"
```

---

### Task 17: Reader/Editor selector in sharing settings

**Files:**
- Replace: `src/components/SharingSettings.vue`

- [ ] **Step 1: Replace the component**

```vue
<template>
	<NcAppSettingsSection id="sharing" :name="t('markdownsite', 'Sharing')">
		<p v-if="!ownedSites.length" class="mds-empty">
			{{ t('markdownsite', "You don't own any wiki sites yet.") }}
		</p>
		<div v-for="site in ownedSites" :key="site.id" class="mds-share-site">
			<h4 class="mds-share-site-name">{{ site.icon || '📄' }} {{ site.name }}</h4>
			<table class="mds-share-table">
				<thead>
					<tr>
						<th>{{ t('markdownsite', 'Type') }}</th>
						<th>{{ t('markdownsite', 'Shared with') }}</th>
						<th>{{ t('markdownsite', 'Role') }}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="!(shareMap[site.id] || []).length">
						<td colspan="4" class="mds-empty">{{ t('markdownsite', 'No shares yet.') }}</td>
					</tr>
					<tr v-for="(s, i) in shareMap[site.id] || []" :key="s.type + ':' + s.with">
						<td>{{ s.type === 'group' ? t('markdownsite', 'Group') : t('markdownsite', 'User') }}</td>
						<td>{{ s.with }}</td>
						<td class="mds-share-role">
							<NcSelect :model-value="roleOption(s.role)"
								:options="roleOptions"
								:clearable="false"
								:searchable="false"
								label="label"
								:input-label="t('markdownsite', 'Role')"
								:label-outside="true"
								@update:model-value="opt => setRole(site, s, opt)" />
						</td>
						<td>
							<NcButton type="tertiary" @click="removeShare(site, i)">
								{{ t('markdownsite', 'Unshare') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<NcSelect
				:model-value="null"
				:options="optionsFor(site.id)"
				:loading="loadingFor(site.id)"
				label="label"
				:placeholder="t('markdownsite', 'Share with user or group…')"
				@search="q => onSearch(site.id, q)"
				@update:model-value="opt => addShare(site, opt)" />
		</div>
	</NcAppSettingsSection>
</template>

<script>
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcButton from '@nextcloud/vue/components/NcButton'
import { translate as t } from '@nextcloud/l10n'
import { useSitesStore } from '../stores/sites.js'
import { getShares, searchSharees, shareSite, updateShareRole } from '../services/api.js'

export default {
	name: 'SharingSettings',
	components: { NcAppSettingsSection, NcSelect, NcButton },
	setup() { return { store: useSitesStore(), t } },
	data() {
		return { shareMap: {}, searchResults: {}, searching: {} }
	},
	computed: {
		ownedSites() { return this.store.sites.filter(s => s.isOwner) },
		roleOptions() {
			return [
				{ id: 'reader', label: t('markdownsite', 'Reader') },
				{ id: 'editor', label: t('markdownsite', 'Editor') },
			]
		},
	},
	watch: {
		ownedSites: {
			immediate: true,
			handler(sites) {
				sites.forEach(s => { if (!(s.id in this.shareMap)) { this.loadShares(s.id) } })
			},
		},
	},
	methods: {
		async loadShares(siteId) {
			this.shareMap = { ...this.shareMap, [siteId]: await getShares(siteId) }
		},
		roleOption(role) {
			return this.roleOptions.find(o => o.id === role) || this.roleOptions[0]
		},
		async setRole(site, share, option) {
			if (!option || option.id === share.role) {
				return
			}
			try {
				await updateShareRole(site.id, share.id, option.id)
				this.shareMap = {
					...this.shareMap,
					[site.id]: this.shareMap[site.id].map(s => (s.id === share.id ? { ...s, role: option.id } : s)),
				}
			} catch (e) {
				const { showError } = await import('@nextcloud/dialogs')
				showError(t('markdownsite', 'Could not update sharing'))
			}
		},
		optionsFor(siteId) {
			return this.searchResults[siteId] || []
		},
		loadingFor(siteId) {
			return !!this.searching[siteId]
		},
		async onSearch(siteId, query) {
			if (!query || query.length < 2) {
				this.searchResults = { ...this.searchResults, [siteId]: [] }
				return
			}
			this.searching = { ...this.searching, [siteId]: true }
			try {
				const results = await searchSharees(query)
				const current = this.shareMap[siteId] || []
				const filtered = results.filter(r => !current.some(c => c.type === r.type && c.with === r.id))
				this.searchResults = {
					...this.searchResults,
					[siteId]: filtered.map(r => ({
						id: r.id,
						type: r.type,
						label: `${r.type === 'group' ? '👥' : '👤'} ${r.label}`,
					})),
				}
			} finally {
				this.searching = { ...this.searching, [siteId]: false }
			}
		},
		async addShare(site, opt) {
			if (!opt) { return }
			const current = this.shareMap[site.id] || []
			const next = [...current, { type: opt.type, with: opt.id, role: 'reader' }]
			await this.persist(site, next)
		},
		async removeShare(site, index) {
			const current = this.shareMap[site.id] || []
			const next = current.filter((_, i) => i !== index)
			await this.persist(site, next)
		},
		async persist(site, shares) {
			try {
				await shareSite(site.id, shares)
				// Reload: replacing the list gives the shares new ids.
				await this.loadShares(site.id)
				const { showSuccess } = await import('@nextcloud/dialogs')
				showSuccess(t('markdownsite', 'Sharing updated'))
			} catch (e) {
				const { showError } = await import('@nextcloud/dialogs')
				showError(t('markdownsite', 'Could not update sharing'))
			}
		},
	},
}
</script>

<style scoped>
.mds-empty { color: var(--color-text-maxcontrast); }
.mds-share-site {
	margin: 0 0 16px;
	padding: 14px 16px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}
.mds-share-site-name { margin: 0 0 10px; font-size: 15px; }
.mds-share-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.mds-share-table th {
	text-align: left; font-weight: 600; color: var(--color-text-maxcontrast);
	font-size: 0.85em; padding: 4px 8px; border-bottom: 1px solid var(--color-border);
}
.mds-share-table td {
	padding: 4px 8px; border-bottom: 1px solid var(--color-border);
}
.mds-share-table td.mds-empty { padding: 10px 8px; }
.mds-share-role { min-width: 140px; }
</style>
```

- [ ] **Step 2: Lint and commit**

Run: `npm run lint`
Expected: exit code 0.

```bash
git add src/components/SharingSettings.vue
git commit -m "feat: choose reader or editor for each share"
```

---

### Task 18: Edit mode in `WikiView`, shared content styles

**Files:**
- Create: `src/styles/content.css`
- Modify: `src/main.js`
- Replace: `src/views/WikiView.vue`

- [ ] **Step 1: Global content styles**

These are the `.mds-content` rules that lived in `WikiView.vue`'s scoped style (without `:deep()`), plus the syntax palette from its unscoped style. Create `src/styles/content.css`:

```css
/*
 * Page content styles, shared by the reading view (WikiView) and the
 * editor's rendered blocks (PageEditor), so both look the same.
 */
.mds-content {
	max-width: 760px;
	margin: 0 auto;
	padding: 16px 0 0;
	line-height: 1.6;
	color: var(--color-main-text);
}
.mds-content [id^='mds-'] { scroll-margin-top: 16px; }
.mds-content h1 { font-size: 1.9em; margin: 0.4em 0 0.5em; font-weight: 700; }
.mds-content h2 { font-size: 1.5em; margin: 1.4em 0 0.4em; font-weight: 700; border-bottom: 1px solid var(--color-border); padding-bottom: 0.2em; }
.mds-content h3 { font-size: 1.2em; margin: 1.2em 0 0.3em; font-weight: 600; }
.mds-content p { margin: 0.7em 0; }
.mds-content ul, .mds-content ol { margin: 0.6em 0; padding-left: 1.6em; }
.mds-content li { margin: 0.2em 0; }
.mds-content a {
	color: var(--mds-link-color, var(--color-primary-element));
	text-decoration: var(--mds-link-decoration, underline);
	font-weight: var(--mds-link-weight, 600);
}
.mds-content a.markdownsite-broken { color: var(--color-error); text-decoration: line-through; font-weight: 400; }
.mds-content img { max-width: 100%; border-radius: var(--border-radius-large, 8px); border: 1px solid var(--color-border); margin: 0.6em 0; }
.mds-content blockquote { border-left: 4px solid var(--color-primary-element); margin: 0.8em 0; padding: 0.2em 0 0.2em 1em; color: var(--color-text-maxcontrast); }
.mds-content code { background: var(--color-background-dark); border-radius: 4px; padding: 0.1em 0.4em; font-family: monospace; }
.mds-content pre { background: var(--color-background-dark); border-radius: var(--border-radius-large, 8px); padding: 12px 16px; overflow-x: auto; }
.mds-content pre code { background: none; padding: 0; }
.mds-content ul { list-style: disc; }
.mds-content ol { list-style: decimal; }
.mds-content ul ul { list-style: circle; }
.mds-content mark { background: rgba(255, 208, 0, 0.35); color: inherit; border-radius: 3px; padding: 0.05em 0.2em; }
.mds-content mark.mds-search-hit { background: rgba(255, 140, 0, 0.45); padding: 0; border-radius: 2px; }
.mds-content del { color: var(--color-text-maxcontrast); }
/* Tables */
/* display:block lets wide tables scroll; width:max-content keeps the frame hugging the columns. */
.mds-content table { border-collapse: separate; border-spacing: 0; margin: 0.9em 0; display: block; width: max-content; max-width: 100%; overflow-x: auto; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); }
.mds-content th, .mds-content td { padding: 7px 12px; text-align: left; border-bottom: 1px solid var(--color-border); border-right: 1px solid var(--color-border); }
.mds-content th:last-child, .mds-content td:last-child { border-right: none; }
.mds-content tbody tr:last-child td { border-bottom: none; }
.mds-content th { background: var(--color-background-dark); font-weight: 600; }
/* Task lists: "- [ ] item" */
.mds-content li:has(> input[type='checkbox']), .mds-content li:has(> p > input[type='checkbox']) {
	list-style: none; position: relative; margin-left: -1.3em; padding-left: 1.75em;
}
.mds-content li > input[type='checkbox'], .mds-content li > p > input[type='checkbox'] { position: absolute; left: 0; top: 0.28em; }
.mds-content li > p:has(> input[type='checkbox']) { margin-top: 0; }
.mds-content input[type='checkbox'] {
	appearance: none; -webkit-appearance: none;
	width: 1.05em; height: 1.05em; min-height: 0; margin: 0; padding: 0;
	border: 2px solid var(--color-text-maxcontrast); border-radius: 0.3em;
	background: transparent; cursor: pointer;
}
.mds-content input[type='checkbox']:hover { border-color: var(--color-primary-element); }
.mds-content input[type='checkbox']:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
.mds-content input[type='checkbox'][disabled] { cursor: default; }
.mds-content input[type='checkbox']:checked { background: var(--color-primary-element); border-color: var(--color-primary-element); }
.mds-content input[type='checkbox']:checked::after {
	content: ''; position: absolute; left: 0.28em; top: 0.07em; width: 0.28em; height: 0.52em;
	border: solid var(--color-primary-element-text, #fff); border-width: 0 2px 2px 0; transform: rotate(45deg);
}
.mds-content li:has(> input[type='checkbox']:checked) { color: var(--color-text-maxcontrast); }
/* Callouts: "> [!note] Title" */
.mds-content .mds-callout {
	--mds-c: 8, 109, 221;
	--mds-icon: '✏️';
	margin: 1em 0; border-radius: var(--border-radius-large, 8px);
	background: rgba(var(--mds-c), 0.1); border: 1px solid rgba(var(--mds-c), 0.25);
}
.mds-content .mds-callout-title {
	display: flex; align-items: center; gap: 0.5em; padding: 0.6em 1em; font-weight: 600;
	color: rgb(var(--mds-c)); list-style: none; cursor: default;
}
.mds-content .mds-callout-title::-webkit-details-marker { display: none; }
.mds-content .mds-callout-icon::before { content: var(--mds-icon); font-size: 1em; }
.mds-content .mds-callout-title-text { flex: 1; color: var(--color-main-text); }
.mds-content summary.mds-callout-title { cursor: pointer; }
.mds-content summary.mds-callout-title::after {
	content: ''; width: 0.5em; height: 0.5em; border: solid var(--color-text-maxcontrast); border-width: 0 2px 2px 0;
	transform: rotate(45deg); transition: transform 0.15s; margin-right: 0.3em;
}
.mds-content details.mds-callout[open] > summary.mds-callout-title::after { transform: rotate(-135deg); }
.mds-content .mds-callout-actions { position: relative; flex: none; margin-left: auto; }
.mds-content .mds-callout-copy {
	display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0;
	border: none; border-radius: var(--border-radius-element, 8px); background: transparent;
	color: var(--color-text-maxcontrast); cursor: pointer; opacity: 0.7;
}
.mds-content .mds-callout-copy:hover, .mds-content .mds-callout-copy:focus-visible, .mds-content .mds-callout-copy[aria-expanded='true'] { opacity: 1; background: rgba(var(--mds-c), 0.18); color: var(--color-main-text); }
.mds-content .mds-callout-copy.is-done { opacity: 1; color: var(--color-success-text, #2d7b41); }
.mds-content .mds-callout-menu {
	position: absolute; right: 0; top: calc(100% + 4px); z-index: 10; min-width: 240px; padding: 4px;
	background: var(--color-main-background); border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large, 8px); box-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
}
.mds-content .mds-callout-menu[hidden] { display: none; }
.mds-content .mds-callout-menu button {
	display: block; width: 100%; padding: 8px 12px; border: none; background: transparent; text-align: left;
	color: var(--color-main-text); font: inherit; font-weight: 400; border-radius: var(--border-radius-element, 8px); cursor: pointer;
}
.mds-content .mds-callout-menu button:hover, .mds-content .mds-callout-menu button:focus-visible { background: var(--color-background-hover); }
.mds-content .mds-callout-content { padding: 0.1em 1em 0.6em; }
.mds-content .mds-callout-content > :first-child { margin-top: 0.3em; }
.mds-content .mds-callout-content > :last-child { margin-bottom: 0.2em; }
.mds-content .mds-callout-content table { background: var(--color-main-background); }
.mds-content .mds-callout[data-callout='abstract'], .mds-content .mds-callout[data-callout='summary'], .mds-content .mds-callout[data-callout='tldr'] { --mds-c: 0, 176, 255; --mds-icon: '📄'; }
.mds-content .mds-callout[data-callout='info'] { --mds-c: 8, 109, 221; --mds-icon: 'ℹ️'; }
.mds-content .mds-callout[data-callout='todo'] { --mds-c: 8, 109, 221; --mds-icon: '☑️'; }
.mds-content .mds-callout[data-callout='tip'], .mds-content .mds-callout[data-callout='hint'], .mds-content .mds-callout[data-callout='important'] { --mds-c: 0, 191, 188; --mds-icon: '💡'; }
.mds-content .mds-callout[data-callout='success'], .mds-content .mds-callout[data-callout='check'], .mds-content .mds-callout[data-callout='done'] { --mds-c: 8, 176, 66; --mds-icon: '✅'; }
.mds-content .mds-callout[data-callout='question'], .mds-content .mds-callout[data-callout='help'], .mds-content .mds-callout[data-callout='faq'] { --mds-c: 236, 117, 0; --mds-icon: '❓'; }
.mds-content .mds-callout[data-callout='warning'], .mds-content .mds-callout[data-callout='caution'], .mds-content .mds-callout[data-callout='attention'] { --mds-c: 236, 117, 0; --mds-icon: '⚠️'; }
.mds-content .mds-callout[data-callout='failure'], .mds-content .mds-callout[data-callout='fail'], .mds-content .mds-callout[data-callout='missing'] { --mds-c: 233, 49, 71; --mds-icon: '❌'; }
.mds-content .mds-callout[data-callout='danger'], .mds-content .mds-callout[data-callout='error'] { --mds-c: 233, 49, 71; --mds-icon: '⚡'; }
.mds-content .mds-callout[data-callout='bug'] { --mds-c: 233, 49, 71; --mds-icon: '🐛'; }
.mds-content .mds-callout[data-callout='example'] { --mds-c: 120, 82, 238; --mds-icon: '📋'; }
.mds-content .mds-callout[data-callout='quote'], .mds-content .mds-callout[data-callout='cite'] { --mds-c: 158, 158, 158; --mds-icon: '💬'; }
/* Code blocks: language label, copy button, highlight.js token colours */
.mds-content .mds-code {
	margin: 0.8em 0; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-dark); overflow: hidden;
}
.mds-content .mds-code-header {
	display: flex; align-items: center; justify-content: space-between; min-height: 32px; padding: 0 4px 0 12px;
	border-bottom: 1px solid var(--color-border); font-size: 0.8em; color: var(--color-text-maxcontrast);
}
.mds-content .mds-code-lang { font-family: monospace; }
.mds-content .mds-code-copy {
	display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; min-height: 0; padding: 0; margin: 0;
	border: none; border-radius: var(--border-radius-element, 8px); background: transparent;
	color: var(--color-text-maxcontrast); cursor: pointer;
}
.mds-content .mds-code-copy:hover, .mds-content .mds-code-copy:focus-visible { background: var(--color-background-hover); color: var(--color-main-text); }
.mds-content .mds-code-copy.is-done { color: var(--color-success-text, #2d7b41); }
.mds-content .mds-code pre { margin: 0; border-radius: 0; background: transparent; }
.mds-content .hljs-comment, .mds-content .hljs-quote { color: var(--mds-hl-comment); font-style: italic; }
.mds-content .hljs-keyword, .mds-content .hljs-selector-tag, .mds-content .hljs-doctag, .mds-content .hljs-section { color: var(--mds-hl-keyword); }
.mds-content .hljs-string, .mds-content .hljs-regexp, .mds-content .hljs-symbol { color: var(--mds-hl-string); }
.mds-content .hljs-number, .mds-content .hljs-literal, .mds-content .hljs-variable, .mds-content .hljs-template-variable { color: var(--mds-hl-number); }
.mds-content .hljs-title, .mds-content .hljs-title.function_, .mds-content .hljs-name { color: var(--mds-hl-title); }
.mds-content .hljs-type, .mds-content .hljs-built_in, .mds-content .hljs-title.class_ { color: var(--mds-hl-type); }
.mds-content .hljs-attr, .mds-content .hljs-attribute, .mds-content .hljs-property, .mds-content .hljs-params { color: var(--mds-hl-attr); }
.mds-content .hljs-meta, .mds-content .hljs-bullet, .mds-content .hljs-link { color: var(--mds-hl-meta); }
.mds-content .hljs-addition { color: var(--mds-hl-meta); background: var(--mds-hl-addition-bg); }
.mds-content .hljs-deletion { color: var(--mds-hl-keyword); background: var(--mds-hl-deletion-bg); }
.mds-content .hljs-emphasis { font-style: italic; }
.mds-content .hljs-strong { font-weight: 700; }
/* Mermaid diagrams */
.mds-content .mds-mermaid { margin: 1em 0; overflow-x: auto; text-align: center; }
.mds-content .mds-mermaid svg { max-width: 100%; height: auto; }
.mds-content .mds-mermaid-error {
	margin: 1em 0; padding: 8px 12px; border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large, 8px);
}
.mds-content .mds-mermaid-error-title { margin: 0 0 6px; color: var(--color-error); font-weight: 600; }

/* Syntax colours. Not scoped: they switch on Nextcloud's theme, set on <body>
   (data-themes "dark…", "light…", or "default" = follow the system). */
.mds-content {
	--mds-hl-comment: #6e7781; --mds-hl-keyword: #cf222e; --mds-hl-string: #0a3069; --mds-hl-number: #0550ae;
	--mds-hl-title: #8250df; --mds-hl-type: #953800; --mds-hl-attr: #0550ae; --mds-hl-meta: #116329;
	--mds-hl-addition-bg: #dafbe1; --mds-hl-deletion-bg: #ffebe9;
}
body[data-themes*='dark'] .mds-content {
	--mds-hl-comment: #8b949e; --mds-hl-keyword: #ff7b72; --mds-hl-string: #a5d6ff; --mds-hl-number: #79c0ff;
	--mds-hl-title: #d2a8ff; --mds-hl-type: #ffa657; --mds-hl-attr: #79c0ff; --mds-hl-meta: #7ee787;
	--mds-hl-addition-bg: rgba(46, 160, 67, 0.15); --mds-hl-deletion-bg: rgba(248, 81, 73, 0.15);
}
@media (prefers-color-scheme: dark) {
	body:not([data-themes*='light']):not([data-themes*='dark']) .mds-content {
		--mds-hl-comment: #8b949e; --mds-hl-keyword: #ff7b72; --mds-hl-string: #a5d6ff; --mds-hl-number: #79c0ff;
		--mds-hl-title: #d2a8ff; --mds-hl-type: #ffa657; --mds-hl-attr: #79c0ff; --mds-hl-meta: #7ee787;
		--mds-hl-addition-bg: rgba(46, 160, 67, 0.15); --mds-hl-deletion-bg: rgba(248, 81, 73, 0.15);
	}
}
```

In `src/main.js`, after `import './publicPath.js'` add:

```js
import './styles/content.css'
```

- [ ] **Step 2: Replace `src/views/WikiView.vue`**

```vue
<template>
	<NcContent app-name="markdownsite">
		<NcAppNavigation>
			<template v-if="store.sites.length">
				<SiteSwitcher />
				<SearchPanel :site-id="activeSiteId" @update:active="v => searching = v" />
				<div v-if="writable && !searching" class="mds-tree-toolbar">
					<NcButton variant="tertiary" @click="newRootPage">
						<template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
						{{ t('markdownsite', 'New page') }}
					</NcButton>
				</div>
				<PageTree v-if="activeSiteId && !searching" :nodes="tree" :site-id="activeSiteId"
					:active-path="prefs.revealActive ? currentPath : ''"
					:editable="writable"
					@changed="onTreeChanged" />
			</template>
			<template #footer>
				<div class="mds-navfooter">
					<NcButton variant="primary" wide @click="showNew = true">
						<template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
						{{ t('markdownsite', 'New site') }}
					</NcButton>
					<NcButton variant="tertiary" :aria-label="t('markdownsite', 'Settings')" @click="showSettings = true">
						<template #icon><NcIconSvgWrapper :path="mdiCog" :size="20" /></template>
					</NcButton>
				</div>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<NcEmptyContent v-if="!store.sites.length"
				:name="t('markdownsite', 'No wiki yet')"
				:description="t('markdownsite', 'Create your first wiki from a folder of Markdown files.')">
				<template #icon><NcIconSvgWrapper :path="mdiBookOpenVariant" :size="64" /></template>
				<template #action><NcButton variant="primary" @click="showNew = true">{{ t('markdownsite', 'New site') }}</NcButton></template>
			</NcEmptyContent>

			<div v-else-if="loading" class="mds-center"><NcLoadingIcon :size="32" /></div>

			<NcEmptyContent v-else-if="error"
				:name="t('markdownsite', 'Page not found')">
				<template #icon><NcIconSvgWrapper :path="mdiFileRemoveOutline" :size="64" /></template>
			</NcEmptyContent>

			<NcEmptyContent v-else-if="!currentPath"
				:name="t('markdownsite', 'Choose a page')">
				<template #icon><NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="64" /></template>
			</NcEmptyContent>

			<div v-else ref="page" class="mds-page" :class="{ 'mds-page--toc-collapsed': prefs.tocCollapsed }">
				<div class="mds-main">
					<div class="mds-page-header">
						<PageBreadcrumb :site-id="activeSiteId"
							:site-name="store.current ? store.current.name : ''"
							:path="currentPath"
							:tree="tree"
							@reveal="revealFolder" />
						<NcButton v-if="writable && !editing"
							variant="tertiary"
							:aria-label="t('markdownsite', 'Edit (E)')"
							:title="t('markdownsite', 'Edit (E)')"
							@click="startEditing">
							<template #icon><NcIconSvgWrapper :path="mdiPencil" :size="20" /></template>
						</NcButton>
						<NcButton v-if="editing" variant="secondary" @click="stopEditing">
							{{ t('markdownsite', 'Done') }}
						</NcButton>
					</div>
					<PageEditor v-if="editing"
						ref="editor"
						:key="currentPath"
						:site-id="activeSiteId"
						:path="currentPath"
						:pages="pages"
						:css-vars="prefs.cssVars"
						@exit="stopEditing"
						@follow="followLink"
						@forbidden="onEditingForbidden" />
					<template v-else>
						<PageToc class="mds-toc-narrow" variant="inline" :toc="toc" @navigate="goToHeading" />
						<article ref="article" class="mds-content" :style="prefs.cssVars" v-html="html" @click="onClick" />
					</template>
					<PagePager :site-id="activeSiteId" :path="currentPath" :tree="tree" />
				</div>
				<PageToc v-if="!editing"
					class="mds-toc-wide"
					variant="side"
					:toc="toc"
					:collapsed="prefs.tocCollapsed"
					@update:collapsed="setTocCollapsed"
					@navigate="goToHeading" />
			</div>
		</NcAppContent>

		<NewSiteDialog v-model:open="showNew" />
		<SettingsDialog v-model:open="showSettings" />
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiCog, mdiBookOpenVariant, mdiFileDocumentOutline, mdiFileRemoveOutline, mdiPencil } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getTree, getPage } from '../services/api.js'
import { decorateCallouts } from '../services/calloutCopy.js'
import { copy } from '../services/clipboard.js'
import { movedPath, newPage, parentOf } from '../services/fileOps.js'
import { decorateCode } from '../services/codeHighlight.js'
import { renderDiagrams } from '../services/mermaid.js'
import { highlightTerms } from '../services/searchHighlight.js'
import { parseQuery } from '../services/searchText.js'
import { headingElement, safeDecode, splitHash } from '../services/anchors.js'
import { enableTaskLists } from '../services/taskList.js'
import { useSitesStore } from '../stores/sites.js'
import { usePrefsStore } from '../stores/prefs.js'
import { useTreeStore } from '../stores/tree.js'
import SiteSwitcher from '../components/SiteSwitcher.vue'
import PageTree from '../components/PageTree.vue'
import PageToc from '../components/PageToc.vue'
import PageBreadcrumb from '../components/PageBreadcrumb.vue'
import PagePager from '../components/PagePager.vue'
import PageEditor from '../components/PageEditor.vue'
import SearchPanel from '../components/SearchPanel.vue'
import NewSiteDialog from '../components/NewSiteDialog.vue'
import SettingsDialog from '../components/SettingsDialog.vue'

export default {
	name: 'WikiView',
	components: {
		NcContent, NcAppNavigation, NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon, NcIconSvgWrapper,
		SiteSwitcher, SearchPanel, PageTree, PageToc, PageBreadcrumb, PagePager, PageEditor, NewSiteDialog, SettingsDialog,
	},
	setup() {
		return {
			store: useSitesStore(),
			prefs: usePrefsStore(),
			treeState: useTreeStore(),
			t,
			mdiPlus, mdiCog, mdiBookOpenVariant, mdiFileDocumentOutline, mdiFileRemoveOutline, mdiPencil,
		}
	},
	data() { return { tree: [], html: '', toc: [], searching: false, editing: false, editAfterLoad: null, pages: [], error: false, loading: false, showNew: false, showSettings: false, homePath: null } },
	computed: {
		routeSiteId() { return this.$route.params.siteId || null },
		activeSiteId() { return this.routeSiteId || (this.store.current && this.store.current.id) || null },
		currentPath() {
			const p = this.$route.params.path
			return Array.isArray(p) ? p.join('/') : (p || '')
		},
		/** Owner or editor, on a folder the owner can write to. */
		writable() { return !!(this.store.current && this.store.current.writable) },
	},
	watch: {
		async $route(to, from) {
			// Only the #heading changed (TOC click, back/forward): scroll, don't reload.
			if (from && to.path === from.path && to.hash !== from.hash) {
				this.scrollToHash()
				return
			}
			if (this.editing) {
				// Leaving the page while editing: save first.
				await this.$refs.editor?.flush()
				this.editing = false
			}
			this.sync()
		},
		activeSiteId(next, prev) {
			// The id is a string in the route and a number in the store: compare as text.
			if (String(next) !== String(prev)) {
				this.treeState.clear()
			}
		},
	},
	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown)
	},
	async mounted() {
		// "E" switches to edit mode (not while typing somewhere).
		this.onKeydown = (ev) => {
			if (ev.key !== 'e' || ev.ctrlKey || ev.metaKey || ev.altKey || this.editing || !this.writable || !this.currentPath) {
				return
			}
			if (ev.target.closest?.('input, textarea, select, [contenteditable="true"], .cm-editor, [role="dialog"]')) {
				return
			}
			ev.preventDefault()
			this.startEditing()
		}
		window.addEventListener('keydown', this.onKeydown)
		this.prefs.load()
		if (!this.store.loaded) { await this.store.load() }
		await this.bootstrap()
	},
	methods: {
		async bootstrap() {
			// No site in route: open first site if any.
			if (!this.routeSiteId && this.store.sites.length) {
				this.$router.replace({ name: 'site', params: { siteId: this.store.sites[0].id } })
				return
			}
			await this.sync()
		},
		async sync() {
			if (!this.activeSiteId) { return }
			this.store.setCurrent(this.activeSiteId)
			await this.loadTree()
			if (!this.currentPath) {
				if (this.homePath) {
					this.$router.replace({ name: 'page', params: { siteId: this.activeSiteId, path: this.homePath } })
				}
				return
			}
			await this.loadPage()
			this.scrollActiveIntoView()
		},
		scrollActiveIntoView() {
			if (!this.prefs.revealActive) { return }
			this.$nextTick(() => {
				const el = document.querySelector('.mds-tree [data-mds-active]')
				if (el && el.scrollIntoView) {
					el.scrollIntoView({ block: 'center', behavior: 'smooth' })
				}
			})
		},
		async loadTree() {
			try {
				const data = await getTree(this.activeSiteId)
				this.tree = data.tree
				this.homePath = data.home
				this.pages = data.pages || []
			} catch (e) {
				this.tree = []; this.homePath = null; this.pages = []
			}
		},
		async loadPage() {
			this.loading = true; this.error = false
			try {
				const data = await getPage(this.activeSiteId, this.currentPath)
				this.html = data.html
				this.toc = data.toc || []
			} catch (e) {
				this.error = true; this.html = ''; this.toc = []
			} finally {
				this.loading = false
			}
			// The article is re-created with fresh HTML: add the client-side behaviours.
			await this.$nextTick()
			decorateCallouts(this.$refs.article)
			enableTaskLists(this.$refs.article)
			// Both load their libraries only when the page needs them; not awaited.
			decorateCode(this.$refs.article)
			renderDiagrams(this.$refs.article)
			if (this.editAfterLoad === this.currentPath) {
				// A page just created from the tree opens in edit mode.
				this.editAfterLoad = null
				this.editing = true
				return
			}
			// Opened from a search result: mark the terms and show the first one.
			const q = this.$route.query.q
			const hit = q ? highlightTerms(this.$refs.article, parseQuery(String(q))) : null
			if (hit && !this.$route.hash) {
				this.$nextTick(() => hit.scrollIntoView({ block: 'center' }))
			} else {
				this.scrollToHash()
			}
		},
		/** Scrolls to the heading named by the URL hash, or to the top of the page. */
		scrollToHash() {
			this.$nextTick(() => {
				const id = this.$route.hash ? this.$route.hash.slice(1) : ''
				const target = (id && headingElement(this.$refs.article, id)) || (!id && this.$refs.page)
				if (target && target.scrollIntoView) {
					target.scrollIntoView({ block: 'start' })
				}
			})
		},
		async goToHeading(id) {
			await this.$router.push({ name: this.$route.name, params: this.$route.params, query: this.$route.query, hash: '#' + id })
			// Same hash again: the route does not change, so scroll explicitly.
			this.scrollToHash()
		},
		revealFolder(path) {
			this.treeState.reveal(path)
			this.$nextTick(() => {
				const el = [...document.querySelectorAll('.mds-tree [data-mds-path]')]
					.find(item => item.getAttribute('data-mds-path') === path)
				if (el && el.scrollIntoView) {
					el.scrollIntoView({ block: 'center', behavior: 'smooth' })
				}
			})
		},
		startEditing() {
			this.editing = true
		},
		/** Saves, leaves edit mode and shows the rendered page again. */
		async stopEditing() {
			await this.$refs.editor?.flush()
			this.editing = false
			await this.loadPage()
		},
		/** Ctrl/Cmd+click on a link in the editor: save, then follow it. */
		async followLink(link) {
			await this.$refs.editor?.flush()
			if (link.href && /^[a-z][a-z0-9+.-]*:/i.test(link.href)) {
				window.open(link.href, '_blank', 'noopener')
				return
			}
			const path = link.wikilink ? this.findPage(link.wikilink) : this.relativePage(link.href)
			if (path) {
				this.editing = false
				this.$router.push({ name: 'page', params: { siteId: this.activeSiteId, path } })
			}
		},
		/** Page a wikilink target names: full path, alias, then title. */
		findPage(target) {
			const name = safeDecode(target.split('#')[0]).trim().replace(/\.md$/i, '').toLowerCase()
			const noExt = p => p.path.replace(/\.md$/i, '').toLowerCase()
			const page = this.pages.find(p => noExt(p) === name)
				|| this.pages.find(p => (p.aliases || []).some(a => a.toLowerCase() === name))
				|| this.pages.find(p => p.title.toLowerCase() === name)
			return page ? page.path : null
		},
		/** Root-relative path of a relative Markdown link from the current page. */
		relativePage(href) {
			const parts = parentOf(this.currentPath).split('/').filter(Boolean)
			for (const segment of safeDecode(splitHash(href)[0]).split('/')) {
				if (segment === '..') {
					parts.pop()
				} else if (segment && segment !== '.') {
					parts.push(segment)
				}
			}
			return parts.join('/') || null
		},
		async onEditingForbidden(text) {
			this.editing = false
			this.store.load()
			const { getDialogBuilder, showSuccess } = await import('@nextcloud/dialogs')
			await getDialogBuilder(t('markdownsite', 'Editing rights removed'))
				.setText(t('markdownsite', 'You can no longer edit this page. Copy your unsaved text to keep it.'))
				.addButton({
					label: t('markdownsite', 'Copy my text'),
					variant: 'primary',
					callback: async () => {
						if (await copy(text)) {
							showSuccess(t('markdownsite', 'Copied'))
						}
					},
				})
				.build()
				.show()
		},
		async newRootPage() {
			const path = await newPage(this.activeSiteId, '')
			if (path) {
				await this.onTreeChanged({ edit: path })
			}
		},
		/** After a tree operation: reload the tree and follow the open page. */
		async onTreeChanged(change) {
			await this.loadTree()
			if (change.edit) {
				this.editAfterLoad = change.edit
				this.$router.push({ name: 'page', params: { siteId: this.activeSiteId, path: change.edit } })
			} else if (change.moved) {
				const next = movedPath(this.currentPath, change.moved.from, change.moved.to)
				if (next !== this.currentPath) {
					this.$router.replace({ name: 'page', params: { siteId: this.activeSiteId, path: next } })
				}
			} else if (change.deleted) {
				const gone = this.currentPath === change.deleted || this.currentPath.startsWith(change.deleted + '/')
				if (gone) {
					this.$router.push({ name: 'site', params: { siteId: this.activeSiteId } })
				}
			}
		},
		setTocCollapsed(collapsed) {
			this.prefs.tocCollapsed = collapsed
			this.prefs.save()
		},
		onClick(ev) {
			const a = ev.target.closest('a')
			if (!a) { return }
			const href = a.getAttribute('href') || ''
			if (href.startsWith('#') && href.length > 1) {
				// Heading on this page ([[#Heading]] or [text](#id)).
				ev.preventDefault()
				this.goToHeading(safeDecode(href.slice(1)))
				return
			}
			const prefix = generateUrl(`/apps/markdownsite/s/${this.activeSiteId}/page/`)
			if (href.startsWith(prefix)) {
				ev.preventDefault()
				const [rel, fragment] = splitHash(href.slice(prefix.length))
				this.$router.push({
					name: 'page',
					params: { siteId: this.activeSiteId, path: safeDecode(rel) },
					hash: fragment ? '#' + safeDecode(fragment) : '',
				})
			}
		},
	},
}
</script>

<style scoped>
.mds-navfooter { display: flex; align-items: center; gap: 4px; padding: 8px; }
.mds-navfooter :deep(.button-vue--vue-primary) { flex: 1 1 auto; }
.mds-center { display: flex; justify-content: center; padding: 48px; }
.mds-tree-toolbar { display: flex; padding: 0 8px; }
.mds-page-header { display: flex; align-items: center; gap: 8px; min-height: 44px; }
.mds-page-header .mds-crumbs { flex: 1 1 auto; min-width: 0; }
/* Page layout: main column (breadcrumb, article, pager) + sticky outline on wide
   screens. The left padding keeps the breadcrumb clear of the navigation toggle. */
.mds-page { display: flex; justify-content: center; align-items: flex-start; gap: 24px; padding: 0 24px 0 52px; }
.mds-main { flex: 0 1 760px; min-width: 0; padding: 24px 0 96px; }
.mds-toc-wide { flex: 0 0 240px; position: sticky; top: 0; max-height: 100vh; overflow-y: auto; }
.mds-page--toc-collapsed .mds-toc-wide { flex-basis: 44px; }
@media (max-width: 1023px) {
	.mds-toc-wide { display: none; }
}
@media (min-width: 1024px) {
	.mds-toc-narrow { display: none; }
}
</style>
```

- [ ] **Step 3: Lint, test, build**

Run: `npm run lint && npm test && npm run build 2>&1 | grep compiled && ls js | grep -c '^markdownsite-editor\.js$'`
Expected: lint exit code 0, `Tests  65 passed (65)`, `compiled with 2 warnings`, `1`.

- [ ] **Step 4: Commit**

```bash
git add src/styles/content.css src/main.js src/views/WikiView.vue
git commit -m "feat: edit pages in place"
```

---

### Task 19: Release 2.0.0

**Files:**
- Modify: `appinfo/info.xml`, `README.md`, `CHANGELOG.md`, `js/*`

- [ ] **Step 1: `appinfo/info.xml`**

Replace `<version>1.5.0</version>` with `<version>2.0.0</version>`.

Replace the `<description>` element with:

```xml
	<description lang="en"><![CDATA[
Turn any folder of Markdown files into a wiki site inside Nextcloud.
Supports Obsidian-style wikilinks, embeds, callouts and YAML frontmatter. Links resolve by
name, so a synced Obsidian vault or any Markdown folder just works. Owners and editors can
edit pages in place and manage pages and folders; links are updated when pages move.
	]]></description>
```

- [ ] **Step 2: `README.md`**

Replace the first paragraph `Turn any folder of Markdown files into a browsable, read-only wiki site inside Nextcloud.` with `Turn any folder of Markdown files into a browsable wiki site inside Nextcloud, editable in place.`

Replace the line starting with `- **Share without a Files share**` with:

```markdown
- **Share without a Files share** — grant a user or group access to a site without giving them Nextcloud Files access to the underlying folder, as a reader or an editor. Only the owner manages shares.
- **Edit in place** — press `E` or the pencil: live preview like Obsidian, autosave, `[[` completion, drag-and-drop images. Create, rename, move (links are updated) and delete pages and folders from the tree.
```

- [ ] **Step 3: `CHANGELOG.md`**

Insert above `## [1.5.0] - 2026-10-02`:

```markdown
## [2.0.0] - 2026-10-02

### Added
- Editing: owners and editors edit pages in place with an Obsidian-style live preview. Changes save automatically; a page changed elsewhere is detected and never overwritten silently.
- File management from the page tree: new page, new folder, folder note, rename, drag-and-drop to move, delete (to the trash bin). Links to a renamed or moved page are updated after confirmation.
- Images dropped or pasted into a page are stored in the vault's attachment folder (Obsidian setting) and embedded.
- Shares have a role: Reader or Editor.

### Changed
- Pages load faster on large sites: the link index is cached.

```

- [ ] **Step 4: Build and check everything**

Run: `vendor/bin/phpunit && composer run lint && npm run lint && npm test && npm run build 2>&1 | grep compiled`
Expected: `OK (182 tests, …)`, both linters exit 0, `Tests  65 passed (65)`, `compiled with 2 warnings`.

- [ ] **Step 5: Commit**

```bash
git add appinfo/info.xml README.md CHANGELOG.md js/
git commit -m "release: 2.0.0"
```

---

### Task 20: Manual verification (owner)

À faire par toi, dans cet ordre. Chaque étape prend moins de deux minutes.

1. Demande à Claude, sur ton ordinateur : « lance `scripts/smoke.sh 31` ». Connecte-toi en `admin` (mot de passe dans `.smoke/credentials.txt`), crée un site à partir du dossier `smoke-site`, puis dans les réglages (roue dentée) partage-le avec `reader` et choisis « Editor » dans la colonne « Role ».
2. Ouvre la page « README » et appuie sur la touche E : la page devient modifiable. Ajoute une phrase à la fin, attends deux secondes (« Saved » s'affiche en haut à droite), puis appuie sur Échap : la page s'affiche avec ta phrase.
3. Dans l'arbre à gauche, clique sur « New page », appelle-la « Essai » : elle s'ouvre en modification. Tape `[[Gui` et choisis « Guide » dans la liste, puis clique sur « Done ».
4. Survole « Guide » dans l'arbre, clique sur les trois points puis « Rename », tape « Manuel » et Entrée : une fenêtre annonce des liens mis à jour, clique « Confirm ». Ouvre « Essai » : le lien s'appelle maintenant « Manuel ». Fais glisser « Essai » sur le dossier « Projects » : elle s'y range. Supprime-la avec les trois points puis « Delete ».
5. Déconnecte-toi, reconnecte-toi en `reader` (mot de passe dans le même fichier) : tu peux modifier une page. Demande ensuite à Claude d'installer la version 2.0.0 sur ton serveur de test (désactiver puis réactiver l'app, sans `occ upgrade`), vérifie qu'une page s'ouvre et se modifie, puis demande « lance `scripts/smoke.sh stop` ».
