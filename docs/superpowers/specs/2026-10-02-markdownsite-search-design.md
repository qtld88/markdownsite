# MarkdownSite — Full-Text Search Design

Status: proposed
Date: 2026-10-02
App version target: 1.5.0

## Motivation

Sub-project **D** of the parity roadmap (see
`2026-10-02-markdownsite-editing-design.md`, "Roadmap context"). API rule from
sub-project A applies: no Nextcloud API newer than 31.

The wiki has no search. Target sizes: the production wiki has 167 pages; the owner's main vault has
4,247 pages (9 MB of Markdown). Reading every file per query is not an option.

## Decisions

| Topic | Decision |
|---|---|
| Where | In-app only, with a "This site / All my sites" toggle. No Nextcloud unified search provider |
| Results display | Results replace the page tree while searching (Obsidian search pane) |
| Index | Database table, refreshed incrementally by file etag |
| Matching | All terms required; case- and accent-insensitive; substring match; `"exact phrase"` |
| Highlight on open | The page opened from a result highlights the terms and scrolls to the first one |
| Excluded | Nextcloud Full text search / Elasticsearch (unavailable on the hosted production instance) |

## 1. Schema

Migration adds `markdownsite_search`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, autoincrement | primary key |
| `site_id` | bigint | index |
| `path` | string 1024 | page path relative to the site root |
| `path_hash` | string 40 | `sha1(path)`; unique index with `site_id` (MySQL index-length safe) |
| `etag` | string 64 | file etag at indexing time |
| `title` | string 255 | display title |
| `title_norm` | string 255 | normalised |
| `aliases_norm` | text | normalised, space-separated |
| `headings_norm` | text | normalised, newline-separated |
| `body` | text | plain text, original case and accents |
| `body_norm` | text | normalised `body`, same length |

Migration adds `search_etag` (string 64, nullable) to `markdownsite_sites`: the
root folder etag at the last completed refresh.

`SiteController::destroy` deletes the site's `markdownsite_search` rows.

## 2. Text extraction and normalisation (pure classes)

### `Search\PlainText::fromMarkdown(string $md): array`

Returns `{title, aliases[], headings[], body}`.

- Strips YAML frontmatter; reads `aliases` and `title` from it.
- Title: frontmatter `title`, else the first h1, else the file basename.
- `[[target|label]]` → `label`; `[[target]]` → `target`; `![[embed]]` → removed;
  `[text](url)` → `text`; images removed.
- Removes emphasis, highlight, strikethrough, heading and list markers, callout
  markers (`> [!type]`), HTML tags.
- Keeps code block contents (code is searchable), drops the fence lines.

### `Search\Normalizer::normalize(string $s): string`

- Lowercases with `mb_strtolower`.
- Maps each character to its base letter (é → e, ß → s, œ → o), **one
  character in, one character out**, so `body_norm` offsets equal `body`
  offsets. The mapping table covers Latin-1 Supplement and Latin Extended-A.
  Characters outside it pass through unchanged.

### `Search\QueryParser::parse(string $q): array`

- Splits on whitespace into terms; a quoted span becomes one phrase term.
- Normalises each term.
- Drops terms shorter than 2 characters; an empty result means "no search".

## 3. Indexing

`Service\SearchIndexer::refresh(Site $site, Folder $root): void`

1. If `$root->getEtag() === $site->getSearchEtag()`, return.
2. Walk the Markdown paths with their etags from file metadata
   (`ContentService::listMarkdownPaths` extended to return etags). No file is
   opened in this step.
3. For each path whose etag differs from the stored row, or with no row: read,
   extract, normalise, upsert.
4. Delete rows whose path no longer exists.
5. Store the root etag in `search_etag`.

`SearchIndexer::indexPage(Site, Folder, string $path)` and
`removePage(Site, string $path)` serve sub-project E: save, move and delete
update the index directly (move = remove old + index new).

First search on a large site runs the full indexing inside the request; the UI
shows "Indexing…" until the response arrives.

## 4. Query

`SearchController::search(string $q, ?int $site, int $offset = 0)`, route
`GET /search`.

- Sites searched: the given site if `roleFor()` grants access, otherwise
  403; without `site`, every site where `roleFor()` is not null.
- Each searched site is refreshed first.
- SQL filter: for every term, `(title_norm LIKE %t% OR aliases_norm LIKE %t%
  OR headings_norm LIKE %t% OR body_norm LIKE %t%)`, all terms ANDed. LIKE
  wildcards in terms are escaped with `escapeLikeParameter()`. At most 500
  candidate rows are fetched.
- Ranking in PHP, per term, summed:

  | Match in | Points |
  |---|---|
  | title | 10 |
  | alias | 8 |
  | heading | 5 |
  | body | 1 per occurrence, max 5 |

  +50 % when the match starts a word.
- Response: 50 results from `offset`, plus `total`. Each result:
  `{siteId, siteName, path, title, folder, snippet, highlights: [[start, length], …]}`.
- `snippet`: ~160 characters of `body` around the first term occurrence, cut on
  word boundaries; `highlights` are offsets inside the snippet.

## 5. Frontend

### `components/SearchPanel.vue`

- Search field at the top of the navigation, above `PageTree`. Opens with a
  click or `Ctrl/Cmd+Shift+F`.
- Queries after 2 characters, 300 ms after the last keystroke; an in-flight
  request is aborted when a new one starts.
- Toggle under the field: "This site" / "All my sites" (default "This site";
  last choice kept in user preferences as `searchScope`).
- While the query is non-empty, results replace the tree: title, folder,
  snippet with highlighted terms. "All my sites" groups results by site.
  "More results" loads the next 50.
- ✕ or Escape clears the query and shows the tree again.
- States: indexing (spinner + "Indexing…"), no results, error.

### Highlight on open

- Opening a result navigates with `?q=<query>` on the page route.
- After render, `src/services/searchHighlight.js` wraps the terms in
  `<mark class="mds-search-hit">` inside the article text nodes (never inside
  links' `href` or code highlighting spans), and scrolls to the first one.
- The `q` parameter is dropped on the next navigation, so highlights vanish.

## 6. Testing

### PHP (PHPUnit)

- `PlainText`: frontmatter, title fallback chain, each link form, code kept.
- `Normalizer`: accents, ligatures, length preserved.
- `QueryParser`: words, phrase, short terms.
- Ranking: title beats body; word-start bonus.
- `SearchIndexer::refresh`: page added, modified, deleted; unchanged root etag
  reads nothing.
- Access: "All my sites" never includes a site without a matching share.

### JavaScript (Vitest)

- Snippet highlight rendering from `highlights` offsets.
- `searchHighlight`: wraps text nodes, skips attributes and code spans.

## Out of scope

- Nextcloud unified search provider.
- Fuzzy matching (typo tolerance).
- Search operators (`tag:`, `path:`).
- Background indexing job.
