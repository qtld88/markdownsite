# MarkdownSite — Code Highlighting & Mermaid Design

Status: proposed
Date: 2026-10-02
App version target: 1.4.0

## Motivation

Sub-project **C** of the parity roadmap (see
`2026-10-02-markdownsite-editing-design.md`, "Roadmap context"). API rule from
sub-project A applies: no Nextcloud API newer than 31.

Code blocks render as plain monospace text and Mermaid diagrams render as their
source. Usage measured on the owner's vaults: ~900 fenced code blocks (mostly
`bash`, `python`, `markdown`, `yaml`, `json`) and 7 files with Mermaid
diagrams in the owner's main vault; none of either in the production wiki.

Blocker: the frontend is forced into a single bundle (`js/markdownsite-main.js`,
2 MB minified) because dynamically loaded chunks returned 404. Mermaid alone
adds ~2.5 MB. Bundling it would slow every visit, with or without diagrams.

Root cause of the 404: `@nextcloud/webpack-vue-config` sets the public path to
`/apps/<app>/js/`. The app is installed under `custom_apps/`, so chunk URLs are
wrong. Nextcloud's own apps set `__webpack_public_path__` at runtime from
`generateFilePath()`.

## Decisions

| Topic | Decision |
|---|---|
| Delivery | Fix chunk loading; load highlight.js and Mermaid only on pages that need them |
| Highlighting | Client-side **highlight.js** |
| Code block extras | Language label and copy button on each block |
| Mermaid security | `securityLevel: 'strict'` |
| Obsidian plugin blocks (`dataview`, `tasks`, …) | Plain text, not executed |
| Server | No change |

## 1. Chunk loading fix

- New `src/publicPath.js`, imported first in `src/main.js`:

  ```js
  import { generateFilePath } from '@nextcloud/router'
  // eslint-disable-next-line no-undef, camelcase
  __webpack_public_path__ = generateFilePath('markdownsite', '', 'js/')
  ```

- `webpack.config.js`: remove the single-chunk constraint
  (`splitChunks: false`, `runtimeChunk: false`, `LimitChunkCountPlugin`).
  Set `output.chunkFilename` to `markdownsite-[name].js?v=[contenthash]` so a
  new release never serves a stale chunk (the `?v=` cache-buster Nextcloud adds
  only covers the entry script).
- The `@nextcloud/dialogs` folder picker becomes a lazy chunk again. It is the
  chunk that failed before, so "New site → Choose folder" is the regression
  test.
- Every dynamic import is wrapped: a `ChunkLoadError` leaves the content as it
  was (plain code blocks) and logs a console warning. The app never breaks on a
  failed chunk.
- Release tarball: include every `js/markdownsite-*.js` file, not only
  `markdownsite-main.js` (update the `nextcloud-app-store-publish` checklist).

## 2. Code highlighting

`src/services/codeHighlight.js`, `decorateCode(articleEl)`:

1. Find `pre > code[class*="language-"]`, excluding `language-mermaid`.
2. None found → return without loading anything.
3. Dynamic import of `highlight.js/lib/core` plus the languages present on the
   page, from `highlight.js/lib/languages/<lang>` (one small chunk each).
4. Unknown language or alias → block left unhighlighted, label still shown.
5. Wrap each `pre` in `.mds-code` with a header holding the language label and
   a copy button. The copy button copies the raw code text and shows the same
   "done" state as the callout copy button (`src/services/calloutCopy.js`).

Styling: `hljs-*` token classes mapped to CSS variables, one light and one dark
palette, switched with Nextcloud's theme (`body[data-themes*="dark"]` and the
`prefers-color-scheme` fallback used by Nextcloud's "system" theme).

`WikiView.vue` calls `decorateCode()` after each render, next to
`decorateCallouts()`.

## 3. Mermaid

`src/services/mermaid.js`, `renderDiagrams(articleEl)`:

1. Find `pre > code.language-mermaid`. None → return.
2. Dynamic import of `mermaid`, initialised once with
   `{ startOnLoad: false, securityLevel: 'strict', theme }`, where `theme` is
   `dark` or `default` from the Nextcloud theme.
3. Replace each block with the rendered SVG inside `.mds-mermaid`.
4. Syntax error → `.mds-mermaid-error` box "Invalid diagram" followed by the
   original source in a code block.
5. Theme change while a page is open is not tracked; the next page render uses
   the new theme.

CSP: no change expected (Nextcloud's Text app renders Mermaid under the default
policy). Verify during the manual pass; if a violation appears, extend
`CspListener` from sub-project A.

## 4. Reuse by sub-project E

The editor's block widgets run `decorateCode()` and `renderDiagrams()` on the
HTML returned by `/render`. With chunk loading fixed, E may also load
CodeMirror as a lazy chunk instead of bundling it.

## 5. Testing

### JavaScript (Vitest, set up in B)

- `codeHighlight`: no code block → no import; known language; unknown
  language; mermaid blocks skipped; copy button copies raw text.
- `mermaid`: no diagram → no import; syntax error → error box with source.
  Mermaid itself is mocked.

### Manual

On the self-hosted test server (31.0.14, `custom_apps/`) and with `scripts/smoke.sh 31` and
`34`:

- New site → Choose folder opens the picker (chunk regression test).
- A page with `bash`, `python`, `yaml` blocks: highlighted, label, copy works.
- A page with a Mermaid diagram and one with an invalid diagram.
- Dark theme for both.
- Network tab: highlight.js and Mermaid chunks absent on a page without code.

Add a code page and a Mermaid page to `tests/fixtures/smoke-site/`.

### Hosted production instance

Checked after the App Store release. If chunks fail there, fall back to
shipping `mermaid.min.js` and highlight.js as static files in `js/`, loaded by
an injected `<script>` tag built from `generateFilePath()`.

## Out of scope

- Server-side highlighting.
- Executing Obsidian plugin blocks (`dataview`, `tasks`).
- Line numbers, line highlighting.
