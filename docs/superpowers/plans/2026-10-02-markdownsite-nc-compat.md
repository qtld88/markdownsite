# Nextcloud 31–34 Compatibility (A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make MarkdownSite 1.2.0 installable and correct on Nextcloud 31–34, develop against the Nextcloud 31 API, and add a Docker smoke script that later sub-projects reuse.

**Architecture:** Metadata change (`info.xml`), dev-dependency pin to `nextcloud/ocp dev-stable31`, two small code modernisations (`getFirstNodeById()`, CSP via a registered `IEventListener`), working lint tooling, and `scripts/smoke.sh` plus a fixture site.

**Tech Stack:** PHP 8.1+, Nextcloud OCP 31, PHPUnit 10, Bash + Docker (`nextcloud:<version>` images), ESLint 8 + eslint-plugin-vue 9.

**Spec:** `docs/superpowers/specs/2026-10-02-markdownsite-nc-compat-design.md`

---

## Assumptions and spec corrections

1. **PHP max version = 8.5.** Read from Nextcloud 34's `lib/versioncheck.php` (blocks `PHP_VERSION_ID >= 80600`) and confirmed by `nextcloud/ocp v34.0.0` `composer.json` (`~8.1 || … || ~8.5`). `info.xml` gets `<php min-version="8.1" max-version="8.5"/>`.
2. **Lint tooling is broken today, so it is fixed first.** `composer run lint` runs `php -l lib/`, and `php -l` on a directory checks nothing (exit 0 even with a syntax error inside). `npm run lint` fails because the repo has no ESLint config. Both are required verification commands, so Task 1 replaces the composer script with a per-file `php -l` loop and adds a minimal `.eslintrc.cjs`. The existing `src/` passes it unchanged.
3. **Mocking `IRootFolder` needs a stub.** In OCP 31, `IRootFolder` extends the server-internal `OC\Hooks\Emitter`, which `nextcloud/ocp` does not ship, so PHPUnit cannot mock it. Task 2 adds `tests/stubs/OcStubs.php` (a two-method interface) loaded from `tests/bootstrap.php`. Later plans rely on this.
4. **Smoke script stages a copy instead of mounting the working tree.** Running `composer install --no-dev` in the working tree would delete PHPUnit and `nextcloud/ocp` from `vendor/`. Worse, the app's `composer.json` maps `OCP\` to `vendor/nextcloud/ocp/OCP/` and Composer's loader is prepended, so a dev `vendor/` inside a live server would shadow the server's own OCP classes. The script therefore builds into `.smoke/markdownsite` (git-ignored), runs `composer install --no-dev` there, and copies it into the container with `docker cp`. A bind mount into `custom_apps/` was tried and fails: Docker creates `custom_apps/` owned by root and the Nextcloud installer aborts with `Cannot write into "apps" directory`.
5. **Credentials.** The spec asks the script to print "the admin credentials location". Passwords are generated per run with `openssl rand` and written to `.smoke/credentials.txt` (git-ignored); no password appears in the repository. The script also creates a second user `reader` so the manual sharing check can run without extra setup.
6. **Fixture image is SVG.** A plan cannot embed a binary PNG, so the fixture image is `logo.svg` (served by `AssetController` as `image/svg+xml`, renders in `<img>`).
7. **No frontend change in A**, so `js/` is not rebuilt. The version is still bumped to 1.2.0 (spec).

Verified while writing this plan: on a `nextcloud:31` container, the staged app enables, a site created from the fixture folder returns its tree and pages, the SVG is served with `200 image/svg+xml`, a share to `reader` works, and the page's CSP contains `img-src 'self' data: blob: https:`.

## File map

| File | Change |
|---|---|
| `composer.json`, `composer.lock` | `nextcloud/ocp` → `dev-stable31`; working `lint` script |
| `.eslintrc.cjs` | New: minimal ESLint config |
| `tests/stubs/OcStubs.php`, `tests/bootstrap.php` | New stub for `OC\Hooks\Emitter` |
| `lib/Service/ContentService.php` | `resolveRoot()` uses `getFirstNodeById()` |
| `lib/Controller/SiteController.php` | `create()` uses `getFirstNodeById()` |
| `tests/unit/Service/ContentServiceTest.php` | New |
| `lib/Listener/CspListener.php` | New |
| `tests/unit/Listener/CspListenerTest.php` | New |
| `lib/AppInfo/Application.php` | Registers `CspListener`; `boot()` empty |
| `appinfo/info.xml` | 1.2.0, NC 31–34, PHP 8.1–8.5 |
| `README.md`, `CHANGELOG.md` | Requirements, smoke script, 1.2.0 entry |
| `scripts/smoke.sh` | New |
| `tests/fixtures/smoke-site/**` | New fixture (3 pages, wikilink, image, callout) |
| `.gitignore` | `/.smoke/` |
| `.claude/skills/nextcloud-app-store-publish/SKILL.md` | Exclude smoke files from the release tarball |

---

### Task 1: Make the linters actually lint

**Files:**
- Modify: `composer.json` (`scripts.lint`)
- Create: `.eslintrc.cjs`

- [ ] **Step 1: Show that the PHP lint is a no-op**

```bash
printf '<?php syntax error(' > /tmp/mds-bad.php && php -l /tmp/ ; echo "exit=$?"; rm /tmp/mds-bad.php
```

Expected: `No syntax errors detected in /tmp/` and `exit=0` (a directory is not linted).

- [ ] **Step 2: Replace the composer lint script**

In `composer.json`, replace the line

```json
        "lint": "php -l lib/",
```

with

```json
        "lint": "find lib tests -name '*.php' -print0 | xargs -0 -n1 php -l",
```

- [ ] **Step 3: Run it**

Run: `composer run lint`
Expected: one `No syntax errors detected in …` line per PHP file under `lib/` and `tests/`, exit code 0.

- [ ] **Step 4: Show that ESLint has no config**

Run: `npm run lint`
Expected: failure with `ESLint couldn't find a configuration file.`

- [ ] **Step 5: Add `.eslintrc.cjs`**

```js
module.exports = {
	root: true,
	env: { browser: true, es2022: true, node: true },
	parserOptions: { ecmaVersion: 2022, sourceType: 'module' },
	extends: ['eslint:recommended', 'plugin:vue/vue3-essential'],
	globals: { __webpack_public_path__: 'writable' },
	rules: {},
}
```

- [ ] **Step 6: Run it**

Run: `npm run lint`
Expected: exit code 0, no problems reported.

- [ ] **Step 7: Commit**

```bash
git add composer.json .eslintrc.cjs
git commit -m "build: make composer and npm lint scripts check every file"
```

---

### Task 2: Develop against the Nextcloud 31 API

**Files:**
- Modify: `composer.json`, `composer.lock`, `tests/bootstrap.php`
- Create: `tests/stubs/OcStubs.php`

- [ ] **Step 1: Pin `nextcloud/ocp` to the 31 branch**

In `composer.json` `require-dev`, replace

```json
        "nextcloud/ocp": "^34.0",
```

with

```json
        "nextcloud/ocp": "dev-stable31",
```

- [ ] **Step 2: Update the lock file**

Run: `composer update nextcloud/ocp`
Expected output contains `Upgrading nextcloud/ocp (v34.0.0 => dev-stable31 …)` (it may also remove `psr/http-client` and `psr/http-message`, which only OCP 34 needed). Then:

Run: `grep -A2 '"name": "nextcloud/ocp"' composer.lock`
Expected: `"version": "dev-stable31",`

- [ ] **Step 3: Add the server-internal stub**

Create `tests/stubs/OcStubs.php`:

```php
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
```

Replace `tests/bootstrap.php` with:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/OcStubs.php';
```

- [ ] **Step 4: Run the suite against OCP 31**

Run: `vendor/bin/phpunit`
Expected: `OK (39 tests, 58 assertions)`

Run: `composer run lint`
Expected: exit code 0.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock tests/bootstrap.php tests/stubs/OcStubs.php
git commit -m "build: develop and test against the Nextcloud 31 API"
```

---

### Task 3: Use `getFirstNodeById()`

`Folder::getFirstNodeById()` exists since Nextcloud 29 (`@since 29.0.0` in OCP 31's `OCP/Files/Folder.php`).

**Files:**
- Create: `tests/unit/Service/ContentServiceTest.php`
- Modify: `lib/Service/ContentService.php` (`resolveRoot()`), `lib/Controller/SiteController.php` (`create()`)

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Service/ContentServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Service\ContentService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

class ContentServiceTest extends TestCase {
	private function site(): Site {
		$site = new Site();
		$site->setOwnerUid('alice');
		$site->setRootFileId(42);
		return $site;
	}

	private function service(?\OCP\Files\Node $found): ContentService {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->with(42)->willReturn($found);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);
		return new ContentService($rootFolder);
	}

	public function testResolveRootReturnsFolder(): void {
		$folder = $this->createMock(Folder::class);
		$this->assertSame($folder, $this->service($folder)->resolveRoot($this->site()));
	}

	public function testResolveRootRejectsFile(): void {
		$file = $this->createMock(File::class);
		$this->assertNull($this->service($file)->resolveRoot($this->site()));
	}

	public function testResolveRootReturnsNullWhenGone(): void {
		$this->assertNull($this->service(null)->resolveRoot($this->site()));
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter ContentServiceTest`
Expected: FAIL — `resolveRoot()` still calls `getById()`, which the mock returns `[]` for, so `testResolveRootReturnsFolder` fails with `Failed asserting that null is identical to an object of class …`.

- [ ] **Step 3: Change `ContentService::resolveRoot()`**

In `lib/Service/ContentService.php`, replace the body of `resolveRoot()`:

```php
	/** Resolve the site root in its owner's mount, or null if the folder is gone. */
	public function resolveRoot(Site $site): ?Folder {
		$ownerFolder = $this->rootFolder->getUserFolder($site->getOwnerUid());
		$node = $ownerFolder->getFirstNodeById($site->getRootFileId());
		return $node instanceof Folder ? $node : null;
	}
```

- [ ] **Step 4: Change `SiteController::create()`**

In `lib/Controller/SiteController.php`, replace

```php
		$nodes = $userFolder->getById($rootFileId);
		if (count($nodes) === 0 || !($nodes[0] instanceof \OCP\Files\Folder)) {
```

with

```php
		if (!($userFolder->getFirstNodeById($rootFileId) instanceof \OCP\Files\Folder)) {
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (42 tests, 61 assertions)`

Run: `grep -rn "getById(" lib/`
Expected: no output.

- [ ] **Step 6: Commit**

```bash
git add lib/Service/ContentService.php lib/Controller/SiteController.php tests/unit/Service/ContentServiceTest.php
git commit -m "refactor: resolve site roots with getFirstNodeById()"
```

---

### Task 4: Register the CSP through an event listener

**Files:**
- Create: `tests/unit/Listener/CspListenerTest.php`, `lib/Listener/CspListener.php`
- Modify: `lib/AppInfo/Application.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Listener/CspListenerTest.php`:

```php
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
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter CspListenerTest`
Expected: 2 errors, `Class "OCA\MarkdownSite\Listener\CspListener" not found`.

- [ ] **Step 3: Write the listener**

Create `lib/Listener/CspListener.php`:

```php
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
```

- [ ] **Step 4: Register it**

Replace `lib/AppInfo/Application.php` with (the `vendor/autoload.php` require must stay):

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\AppInfo;

use OCA\MarkdownSite\Listener\CspListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'markdownsite';

	public function __construct() {
		parent::__construct(self::APP_ID);

		// Nextcloud does not auto-load an app's Composer autoloader, so the
		// bundled third-party libraries (league/commonmark, symfony/yaml) are
		// otherwise invisible at runtime. Register it explicitly.
		$autoload = __DIR__ . '/../../vendor/autoload.php';
		if (is_file($autoload)) {
			require_once $autoload;
		}
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (44 tests, 63 assertions)`

Run: `composer run lint`
Expected: exit code 0.

- [ ] **Step 6: Commit**

```bash
git add lib/Listener/CspListener.php lib/AppInfo/Application.php tests/unit/Listener/CspListenerTest.php
git commit -m "refactor: register the image CSP through an event listener"
```

---

### Task 5: Declare Nextcloud 31–34

**Files:**
- Modify: `appinfo/info.xml`, `README.md`, `CHANGELOG.md`

- [ ] **Step 1: Update `appinfo/info.xml`**

Replace `<version>1.1.1</version>` with `<version>1.2.0</version>`.

Replace the two dependency lines

```xml
		<nextcloud min-version="28" max-version="31"/>
		<php min-version="8.1" max-version="8.4"/>
```

with

```xml
		<nextcloud min-version="31" max-version="34"/>
		<php min-version="8.1" max-version="8.5"/>
```

- [ ] **Step 2: Validate the XML**

Run: `php -r '$x = simplexml_load_file("appinfo/info.xml"); echo $x->version, " ", $x->dependencies->nextcloud["min-version"], "-", $x->dependencies->nextcloud["max-version"], " php ", $x->dependencies->php["max-version"], PHP_EOL;'`
Expected: `1.2.0 31-34 php 8.5`

- [ ] **Step 3: Update `README.md`**

Replace the `## Requirements` list

```markdown
- Nextcloud 28–31
- PHP 8.1–8.4
```

with

```markdown
- Nextcloud 31–34
- PHP 8.1–8.5
```

Replace the `## Development` section with:

````markdown
## Development

```bash
npm ci
npm run build      # or: npm run watch
npm run lint

composer install
vendor/bin/phpunit
composer run lint
```

The PHP code is developed against the oldest supported Nextcloud API
(`nextcloud/ocp` `dev-stable31`), so a newer API cannot slip in unnoticed.

### Smoke test in Docker

```bash
scripts/smoke.sh 31    # or 34, or any nextcloud image tag
scripts/smoke.sh stop
```

The script builds the app, starts a throwaway Nextcloud with the app enabled,
uploads `tests/fixtures/smoke-site/` to the admin's files, creates a second
user `reader`, and prints the URL. Passwords are in `.smoke/credentials.txt`.
````

- [ ] **Step 4: Update `CHANGELOG.md`**

Insert above `## [1.1.1] - 2026-10-02`:

```markdown
## [1.2.0] - 2026-10-02

### Changed
- Supports Nextcloud 31 to 34 and PHP 8.1 to 8.5. Nextcloud 28–30 are no longer supported (the interface needs Nextcloud 31).

```

- [ ] **Step 5: Commit**

```bash
git add appinfo/info.xml README.md CHANGELOG.md
git commit -m "feat: support Nextcloud 31 to 34"
```

---

### Task 6: Smoke script and fixture site

**Files:**
- Create: `scripts/smoke.sh`, `tests/fixtures/smoke-site/README.md`, `tests/fixtures/smoke-site/Guides/Guide.md`, `tests/fixtures/smoke-site/Guides/Notes.md`, `tests/fixtures/smoke-site/Guides/logo.svg`
- Modify: `.gitignore`, `.claude/skills/nextcloud-app-store-publish/SKILL.md`

- [ ] **Step 1: Create the fixture pages**

`tests/fixtures/smoke-site/README.md`:

```markdown
# Smoke site

Home page of the smoke-test site. Read the [[Guide]] next.

> [!note] Callout
> This box should render with a blue border and an icon.
```

`tests/fixtures/smoke-site/Guides/Guide.md`:

```markdown
# Guide

The logo below is an image stored next to this page:

![[logo.svg]]

Back to the [home page](../README.md). See also [[Notes]].
```

`tests/fixtures/smoke-site/Guides/Notes.md`:

```markdown
# Notes

A third page, reachable from the tree and from the Guide.
```

`tests/fixtures/smoke-site/Guides/logo.svg`:

```xml
<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" viewBox="0 0 120 60">
	<rect width="120" height="60" rx="8" fill="#0082c9"/>
	<text x="60" y="38" font-family="sans-serif" font-size="20" fill="#fff" text-anchor="middle">smoke</text>
</svg>
```

- [ ] **Step 2: Create `scripts/smoke.sh`**

```bash
#!/usr/bin/env bash
# Start a throwaway Nextcloud in Docker with this app enabled.
#
#   scripts/smoke.sh 31      start Nextcloud 31 (any nextcloud image tag works)
#   scripts/smoke.sh stop    remove the container
#
# The app is staged in .smoke/markdownsite (production build, no dev
# dependencies) and copied into the container's custom_apps/, so the working
# copy's vendor/ keeps its dev dependencies. Passwords are generated per run
# and written to .smoke/credentials.txt (git-ignored).
set -euo pipefail

NAME=markdownsite-smoke
PORT="${SMOKE_PORT:-8080}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$ROOT/.smoke"

if [ "${1:-}" = "" ]; then
	echo "usage: $0 <nextcloud-version>|stop" >&2
	exit 2
fi

if [ "$1" = "stop" ]; then
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	echo "Stopped $NAME."
	exit 0
fi
VERSION="$1"

echo "==> Building frontend"
(cd "$ROOT" && npm run build)

echo "==> Staging app in $STAGE/markdownsite"
rm -rf "$STAGE"
mkdir -p "$STAGE/markdownsite"
(cd "$ROOT" && tar \
	--exclude='./.git' --exclude='./.smoke' --exclude='./node_modules' \
	--exclude='./vendor' --exclude='./docs' --exclude='./.claude' \
	-cf - .) | tar -xf - -C "$STAGE/markdownsite"
(cd "$STAGE/markdownsite" && composer install --no-dev --no-interaction --quiet)

ADMIN_PASS="$(openssl rand -hex 12)"
USER_PASS="$(openssl rand -hex 12)"
printf 'admin  %s\nreader %s\n' "$ADMIN_PASS" "$USER_PASS" > "$STAGE/credentials.txt"

echo "==> Starting nextcloud:$VERSION"
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" -p "$PORT:80" \
	-e SQLITE_DATABASE=nextcloud \
	-e NEXTCLOUD_ADMIN_USER=admin \
	-e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASS" \
	-e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
	"nextcloud:$VERSION" >/dev/null

occ() { docker exec -u www-data "$NAME" php occ "$@"; }

echo "==> Waiting for the installation to finish"
for _ in $(seq 1 120); do
	if occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
		break
	fi
	sleep 2
done
occ status --output=json | grep -q '"installed":true' || { echo "Nextcloud did not finish installing" >&2; exit 1; }

echo "==> Installing and enabling markdownsite"
docker cp "$STAGE/markdownsite" "$NAME:/var/www/html/custom_apps/markdownsite"
docker exec "$NAME" chown -R www-data:www-data /var/www/html/custom_apps/markdownsite
occ app:enable markdownsite

echo "==> Creating a second user and uploading the fixture site"
docker exec -u www-data -e OC_PASS="$USER_PASS" "$NAME" php occ user:add --password-from-env reader >/dev/null
docker cp "$ROOT/tests/fixtures/smoke-site" "$NAME:/var/www/html/data/admin/files/smoke-site"
docker exec "$NAME" chown -R www-data:www-data /var/www/html/data/admin/files/smoke-site
occ files:scan --path=/admin/files/smoke-site >/dev/null

echo
echo "Ready: http://localhost:$PORT/index.php/apps/markdownsite/"
echo "Users and passwords: $STAGE/credentials.txt"
echo "Stop with: $0 stop"
```

Run: `chmod +x scripts/smoke.sh && bash -n scripts/smoke.sh && echo ok`
Expected: `ok`

Run: `scripts/smoke.sh; echo "exit=$?"`
Expected: `usage: scripts/smoke.sh <nextcloud-version>|stop` and `exit=2`.

- [ ] **Step 3: Ignore the staging folder**

Append to `.gitignore`:

```
/.smoke/
```

- [ ] **Step 4: Keep smoke files out of the release tarball**

In `.claude/skills/nextcloud-app-store-publish/SKILL.md`, in the "Build recipe" `tar` command, replace

```
  --exclude='.env*' --exclude='*.key' --exclude='screenshots' \
```

with

```
  --exclude='.env*' --exclude='*.key' --exclude='screenshots' \
  --exclude='./scripts' --exclude='./tests/fixtures' --exclude='./.smoke' \
```

- [ ] **Step 5: Final checks**

Run: `vendor/bin/phpunit && composer run lint && npm run lint && git status --short`
Expected: `OK (44 tests, 63 assertions)`, both linters exit 0, and `git status` lists only the files of this task.

- [ ] **Step 6: Commit**

```bash
git add scripts/smoke.sh tests/fixtures/smoke-site .gitignore .claude/skills/nextcloud-app-store-publish/SKILL.md
git commit -m "test: add Docker smoke script and fixture site"
```

---

### Task 7: Manual verification (owner)

À faire par toi, dans cet ordre. Chaque étape prend moins de deux minutes.

1. Demande à Claude, sur ton ordinateur : « lance `scripts/smoke.sh 31` dans le dossier markdownsite ». Il te donne une adresse. Ouvre-la et connecte-toi avec l'utilisateur `admin` et le mot de passe écrit dans le fichier `.smoke/credentials.txt`.
2. Dans MarkdownSite, clique sur « New site », donne un nom, choisis le dossier `smoke-site`, puis « Create ». Vérifie que la page d'accueil s'affiche avec un encadré bleu, clique sur le lien « Guide » et vérifie que le logo bleu « smoke » apparaît.
3. Dans les réglages (roue dentée), partage le site avec l'utilisateur `reader`. Déconnecte-toi, reconnecte-toi en `reader` (mot de passe dans le même fichier) et vérifie que le site s'ouvre.
4. Demande à Claude : « lance `scripts/smoke.sh 34` », refais les étapes 2 et 3, puis demande-lui « lance `scripts/smoke.sh stop` ».
5. Demande à Claude d'installer la version 1.2.0 sur ton serveur de test comme d'habitude, puis ouvre un de tes vrais wikis et vérifie qu'une page, un lien et une image s'affichent.
