# MarkdownSite — Nextcloud 31–34 Compatibility Design

Status: proposed
Date: 2026-10-02
App version target: 1.2.0

## Motivation

Sub-project **A** of the parity roadmap (see
`2026-10-02-markdownsite-editing-design.md`, "Roadmap context").

- `appinfo/info.xml` declares Nextcloud 28–31. The app cannot be installed on
  32, 33 or 34.
- The declared minimum is wrong: `@nextcloud/vue` 9 (used by the frontend)
  requires Nextcloud 31+. On 28–30 the UI breaks despite the declaration.
- `composer.json` develops against `nextcloud/ocp ^34`, so an API newer than 31
  would go unnoticed.

Both production targets run Nextcloud 31: a hosted production instance (no SSH, the admin installs from the App Store) and a
self-hosted test server (31.0.14, PHP 8.3).

## Decisions

| Topic | Decision |
|---|---|
| Supported range | Nextcloud **31–34** |
| API rule for all later sub-projects (B–E) | No Nextcloud API newer than 31 |
| PHP range | min 8.1; max = highest version Nextcloud 34 supports, read from the NC 34 server source at implementation time |

## Changes

### Metadata

- `appinfo/info.xml`: `<nextcloud min-version="31" max-version="34"/>`, PHP
  range per the decision above, `<version>1.2.0</version>`.
- `README.md` requirements: "Nextcloud 31–34" and the PHP range.
- `CHANGELOG.md`: 1.2.0 entry.

### API guard

`composer.json` `require-dev`: `nextcloud/ocp` changes from `^34.0` to
`dev-stable31`. Development, IDE completion and tests run against the oldest
supported API. Run `composer update nextcloud/ocp` and commit `composer.lock`.

### Code

- `lib/Controller/SiteController.php:61` and
  `lib/Service/ContentService.php:22`: replace the `getById()` + loop with
  `getFirstNodeById()`, keeping the `instanceof Folder` check.
- CSP: `Application::boot()` currently builds an
  `AddContentSecurityPolicyEvent` through `injectFn`. Replace it with
  `lib/Listener/CspListener.php` implementing
  `IEventListener<AddContentSecurityPolicyEvent>`, registered in `register()`
  with `$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class)`.
  The policy content stays the same (`https:`, `data:`, `blob:` image sources).
  `boot()` becomes empty.

### Verification script

`scripts/smoke.sh <version>` (reused by sub-projects B–E):

1. Run `npm run build` and `composer install --no-dev`.
2. Start a `nextcloud:<version>` Docker container with SQLite and an admin
   user, the app directory mounted into `custom_apps/markdownsite`.
3. Run `occ app:enable markdownsite` and fail on a non-zero exit code.
4. Upload a small fixture folder (`tests/fixtures/smoke-site/`: three pages,
   one wikilink, one image, one callout) to the admin's files.
5. Print the URL and the admin credentials location for the manual pass.

`scripts/smoke.sh stop` removes the container.

Manual pass on 31 and 34: create a site from the fixture folder, open a page,
follow the wikilink, see the image, share the site to a second user and open it
as that user.

The script and the fixture are excluded from the release tarball.

### Real-instance check

Deploy to the self-hosted test server (31.0.14) with the existing procedure and repeat the
manual pass.

## Testing

- `vendor/bin/phpunit` passes against `nextcloud/ocp dev-stable31`.
- `php -l` on all of `lib/`.
- Smoke script succeeds on `31` and `34`.

## Out of scope

- Continuous integration matrix (GitHub Actions). Revisit if manual smoke runs
  become a burden.
- Any feature work (B–E).
