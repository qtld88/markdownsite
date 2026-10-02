# Execution log

## Plan A: Nextcloud 31-34 compatibility (1.2.0)

- Tasks 1-6 done as written, one commit each; every Expected line matched.
- Environment: Docker Hub answered 429 for nextcloud:31 and nextcloud:34; used the mirror.gcr.io mirror and re-tagged, as the plan allows.
- Task 2: the first `composer update nextcloud/ocp` timed out on the network; the retry (with COMPOSER_ALLOW_SUPERUSER=1, container runs as root) produced the expected result. composer.lock `plugin-api-version` changed from 2.9.0 to 2.6.0 (older Composer in this environment), harmless.
- Commit trailers: `Co-Authored-By: Claude <noreply@anthropic.com>` and `Claude-Session` were appended to every commit message after the plan's given message.
- scripts/smoke.sh rebuilds the frontend, which rewrites js/markdownsite-main.js.map (non-deterministic map); plan A changes no src/, so that change was discarded (`git checkout js/`) and not committed.
- Smoke 31 and 34: app enables (1.2.0), site created from the fixture via API, tree and pages returned, logo.svg served as 200 image/svg+xml, CSP contains `img-src 'self' data: blob: https:`, share to `reader` works (31), headless Chromium shows the callout and the logo (31 and 34).
