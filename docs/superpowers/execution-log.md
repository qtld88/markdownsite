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

## Plan C: Code highlighting and Mermaid (1.4.0)

- Tasks 1-10 done as written, one commit each; every Expected line matched (69 PHP tests, 32 Vitest tests, build with 2 warnings, 34 `markdownsite-hljs-*.js` chunks).
- Task 5 only: the "fails first" `npm test` showed a Vite import-resolution error (output trimmed); the failing state is the same as the plan describes.
- Release commit: `js/` now holds 198 files (about 27 MB, sources maps included); no absolute local path appears in any of them. Intermediate commits do not include `js/`.
- Rebuilding rewrites `js/markdownsite-main.js.map` only (non-deterministic), so it is discarded outside release commits.
- Smoke 31 (headless Chromium): "New site -> Choose folder..." opens the file picker (chunk path fix); the README page loads no highlight chunk; the Code page loads `hljs-core`, `hljs-yaml`, `hljs-bash`, `hljs-python`; labels bash/python/yaml/dataview; copy puts the raw text on the clipboard; the Diagrams page renders one SVG diagram and one "Invalid diagram" box with the source; dark theme (`data-themes="dark"`) keeps code and diagram readable; no console errors, no 4xx on app assets.

## Plan D: Search (1.5.0)

- Tasks 1-14 done as written, one commit each; every Expected line matched (107 PHP tests, 42 Vitest tests, build with 2 warnings).
- Test-first order: for tasks whose class was written together with its test (D-1), the implementer moved the class aside to observe the plan's expected "class not found" failure; same result as the plan.
- Only the release commit includes `js/` (6 files changed: main bundle, mermaid bundle and one mermaid chunk, with maps); a rebuild at that point is byte-identical to what is committed.
- Smoke 31 (curl): `GET /search` returns accent-insensitive hits with highlights for `cafe creme`, the phrase `"second section"` finds two pages, `python` finds the Code page, nonsense returns an empty list, "all sites" scope works; table `oc_markdownsite_search` and column `search_etag` exist and `oc_migrations` lists `1500Date20261002000000`; `occ app:disable` then `occ app:enable` works.
- Smoke 31 (headless Chromium): the search field replaces the tree while searching, snippets show marks, opening a result adds `?q=` and highlights 4 matches, navigating elsewhere drops `q` and the marks, `Ctrl+Shift+F` focuses the field, "All my sites" groups by site, Escape brings the tree back, "No results" appears for a nonsense query, no console errors.
- Not checked: upgrading a real 1.4.0 install in place (the migration ran on a fresh install of 1.5.0).

## Plan E: Editing and file management (2.0.0)

- Tasks 1-19 done as written, one commit each; every Expected line matched (182 PHP tests, 65 Vitest tests, build with 2 warnings, 202 files in `js/`, about 30 MB, no absolute local path in them).
- Task 3: the implementer edited `SiteController.php` and `routes.php` with scripted replacements; the reviewer proved the result byte-identical to the plan's file. No other deviation.
- Only the release commit includes `js/` (14 modified, 4 new: `markdownsite-editor.js` and the CodeMirror vendor chunk, with maps); a rebuild at that point is identical to what is committed except the non-deterministic main source map.
- Smoke 31 (curl): `/sites` carries `role` and `writable`; a reader gets 403 on `/source` and `PUT /page`; promoting the share to editor works (`PUT /sites/{id}/shares/{shareId}`); save returns a new etag, a stale etag returns 409 with the server text; creating a page twice returns 409; `/render` renders wikilinks and embeds; renaming `Guides/Guide.md` to `Guides/Manuel.md` rewrote `[[Guide]]` to `[[Manuel]]` in the README; moving a folder into itself returns 400 `into-itself`; renaming `Projects` to `Work` renamed its folder note; uploading the same file twice yields `pic.svg` then `pic 1.svg`; delete and invalid-name refusal work.
- Smoke 31 and 34 (headless Chromium, as `reader` with the Editor role): `E` opens CodeMirror, autosave shows "Saved", Escape returns to the rendered page with the new text; "New page" opens a new page in edit mode; typing `[[Man` offers "Manuel" and completes; "Done" shows the rendered link; "Modified elsewhere" banner appears after a change through WebDAV and "Keep mine" saves; inline Rename of "Manuel" to "Guide" shows the "Update links?" dialog and Confirm rewrites the links; drag and drop of a page onto a folder moves it and the route follows (HTML5 drag was simulated with dispatched DragEvents, because Playwright's real-mouse drag hangs in headless Chromium); Delete asks for confirmation and returns to the home page; with the Reader role there is no Edit button, no "New page" button and no tree menu, and `E` does nothing; the settings dialog shows the Reader/Editor selector per share. No console errors.
- Smoke 34 (NC 34.0.x) also re-ran the API checks, the outline/breadcrumb/pager, code and diagram, and search checks of plans B to D: all passed.
- Not checked automatically: pasting or dropping an image into the editor (upload endpoint tested with curl only), losing the edit right while editing (403 dialog), "Recreate with my text" after a deletion elsewhere, real-mouse drag and drop, upgrade of an existing 1.x install in place (the migrations ran on fresh installs only), all owner steps on the owner's own servers.
