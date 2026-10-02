# MarkdownSite — Navigation Design (TOC, Breadcrumb, Previous/Next)

Status: proposed
Date: 2026-10-02
App version target: 1.3.0

## Motivation

Sub-project **B** of the parity roadmap (see
`2026-10-02-markdownsite-editing-design.md`, "Roadmap context"). API rule from
sub-project A applies: no Nextcloud API newer than 31.

Today a reader has the left page tree and nothing else: no outline of the
current page, no position within the site, no way to read the site in order.

Two gaps in the renderer block any outline:

1. Headings get no `id`, so nothing can point at a section.
2. `LinkResolver::stripFragment()` drops the `#Heading` part of
   `[[Page#Heading]]` and `[text](Page.md#heading)`. The link opens the page,
   not the section.

## Decisions

| Topic | Decision |
|---|---|
| TOC placement | Sticky right column, current section highlighted, **collapsible** (state saved in user preferences). Narrow screens: collapsible "On this page" block at the top of the article |
| TOC threshold | Shown only when the page has ≥ 3 headings |
| Previous/next order | Tree order, depth-first, across folders |
| Folder click (breadcrumb and tree) | Open the folder note if one exists; otherwise expand the folder in the tree |
| Folder note names | `X/X.md`, then `X/index.md`, then `X/README.md` (case-insensitive) |

## 1. Heading anchors (server)

- `MarkdownRenderer` adds CommonMark's `HeadingPermalinkExtension` with
  `apply_id_to_heading: true`, empty `id_prefix`, empty `fragment_prefix`, and
  `insert: 'none'` (no visible permalink symbol). Duplicate slugs get `-1`,
  `-2` through CommonMark's unique slug normalizer.
- `LinkResolver` keeps the fragment instead of stripping it. The fragment is
  slugified with the **same** `SlugNormalizer` instance the renderer uses, so
  `[[Page#My heading]]` → `…/page/Page.md#my-heading`.
- `[[#Heading]]` (empty target) resolves to the current page plus the anchor.
- Markdown links with an explicit fragment (`Page.md#my-heading`) keep the
  fragment unchanged.
- `PageController::page` adds `toc: [{level, text, id}]` to its JSON response,
  collected from `Heading` nodes after parsing. `text` is the plain-text
  content of the heading.

## 2. Folder notes (server)

- `ContentService::listTree()` adds `note: <path>` to a folder entry when a
  folder note exists (priority order from Decisions).
- The folder note is removed from that folder's `children`: the folder
  represents it.
- `PageController::homePath()` applies the same rule to the site root
  (`Root/<RootName>.md`, `index.md`, `README.md`), then falls back to the first
  Markdown page found (current behaviour). The current list (`Readme.md`,
  `README.md`, `readme.md`, `index.md`, `Index.md`) is covered by the
  case-insensitive match.
- Extract the detection into `ContentService::folderNote(Folder $folder): ?string`
  so the tree and the home page share it.

## 3. Frontend

### Routing and scrolling

- The router keeps the URL hash. After a page loads, the view scrolls to the
  element whose `id` matches the hash. Without a hash, it scrolls to the top.
- `WikiView::onClick` handles same-page `#anchor` links: update the hash and
  scroll, no reload.

### `components/PageToc.vue`

- Props: `toc`. Renders nothing below the threshold.
- Levels: h2–h4 when the page has exactly one h1, otherwise h1–h4.
- Wide screens: sticky right column beside `.mds-content`. A header button
  collapses the column to a narrow rail with an "outline" icon.
- Narrow screens (below 1024 px): collapsible "On this page" block above the
  article.
- An `IntersectionObserver` marks the heading currently in view as active.
- Collapsed state: new preference `tocCollapsed` (boolean, default `false`) in
  `PreferencesController` and `stores/prefs.js`.

### `components/PageBreadcrumb.vue`

- Above the article: `Site › Folder › Subfolder › Page`. The site segment opens
  the home page.
- A folder segment opens the folder note when `note` is set, otherwise expands
  and reveals that folder in `PageTree`.
- Narrow screens: middle segments collapse into `…`.
- The current page segment is plain text.

### `components/PagePager.vue`

- Pure helper `src/services/pageOrder.js`: `flatten(tree)` returns pages in
  depth-first tree order. A folder with a `note` contributes that note at the
  folder's position, before its children. `neighbours(order, path)` returns
  `{prev, next}`.
- Two cards at the bottom of the article: "← Previous title" and
  "Next title →". A missing side renders nothing.

### `PageTree.vue`

- Clicking a folder with a `note` opens the note and expands the folder.
- Clicking a folder without a `note` toggles it (current behaviour).
- The active-page highlight also applies to a folder whose note is open.

## 4. Testing

### PHP (PHPUnit)

- Heading ids: simple, accented, duplicate headings.
- `[[Page#Heading]]`, `[[Page#Heading|label]]`, `[[#Heading]]`,
  `[text](Page.md#x)`: hrefs carry the expected fragment.
- `toc` content and levels.
- `folderNote()`: each name, priority order, case-insensitivity, none.
- `listTree()`: note set on the folder and removed from children.

### JavaScript (Vitest)

Vitest is introduced here (sub-project E reuses it). Add `vitest` as a dev
dependency and an `npm test` script.

- `pageOrder.flatten`: nested folders, folder notes, empty folders.
- `pageOrder.neighbours`: first page, last page, middle, unknown path.

## Out of scope

- Keyboard shortcuts for previous/next.
- Ordering by frontmatter (`order:`).
- Generated index pages for folders without a note.
