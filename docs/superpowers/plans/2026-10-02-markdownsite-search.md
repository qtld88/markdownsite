# Full-Text Search (D) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In-app full-text search over one site or all of the user's sites, backed by a database index refreshed by file etag, with results in the navigation and highlighted terms on the opened page.

**Architecture:** Pure classes extract plain text from Markdown (`PlainText`), fold case and accents one character to one character (`Normalizer`), parse queries (`QueryParser`) and score/snippet results (`Ranker`). `SearchIndexer` keeps the new `markdownsite_search` table in step with a site's files, reading only pages whose etag changed and nothing when the root folder's etag is unchanged. `SearchController` (`GET /search`) checks access, refreshes, filters with `LIKE` on the normalised columns and ranks in PHP. The frontend adds `SearchPanel` (replaces the tree while a query is typed) and `highlightTerms()` on the opened page.

**Tech Stack:** PHP 8.1, Nextcloud 31 OCP (`QBMapper`, `IQueryBuilder`, `IDBConnection::escapeLikeParameter`, migrations), symfony/yaml, PHPUnit 10, Vue 3.5, @nextcloud/vue 9.8, axios with `AbortController`, Vitest 3.2 + jsdom.

**Spec:** `docs/superpowers/specs/2026-10-02-markdownsite-search-design.md`
**Depends on:** plans A–C (OCP 31 stubs, `ContentService::getChild()`, `WikiView` layout, `decorateCode()`/`renderDiagrams()`, jsdom tests).

---

## Assumptions and spec corrections

1. **Access uses `AccessService::canView()`.** The spec names `roleFor()`, which only arrives with sub-project E. Plan E replaces `canView()` by `roleFor()` everywhere, `SearchController` included. For "All my sites" the controller takes `SiteMapper::findVisible()` and still checks `canView()` on each site, so one rule covers both cases.
2. **`ContentService::listMarkdownEtags()` is added; `listMarkdownPaths()` keeps its return type** (`array_keys()` of the new method). `IndexBuilder` depends on the old signature. The walk now recurses on `Folder` nodes instead of calling `$root->get()` per folder.
3. **`PlainText::fromMarkdown()` takes a second argument, the fallback title** (the file name), because the title chain ends with "the file basename" and the Markdown alone does not know it. `[[Page#Heading]]` becomes `Page Heading` in the indexed text.
4. **Lower-casing uses simple case mapping** (`MB_CASE_LOWER_SIMPLE`), not `mb_strtolower()`: full mapping turns `İ` into two characters and would break "same length". The folding table (97 entries) is the lower-case letters of Latin-1 Supplement and Latin Extended-A; ligatures fold to one letter (`ß`→`s`, `œ`→`o`, `æ`→`a`, `ĳ`→`i`).
5. **Offsets are in Unicode code points.** `highlights` are `[start, length]` in code points; the frontend splits snippets with `Array.from()`, so an emoji before a match does not shift the highlight. On the page, `highlightTerms()` folds text with a JavaScript port of the normaliser that keeps UTF-16 lengths, so its indexes are valid on the original text.
6. **Ranking detail.** The +50 % bonus applies per field (title, aliases, headings, body) when at least one occurrence in that field starts a word. Ties sort by title. `Ranker` counts with byte offsets on UTF-8 (`strpos`) and only converts to characters inside a ~200-character window: a naive `mb_strpos()` loop is quadratic on long pages (measured: 50 results on a 644 KB page take 36 ms with this approach).
7. **Snippet ellipses are part of the snippet** (`…` at a cut start or end), and `highlights` already account for them.
8. **"Indexing…" is shown when a request takes more than one second** ("Searching…" before that). The server does not report whether it is indexing; a slow first request is the indexing case the spec describes.
9. **`Ctrl/Cmd+Shift+F` is caught in the capture phase and stopped.** Nextcloud 31's unified search also reacts to it and otherwise takes the focus (observed in Chromium).
10. **Nothing to do with full-text search providers**: no `IProvider`, as the spec excludes unified search.
11. **Migration safety.** Table `markdownsite_search` (19 characters, under Nextcloud's 23-character limit for default primary-key names), index names under 30 characters, `TEXT` columns nullable, `search_etag` nullable. Verified on `nextcloud:31` (SQLite): installing 1.5.0 over an installed 1.4.0 with `occ app:disable markdownsite` then `occ app:enable markdownsite` runs the migration (`oc_migrations` lists `1500Date20261002000000`, the table and the column exist).

Verified while writing this plan on `nextcloud:31` with headless Chromium: `Ctrl+Shift+F` focuses the field, results replace the tree, snippets show marks, opening a result adds `?q=` and highlights 24 matches scrolled into view, the next navigation drops `q` and the marks, "All my sites" groups by site, Escape brings the tree back, "No results" shows for a nonsense query; `GET /search?q=python&site=1` answered in 0.3 s including the first indexing.

## File map

| File | Change |
|---|---|
| `lib/Search/Normalizer.php`, `QueryParser.php`, `PlainText.php`, `Ranker.php` + tests in `tests/unit/Search/` | New |
| `lib/Migration/Version1500Date20261002000000.php` | New table, new column |
| `lib/Db/Site.php` | `searchEtag` |
| `lib/Db/SearchEntry.php`, `lib/Db/SearchMapper.php` | New |
| `lib/Service/ContentService.php` + test | `listMarkdownEtags()` |
| `lib/Service/SearchIndexer.php` + `tests/unit/Service/SearchIndexerTest.php` | New |
| `lib/Controller/SearchController.php` + `tests/unit/Controller/SearchControllerTest.php`, `appinfo/routes.php` | New `GET /search` |
| `lib/Controller/SiteController.php` | Deleting a site deletes its index rows |
| `lib/Controller/PreferencesController.php` + test, `src/stores/prefs.js` | `searchScope` |
| `src/services/searchText.js`, `src/services/searchHighlight.js` + tests | New |
| `src/services/api.js` | `searchPages()` |
| `src/components/SearchPanel.vue` | New |
| `src/views/WikiView.vue` | Panel above the tree; highlight on open |
| `tests/fixtures/smoke-site/Guides/Recipes.md` | New |
| `appinfo/info.xml`, `CHANGELOG.md`, `README.md`, `js/*` | 1.5.0 |

---

### Task 1: `Normalizer`

**Files:**
- Create: `tests/unit/Search/NormalizerTest.php`, `lib/Search/Normalizer.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Search/NormalizerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Search;

use OCA\MarkdownSite\Search\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase {
	public function testLowercasesAndRemovesAccents(): void {
		$this->assertSame('ete a paris, deja vu', Normalizer::normalize('Été à Paris, Déjà vu'));
		$this->assertSame('ceske budejovice', Normalizer::normalize('České Budějovice'));
		$this->assertSame('lodz', Normalizer::normalize('Łódź'));
	}

	public function testFoldsLigaturesToOneLetter(): void {
		$this->assertSame('strase', Normalizer::normalize('Straße'));
		$this->assertSame('ouvre', Normalizer::normalize('Œuvre'));
		$this->assertSame('aon', Normalizer::normalize('Æon'));
	}

	public function testPreservesLength(): void {
		foreach (['Straße', 'İstanbul', 'Œuvre & Æsir', 'naïve café 😀 日本', "ĳ ŉ ſ"] as $text) {
			$this->assertSame(mb_strlen($text), mb_strlen(Normalizer::normalize($text)), $text);
		}
	}

	public function testLeavesOtherScriptsUnchanged(): void {
		$this->assertSame('日本語 😀 ελληνικά', Normalizer::normalize('日本語 😀 Ελληνικά'));
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter NormalizerTest`
Expected: 4 errors, `Class "OCA\MarkdownSite\Search\Normalizer" not found`.

- [ ] **Step 3: Write the class**

`lib/Search/Normalizer.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Search;

/**
 * Folds text for matching: lower case, accents and ligatures reduced to their
 * base letter. Exactly one character in, one character out, so an offset in
 * the folded text is the same offset in the original.
 */
class Normalizer {
	/** Lower-case Latin-1 Supplement and Latin Extended-A letters => base letter. */
	private const BASE = [
		'ß' => 's', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a',
		'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
		'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 't', 'ÿ' => 'y',
		'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd',
		'đ' => 'd', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ĝ' => 'g', 'ğ' => 'g',
		'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i',
		'ı' => 'i', 'ĳ' => 'i', 'ĵ' => 'j', 'ķ' => 'k', 'ĸ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l',
		'ŀ' => 'l', 'ł' => 'l', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n', 'ŋ' => 'n', 'ō' => 'o',
		'ŏ' => 'o', 'ő' => 'o', 'œ' => 'o', 'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's',
		'ş' => 's', 'š' => 's', 'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u',
		'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ŵ' => 'w', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
		'ſ' => 's',
	];

	public static function normalize(string $s): string {
		// Simple case mapping is one code point to one code point (full
		// mapping would turn "İ" into two).
		return strtr(mb_convert_case($s, MB_CASE_LOWER_SIMPLE, 'UTF-8'), self::BASE);
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (73 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Search/Normalizer.php tests/unit/Search/NormalizerTest.php
git commit -m "feat: case- and accent-folding normaliser for search"
```

---

### Task 2: `QueryParser`

**Files:**
- Create: `tests/unit/Search/QueryParserTest.php`, `lib/Search/QueryParser.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Search/QueryParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Search;

use OCA\MarkdownSite\Search\QueryParser;
use PHPUnit\Framework\TestCase;

class QueryParserTest extends TestCase {
	public function testSplitsWordsAndNormalises(): void {
		$this->assertSame(['cafe', 'creme'], QueryParser::parse('  Café   CRÈME '));
	}

	public function testQuotedPhraseIsOneTerm(): void {
		$this->assertSame(['mot de passe', 'oublie'], QueryParser::parse('"Mot  de passe" oublié'));
	}

	public function testUnclosedQuoteRunsToTheEnd(): void {
		$this->assertSame(['nextcloud', 'talk app'], QueryParser::parse('nextcloud "talk app'));
	}

	public function testDropsShortTermsAndDuplicates(): void {
		$this->assertSame(['ab', 'vault'], QueryParser::parse('a ab "" x vault Vault'));
	}

	public function testEmptyMeansNoSearch(): void {
		$this->assertSame([], QueryParser::parse(''));
		$this->assertSame([], QueryParser::parse('a "b"'));
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter QueryParserTest`
Expected: 5 errors, `Class "OCA\MarkdownSite\Search\QueryParser" not found`.

- [ ] **Step 3: Write the class**

`lib/Search/QueryParser.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Search;

class QueryParser {
	/** Terms shorter than this are dropped. */
	public const MIN_TERM_LENGTH = 2;

	/**
	 * Normalised search terms. Whitespace separates terms; a "quoted span" is
	 * one term (an unclosed quote runs to the end). An empty list means "no
	 * search".
	 *
	 * @return list<string>
	 */
	public static function parse(string $q): array {
		preg_match_all('/"([^"]*)"?|(\S+)/u', $q, $matches, PREG_SET_ORDER);
		$terms = [];
		foreach ($matches as $m) {
			$raw = isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1];
			$term = trim(preg_replace('/\s+/u', ' ', Normalizer::normalize($raw)) ?? '');
			if (mb_strlen($term) >= self::MIN_TERM_LENGTH && !in_array($term, $terms, true)) {
				$terms[] = $term;
			}
		}
		return $terms;
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (78 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Search/QueryParser.php tests/unit/Search/QueryParserTest.php
git commit -m "feat: search query parser with quoted phrases"
```

---

### Task 3: `PlainText`

**Files:**
- Create: `tests/unit/Search/PlainTextTest.php`, `lib/Search/PlainText.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Search/PlainTextTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Search;

use OCA\MarkdownSite\Search\PlainText;
use PHPUnit\Framework\TestCase;

class PlainTextTest extends TestCase {
	public function testFrontmatterGivesTitleAndAliases(): void {
		$md = "---\ntitle: Password manager\naliases: [BW, Vault]\ntags: x\n---\n# Bitwarden\n\nBody";
		$out = PlainText::fromMarkdown($md, 'Bitwarden');
		$this->assertSame('Password manager', $out['title']);
		$this->assertSame(['BW', 'Vault'], $out['aliases']);
		$this->assertSame('Bitwarden' . "\n\n" . 'Body', $out['body']);
	}

	public function testSingleAliasString(): void {
		$out = PlainText::fromMarkdown("---\naliases: BW\n---\ntext");
		$this->assertSame(['BW'], $out['aliases']);
	}

	public function testTitleFallsBackToFirstH1ThenFileName(): void {
		$this->assertSame('First', PlainText::fromMarkdown("## Sub\n# First\n# Second", 'File')['title']);
		$this->assertSame('File', PlainText::fromMarkdown("## Only a sub", 'File')['title']);
	}

	public function testHeadingsAreCollectedWithoutMarkers(): void {
		$out = PlainText::fromMarkdown("# Guide\n\n## Install *now* ##\n\ntext\n\n### See [[Notes|the notes]]", '');
		$this->assertSame(['Guide', 'Install now', 'See the notes'], $out['headings']);
	}

	public function testLinkForms(): void {
		$md = "See [[Target|label]], [[Other Page]], [[Guide#Install]], [[#Usage]], ![[pic.png]], [text](http://x.y/z) and ![alt](img.png).";
		$this->assertSame('See label, Other Page, Guide Install, Usage, , text and .', PlainText::fromMarkdown($md)['body']);
	}

	public function testRemovesFormattingAndMarkers(): void {
		$md = "> [!note]- Title here\n> **bold** _em_ ==hi== ~~del~~ `code` <b>tag</b>\n\n- [ ] task\n1. one\n* star";
		$this->assertSame(
			"Title here\nbold em hi del code tag\n\ntask\none\nstar",
			PlainText::fromMarkdown($md)['body'],
		);
	}

	public function testKeepsSnakeCaseWords(): void {
		$this->assertSame('use file_name and 2*3', PlainText::fromMarkdown('use file_name and 2*3')['body']);
	}

	public function testCodeIsKeptButFencesDropped(): void {
		$md = "Intro\n\n```bash\n# not a heading\necho **raw**\n```\n\nOutro";
		$out = PlainText::fromMarkdown($md);
		$this->assertSame("Intro\n\n# not a heading\necho **raw**\n\nOutro", $out['body']);
		$this->assertSame([], $out['headings']);
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter PlainTextTest`
Expected: 8 errors, `Class "OCA\MarkdownSite\Search\PlainText" not found`.

- [ ] **Step 3: Write the class**

`lib/Search/PlainText.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Search;

use Symfony\Component\Yaml\Yaml;

/** Reduces an Obsidian Markdown page to the plain text that search reads. */
class PlainText {
	/**
	 * @param string $fallbackTitle used when there is neither a frontmatter
	 *                              title nor an h1 (normally the file name)
	 * @return array{title: string, aliases: list<string>, headings: list<string>, body: string}
	 */
	public static function fromMarkdown(string $md, string $fallbackTitle = ''): array {
		$md = str_replace(["\r\n", "\r"], "\n", $md);
		$frontmatter = [];
		if (preg_match('/\A---[ \t]*\n(.*?)\n---[ \t]*(?:\n|\z)/s', $md, $m)) {
			$md = substr($md, strlen($m[0]));
			try {
				$data = Yaml::parse($m[1]);
				$frontmatter = is_array($data) ? $data : [];
			} catch (\Throwable) {
			}
		}

		$lines = [];
		$headings = [];
		$firstH1 = null;
		$fence = null;
		foreach (explode("\n", $md) as $line) {
			if ($fence !== null) {
				if (preg_match('/^\s*' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $line)) {
					$fence = null;
				} else {
					$lines[] = $line; // code stays searchable, verbatim
				}
				continue;
			}
			if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $f)) {
				$fence = $f[1];
				continue;
			}
			$line = preg_replace('/^\s*(?:>\s*)+\[![\w-]+\][+-]?\s*/', '', $line) ?? $line;
			$line = preg_replace('/^\s*(?:>\s?)+/', '', $line) ?? $line;
			if (preg_match('/^\s*(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $h)) {
				$text = self::inline($h[2]);
				$headings[] = $text;
				if ($firstH1 === null && strlen($h[1]) === 1) {
					$firstH1 = $text;
				}
				$lines[] = $text;
				continue;
			}
			$line = preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+(?:\[[ xX]\]\s+)?/', '', $line) ?? $line;
			$lines[] = self::inline($line);
		}

		$aliases = $frontmatter['aliases'] ?? [];
		$aliases = array_values(array_filter(
			array_map(fn ($a) => trim((string) $a), is_array($aliases) ? $aliases : [$aliases]),
			fn (string $a) => $a !== '',
		));
		$title = is_scalar($frontmatter['title'] ?? null) && trim((string) $frontmatter['title']) !== ''
			? trim((string) $frontmatter['title'])
			: ($firstH1 ?? $fallbackTitle);
		$body = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');

		return ['title' => $title, 'aliases' => $aliases, 'headings' => $headings, 'body' => $body];
	}

	/** Inline Markdown to plain text. */
	private static function inline(string $s): string {
		$s = preg_replace('/!\[\[[^\]]*\]\]/', '', $s) ?? $s;                          // ![[embed]]
		$s = preg_replace('/\[\[[^\]|]*\|([^\]]*)\]\]/', '$1', $s) ?? $s;              // [[target|label]]
		$s = preg_replace_callback('/\[\[([^\]]*)\]\]/', fn ($m) => trim(str_replace('#', ' ', $m[1])), $s) ?? $s; // [[target#heading]]
		$s = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $s) ?? $s;                     // ![alt](src)
		$s = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s) ?? $s;                  // [text](url)
		$s = preg_replace('/<[^>]+>/', '', $s) ?? $s;                                   // HTML tags
		$s = str_replace(['**', '__', '==', '~~', '`'], '', $s);
		$s = preg_replace('/(?<![\p{L}\p{N}])[*_]+|[*_]+(?![\p{L}\p{N}])/u', '', $s) ?? $s; // *em* _em_
		return trim($s);
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (86 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Search/PlainText.php tests/unit/Search/PlainTextTest.php
git commit -m "feat: extract searchable plain text from Markdown"
```

---

### Task 4: `Ranker`

**Files:**
- Create: `tests/unit/Search/RankerTest.php`, `lib/Search/Ranker.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Search/RankerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Search;

use OCA\MarkdownSite\Search\Normalizer;
use OCA\MarkdownSite\Search\Ranker;
use PHPUnit\Framework\TestCase;

class RankerTest extends TestCase {
	private function row(string $title = '', string $aliases = '', string $headings = '', string $body = ''): array {
		return [
			'title_norm' => Normalizer::normalize($title),
			'aliases_norm' => Normalizer::normalize($aliases),
			'headings_norm' => Normalizer::normalize($headings),
			'body_norm' => Normalizer::normalize($body),
		];
	}

	public function testTitleBeatsBody(): void {
		$inTitle = Ranker::score(['vault'], $this->row(title: 'Vault'));
		$inBody = Ranker::score(['vault'], $this->row(body: 'vault vault vault vault vault vault vault'));
		$this->assertGreaterThan($inBody, $inTitle);
		$this->assertSame(15.0, $inTitle);   // 10 × 1.5 (starts a word)
		$this->assertSame(7.5, $inBody);     // capped at 5 occurrences × 1.5
	}

	public function testFieldPoints(): void {
		$this->assertSame(12.0, Ranker::score(['bw'], $this->row(aliases: 'bw vault')));
		$this->assertSame(7.5, Ranker::score(['setup'], $this->row(headings: "Intro\nSetup")));
	}

	public function testWordStartBonus(): void {
		$start = Ranker::score(['pass'], $this->row(body: 'password'));
		$inside = Ranker::score(['pass'], $this->row(body: 'bypass'));
		$this->assertSame(1.5, $start);
		$this->assertSame(1.0, $inside);
	}

	public function testTermsAreSummed(): void {
		$both = Ranker::score(['alpha', 'beta'], $this->row(title: 'Alpha', body: 'beta'));
		$this->assertSame(15.0 + 1.5, $both);
	}

	public function testSnippetAroundFirstMatchWithHighlights(): void {
		$body = str_repeat('lorem ipsum ', 20) . 'the Café crème recipe ' . str_repeat('dolor sit ', 20);
		$out = Ranker::snippet($body, Normalizer::normalize($body), ['cafe', 'creme']);
		$this->assertStringStartsWith('…', $out['snippet']);
		$this->assertStringEndsWith('…', $out['snippet']);
		$this->assertLessThanOrEqual(Ranker::SNIPPET_LENGTH + 2, mb_strlen($out['snippet']));
		$this->assertCount(2, $out['highlights']);
		[$start, $length] = $out['highlights'][0];
		$this->assertSame('Café', mb_substr($out['snippet'], $start, $length));
		[$start, $length] = $out['highlights'][1];
		$this->assertSame('crème', mb_substr($out['snippet'], $start, $length));
	}

	public function testSnippetWithoutBodyMatchStartsAtTheTop(): void {
		$out = Ranker::snippet("First line\nsecond line", "first line\nsecond line", ['title']);
		$this->assertSame('First line second line', $out['snippet']);
		$this->assertSame([], $out['highlights']);
	}

	public function testOverlappingHighlightsAreMerged(): void {
		$out = Ranker::snippet('password', 'password', ['pass', 'password']);
		$this->assertSame([[0, 8]], $out['highlights']);
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter RankerTest`
Expected: 7 errors, `Class "OCA\MarkdownSite\Search\Ranker" not found`.

- [ ] **Step 3: Write the class**

`lib/Search/Ranker.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Search;

/**
 * Scores candidate pages and cuts result snippets. Snippet offsets are in
 * characters (Unicode code points), not bytes.
 */
class Ranker {
	public const SNIPPET_LENGTH = 160;
	private const SNIPPET_LEAD = 40;

	private const POINTS_TITLE = 10;
	private const POINTS_ALIAS = 8;
	private const POINTS_HEADING = 5;
	private const BODY_MAX = 5;
	private const WORD_START_BONUS = 1.5;

	/**
	 * Sum over terms of: title 10, alias 8, heading 5, body 1 per occurrence
	 * (max 5); each field's points × 1.5 when an occurrence starts a word.
	 *
	 * @param list<string> $terms normalised terms
	 * @param array{title_norm: string, aliases_norm: string, headings_norm: string, body_norm: string} $row
	 */
	public static function score(array $terms, array $row): float {
		$score = 0.0;
		foreach ($terms as $term) {
			foreach ([
				[$row['title_norm'], self::POINTS_TITLE, 1],
				[$row['aliases_norm'], self::POINTS_ALIAS, 1],
				[$row['headings_norm'], self::POINTS_HEADING, 1],
				[$row['body_norm'], 1, self::BODY_MAX],
			] as [$text, $points, $maxCount]) {
				$count = 0;
				$wordStart = false;
				$offset = 0;
				// Byte offsets: both strings are UTF-8, so a match is a match.
				while (($pos = strpos($text, $term, $offset)) !== false) {
					$count++;
					$wordStart = $wordStart || self::startsWord($text, $pos);
					$offset = $pos + 1;
					if ($count >= $maxCount && $wordStart) {
						break;
					}
				}
				if ($count > 0) {
					$score += min($count, $maxCount) * $points * ($wordStart ? self::WORD_START_BONUS : 1);
				}
			}
		}
		return $score;
	}

	/**
	 * About SNIPPET_LENGTH characters of $body around the first match, cut on
	 * word boundaries, with "…" where text was cut, plus the [start, length]
	 * (in characters) of every match inside the snippet.
	 *
	 * @param list<string> $terms normalised terms
	 * @return array{snippet: string, highlights: list<array{0: int, 1: int}>}
	 */
	public static function snippet(string $body, string $bodyNorm, array $terms): array {
		$firstByte = null;
		foreach ($terms as $term) {
			$pos = strpos($bodyNorm, $term);
			if ($pos !== false && ($firstByte === null || $pos < $firstByte)) {
				$firstByte = $pos;
			}
		}
		// $body and $bodyNorm have the same characters at the same character
		// offsets (Normalizer is one-to-one), but not the same byte offsets.
		$first = $firstByte === null ? 0 : mb_strlen(substr($bodyNorm, 0, $firstByte));
		$start = max(0, $first - self::SNIPPET_LEAD);
		$windowLength = self::SNIPPET_LEAD + self::SNIPPET_LENGTH + 1;
		$norm = mb_substr($bodyNorm, $start, $windowLength);
		$text = mb_substr($body, $start, $windowLength);
		$atEnd = $start + mb_strlen($norm) >= mb_strlen($bodyNorm);

		$cutStart = 0;
		if ($start > 0) {
			// Begin after a space, so the snippet does not start mid-word.
			$space = self::spaceBetween($norm, 0, $first - $start);
			$cutStart = $space === null ? 0 : $space + 1;
		}
		$cutEnd = min(mb_strlen($norm), $cutStart + self::SNIPPET_LENGTH);
		$truncated = !$atEnd || $cutEnd < mb_strlen($norm);
		if ($truncated) {
			$space = self::spaceBefore($norm, $cutEnd, $first - $start);
			if ($space !== null) {
				$cutEnd = $space;
			}
		}

		$prefix = $start + $cutStart > 0 ? '…' : '';
		$suffix = $truncated ? '…' : '';
		// Whitespace (newlines included) becomes plain spaces: same length.
		$snippetText = preg_replace('/\s/u', ' ', mb_substr($text, $cutStart, $cutEnd - $cutStart)) ?? '';
		$snippetNorm = mb_substr($norm, $cutStart, $cutEnd - $cutStart);

		$highlights = [];
		foreach ($terms as $term) {
			$offset = 0;
			while (($pos = mb_strpos($snippetNorm, $term, $offset)) !== false) {
				$highlights[] = [$pos + mb_strlen($prefix), mb_strlen($term)];
				$offset = $pos + 1;
			}
		}
		usort($highlights, fn ($a, $b) => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);
		$merged = [];
		$reach = -1;
		foreach ($highlights as $h) {
			if ($h[0] >= $reach) {
				$merged[] = $h;
				$reach = $h[0] + $h[1];
			}
		}
		return ['snippet' => $prefix . $snippetText . $suffix, 'highlights' => $merged];
	}

	/** True when the character before byte offset $pos is not a letter or digit. */
	private static function startsWord(string $text, int $pos): bool {
		if ($pos === 0) {
			return true;
		}
		$i = $pos - 1;
		while ($i > 0 && (ord($text[$i]) & 0xC0) === 0x80) {
			$i--; // back to the first byte of the previous UTF-8 character
		}
		return !preg_match('/^[\p{L}\p{N}]$/u', substr($text, $i, $pos - $i));
	}

	/** Character offset of the first whitespace in [$from, $to), or null. */
	private static function spaceBetween(string $text, int $from, int $to): ?int {
		for ($i = $from; $i < $to; $i++) {
			if (preg_match('/^\s$/u', mb_substr($text, $i, 1))) {
				return $i;
			}
		}
		return null;
	}

	/** Character offset of the last whitespace in ($after, $from], or null. */
	private static function spaceBefore(string $text, int $from, int $after): ?int {
		for ($i = $from; $i > $after; $i--) {
			if (preg_match('/^\s$/u', mb_substr($text, $i, 1))) {
				return $i;
			}
		}
		return null;
	}
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (93 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Search/Ranker.php tests/unit/Search/RankerTest.php
git commit -m "feat: rank search results and cut snippets"
```

---

### Task 5: Index table and mapper

**Files:**
- Create: `lib/Migration/Version1500Date20261002000000.php`, `lib/Db/SearchEntry.php`, `lib/Db/SearchMapper.php`
- Modify: `lib/Db/Site.php`

- [ ] **Step 1: Migration**

`lib/Migration/Version1500Date20261002000000.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Full-text search index (1.5.0). */
class Version1500Date20261002000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('markdownsite_search')) {
			$t = $schema->createTable('markdownsite_search');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('site_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 1024]);
			$t->addColumn('path_hash', Types::STRING, ['notnull' => true, 'length' => 40]);
			$t->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('title_norm', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('aliases_norm', Types::TEXT, ['notnull' => false]);
			$t->addColumn('headings_norm', Types::TEXT, ['notnull' => false]);
			$t->addColumn('body', Types::TEXT, ['notnull' => false]);
			$t->addColumn('body_norm', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['site_id'], 'mdsite_search_site_idx');
			$t->addUniqueIndex(['site_id', 'path_hash'], 'mdsite_search_path_uq');
		}

		$sites = $schema->getTable('markdownsite_sites');
		if (!$sites->hasColumn('search_etag')) {
			$sites->addColumn('search_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		return $schema;
	}
}
```

- [ ] **Step 2: `searchEtag` on `Site`**

In `lib/Db/Site.php`:

After ` * @method void setCreatedAt(int $v)` add

```php
 * @method string|null getSearchEtag()
 * @method void setSearchEtag(?string $v)
```

After `	protected int $createdAt = 0;` add

```php
	protected ?string $searchEtag = null;
```

After `		$this->addType('createdAt', 'integer');` add

```php
		$this->addType('searchEtag', 'string');
```

(`toArray()` stays as it is: the etag is not exposed.)

- [ ] **Step 3: Entity**

`lib/Db/SearchEntry.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One indexed page.
 *
 * @method int getSiteId()
 * @method void setSiteId(int $v)
 * @method string getPath()
 * @method void setPath(string $v)
 * @method string getPathHash()
 * @method void setPathHash(string $v)
 * @method string getEtag()
 * @method void setEtag(string $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method string getTitleNorm()
 * @method void setTitleNorm(string $v)
 * @method string|null getAliasesNorm()
 * @method void setAliasesNorm(?string $v)
 * @method string|null getHeadingsNorm()
 * @method void setHeadingsNorm(?string $v)
 * @method string|null getBody()
 * @method void setBody(?string $v)
 * @method string|null getBodyNorm()
 * @method void setBodyNorm(?string $v)
 */
class SearchEntry extends Entity {
	protected int $siteId = 0;
	protected string $path = '';
	protected string $pathHash = '';
	protected string $etag = '';
	protected string $title = '';
	protected string $titleNorm = '';
	protected ?string $aliasesNorm = null;
	protected ?string $headingsNorm = null;
	protected ?string $body = null;
	protected ?string $bodyNorm = null;

	public function __construct() {
		$this->addType('siteId', 'integer');
		$this->addType('path', 'string');
		$this->addType('pathHash', 'string');
		$this->addType('etag', 'string');
		$this->addType('title', 'string');
		$this->addType('titleNorm', 'string');
		$this->addType('aliasesNorm', 'string');
		$this->addType('headingsNorm', 'string');
		$this->addType('body', 'string');
		$this->addType('bodyNorm', 'string');
	}
}
```

- [ ] **Step 4: Mapper**

`lib/Db/SearchMapper.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<SearchEntry> */
class SearchMapper extends QBMapper {
	/** Most candidate rows a query fetches before ranking. */
	public const MAX_CANDIDATES = 500;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'markdownsite_search', SearchEntry::class);
	}

	/**
	 * Stored etag per path, without loading page text.
	 * @return array<string,string> path => etag
	 */
	public function etagsBySite(int $siteId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('path', 'etag')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		$out = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$out[(string) $row['path']] = (string) $row['etag'];
		}
		$result->closeCursor();
		return $out;
	}

	/** Inserts the page, or replaces the row already stored for its path. */
	public function upsert(SearchEntry $entry): void {
		$entry->setPathHash(sha1($entry->getPath()));
		$existing = $this->findId($entry->getSiteId(), $entry->getPathHash());
		if ($existing !== null) {
			$entry->setId($existing);
			$this->update($entry);
			return;
		}
		try {
			$this->insert($entry);
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// A concurrent request indexed the same page first: overwrite it.
			$entry->setId((int) $this->findId($entry->getSiteId(), $entry->getPathHash()));
			$this->update($entry);
		}
	}

	/** @param string[] $paths */
	public function deletePaths(int $siteId, array $paths): void {
		foreach (array_chunk($paths, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('path_hash', $qb->createNamedParameter(array_map('sha1', $chunk), IQueryBuilder::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function deleteBySite(int $siteId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Rows of $siteIds where every term occurs in the title, an alias, a
	 * heading or the body (normalised columns). At most MAX_CANDIDATES rows.
	 *
	 * @param int[] $siteIds
	 * @param list<string> $terms normalised terms
	 * @return list<array<string,mixed>>
	 */
	public function candidates(array $siteIds, array $terms): array {
		if ($siteIds === [] || $terms === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('site_id', 'path', 'title', 'title_norm', 'aliases_norm', 'headings_norm', 'body', 'body_norm')
			->from($this->getTableName())
			->where($qb->expr()->in('site_id', $qb->createNamedParameter($siteIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->setMaxResults(self::MAX_CANDIDATES);
		foreach ($terms as $term) {
			$like = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%');
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->like('title_norm', $like),
				$qb->expr()->like('aliases_norm', $like),
				$qb->expr()->like('headings_norm', $like),
				$qb->expr()->like('body_norm', $like),
			));
		}
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	private function findId(int $siteId, string $pathHash): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('site_id', $qb->createNamedParameter($siteId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter($pathHash)));
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id === false ? null : (int) $id;
	}
}
```

- [ ] **Step 5: Lint and tests**

Run: `composer run lint && vendor/bin/phpunit`
Expected: lint exit code 0, `OK (93 tests, …)`.

- [ ] **Step 6: Commit**

```bash
git add lib/Migration/Version1500Date20261002000000.php lib/Db/Site.php lib/Db/SearchEntry.php lib/Db/SearchMapper.php
git commit -m "feat: search index table"
```

---

### Task 6: `listMarkdownEtags()` and `SearchIndexer`

**Files:**
- Modify: `lib/Service/ContentService.php`, `tests/unit/Service/ContentServiceTest.php`
- Create: `tests/unit/Service/SearchIndexerTest.php`, `lib/Service/SearchIndexer.php`

- [ ] **Step 1: Write the failing tests**

In `tests/unit/Service/ContentServiceTest.php`, add before the class's closing `}`:

```php
	public function testListMarkdownEtagsWalksFoldersWithoutReadingFiles(): void {
		$page = fn (string $name, string $etag) => $this->fileWithEtag($name, $etag);
		$deep = $this->folder('Deep', [$page('Inner.md', 'e3')]);
		$root = $this->folder('Wiki', [$page('Home.md', 'e1'), $deep, $page('image.png', 'e9'), $page('.draft.md', 'e8')]);
		$this->assertSame(['Home.md' => 'e1', 'Deep/Inner.md' => 'e3'], $this->content()->listMarkdownEtags($root));
		$this->assertSame(['Home.md', 'Deep/Inner.md'], $this->content()->listMarkdownPaths($root));
	}

	private function fileWithEtag(string $name, string $etag): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getEtag')->willReturn($etag);
		$file->expects($this->never())->method('getContent');
		return $file;
	}
```

Create `tests/unit/Service/SearchIndexerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Service;

use OCA\MarkdownSite\Db\SearchEntry;
use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchIndexerTest extends TestCase {
	private ContentService&MockObject $content;
	private SearchMapper&MockObject $index;
	private SiteMapper&MockObject $sites;
	private Folder&MockObject $root;
	private Site $site;

	protected function setUp(): void {
		$this->content = $this->createMock(ContentService::class);
		$this->index = $this->createMock(SearchMapper::class);
		$this->sites = $this->createMock(SiteMapper::class);
		$this->root = $this->createMock(Folder::class);
		$this->root->method('getEtag')->willReturn('root-2');
		$this->site = new Site();
		$this->site->setId(7);
		$this->site->setSearchEtag('root-1');
	}

	private function indexer(): SearchIndexer {
		return new SearchIndexer($this->content, $this->index, $this->sites);
	}

	private function file(string $content, string $etag): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getEtag')->willReturn($etag);
		return $file;
	}

	public function testUnchangedRootReadsNothing(): void {
		$this->site->setSearchEtag('root-2');
		$this->content->expects($this->never())->method('listMarkdownEtags');
		$this->content->expects($this->never())->method('getChild');
		$this->index->expects($this->never())->method('upsert');
		$this->indexer()->refresh($this->site, $this->root);
	}

	public function testIndexesAddedPageAndStoresRootEtag(): void {
		$this->content->method('listMarkdownEtags')->willReturn(['Guides/Café.md' => 'e1']);
		$this->index->method('etagsBySite')->with(7)->willReturn([]);
		$this->content->method('getChild')->with($this->root, 'Guides/Café.md')
			->willReturn($this->file("# Café crème\n\nUn **bon** café.", 'e1'));
		$this->index->expects($this->once())->method('upsert')->with($this->callback(
			fn (SearchEntry $e) => $e->getSiteId() === 7
				&& $e->getPath() === 'Guides/Café.md'
				&& $e->getEtag() === 'e1'
				&& $e->getTitle() === 'Café crème'
				&& $e->getTitleNorm() === 'cafe creme'
				&& $e->getHeadingsNorm() === 'cafe creme'
				&& $e->getBody() === "Café crème\n\nUn bon café."
				&& $e->getBodyNorm() === "cafe creme\n\nun bon cafe.",
		));
		$this->sites->expects($this->once())->method('update')->with($this->site);
		$this->indexer()->refresh($this->site, $this->root);
		$this->assertSame('root-2', $this->site->getSearchEtag());
	}

	public function testReindexesOnlyModifiedPages(): void {
		$this->content->method('listMarkdownEtags')->willReturn(['A.md' => 'same', 'B.md' => 'new']);
		$this->index->method('etagsBySite')->willReturn(['A.md' => 'same', 'B.md' => 'old']);
		$this->content->expects($this->once())->method('getChild')->with($this->root, 'B.md')
			->willReturn($this->file('B text', 'new'));
		$this->index->expects($this->once())->method('upsert');
		$this->index->expects($this->never())->method('deletePaths');
		$this->indexer()->refresh($this->site, $this->root);
	}

	public function testDeletesRowsOfRemovedPages(): void {
		$this->content->method('listMarkdownEtags')->willReturn(['A.md' => 'same']);
		$this->index->method('etagsBySite')->willReturn(['A.md' => 'same', 'Gone.md' => 'x']);
		$this->content->expects($this->never())->method('getChild');
		$this->index->expects($this->once())->method('deletePaths')->with(7, ['Gone.md']);
		$this->indexer()->refresh($this->site, $this->root);
	}

	public function testIndexPageRemovesAMissingPage(): void {
		$this->content->method('getChild')->willThrowException(new NotFoundException('x'));
		$this->index->expects($this->once())->method('deletePaths')->with(7, ['Old.md']);
		$this->indexer()->indexPage($this->site, $this->root, 'Old.md');
	}

	public function testTitleFallsBackToFileName(): void {
		$this->content->method('getChild')->willReturn($this->file('no heading here', 'e'));
		$this->index->expects($this->once())->method('upsert')->with($this->callback(
			fn (SearchEntry $e) => $e->getTitle() === 'Meeting notes',
		));
		$this->indexer()->indexPage($this->site, $this->root, 'Notes/Meeting notes.md');
	}
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --filter 'ContentServiceTest|SearchIndexerTest'`
Expected: FAIL — `Call to undefined method …ContentService::listMarkdownEtags()` and `Class "OCA\MarkdownSite\Service\SearchIndexer" not found`.

- [ ] **Step 3: `listMarkdownEtags()`**

In `lib/Service/ContentService.php`, replace the whole `listMarkdownPaths()` method (from its `/** All .md paths under root` docblock to the end of the method) with:

```php
	/** All .md paths under root, relative to root. @return string[] */
	public function listMarkdownPaths(Folder $root, string $rel = ''): array {
		return array_map('strval', array_keys($this->listMarkdownEtags($root, $rel)));
	}

	/**
	 * All .md files under root with their etag, read from file metadata: no
	 * file is opened. @return array<string,string> path relative to root => etag
	 */
	public function listMarkdownEtags(Folder $root, string $rel = ''): array {
		$base = $rel === '' ? $root : $root->get($rel);
		if (!($base instanceof Folder)) {
			return [];
		}
		return $this->collectMarkdown($base, $rel);
	}

	/** @return array<string,string> */
	private function collectMarkdown(Folder $base, string $rel): array {
		$out = [];
		foreach ($base->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (str_starts_with($name, '.')) {
				continue;
			}
			$path = $rel === '' ? $name : $rel . '/' . $name;
			if ($node instanceof Folder) {
				$out += $this->collectMarkdown($node, $path);
			} elseif (preg_match('/\.md$/i', $name)) {
				$out[$path] = $node->getEtag();
			}
		}
		return $out;
	}
```

- [ ] **Step 4: `SearchIndexer`**

`lib/Service/SearchIndexer.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\SearchEntry;
use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Search\Normalizer;
use OCA\MarkdownSite\Search\PlainText;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/** Keeps markdownsite_search in step with a site's files. */
class SearchIndexer {
	public function __construct(
		private ContentService $content,
		private SearchMapper $index,
		private SiteMapper $sites,
	) {
	}

	/**
	 * Brings the site's index up to date. Pages are only read when their etag
	 * changed; nothing is read when the root folder's etag did not change.
	 */
	public function refresh(Site $site, Folder $root): void {
		$rootEtag = $root->getEtag();
		if ($rootEtag === $site->getSearchEtag()) {
			return;
		}
		$current = $this->content->listMarkdownEtags($root);
		$stored = $this->index->etagsBySite($site->getId());
		foreach ($current as $path => $etag) {
			if (($stored[$path] ?? null) !== $etag) {
				$this->indexPage($site, $root, (string) $path);
			}
		}
		$gone = array_diff_key($stored, $current);
		if ($gone !== []) {
			$this->index->deletePaths($site->getId(), array_map('strval', array_keys($gone)));
		}
		$site->setSearchEtag($rootEtag);
		$this->sites->update($site);
	}

	/** (Re)indexes one page; removes it from the index when it no longer exists. */
	public function indexPage(Site $site, Folder $root, string $path): void {
		try {
			$file = $this->content->getChild($root, $path);
		} catch (NotFoundException) {
			$this->removePage($site, $path);
			return;
		}
		if (!$file instanceof File) {
			return;
		}
		$text = PlainText::fromMarkdown($file->getContent(), preg_replace('/\.md$/i', '', basename($path)) ?? $path);
		$entry = new SearchEntry();
		$entry->setSiteId($site->getId());
		$entry->setPath($path);
		$entry->setEtag($file->getEtag());
		$entry->setTitle(mb_substr($text['title'], 0, 255));
		$entry->setTitleNorm(mb_substr(Normalizer::normalize($text['title']), 0, 255));
		$entry->setAliasesNorm(Normalizer::normalize(implode(' ', $text['aliases'])));
		$entry->setHeadingsNorm(Normalizer::normalize(implode("\n", $text['headings'])));
		$entry->setBody($text['body']);
		$entry->setBodyNorm(Normalizer::normalize($text['body']));
		$this->index->upsert($entry);
	}

	public function removePage(Site $site, string $path): void {
		$this->index->deletePaths($site->getId(), [$path]);
	}
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (100 tests, …)`.

- [ ] **Step 6: Commit**

```bash
git add lib/Service/ContentService.php lib/Service/SearchIndexer.php tests/unit/Service/ContentServiceTest.php tests/unit/Service/SearchIndexerTest.php
git commit -m "feat: refresh the search index by file etag"
```

---

### Task 7: `GET /search`

**Files:**
- Create: `tests/unit/Controller/SearchControllerTest.php`, `lib/Controller/SearchController.php`
- Modify: `appinfo/routes.php`, `lib/Controller/SiteController.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/Controller/SearchControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Controller;

use OCA\MarkdownSite\Controller\SearchController;
use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShare;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Search\Normalizer;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchControllerTest extends TestCase {
	private SiteMapper&MockObject $sites;
	private SiteShareMapper&MockObject $shares;
	private ContentService&MockObject $content;
	private SearchIndexer&MockObject $indexer;
	private SearchMapper&MockObject $index;
	/** @var array<int,SiteShare[]> */
	private array $sharesBySite = [];

	protected function setUp(): void {
		$this->sites = $this->createMock(SiteMapper::class);
		$this->shares = $this->createMock(SiteShareMapper::class);
		$this->shares->method('findBySite')->willReturnCallback(fn (int $id) => $this->sharesBySite[$id] ?? []);
		$this->content = $this->createMock(ContentService::class);
		$this->content->method('resolveRoot')->willReturn($this->createMock(Folder::class));
		$this->indexer = $this->createMock(SearchIndexer::class);
		$this->index = $this->createMock(SearchMapper::class);
	}

	private function controller(): SearchController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['staff']);
		return new SearchController(
			$this->createMock(IRequest::class), $this->sites, $this->shares, new AccessService(),
			$this->content, $this->indexer, $this->index, $session, $groups,
		);
	}

	private function site(int $id, string $owner, string $name = 'Wiki'): Site {
		$site = new Site();
		$site->setId($id);
		$site->setOwnerUid($owner);
		$site->setName($name);
		return $site;
	}

	private function share(int $siteId, string $type, string $with): SiteShare {
		$share = new SiteShare();
		$share->setSiteId($siteId);
		$share->setShareType($type);
		$share->setShareWith($with);
		return $share;
	}

	/** A candidate row as SearchMapper::candidates() returns it. */
	private function row(int $siteId, string $path, string $title, string $body): array {
		return [
			'site_id' => $siteId, 'path' => $path, 'title' => $title,
			'title_norm' => Normalizer::normalize($title), 'aliases_norm' => '', 'headings_norm' => '',
			'body' => $body, 'body_norm' => Normalizer::normalize($body),
		];
	}

	public function testAllMySitesNeverIncludesASiteWithoutMatchingShare(): void {
		$own = $this->site(1, 'bob');
		$shared = $this->site(2, 'alice');
		$this->sharesBySite[2] = [$this->share(2, 'group', 'staff')];
		$stranger = $this->site(3, 'carol');
		$this->sharesBySite[3] = [$this->share(3, 'user', 'dave')];
		$this->sites->method('findVisible')->willReturn([$own, $shared, $stranger]);
		$this->index->expects($this->once())->method('candidates')->with([1, 2], ['vault'])->willReturn([]);
		$this->indexer->expects($this->exactly(2))->method('refresh');
		$this->controller()->search('vault');
	}

	public function testOneSiteWithoutAccessIsForbidden(): void {
		$this->sites->method('find')->with(3)->willReturn($this->site(3, 'carol'));
		$this->index->expects($this->never())->method('candidates');
		$response = $this->controller()->search('vault', 3);
		$this->assertSame(403, $response->getStatus());
	}

	public function testTooShortQueryReturnsNothingWithoutIndexing(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$this->indexer->expects($this->never())->method('refresh');
		$response = $this->controller()->search('a', 1);
		$this->assertSame(['results' => [], 'total' => 0, 'offset' => 0], $response->getData());
	}

	public function testResultsAreRankedAndShaped(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob', 'Docs'));
		$this->index->method('candidates')->willReturn([
			$this->row(1, 'Notes/Misc.md', 'Misc', 'The vault is mentioned here.'),
			$this->row(1, 'Vault.md', 'Vault', 'All about it.'),
		]);
		$data = $this->controller()->search('Vault', 1)->getData();
		$this->assertSame(2, $data['total']);
		$this->assertSame('Vault.md', $data['results'][0]['path']);
		$this->assertSame([
			'siteId' => 1,
			'siteName' => 'Docs',
			'path' => 'Notes/Misc.md',
			'title' => 'Misc',
			'folder' => 'Notes',
			'snippet' => 'The vault is mentioned here.',
			'highlights' => [[4, 5]],
		], $data['results'][1]);
	}

	public function testPagesOfFifty(): void {
		$this->sites->method('find')->willReturn($this->site(1, 'bob'));
		$rows = [];
		for ($i = 0; $i < 60; $i++) {
			$rows[] = $this->row(1, "P$i.md", sprintf('Page %02d', $i), 'vault');
		}
		$this->index->method('candidates')->willReturn($rows);
		$first = $this->controller()->search('vault', 1)->getData();
		$this->assertCount(50, $first['results']);
		$this->assertSame(60, $first['total']);
		$second = $this->controller()->search('vault', 1, 50)->getData();
		$this->assertCount(10, $second['results']);
		$this->assertSame('Page 50', $second['results'][0]['title']);
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter SearchControllerTest`
Expected: 5 errors, `Class "OCA\MarkdownSite\Controller\SearchController" not found`.

- [ ] **Step 3: Write the controller**

`lib/Controller/SearchController.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Controller;

use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Db\SiteShareMapper;
use OCA\MarkdownSite\Search\QueryParser;
use OCA\MarkdownSite\Search\Ranker;
use OCA\MarkdownSite\Service\AccessService;
use OCA\MarkdownSite\Service\ContentService;
use OCA\MarkdownSite\Service\SearchIndexer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class SearchController extends Controller {
	public const PAGE_SIZE = 50;

	public function __construct(
		IRequest $request,
		private SiteMapper $sites,
		private SiteShareMapper $shareMapper,
		private AccessService $access,
		private ContentService $content,
		private SearchIndexer $indexer,
		private SearchMapper $index,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct('markdownsite', $request);
	}

	/**
	 * Full-text search in one site ($site) or in every site the user can
	 * read (no $site). 50 results from $offset, best first.
	 */
	#[NoAdminRequired]
	public function search(string $q = '', ?int $site = null, int $offset = 0): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}
		$uid = $user->getUID();
		$groups = $this->groupManager->getUserGroupIds($user);

		if ($site !== null) {
			$one = $this->sites->find($site);
			if ($one === null) {
				return new JSONResponse(['error' => 'site-not-found'], 404);
			}
			$candidates = [$one];
		} else {
			$candidates = $this->sites->findVisible($uid, $groups);
		}
		// findVisible() already filters by share; checking again keeps one rule for both cases.
		$readable = array_values(array_filter(
			$candidates,
			fn (Site $s) => $this->access->canView($s, $uid, $groups, $this->shareMapper->findBySite($s->getId())),
		));
		if ($site !== null && $readable === []) {
			return new JSONResponse(['error' => 'forbidden'], 403);
		}

		$terms = QueryParser::parse($q);
		if ($terms === []) {
			return new JSONResponse(['results' => [], 'total' => 0, 'offset' => 0]);
		}

		$byId = [];
		foreach ($readable as $s) {
			$root = $this->content->resolveRoot($s);
			if ($root === null) {
				continue; // folder deleted or no longer reachable
			}
			$this->indexer->refresh($s, $root);
			$byId[$s->getId()] = $s;
		}

		$ranked = [];
		foreach ($this->index->candidates(array_keys($byId), $terms) as $row) {
			$ranked[] = ['row' => $row, 'score' => Ranker::score($terms, [
				'title_norm' => (string) $row['title_norm'],
				'aliases_norm' => (string) ($row['aliases_norm'] ?? ''),
				'headings_norm' => (string) ($row['headings_norm'] ?? ''),
				'body_norm' => (string) ($row['body_norm'] ?? ''),
			])];
		}
		usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']
			?: strcasecmp((string) $a['row']['title'], (string) $b['row']['title']));

		$offset = max(0, $offset);
		$results = [];
		foreach (array_slice($ranked, $offset, self::PAGE_SIZE) as $item) {
			$row = $item['row'];
			$siteId = (int) $row['site_id'];
			$path = (string) $row['path'];
			$dir = dirname($path);
			$snippet = Ranker::snippet((string) ($row['body'] ?? ''), (string) ($row['body_norm'] ?? ''), $terms);
			$results[] = [
				'siteId' => $siteId,
				'siteName' => $byId[$siteId]->getName(),
				'path' => $path,
				'title' => (string) $row['title'],
				'folder' => $dir === '.' ? '' : $dir,
				'snippet' => $snippet['snippet'],
				'highlights' => $snippet['highlights'],
			];
		}
		return new JSONResponse(['results' => $results, 'total' => count($ranked), 'offset' => $offset]);
	}
}
```

- [ ] **Step 4: Route**

In `appinfo/routes.php`, after the `preferences#update` line add:

```php
		['name' => 'search#search', 'url' => '/search', 'verb' => 'GET'],
```

- [ ] **Step 5: Delete index rows with the site**

In `lib/Controller/SiteController.php`:

Add `use OCA\MarkdownSite\Db\SearchMapper;` before `use OCA\MarkdownSite\Db\Site;`.

Replace

```php
		private IRootFolder $rootFolder,
	) {
```

with

```php
		private IRootFolder $rootFolder,
		private SearchMapper $searchIndex,
	) {
```

In `destroy()`, replace

```php
		$this->shares->deleteBySite($id);
		$this->sites->delete($site);
```

with

```php
		$this->shares->deleteBySite($id);
		$this->searchIndex->deleteBySite($id);
		$this->sites->delete($site);
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit && composer run lint`
Expected: `OK (105 tests, …)`, lint exit code 0.

- [ ] **Step 7: Commit**

```bash
git add lib/Controller/SearchController.php tests/unit/Controller/SearchControllerTest.php appinfo/routes.php lib/Controller/SiteController.php
git commit -m "feat: search endpoint over one site or all readable sites"
```

---

### Task 8: `searchScope` preference

**Files:**
- Modify: `lib/Controller/PreferencesController.php`, `tests/unit/Controller/PreferencesControllerTest.php`, `src/stores/prefs.js`

- [ ] **Step 1: Write the failing tests**

In `tests/unit/Controller/PreferencesControllerTest.php`, add before the class's closing `}`:

```php
	public function testSearchScopeDefaultsToSite(): void {
		$this->assertSame('site', $this->controller()->index()->getData()['searchScope']);
	}

	public function testSearchScopeIsSavedAndValidated(): void {
		$this->assertSame('all', $this->controller()->update('', true, true, true, false, 'all')->getData()['searchScope']);
		$this->assertSame('site', $this->controller()->update('', true, true, true, false, 'everything')->getData()['searchScope']);
	}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --filter PreferencesControllerTest`
Expected: FAIL — 2 failures, with PHP warnings `Undefined array key "searchScope"`.

- [ ] **Step 3: Store the preference**

In `lib/Controller/PreferencesController.php`:

In the `update()` signature, replace `bool $tocCollapsed = false): JSONResponse {` with `bool $tocCollapsed = false, string $searchScope = 'site'): JSONResponse {`.

After `$this->config->setUserValue($uid, self::APP, 'toc_collapsed', $tocCollapsed ? '1' : '0');` add

```php
		$this->config->setUserValue($uid, self::APP, 'search_scope', $searchScope === 'all' ? 'all' : 'site');
```

In the `read()` docblock, replace `tocCollapsed:bool} */` with `tocCollapsed:bool,searchScope:string} */`, and after the `'tocCollapsed' => …,` line add

```php
			'searchScope' => $this->config->getUserValue($uid, self::APP, 'search_scope', 'site') === 'all' ? 'all' : 'site',
```

- [ ] **Step 4: Store it on the client**

In `src/stores/prefs.js`, add `searchScope: 'site',` after `tocCollapsed: false,` in `state`, and `searchScope: this.searchScope,` after `tocCollapsed: this.tocCollapsed,` in `save()`.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit && npm run lint`
Expected: `OK (107 tests, …)`, lint exit code 0.

- [ ] **Step 6: Commit**

```bash
git add lib/Controller/PreferencesController.php tests/unit/Controller/PreferencesControllerTest.php src/stores/prefs.js
git commit -m "feat: remember the search scope"
```

---

### Task 9: Search text helpers (JavaScript)

**Files:**
- Create: `tests/js/searchText.test.js`, `src/services/searchText.js`

- [ ] **Step 1: Write the failing test**

`tests/js/searchText.test.js`:

```js
import { describe, expect, it } from 'vitest'
import { normalize, parseQuery, snippetParts } from '../../src/services/searchText.js'

describe('normalize', () => {
	it('matches the server: lower case, no accents, same length', () => {
		expect(normalize('Été à Paris, Straße, Œuvre, Łódź')).toBe('ete a paris, strase, ouvre, lodz')
		for (const text of ['İstanbul', 'naïve café 😀 日本', 'ĳ ŉ ſ']) {
			expect(normalize(text).length).toBe(text.length)
		}
	})
})

describe('parseQuery', () => {
	it('splits words, keeps quoted phrases, drops short and repeated terms', () => {
		expect(parseQuery('  Café "Mot  de passe" a x café')).toEqual(['cafe', 'mot de passe'])
		expect(parseQuery('nextcloud "talk app')).toEqual(['nextcloud', 'talk app'])
		expect(parseQuery('')).toEqual([])
	})
})

describe('snippetParts', () => {
	it('splits a snippet on highlight offsets', () => {
		expect(snippetParts('…the Café crème…', [[5, 4], [10, 5]])).toEqual([
			{ text: '…the ', hit: false },
			{ text: 'Café', hit: true },
			{ text: ' ', hit: false },
			{ text: 'crème', hit: true },
			{ text: '…', hit: false },
		])
	})

	it('counts offsets in code points, not UTF-16 units', () => {
		expect(snippetParts('😀 vault', [[2, 5]])).toEqual([
			{ text: '😀 ', hit: false },
			{ text: 'vault', hit: true },
		])
	})

	it('returns the whole text without highlights', () => {
		expect(snippetParts('plain', [])).toEqual([{ text: 'plain', hit: false }])
	})
})
```

- [ ] **Step 2: Run it to see it fail**

Run: `npm test`
Expected: FAIL — `Cannot find module '../../src/services/searchText.js'`.

- [ ] **Step 3: Write the helpers**

`src/services/searchText.js`:

```js
/*
 * Search text helpers, mirroring lib/Search/Normalizer.php and
 * lib/Search/QueryParser.php so the page highlights what the server matched.
 */

// Lower-case Latin-1 Supplement and Latin Extended-A letters => base letter.
const BASE = {
	'ß': 's', 'à': 'a', 'á': 'a', 'â': 'a', 'ã': 'a', 'ä': 'a', 'å': 'a', 'æ': 'a',
	'ç': 'c', 'è': 'e', 'é': 'e', 'ê': 'e', 'ë': 'e', 'ì': 'i', 'í': 'i', 'î': 'i',
	'ï': 'i', 'ð': 'd', 'ñ': 'n', 'ò': 'o', 'ó': 'o', 'ô': 'o', 'õ': 'o', 'ö': 'o',
	'ø': 'o', 'ù': 'u', 'ú': 'u', 'û': 'u', 'ü': 'u', 'ý': 'y', 'þ': 't', 'ÿ': 'y',
	'ā': 'a', 'ă': 'a', 'ą': 'a', 'ć': 'c', 'ĉ': 'c', 'ċ': 'c', 'č': 'c', 'ď': 'd',
	'đ': 'd', 'ē': 'e', 'ĕ': 'e', 'ė': 'e', 'ę': 'e', 'ě': 'e', 'ĝ': 'g', 'ğ': 'g',
	'ġ': 'g', 'ģ': 'g', 'ĥ': 'h', 'ħ': 'h', 'ĩ': 'i', 'ī': 'i', 'ĭ': 'i', 'į': 'i',
	'ı': 'i', 'ĳ': 'i', 'ĵ': 'j', 'ķ': 'k', 'ĸ': 'k', 'ĺ': 'l', 'ļ': 'l', 'ľ': 'l',
	'ŀ': 'l', 'ł': 'l', 'ń': 'n', 'ņ': 'n', 'ň': 'n', 'ŉ': 'n', 'ŋ': 'n', 'ō': 'o',
	'ŏ': 'o', 'ő': 'o', 'œ': 'o', 'ŕ': 'r', 'ŗ': 'r', 'ř': 'r', 'ś': 's', 'ŝ': 's',
	'ş': 's', 'š': 's', 'ţ': 't', 'ť': 't', 'ŧ': 't', 'ũ': 'u', 'ū': 'u', 'ŭ': 'u',
	'ů': 'u', 'ű': 'u', 'ų': 'u', 'ŵ': 'w', 'ŷ': 'y', 'ź': 'z', 'ż': 'z', 'ž': 'z',
	'ſ': 's',
}

const MIN_TERM_LENGTH = 2

/**
 * Lower case, accents and ligatures folded to their base letter. One code
 * point in, one code point out, and each keeps its UTF-16 length, so string
 * indices in the result are valid in the original.
 */
export function normalize(text) {
	let out = ''
	for (const ch of String(text)) {
		// Simple case mapping: keep one code point ("İ" would become two).
		const lower = ch.toLowerCase()
		const one = lower.length === ch.length ? lower : String.fromCodePoint(lower.codePointAt(0))
		out += BASE[one] || one
	}
	return out
}

/** Normalised terms of a query: words, or "quoted phrases"; terms shorter than 2 dropped. */
export function parseQuery(query) {
	const terms = []
	for (const match of String(query).matchAll(/"([^"]*)"?|(\S+)/gu)) {
		const raw = match[2] !== undefined ? match[2] : match[1]
		const term = normalize(raw).replace(/\s+/gu, ' ').trim()
		if ([...term].length >= MIN_TERM_LENGTH && !terms.includes(term)) {
			terms.push(term)
		}
	}
	return terms
}

/**
 * Splits a result snippet into plain and highlighted parts. `highlights` are
 * [start, length] pairs in code points (from the server).
 *
 * @return {Array<{text: string, hit: boolean}>}
 */
export function snippetParts(snippet, highlights) {
	const chars = Array.from(snippet)
	const parts = []
	let at = 0
	for (const [start, length] of highlights || []) {
		if (start < at || start >= chars.length) {
			continue
		}
		if (start > at) {
			parts.push({ text: chars.slice(at, start).join(''), hit: false })
		}
		parts.push({ text: chars.slice(start, start + length).join(''), hit: true })
		at = start + length
	}
	if (at < chars.length) {
		parts.push({ text: chars.slice(at).join(''), hit: false })
	}
	return parts
}
```

- [ ] **Step 4: Run the tests**

Run: `npm test && npm run lint`
Expected: `Tests  37 passed (37)`, lint exit code 0.

- [ ] **Step 5: Commit**

```bash
git add tests/js/searchText.test.js src/services/searchText.js
git commit -m "feat: client-side query parsing and snippet parts"
```

---

### Task 10: Highlight terms on the opened page

**Files:**
- Create: `tests/js/searchHighlight.test.js`, `src/services/searchHighlight.js`

- [ ] **Step 1: Write the failing test**

`tests/js/searchHighlight.test.js`:

```js
// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { highlightTerms } from '../../src/services/searchHighlight.js'

function article(html) {
	const el = document.createElement('article')
	el.innerHTML = html
	document.body.replaceChildren(el)
	return el
}

describe('highlightTerms', () => {
	it('wraps matches in text nodes, ignoring case and accents', () => {
		const el = article('<p>Le café et le CAFÉ crème.</p>')
		const first = highlightTerms(el, ['cafe'])
		const marks = [...el.querySelectorAll('mark.mds-search-hit')]
		expect(marks.map(m => m.textContent)).toEqual(['café', 'CAFÉ'])
		expect(first).toBe(marks[0])
		expect(el.textContent).toBe('Le café et le CAFÉ crème.')
	})

	it('wraps link text but never touches attributes', () => {
		const el = article('<p><a href="/apps/x/vault.md" title="vault">Vault page</a></p>')
		highlightTerms(el, ['vault'])
		const a = el.querySelector('a')
		expect(a.getAttribute('href')).toBe('/apps/x/vault.md')
		expect(a.getAttribute('title')).toBe('vault')
		expect(a.querySelector('mark').textContent).toBe('Vault')
	})

	it('skips code blocks and inline code', () => {
		const el = article('<p>vault <code>vault</code></p><pre><code class="language-bash">vault</code></pre>')
		highlightTerms(el, ['vault'])
		expect(el.querySelectorAll('mark').length).toBe(1)
		expect(el.querySelector('pre mark')).toBeNull()
	})

	it('wraps several terms and phrases, merging overlaps', () => {
		const el = article('<p>mot de passe oublié</p>')
		highlightTerms(el, ['mot de passe', 'passe', 'oublie'])
		expect([...el.querySelectorAll('mark')].map(m => m.textContent)).toEqual(['mot de passe', 'oublié'])
	})

	it('returns null when nothing matches', () => {
		expect(highlightTerms(article('<p>nothing</p>'), ['vault'])).toBeNull()
		expect(highlightTerms(article('<p>x</p>'), [])).toBeNull()
	})
})
```

- [ ] **Step 2: Run it to see it fail**

Run: `npm test`
Expected: FAIL — `Failed to resolve import "../../src/services/searchHighlight.js" from "tests/js/searchHighlight.test.js"`.

- [ ] **Step 3: Write the helper**

`src/services/searchHighlight.js`:

```js
import { normalize } from './searchText.js'

// Text inside these is never wrapped: code (highlighting replaces it), diagrams,
// buttons and existing marks.
const SKIP = 'pre, code, script, style, svg, button, mark.mds-search-hit'

/** [start, end) UTF-16 ranges of every term in `text`, sorted, overlaps merged. */
function ranges(text, terms) {
	const found = []
	for (const term of terms) {
		let at = text.indexOf(term)
		while (at !== -1) {
			found.push([at, at + term.length])
			at = text.indexOf(term, at + 1)
		}
	}
	found.sort((a, b) => a[0] - b[0] || b[1] - a[1])
	const merged = []
	for (const range of found) {
		const last = merged[merged.length - 1]
		if (last && range[0] < last[1]) {
			last[1] = Math.max(last[1], range[1])
		} else {
			merged.push(range)
		}
	}
	return merged
}

/**
 * Wraps every occurrence of `terms` (normalised, see parseQuery) in the text
 * of `root` with <mark class="mds-search-hit">. Matching ignores case and
 * accents. Returns the first mark, or null.
 */
export function highlightTerms(root, terms) {
	if (!root || !terms || !terms.length) {
		return null
	}
	const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
		acceptNode: node => (node.parentElement?.closest(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
	})
	const nodes = []
	while (walker.nextNode()) {
		nodes.push(walker.currentNode)
	}
	for (const node of nodes) {
		const found = ranges(normalize(node.data), terms)
		// From the end, so earlier offsets stay valid while splitting.
		for (let i = found.length - 1; i >= 0; i--) {
			const [start, end] = found[i]
			const hit = node.splitText(start)
			hit.splitText(end - start)
			const mark = document.createElement('mark')
			mark.className = 'mds-search-hit'
			hit.replaceWith(mark)
			mark.appendChild(hit)
		}
	}
	return root.querySelector('mark.mds-search-hit')
}
```

- [ ] **Step 4: Run the tests**

Run: `npm test && npm run lint`
Expected: `Test Files  10 passed (10)`, `Tests  42 passed (42)`, lint exit code 0.

- [ ] **Step 5: Commit**

```bash
git add tests/js/searchHighlight.test.js src/services/searchHighlight.js
git commit -m "feat: mark search terms in the page"
```

---

### Task 11: `SearchPanel`

**Files:**
- Modify: `src/services/api.js`
- Create: `src/components/SearchPanel.vue`

- [ ] **Step 1: API call**

Append to `src/services/api.js`:

```js
export const searchPages = (params, signal) => axios.get(base('/search'), { params, signal }).then(r => r.data)
```

- [ ] **Step 2: Write the component**

`src/components/SearchPanel.vue`:

```vue
<template>
	<div class="mds-search">
		<NcTextField ref="field"
			v-model="query"
			class="mds-search-field"
			:label="t('markdownsite', 'Search')"
			:placeholder="t('markdownsite', 'Search (Ctrl+Shift+F)')"
			trailing-button-icon="close"
			:trailing-button-label="t('markdownsite', 'Clear search')"
			:show-trailing-button="query !== ''"
			@trailing-button-click="clear"
			@keydown.esc.prevent="clear" />

		<template v-if="active">
			<div class="mds-search-scope" role="radiogroup" :aria-label="t('markdownsite', 'Search in')">
				<NcCheckboxRadioSwitch type="radio" name="mds-search-scope" value="site"
					button-variant button-variant-grouped="horizontal"
					:model-value="prefs.searchScope" @update:model-value="setScope">
					{{ t('markdownsite', 'This site') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch type="radio" name="mds-search-scope" value="all"
					button-variant button-variant-grouped="horizontal"
					:model-value="prefs.searchScope" @update:model-value="setScope">
					{{ t('markdownsite', 'All my sites') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="loading && !results.length" class="mds-search-state">
				<NcLoadingIcon :size="20" />
				<span>{{ slow ? t('markdownsite', 'Indexing…') : t('markdownsite', 'Searching…') }}</span>
			</div>
			<p v-else-if="error" class="mds-search-state mds-search-state--error">
				{{ t('markdownsite', 'Search failed. Try again.') }}
			</p>
			<p v-else-if="!terms.length" class="mds-search-state">
				{{ t('markdownsite', 'Type at least 2 characters.') }}
			</p>
			<p v-else-if="!results.length" class="mds-search-state">
				{{ t('markdownsite', 'No results') }}
			</p>

			<div v-for="group in groups" :key="group.siteId" class="mds-search-group">
				<h3 v-if="scope === 'all'" class="mds-search-site">{{ group.siteName }}</h3>
				<ul class="mds-search-results">
					<li v-for="result in group.results" :key="result.siteId + ':' + result.path">
						<RouterLink class="mds-search-result" :to="routeTo(result)">
							<span class="mds-search-title">{{ result.title }}</span>
							<span v-if="result.folder" class="mds-search-folder">{{ result.folder }}</span>
							<span class="mds-search-snippet">
								<template v-for="(part, i) in parts(result)" :key="i">
									<mark v-if="part.hit">{{ part.text }}</mark>
									<template v-else>{{ part.text }}</template>
								</template>
							</span>
						</RouterLink>
					</li>
				</ul>
			</div>

			<NcButton v-if="results.length < total" class="mds-search-more" variant="tertiary" wide
				:disabled="loading" @click="more">
				{{ t('markdownsite', 'More results') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'
import { searchPages } from '../services/api.js'
import { parseQuery, snippetParts } from '../services/searchText.js'
import { usePrefsStore } from '../stores/prefs.js'

const DEBOUNCE_MS = 300
const SLOW_MS = 1000

export default {
	name: 'SearchPanel',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcTextField },
	props: {
		siteId: { type: [String, Number], default: null },
	},
	emits: ['update:active'],
	setup() { return { prefs: usePrefsStore(), t } },
	data() {
		return { query: '', results: [], total: 0, loading: false, slow: false, error: false }
	},
	computed: {
		active() { return this.query.trim() !== '' },
		terms() { return parseQuery(this.query) },
		scope() { return this.prefs.searchScope === 'all' || !this.siteId ? 'all' : 'site' },
		/** Results grouped by site, groups in order of their best result. */
		groups() {
			const groups = []
			for (const result of this.results) {
				let group = groups.find(g => g.siteId === result.siteId)
				if (!group) {
					group = { siteId: result.siteId, siteName: result.siteName, results: [] }
					groups.push(group)
				}
				group.results.push(result)
			}
			return groups
		},
	},
	watch: {
		query() { this.schedule() },
		active(value) { this.$emit('update:active', value) },
		siteId() { if (this.active && this.scope === 'site') { this.schedule() } },
	},
	mounted() {
		// Capture phase + stopPropagation: Nextcloud's unified search also
		// reacts to Ctrl+(Shift+)F and would take the focus otherwise.
		this.onKeydown = (ev) => {
			if ((ev.ctrlKey || ev.metaKey) && ev.shiftKey && ev.key.toLowerCase() === 'f') {
				ev.preventDefault()
				ev.stopPropagation()
				this.$refs.field?.focus()
			}
		}
		window.addEventListener('keydown', this.onKeydown, true)
	},
	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown, true)
		this.cancel()
	},
	methods: {
		parts(result) { return snippetParts(result.snippet, result.highlights) },
		routeTo(result) {
			return { name: 'page', params: { siteId: result.siteId, path: result.path }, query: { q: this.query.trim() } }
		},
		clear() {
			this.query = ''
		},
		setScope(scope) {
			this.prefs.searchScope = scope
			this.prefs.save()
			this.schedule()
		},
		cancel() {
			clearTimeout(this.timer)
			clearTimeout(this.slowTimer)
			this.controller?.abort()
			this.controller = null
		},
		schedule() {
			this.cancel()
			this.error = false
			if (!this.terms.length) {
				this.results = []
				this.total = 0
				this.loading = false
				return
			}
			this.timer = setTimeout(() => this.run(0), DEBOUNCE_MS)
		},
		more() {
			this.run(this.results.length)
		},
		async run(offset) {
			this.cancel()
			const controller = new AbortController()
			this.controller = controller
			this.loading = true
			this.slow = false
			// The first search on a large site indexes it inside the request.
			this.slowTimer = setTimeout(() => { this.slow = true }, SLOW_MS)
			try {
				const params = { q: this.query.trim(), offset }
				if (this.scope === 'site') {
					params.site = this.siteId
				}
				const data = await searchPages(params, controller.signal)
				this.results = offset === 0 ? data.results : [...this.results, ...data.results]
				this.total = data.total
			} catch (e) {
				if (controller.signal.aborted) {
					return
				}
				this.error = true
			} finally {
				if (this.controller === controller) {
					clearTimeout(this.slowTimer)
					this.loading = false
					this.controller = null
				}
			}
		},
	},
}
</script>

<style scoped>
.mds-search { padding: 0 8px 4px; }
.mds-search-scope { display: flex; margin: 6px 0; }
.mds-search-scope :deep(.checkbox-radio-switch) { flex: 1 1 0; }
.mds-search-state { display: flex; align-items: center; gap: 8px; padding: 8px 4px; color: var(--color-text-maxcontrast); }
.mds-search-state--error { color: var(--color-error); }
.mds-search-site { margin: 10px 4px 2px; font-size: 0.85em; font-weight: 600; color: var(--color-text-maxcontrast); text-transform: uppercase; letter-spacing: 0.04em; }
.mds-search-results { list-style: none; margin: 0; padding: 0; }
.mds-search-result {
	display: flex; flex-direction: column; gap: 2px; padding: 6px 8px; border-radius: var(--border-radius-element, 8px);
	color: var(--color-main-text); text-decoration: none;
}
.mds-search-result:hover, .mds-search-result:focus-visible { background: var(--color-background-hover); }
.mds-search-title { font-weight: 600; }
.mds-search-folder { font-size: 0.8em; color: var(--color-text-maxcontrast); }
.mds-search-snippet { font-size: 0.85em; color: var(--color-text-maxcontrast); line-height: 1.35; overflow-wrap: anywhere; }
.mds-search-snippet mark { background: rgba(255, 208, 0, 0.45); color: var(--color-main-text); border-radius: 2px; }
.mds-search-more { margin-top: 6px; }
</style>
```

- [ ] **Step 3: Lint**

Run: `npm run lint`
Expected: exit code 0.

- [ ] **Step 4: Commit**

```bash
git add src/services/api.js src/components/SearchPanel.vue
git commit -m "feat: search panel in the navigation"
```

---

### Task 12: Wire search into `WikiView`

**Files:**
- Modify: `src/views/WikiView.vue`

- [ ] **Step 1: Panel above the tree**

Replace

```vue
				<SiteSwitcher />
				<PageTree v-if="activeSiteId" :nodes="tree" :site-id="activeSiteId"
					:active-path="prefs.revealActive ? currentPath : ''" />
```

with

```vue
				<SiteSwitcher />
				<SearchPanel :site-id="activeSiteId" @update:active="v => searching = v" />
				<PageTree v-if="activeSiteId && !searching" :nodes="tree" :site-id="activeSiteId"
					:active-path="prefs.revealActive ? currentPath : ''" />
```

- [ ] **Step 2: Imports, registration, state**

After `import { renderDiagrams } from '../services/mermaid.js'` add

```js
import { highlightTerms } from '../services/searchHighlight.js'
import { parseQuery } from '../services/searchText.js'
```

After `import PagePager from '../components/PagePager.vue'` add

```js
import SearchPanel from '../components/SearchPanel.vue'
```

Replace `SiteSwitcher, PageTree, PageToc,` with `SiteSwitcher, SearchPanel, PageTree, PageToc,` in `components`.

In `data()`, replace `html: '', toc: [],` with `html: '', toc: [], searching: false,`.

- [ ] **Step 3: Highlight on open**

In `loadPage()`, replace

```js
			decorateCode(this.$refs.article)
			renderDiagrams(this.$refs.article)
			this.scrollToHash()
		},
```

with

```js
			decorateCode(this.$refs.article)
			renderDiagrams(this.$refs.article)
			// Opened from a search result: mark the terms and show the first one.
			const q = this.$route.query.q
			const hit = q ? highlightTerms(this.$refs.article, parseQuery(String(q))) : null
			if (hit && !this.$route.hash) {
				this.$nextTick(() => hit.scrollIntoView({ block: 'center' }))
			} else {
				this.scrollToHash()
			}
		},
```

- [ ] **Step 4: Mark style**

In the `<style scoped>` block, after the line starting with `.mds-content :deep(mark) {`, add:

```css
.mds-content :deep(mark.mds-search-hit) { background: rgba(255, 140, 0, 0.45); padding: 0; border-radius: 2px; }
```

- [ ] **Step 5: Lint, test, build**

Run: `npm run lint && npm test && npm run build 2>&1 | grep compiled`
Expected: lint exit code 0, `Tests  42 passed (42)`, `compiled with 2 warnings`.

- [ ] **Step 6: Commit**

```bash
git add src/views/WikiView.vue
git commit -m "feat: search from the navigation, highlight terms on the opened page"
```

---

### Task 13: Fixture page

**Files:**
- Create: `tests/fixtures/smoke-site/Guides/Recipes.md`

- [ ] **Step 1: Write the page**

```markdown
---
aliases: [Cooking]
---
# Recipes

## Café crème

Pour a strong espresso, then add hot milk. Searching for "cafe creme" finds
this page even without the accents.

## Crêpes

Mix flour, eggs and milk; rest the batter for an hour.
```

- [ ] **Step 2: Commit**

```bash
git add tests/fixtures/smoke-site/Guides/Recipes.md
git commit -m "test: fixture page for accent-insensitive search"
```

---

### Task 14: Release 1.5.0

**Files:**
- Modify: `appinfo/info.xml`, `CHANGELOG.md`, `README.md`, `js/*`

- [ ] **Step 1: Bump the version**

In `appinfo/info.xml`, replace `<version>1.4.0</version>` with `<version>1.5.0</version>`. The new version makes Nextcloud run the migration when the app is re-enabled.

- [ ] **Step 2: Changelog**

Insert above `## [1.4.0] - 2026-10-02` in `CHANGELOG.md`:

```markdown
## [1.5.0] - 2026-10-02

### Added
- Search: a search field above the page tree finds pages in the current site or in all your sites. All words must appear; case and accents are ignored; `"quoted words"` search for an exact phrase. Results show where the words appear.
- The page opened from a result highlights the words and scrolls to the first one.
- Keyboard shortcut Ctrl+Shift+F (Cmd+Shift+F on a Mac) to search.

### Notes
- The first search on a large site prepares an index and can take a few seconds.

```

- [ ] **Step 3: README features**

In `README.md`, after the line starting with `- **Code and diagrams**`, add:

```markdown
- **Search** — find pages by title, alias, heading or text in one site or all your sites, ignoring case and accents; matches are highlighted in the opened page.
```

- [ ] **Step 4: Build and check everything**

Run: `vendor/bin/phpunit && composer run lint && npm run lint && npm test && npm run build 2>&1 | grep compiled`
Expected: `OK (107 tests, …)`, both linters exit 0, `Tests  42 passed (42)`, `compiled with 2 warnings`.

- [ ] **Step 5: Commit**

```bash
git add appinfo/info.xml CHANGELOG.md README.md js/
git commit -m "release: 1.5.0"
```

---

### Task 15: Manual verification (owner)

À faire par toi, dans cet ordre. Chaque étape prend moins de deux minutes.

1. Demande à Claude, sur ton ordinateur : « lance `scripts/smoke.sh 31` ». Connecte-toi en `admin` (mot de passe dans `.smoke/credentials.txt`) et crée un site à partir du dossier `smoke-site`.
2. Clique dans le champ « Search » en haut à gauche et tape `cafe creme` (sans accents) : la page « Recipes » apparaît à la place de l'arbre, avec les mots surlignés. Clique dessus : la page s'ouvre, les mots « Café crème » sont surlignés en orange.
3. Clique sur une autre page dans l'arbre (efface d'abord la recherche avec la croix) : plus aucun surlignage. Appuie sur Ctrl+Maj+F (Cmd+Maj+F sur Mac) : le curseur va dans le champ de recherche. Tape `"second section"` avec les guillemets : deux pages sortent.
4. Clique sur « All my sites », tape `lorem` : les résultats sont regroupés sous le nom du site. Appuie sur Échap : l'arbre revient.
5. Demande à Claude d'installer la version 1.5.0 sur ton serveur de test (en désactivant puis réactivant l'app, sans `occ upgrade`), puis cherche un mot que tu sais présent dans ton wiki. La première recherche peut prendre quelques secondes. Demande enfin « lance `scripts/smoke.sh stop` ».
