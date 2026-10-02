# MarkdownSite — In-App Editing & File Management Design

Status: proposed
Date: 2026-10-02
App version target: 2.0.0

## Motivation

MarkdownSite is read-only by design: content is edited in Files or via desktop
sync. A comparable app (JoaoMSacramento/markdown-wiki) ships an integrated
Markdown editor and file management (create, rename, move, delete,
drag-and-drop). This design adds both to MarkdownSite without giving up what it
does better: Obsidian-style wikilinks resolved by page name, per-site sharing
without Files access, multiple sites per user, Obsidian rendering.

The non-negotiable constraint: **editing must never degrade an Obsidian vault.**
The file text is the source of truth. The app only changes the characters the
user types or the links the user agrees to rewrite.

## Roadmap context

This is sub-project **E** of a five-part parity effort. Each part gets its own
spec, plan and implementation.

| # | Sub-project | Depends on |
|---|---|---|
| A | Nextcloud 32–34 compatibility (`info.xml` max-version, deprecated APIs) | — |
| B | Table of contents, breadcrumb, previous/next | — |
| C | Syntax highlighting and Mermaid in reading view | — |
| D | Full-text search within a site | — |
| E | In-app editing and file management (this document) | C (Mermaid widget) |

Build order: A → B → C → D → E.

## Decisions

| Topic | Decision |
|---|---|
| Who edits | Per-share role: **Reader** or **Editor**. Editors may edit, create, rename, move, delete. Only the owner manages shares and deletes the site. |
| Editor style | Obsidian-style **live preview**: the Markdown text is edited directly; syntax hides when the cursor is elsewhere. No Markdown re-serialisation. |
| Editor technology | **CodeMirror 6** for text and inline decorations; **block widgets rendered by the server** (same renderer as reading view). |
| Link updates on rename/move | Proposed: dialog shows "N links in M pages will be updated", checkbox on by default. |
| Saving | **Autosave**, 1.5 s after the last keystroke. |
| Default mode | **Reading**; pencil button or `E` key switches to editing in place. |
| Tree contents | Markdown pages and folders only. Attachments are added by drag/paste in the editor. |
| Attachment folder | `attachmentFolderPath` from `.obsidian/app.json` if present, else `attachments/`. |

## 1. Access model and data

### Schema

Migration adds `role` to `markdownsite_shares`:

- type `string`, length 8, not null, default `'reader'`
- values: `reader` | `editor`
- existing rows become `reader`

`SiteShare` gains `getRole()` / `setRole()`.

### AccessService

`canView()` is replaced by:

```php
/** @return 'owner'|'editor'|'reader'|null */
public function roleFor(Site $site, string $uid, array $groups, array $shares): ?string
```

- Owner → `owner`.
- Otherwise the strongest role among matching user and group shares
  (`editor` > `reader`).
- No match → `null`.

`canView()` and `canEdit()` become thin helpers over `roleFor()`.

Owner-only operations stay owner-only: share management, site deletion.

### Writes

- All writes go through the owner's mount, via the root returned by
  `ContentService::resolveRoot()`.
- If `$root->isUpdateable()` is false (the owner only has read access to the
  folder), editing is disabled for everyone and write endpoints return
  `403 {error: 'folder-read-only'}`.
- Known limitation: Nextcloud file versions attribute writes to the owner. The
  real author is not visible in Files.

### SiteContextResolver (targeted cleanup)

`PageController::root()` and `AssetController::file()` duplicate the
user → site → shares → access → root chain. Extract it into
`Service\SiteContextResolver::resolve(int $siteId): SiteContext`, where
`SiteContext` holds `site`, `root`, `role`. Both existing controllers and the new
`EditController` use it. Failures map to the existing JSON errors
(`unauthenticated` 401, `site-not-found` 404, `forbidden` 403).

### API exposure

`GET /sites` adds `role` and `writable` (`role` in owner/editor **and** root
updateable) per site.

### Sharing UI

`SharingSettings.vue`: each share row gets a Reader/Editor selector, default
Reader. `SiteController::share` accepts and persists `role`. A new
`PUT /sites/{id}/shares/{shareId}` changes the role of an existing share.

## 2. Server API

New `Controller\EditController`. Every action requires `canEdit()` and a
writable root. CSRF protection stays on (Nextcloud default; `@nextcloud/axios`
sends the token).

| Route | Purpose |
|---|---|
| `GET /s/{siteId}/source/{path}` | Raw Markdown + `etag` |
| `PUT /s/{siteId}/page/{path}` `{content, etag}` | Save. `etag` mismatch → `409 {etag, content}` with current server state |
| `POST /s/{siteId}/pages` `{path}` | Create an empty page. Exists → 409 |
| `POST /s/{siteId}/folders` `{path}` | Create a folder. Exists → 409 |
| `GET /s/{siteId}/backlinks/{path}` | `{count, pages[]}` of links a move would rewrite. Folder path → aggregated over its pages |
| `POST /s/{siteId}/move` `{from, to, updateLinks}` | Rename or move a page or folder. Returns `{path, updated, failed[]}` |
| `DELETE /s/{siteId}/node/{path}` | Delete page or folder → Nextcloud trash bin |
| `POST /s/{siteId}/attachments` (multipart `file`, `page`) | Store in the attachment folder; de-duplicate name (`image 1.png`); return `{path, embed}` where `embed` is `![[name]]` |
| `POST /s/{siteId}/render` `{markdown, path}` | Render a Markdown fragment in the context of `path` (for editor widgets) |

`etag` is `OCP\Files\Node::getEtag()`.

### Path safety

- Every existing node is reached through `ContentService::getChild()` (root
  clamp).
- New names and targets are validated by `Wiki\NameValidator`: reject `..`
  segments, empty segments, names starting with `.`, and characters forbidden
  by Nextcloud. Page paths are forced to end in `.md`.
- Move refuses a folder into itself or a descendant, and refuses moving the
  site root.

### LinkRewriter

`Wiki\LinkRewriter` is a pure class (no Nextcloud dependency).

```php
/** @return string rewritten markdown */
public function rewrite(string $markdown, string $pageDir, string $oldPath, string $newPath, WikilinkIndex $before): string
```

- Rewrites `[[Old]]`, `[[Old|label]]`, `[[Old#heading]]`, `[[Old#^block]]`,
  `![[Old]]`, `[[folder/Old]]`, and `[text](rel/Old.md)`.
- Rewrites a link only if, using `$before`, it resolved to `$oldPath` from
  `$pageDir`. Same-named pages elsewhere are untouched.
- Keeps the link form: a bare-name wikilink stays bare when the new basename
  is still unambiguous; it gains the shortest unambiguous path otherwise.
- Skips fenced code blocks, indented code blocks and inline code.
- When the moved file is itself a page, its own relative Markdown links are
  rewritten to stay valid from the new directory.

`countAffected()` uses the same matching to serve `/backlinks`.

### AttachmentLocator

`Service\AttachmentLocator::folderFor(Folder $root, string $pagePath): string`

- Reads `.obsidian/app.json` → `attachmentFolderPath`.
- `./` or `./sub` → relative to the page's directory.
- Absent, empty or `/` → `attachments/`.
- Creates the folder on first upload.

### Index cache (targeted cleanup)

`IndexBuilder::build()` currently reads every `.md` file on every page view.
With `/render` called while typing, this does not scale. Cache the built index
in `ICacheFactory::createDistributed('markdownsite')`, keyed by
`siteId + root etag`. The root folder etag changes whenever any descendant
changes, so the cache invalidates itself. Reading view benefits too.

## 3. Editor (frontend)

### Loading

CodeMirror (~150 KB) is a lazy chunk, imported when the user first enters edit
mode. Readers never download it. This relies on the chunk-loading fix from
sub-project C. If C's hosted-instance check forced the static-file fallback, CodeMirror
goes into the main bundle instead.

API rule: no Nextcloud API newer than 31 (see sub-project A).

Packages: `@codemirror/state`, `@codemirror/view`, `@codemirror/commands`,
`@codemirror/autocomplete`, `@codemirror/lang-markdown`, `@lezer/markdown`.

### Modules (`src/editor/`)

| Module | Responsibility |
|---|---|
| `syntax.js` | `@lezer/markdown` extensions for `[[wikilinks]]`, `![[embeds]]`, `==highlight==` |
| `livePreview.js` | Inline decorations hiding `**`, `_`, `~~`, `==`, heading `#`, backticks, `[text](url)`, `[[target\|label]]`. A marker reappears when the selection touches its element |
| `blockWidgets.js` | Replace callouts, tables, embeds, images, Mermaid fences and frontmatter with server HTML from `/render` while the cursor is outside the block. Cache by block-text hash. Raw text while loading or on render failure. Ordinary code blocks stay editable text |
| `wikilinkComplete.js` | `[[` triggers completion over pages and aliases |
| `attachments.js` | Drop or paste a file → upload → insert returned `embed` |
| `autosave.js` | Save state machine (see section 5) |
| `keymap.js` | Cmd/Ctrl+B, I, K; Escape leaves edit mode |

`GET /s/{siteId}/tree` adds `pages: [{path, title, aliases}]` for completion.

### Components

- New `components/PageEditor.vue`: mounts CodeMirror with the modules above;
  props `siteId`, `path`; emits `saved`, `conflict`, `exit`.
- `WikiView.vue`: adds `editing` state, a pencil button in the page header
  (shown when the site is `writable`) and the `E` shortcut (ignored inside
  inputs). Escape or the button flushes the save, leaves edit mode and reloads
  the rendered page.
- In edit mode, Cmd/Ctrl+click on a link flushes the save, then navigates.
- Reading view and editor widgets share the `.mds-content` styles so the layout
  matches across modes.

## 4. File management (tree)

For editors and owner only. Readers see the current tree unchanged.

- `PageTree.vue`: an `NcActions` menu per node with New page here, New folder
  (folders only), Rename, Delete. A "+" button in the navigation header creates
  a page at the root.
- Rename happens inline: the name becomes an input; Enter commits, Escape
  cancels.
- Native HTML5 drag-and-drop: drop a page or folder on a folder to move it. A
  root drop zone sits at the top of the tree. Invalid drops (into self or a
  descendant) are refused client-side and server-side.

### Rename / move flow

1. Call `/backlinks` for the source.
2. Count is 0 → move immediately with `updateLinks: false`.
3. Count > 0 → dialog "N links in M pages will be updated", checkbox checked,
   Cancel / Confirm.
4. If the open page moved, the route follows the new path.

### Create and delete

- New page creates an empty `Name.md` and opens it in edit mode.
- Delete asks "Move "X" to the trash?" (folders add "+ N pages"). If the open
  page is deleted, navigate to the site home.

### Folder notes (from sub-project B)

- Renaming a folder `X` whose note is `X/X.md` also renames the note to the new
  folder name, inside the same operation and link rewrite.
- A folder's action menu adds "Create folder note" when it has none; it creates
  `X/X.md` and opens it in edit mode.
- Deleting a folder deletes its note with it.

After every operation the tree reloads. The link index refreshes through the
root etag. The search index (sub-project D) is updated directly: save and
create call `SearchIndexer::indexPage()`, delete calls `removePage()`, move
calls both, and link rewrites re-index each rewritten page.

## 5. Error handling

### Save state machine (`autosave.js`)

States: `idle`, `dirty`, `saving`, `saved`, `error`, `conflict`.

- Typing → `dirty`; 1.5 s debounce → `saving` → `PUT` with last known `etag`.
- Forced flush on page change, edit-mode exit and `beforeunload`.
- Network failure → `error`, up to 3 retries with back-off (2 s, 5 s, 15 s).
  Editing continues. The unsaved text is mirrored to `sessionStorage` under
  `markdownsite:draft:{siteId}:{path}` and offered on next open. The browser
  warns on close while not `saved`.
- `409` → `conflict`: autosave pauses; banner "Modified elsewhere" with
  *Reload* (discard local text) or *Keep mine* (re-save with the server `etag`).
- `403` → leave edit mode; message "Editing rights removed"; a button copies
  the unsaved text to the clipboard.
- `404` → banner "Page deleted elsewhere" with *Recreate with my text*.

### File operations

- Name collision on create, rename or move → `409`; inline "Already exists" in
  the tree.
- Move with link rewrite: the move happens first, then each affected page is
  rewritten. Partial failure returns `failed[]`; the UI shows "N pages not
  updated" with the list. No automatic rollback.
- Upload rejected (size, quota) → toast with the server message.
- `/render` failure → the block stays raw text, no message.

## 6. Testing

### PHP (PHPUnit, existing setup)

- `AccessService::roleFor`: owner, editor, reader, user + group share → max,
  no match.
- `LinkRewriter`: each link form, homonyms untouched, code ignored, form
  preserved, ambiguity → path added, moved page's own relative links.
- `AttachmentLocator`: no `app.json`, fixed folder, `./`, `./sub`.
- `NameValidator`: `..`, empty segment, leading dot, forbidden characters,
  `.md` forcing.
- `EditController`: save OK, etag conflict, reader refused, read-only folder,
  move into descendant refused.

### JavaScript (new: Vitest)

Vitest is set up in sub-project B; E adds its tests to it.

- `autosave.js`: debounce, forced flush, conflict, retries, draft mirroring.
- `syntax.js`: `[[a]]`, `[[a|b]]`, `[[a#h]]`, `![[a]]`, `==x==`.

## Out of scope

- Search, table of contents, breadcrumb, previous/next (B, D).
- Syntax highlighting and Mermaid in reading view (C). The editor's Mermaid
  widget reuses C's rendering, so C ships first.
- Real-time collaborative editing.
- An "All files" tree view.
- Per-author attribution in Nextcloud file versions.
