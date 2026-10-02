# Execution log

## Plan A: Nextcloud 31-34 compatibility (1.2.0)

- Tasks 1-6 done as written, one commit each; every Expected line matched.
- Environment: Docker Hub answered 429 for nextcloud:31 and nextcloud:34; used the mirror.gcr.io mirror and re-tagged, as the plan allows.
- Task 2: the first `composer update nextcloud/ocp` timed out on the network; the retry (with COMPOSER_ALLOW_SUPERUSER=1, container runs as root) produced the expected result. composer.lock `plugin-api-version` changed from 2.9.0 to 2.6.0 (older Composer in this environment), harmless.
- Commit trailers: `Co-Authored-By: Claude <noreply@anthropic.com>` and `Claude-Session` were appended to every commit message after the plan's given message.
- scripts/smoke.sh rebuilds the frontend, which rewrites js/markdownsite-main.js.map (non-deterministic map); plan A changes no src/, so that change was discarded (`git checkout js/`) and not committed.
- Smoke 31 and 34: app enables (1.2.0), site created from the fixture via API, tree and pages returned, logo.svg served as 200 image/svg+xml, CSP contains `img-src 'self' data: blob: https:`, share to `reader` works (31), headless Chromium shows the callout and the logo (31 and 34).

## Plan B: Navigation (1.3.0)

- Tasks 1-12 done as written, one commit each; every Expected line matched (69 PHP tests, 17 Vitest tests, build with 3 warnings).
- Task 1/2 wording only: Vitest 3 reports a missing module as `Failed to load url ...` instead of `Cannot find module`; PHPUnit reports a missing property as a failed null assertion instead of "Undefined property". Same failing state, same counts.
- Task 12: the build did not change `js/markdownsite-main.js.LICENSE.txt`, so only `js/markdownsite-main.js` and its map are in the release commit.
- Intermediate commits do not include `js/` (a build rewrites the source map each time); only the release commit does.
- Smoke 31 (curl): tree marks `Projects/Projects.md` as folder note; page API returns `toc` ids and `mds-` heading ids; `tocCollapsed` preference round-trips.
- Smoke 31 (headless Chromium, 1600 px wide): side outline visible and sticky, TOC click scrolls to `#third-section` and highlights it, collapse persists after reload, breadcrumb "Smoke > Guides > Long page", Previous/Next cards navigate, clicking folder "Projects" opens its note, at 800 px the side outline is replaced by the collapsible inline "On this page" block, no console errors.
