# Navigation: TOC, Breadcrumb, Previous/Next (B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give readers an outline of the current page, their position in the site, and previous/next links, with heading anchors that wikilinks can target.

**Architecture:** The server adds ids to headings (CommonMark `HeadingPermalinkExtension`), keeps link fragments, returns a `toc` array with each page, and marks folder notes in the tree. The frontend adds three components around the article (`PageBreadcrumb`, `PageToc`, `PagePager`), a shared tree-state store, and hash-based scrolling. Pure logic lives in small modules tested with Vitest.

**Tech Stack:** PHP 8.1, league/commonmark 2.8, PHPUnit 10, Vue 3.5, vue-router 4.6 (hash history), Pinia 3, @nextcloud/vue 9.8, Vitest 3.2.

**Spec:** `docs/superpowers/specs/2026-10-02-markdownsite-navigation-design.md`
**Depends on:** plan A (`tests/stubs/OcStubs.php`, working `npm run lint`, `scripts/smoke.sh`, fixture site).

---

## Assumptions and spec corrections

1. **Heading ids carry an `mds-` prefix; links do not.** The spec asks for an empty `id_prefix`. A bare slug collides with Nextcloud's own layout ids: a heading "Content" would become `id="content"`, and Nextcloud styles `#content` (the whole page body). So headings get `id="mds-<slug>"` (`id_prefix: 'mds'`), while link fragments, URLs and the `toc` ids keep the bare slug as the spec describes (`…/page/Page.md#my-heading`). The frontend looks headings up as `mds-<slug>` (falling back to the bare id, so hand-written `[x](#some-id)` still works). This is the same approach GitHub uses (`user-content-`).
2. **Link slugs use the inner `SlugNormalizer`, not the environment's.** CommonMark wraps the configured normalizer in a `UniqueSlugNormalizer` per document. Wikilinks are parsed before headings get their ids, so slugging a link fragment through the environment's normalizer would mark `setup` as used and push the real heading to `setup-1`. `LinkResolver` owns a plain `SlugNormalizer`; `MarkdownRenderer` passes that same instance as `slug_normalizer.instance`, so both use identical rules. A regression test covers this (`testSameNamedLinkDoesNotShiftHeadingId`).
3. **`[[#Heading]]` renders as `href="#heading"`.** The renderer only knows the current directory, not the current page; a same-page anchor is equivalent, and `WikiView::onClick` handles `#…` links (spec section 3). `LinkTarget` gains the kind `anchor`.
4. **Wikilink edge cases.** `[[Page#^block]]` (Obsidian block reference) links to the page without a fragment: blocks have no ids. `[[Page#A#B]]` (nested heading) targets the last part, `B`. Embeds (`![[Page#Heading]]`) are unchanged.
5. **`folderNote()` returns the note's file name** (as stored, e.g. `ReadMe.MD`), not a path; callers prefix the folder path. A sub-folder named `index.md` is not a note.
6. **The root's own note stays in the tree.** The spec removes a note from its folder's children; the site root is not a tree entry, so its home page stays visible at the top level.
7. **Breadcrumb collapse is custom**, not `NcBreadcrumbs`: `NcBreadcrumb` has no click event, and a folder without a note must reveal itself in the tree. Below 1024 px every folder except the last is hidden behind `…`.
8. **Tree open state moves to a Pinia store** (`src/stores/tree.js`). `PageTree` is recursive and kept its open map per level, so nothing outside could reveal a folder. The store is cleared when the site changes.
9. **TOC threshold counts all headings** (`toc.length >= 3`), as the spec says, before level filtering.
10. **Vitest 3.2, not 5.** Vitest 5 requires Node ≥ 22.12; `package.json` declares Node ≥ 20. Tests live in `tests/js/` (outside `src/`, so webpack and ESLint ignore them) and run in Node; nothing in this plan needs a DOM. The script is `vitest run --dir tests/js`: without `--dir`, Vitest also runs the copy of the tests that `scripts/smoke.sh` stages in `.smoke/`.
11. **Scroll offsets.** The navigation toggle button sits in the top-left corner of the content area, so the page layout gets 52 px of left padding.
12. **Same-hash clicks.** Clicking a TOC entry whose hash is already in the URL does not change the route; `goToHeading()` scrolls explicitly after `router.push()`.

Verified while writing this plan on a `nextcloud:31` container with the plan A smoke script and headless Chromium: outline column sticky while scrolling, active entry highlighted, collapse persisted, narrow "On this page" block, breadcrumb, previous/next cards, folder note opening from the tree, `[[Page#Heading]]` and `[[#Heading]]` scrolling to the heading, no console errors.

## File map

| File | Change |
|---|---|
| `package.json`, `package-lock.json` | `vitest` dev dependency, `test` script |
| `src/services/pageOrder.js` + `tests/js/pageOrder.test.js` | New |
| `lib/Wiki/LinkTarget.php` | `fragment` property, `anchor` kind |
| `lib/Wiki/LinkResolver.php` | Keeps fragments, owns the slug normalizer |
| `tests/unit/Wiki/LinkResolverTest.php` | 5 tests added |
| `lib/Wiki/MarkdownRenderer.php` | Heading ids, `toc`, fragment URLs |
| `lib/Wiki/WikilinkInlineParser.php` | Fragment and anchor URLs |
| `tests/unit/Wiki/MarkdownRendererTest.php` | 1 assertion updated, 10 tests added |
| `lib/Service/ContentService.php` | `folderNote()`, notes in `listTree()` |
| `tests/unit/Service/ContentServiceTest.php` | 8 tests added |
| `lib/Controller/PageController.php` | `toc` in the page response, home = root folder note |
| `lib/Controller/PreferencesController.php` + `tests/unit/Controller/PreferencesControllerTest.php` | `tocCollapsed` |
| `src/services/toc.js`, `src/services/breadcrumb.js`, `src/services/anchors.js` + tests | New |
| `src/stores/prefs.js` | `tocCollapsed` |
| `src/stores/tree.js` | New |
| `src/components/PageTree.vue` | Folder notes, shared open state |
| `src/components/PageToc.vue`, `PageBreadcrumb.vue`, `PagePager.vue` | New |
| `src/views/WikiView.vue` | Layout, hash scrolling, anchor clicks |
| `tests/fixtures/smoke-site/**` | Long page, folder note |
| `appinfo/info.xml`, `CHANGELOG.md`, `README.md`, `js/*` | 1.3.0 |

---

### Task 1: Vitest and the reading order helper

**Files:**
- Modify: `package.json`, `package-lock.json`
- Create: `tests/js/pageOrder.test.js`, `src/services/pageOrder.js`

- [ ] **Step 1: Install Vitest and add the script**

```bash
npm install --save-dev vitest@^3.2.7
npm pkg set scripts.test="vitest run --dir tests/js"
```

Run: `grep -n '"test"\|"vitest"' package.json`
Expected: `"test": "vitest run --dir tests/js"` and `"vitest": "^3.2.7"`.

- [ ] **Step 2: Write the failing test**

Create `tests/js/pageOrder.test.js`:

```js
import { describe, expect, it } from 'vitest'
import { flatten, neighbours } from '../../src/services/pageOrder.js'

const tree = [
	{
		name: 'Guides', path: 'Guides', type: 'dir', note: 'Guides/Guides.md',
		children: [
			{ name: 'Deep', path: 'Guides/Deep', type: 'dir', children: [
				{ name: 'Inner', path: 'Guides/Deep/Inner.md', type: 'page' },
			] },
			{ name: 'Alpha', path: 'Guides/Alpha.md', type: 'page' },
		],
	},
	{ name: 'Empty', path: 'Empty', type: 'dir', children: [] },
	{ name: 'Home', path: 'Home.md', type: 'page' },
]

describe('flatten', () => {
	it('lists pages depth-first, folder notes before their children', () => {
		expect(flatten(tree)).toEqual([
			{ path: 'Guides/Guides.md', title: 'Guides' },
			{ path: 'Guides/Deep/Inner.md', title: 'Inner' },
			{ path: 'Guides/Alpha.md', title: 'Alpha' },
			{ path: 'Home.md', title: 'Home' },
		])
	})

	it('skips empty folders and handles an empty tree', () => {
		expect(flatten([{ name: 'E', path: 'E', type: 'dir', children: [] }])).toEqual([])
		expect(flatten([])).toEqual([])
	})
})

describe('neighbours', () => {
	const order = flatten(tree)

	it('has no previous page for the first page', () => {
		expect(neighbours(order, 'Guides/Guides.md')).toEqual({
			prev: null,
			next: { path: 'Guides/Deep/Inner.md', title: 'Inner' },
		})
	})

	it('has no next page for the last page', () => {
		expect(neighbours(order, 'Home.md')).toEqual({
			prev: { path: 'Guides/Alpha.md', title: 'Alpha' },
			next: null,
		})
	})

	it('returns both sides for a middle page', () => {
		expect(neighbours(order, 'Guides/Deep/Inner.md')).toEqual({
			prev: { path: 'Guides/Guides.md', title: 'Guides' },
			next: { path: 'Guides/Alpha.md', title: 'Alpha' },
		})
	})

	it('returns nothing for an unknown path', () => {
		expect(neighbours(order, 'Nope.md')).toEqual({ prev: null, next: null })
	})
})
```

- [ ] **Step 3: Run it to see it fail**

Run: `npm test`
Expected: FAIL, `Cannot find module '../../src/services/pageOrder.js'`.

- [ ] **Step 4: Write the helper**

Create `src/services/pageOrder.js`:

```js
/**
 * Reading order of a site: pages in depth-first tree order. A folder with a
 * folder note contributes that note at the folder's position, before its
 * children.
 *
 * @param {Array} tree nodes from GET /s/{siteId}/tree
 * @return {Array<{path: string, title: string}>}
 */
export function flatten(tree) {
	const out = []
	for (const node of tree || []) {
		if (node.type === 'dir') {
			if (node.note) {
				out.push({ path: node.note, title: node.name })
			}
			out.push(...flatten(node.children))
		} else {
			out.push({ path: node.path, title: node.name })
		}
	}
	return out
}

/**
 * Pages before and after `path` in `order`.
 *
 * @param {Array<{path: string, title: string}>} order result of flatten()
 * @param {string} path current page path
 * @return {{prev: object|null, next: object|null}}
 */
export function neighbours(order, path) {
	const i = order.findIndex(p => p.path === path)
	if (i === -1) {
		return { prev: null, next: null }
	}
	return {
		prev: i > 0 ? order[i - 1] : null,
		next: i < order.length - 1 ? order[i + 1] : null,
	}
}
```

- [ ] **Step 5: Run the tests**

Run: `npm test`
Expected: `Test Files  1 passed (1)`, `Tests  6 passed (6)`.

Run: `npm run lint`
Expected: exit code 0.

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json tests/js/pageOrder.test.js src/services/pageOrder.js
git commit -m "test: add Vitest and the page reading order helper"
```

---

### Task 2: Keep link fragments in `LinkResolver`

**Files:**
- Modify: `lib/Wiki/LinkTarget.php`, `lib/Wiki/LinkResolver.php`, `tests/unit/Wiki/LinkResolverTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/unit/Wiki/LinkResolverTest.php`, add these methods before the class's closing `}`:

```php
	public function testWikilinkKeepsHeadingAsSlug(): void {
		$t = $this->resolver()->resolveWikilink('', 'Bitwarden#My Heading');
		$this->assertSame('page', $t->kind);
		$this->assertSame('LEXIQUE/Bitwarden.md', $t->path);
		$this->assertSame('my-heading', $t->fragment);
	}

	public function testWikilinkNestedHeadingTargetsLastPart(): void {
		$t = $this->resolver()->resolveWikilink('', 'Bitwarden#Setup#Ünïcode step');
		$this->assertSame('ünïcode-step', $t->fragment);
	}

	public function testWikilinkBlockReferenceHasNoFragment(): void {
		$t = $this->resolver()->resolveWikilink('', 'Bitwarden#^abc123');
		$this->assertSame('page', $t->kind);
		$this->assertSame('', $t->fragment);
	}

	public function testWikilinkToHeadingOnSamePage(): void {
		$t = $this->resolver()->resolveWikilink('OUTILS', '#Second part');
		$this->assertSame('anchor', $t->kind);
		$this->assertSame('second-part', $t->fragment);
	}

	public function testMarkdownLinkKeepsExplicitFragment(): void {
		$t = $this->resolver()->resolveHref('OUTILS', '../LEXIQUE/Bitwarden.md#Keep_As-Is');
		$this->assertSame('page', $t->kind);
		$this->assertSame('LEXIQUE/Bitwarden.md', $t->path);
		$this->assertSame('Keep_As-Is', $t->fragment);
	}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --filter LinkResolverTest`
Expected: FAIL — 5 failures/errors (`Undefined property: OCA\MarkdownSite\Wiki\LinkTarget::$fragment`, and `[[#Second part]]` resolves as `broken`).

- [ ] **Step 3: Add the fragment to `LinkTarget`**

Replace `lib/Wiki/LinkTarget.php` with:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

class LinkTarget {
	public function __construct(
		public string $kind,   // 'page' | 'asset' | 'external' | 'anchor' | 'broken'
		public string $path,   // root-relative path (page/asset), original href (external), or '' (anchor/broken)
		public string $fragment = '', // heading id without '#' (page/anchor), or ''
	) {
	}
}
```

- [ ] **Step 4: Rewrite `LinkResolver`**

Replace `lib/Wiki/LinkResolver.php` with:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Normalizer\SlugNormalizer;
use League\CommonMark\Normalizer\TextNormalizerInterface;

class LinkResolver {
	private TextNormalizerInterface $slugs;

	public function __construct(
		private PathResolver $paths,
		private WikilinkIndex $index,
		?TextNormalizerInterface $slugs = null,
	) {
		$this->slugs = $slugs ?? new SlugNormalizer();
	}

	/**
	 * The normalizer that turns heading text into ids. MarkdownRenderer hands
	 * this same instance to CommonMark so link fragments and heading ids agree.
	 */
	public function slugNormalizer(): TextNormalizerInterface {
		return $this->slugs;
	}

	/** Standard markdown link/image href: [text](href) or ![alt](href). */
	public function resolveHref(string $currentDir, string $href): LinkTarget {
		$href = trim($href);
		if ($this->isExternal($href)) {
			return new LinkTarget('external', $href);
		}
		[$href, $fragment] = $this->splitFragment($href);
		try {
			$resolved = $this->paths->resolve($currentDir, rawurldecode($href));
		} catch (PathTraversalException) {
			return new LinkTarget('broken', '');
		}
		return $this->isMarkdown($resolved)
			? new LinkTarget('page', $resolved, $fragment)
			: new LinkTarget('asset', $resolved);
	}

	/** Obsidian wikilink [[target]], [[target#Heading]] or [[#Heading]]. */
	public function resolveWikilink(string $currentDir, string $target): LinkTarget {
		[$name, $heading] = $this->splitFragment(trim($target));
		$fragment = $this->headingSlug($heading);
		if (trim($name) === '') {
			return $fragment === ''
				? new LinkTarget('broken', '')
				: new LinkTarget('anchor', '', $fragment);
		}
		$resolved = $this->index->resolve($currentDir, $name);
		return $resolved === null
			? new LinkTarget('broken', '')
			: new LinkTarget('page', $resolved, $fragment);
	}

	/** Obsidian embed ![[target]] — a note embed or an image/attachment. */
	public function resolveEmbed(string $currentDir, string $target): LinkTarget {
		[$target] = $this->splitFragment(trim($target));
		if ($this->isMarkdown($target) || !$this->hasExtension($target)) {
			$resolved = $this->index->resolve($currentDir, $target);
			return $resolved === null
				? new LinkTarget('broken', '')
				: new LinkTarget('page', $resolved);
		}
		// image / attachment embed: resolve like a path, first by index basename then relative
		$byIndex = $this->index->resolve($currentDir, $target);
		if ($byIndex !== null) {
			return new LinkTarget('asset', $byIndex);
		}
		try {
			$resolved = $this->paths->resolve($currentDir, $target);
		} catch (PathTraversalException) {
			return new LinkTarget('broken', '');
		}
		return new LinkTarget('asset', $resolved);
	}

	/**
	 * Heading part of a wikilink as a heading id. `Heading#Sub` (Obsidian's
	 * nested form) targets the last heading; `^block` references have no
	 * matching element, so they yield no fragment.
	 */
	private function headingSlug(string $heading): string {
		$parts = explode('#', $heading);
		$last = trim(end($parts));
		if ($last === '' || str_starts_with($last, '^')) {
			return '';
		}
		return $this->slugs->normalize($last);
	}

	/** @return array{0: string, 1: string} [before '#', after '#'] */
	private function splitFragment(string $href): array {
		$pos = strpos($href, '#');
		return $pos === false ? [$href, ''] : [substr($href, 0, $pos), substr($href, $pos + 1)];
	}

	private function isExternal(string $href): bool {
		return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)
			|| str_starts_with($href, 'mailto:')
			|| str_starts_with($href, '#');
	}

	private function isMarkdown(string $path): bool {
		return (bool) preg_match('/\.md$/i', $path);
	}

	private function hasExtension(string $path): bool {
		return (bool) preg_match('/\.[a-z0-9]+$/i', basename($path));
	}
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (49 tests, …)`.

- [ ] **Step 6: Commit**

```bash
git add lib/Wiki/LinkTarget.php lib/Wiki/LinkResolver.php tests/unit/Wiki/LinkResolverTest.php
git commit -m "feat: keep heading fragments when resolving links"
```

---

### Task 3: Heading ids, `toc`, and fragment URLs in the renderer

**Files:**
- Modify: `lib/Wiki/MarkdownRenderer.php`, `lib/Wiki/WikilinkInlineParser.php`, `tests/unit/Wiki/MarkdownRendererTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/unit/Wiki/MarkdownRendererTest.php`, in `testExtractsFrontmatter()`, replace

```php
		$this->assertStringContainsString('<h1>Body</h1>', $out['html']);
```

with

```php
		$this->assertStringContainsString('<h1 id="mds-body">Body</h1>', $out['html']);
```

Then add these methods before the class's closing `}`:

```php
	public function testHeadingsGetPrefixedIds(): void {
		$out = $this->renderer()->render("# Hello World\n\n## Café crème", '');
		$this->assertStringContainsString('<h1 id="mds-hello-world">Hello World</h1>', $out['html']);
		$this->assertStringContainsString('<h2 id="mds-café-crème">Café crème</h2>', $out['html']);
	}

	public function testDuplicateHeadingsGetNumberedIds(): void {
		$out = $this->renderer()->render("## Notes\n\n## Notes\n\n## Notes", '');
		$this->assertStringContainsString('id="mds-notes"', $out['html']);
		$this->assertStringContainsString('id="mds-notes-1"', $out['html']);
		$this->assertStringContainsString('id="mds-notes-2"', $out['html']);
	}

	public function testNoPermalinkSymbolIsInserted(): void {
		$out = $this->renderer()->render("## Title", '');
		$this->assertStringNotContainsString('heading-permalink', $out['html']);
		$this->assertStringNotContainsString('¶', $out['html']);
	}

	public function testTocListsHeadingsWithLevelTextAndId(): void {
		$md = "# Guide\n\n## First *step*\n\ntext\n\n### See [[Bitwarden]]\n\n## First step";
		$out = $this->renderer()->render($md, 'OUTILS');
		$this->assertSame([
			['level' => 1, 'text' => 'Guide', 'id' => 'guide'],
			['level' => 2, 'text' => 'First step', 'id' => 'first-step'],
			['level' => 3, 'text' => 'See Bitwarden', 'id' => 'see-bitwarden'],
			['level' => 2, 'text' => 'First step', 'id' => 'first-step-1'],
		], $out['toc']);
	}

	public function testTocIsEmptyWithoutHeadings(): void {
		$this->assertSame([], $this->renderer()->render('just text', '')['toc']);
	}

	public function testWikilinkToHeadingCarriesFragment(): void {
		$out = $this->renderer()->render("[[Bitwarden#Master password]]", 'OUTILS');
		$this->assertStringContainsString('href="/PAGE/LEXIQUE/Bitwarden.md#master-password"', $out['html']);
	}

	public function testWikilinkToHeadingWithLabel(): void {
		$out = $this->renderer()->render("[[Bitwarden#Master password|the password]]", 'OUTILS');
		$this->assertStringContainsString('href="/PAGE/LEXIQUE/Bitwarden.md#master-password"', $out['html']);
		$this->assertStringContainsString('>the password<', $out['html']);
	}

	public function testWikilinkToHeadingOnSamePage(): void {
		$out = $this->renderer()->render("## Next steps\n\nSee [[#Next steps]].", '');
		$this->assertStringContainsString('href="#next-steps"', $out['html']);
		$this->assertStringContainsString('id="mds-next-steps"', $out['html']);
	}

	public function testMarkdownLinkKeepsFragment(): void {
		$out = $this->renderer()->render("[BW](../LEXIQUE/Bitwarden.md#x)", 'OUTILS');
		$this->assertStringContainsString('href="/PAGE/LEXIQUE/Bitwarden.md#x"', $out['html']);
	}

	public function testSameNamedLinkDoesNotShiftHeadingId(): void {
		// A link fragment must not count as a "used" slug for the heading.
		$out = $this->renderer()->render("See [[#Setup]].\n\n## Setup", '');
		$this->assertStringContainsString('id="mds-setup"', $out['html']);
	}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --filter MarkdownRendererTest`
Expected: FAIL — 10 failures (headings have no `id`, there is no `toc` key, wikilink hrefs have no fragment).

- [ ] **Step 3: Rewrite the renderer**

Replace `lib/Wiki/MarkdownRenderer.php` with:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerHelper;

class MarkdownRenderer {
	/**
	 * Heading ids are "mds-<slug>": a bare slug such as "content" or "header"
	 * would collide with ids in Nextcloud's own page layout. Links and the
	 * TOC carry the bare slug; the frontend adds the prefix when it looks a
	 * heading up.
	 */
	public const HEADING_ID_PREFIX = 'mds';

	public function __construct(
		private LinkResolver $resolver,
		private UrlBuilder $urls,
	) {
	}

	/**
	 * @return array{html: string, meta: array<string,mixed>, toc: list<array{level: int, text: string, id: string}>}
	 */
	public function render(string $markdown, string $currentDir): array {
		$environment = new Environment([
			'html_input' => 'strip',
			'allow_unsafe_links' => false,
			'slug_normalizer' => ['instance' => $this->resolver->slugNormalizer()],
			'heading_permalink' => [
				'apply_id_to_heading' => true,
				'id_prefix' => self::HEADING_ID_PREFIX,
				'fragment_prefix' => '',
				'insert' => 'none',
			],
		]);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new FrontMatterExtension());
		$environment->addExtension(new TableExtension());
		$environment->addExtension(new TaskListExtension());
		$environment->addExtension(new StrikethroughExtension());
		$environment->addExtension(new HighlightExtension());
		$environment->addExtension(new HeadingPermalinkExtension());
		$environment->addExtension(new CalloutExtension($markdown));
		// Priority must beat CommonMarkCoreExtension's OpenBracketParser (20),
		// CloseBracketParser (30) and BangParser (10) — otherwise those consume
		// the leading '['/'!' before our regex-based parsers ever see them.
		$environment->addInlineParser(new EmbedInlineParser($this->resolver, $this->urls, $currentDir), 100);
		$environment->addInlineParser(new WikilinkInlineParser($this->resolver, $this->urls, $currentDir), 100);

		// Rewrite standard links/images (which commonmark already parsed) after
		// the document is fully parsed. $currentDir is captured directly by this
		// closure, so there's no need to thread it through node data.
		$environment->addEventListener(
			DocumentParsedEvent::class,
			function (DocumentParsedEvent $e) use ($currentDir): void {
				foreach ($e->getDocument()->iterator() as $node) {
					if ($node instanceof Link) {
						$this->rewriteLink($node, $currentDir);
					} elseif ($node instanceof Image) {
						$this->rewriteImage($node, $currentDir);
					}
				}
			},
			-100,
		);

		$converter = new MarkdownConverter($environment);
		$result = $converter->convert($markdown);

		$meta = [];
		if ($result instanceof RenderedContentWithFrontMatter) {
			$fm = $result->getFrontMatter();
			$meta = is_array($fm) ? $fm : [];
		}

		return [
			'html' => (string) $result->getContent(),
			'meta' => $meta,
			'toc' => $this->toc($result->getDocument()),
		];
	}

	/** @return list<array{level: int, text: string, id: string}> */
	private function toc(Document $document): array {
		$toc = [];
		$prefix = self::HEADING_ID_PREFIX . '-';
		foreach ($document->iterator(NodeIterator::FLAG_BLOCKS_ONLY) as $node) {
			if (!$node instanceof Heading) {
				continue;
			}
			$id = (string) $node->data->get('attributes/id', '');
			$toc[] = [
				'level' => $node->getLevel(),
				'text' => trim(StringContainerHelper::getChildText($node, [RawMarkupContainerInterface::class])),
				'id' => str_starts_with($id, $prefix) ? substr($id, strlen($prefix)) : $id,
			];
		}
		return $toc;
	}

	private function rewriteLink(Link $node, string $currentDir): void {
		// Nodes created by WikilinkInlineParser/EmbedInlineParser already have
		// a final app URL; only rewrite plain [text](href) links here.
		if ($node->data->get('markdownsite/resolved', false)) {
			return;
		}
		$target = $this->resolver->resolveHref($currentDir, $node->getUrl());
		$node->setUrl($this->targetUrl($target));
		if ($target->kind === 'broken') {
			$node->data->append('attributes/class', 'markdownsite-broken');
		}
	}

	private function rewriteImage(Image $node, string $currentDir): void {
		if ($node->data->get('markdownsite/resolved', false)) {
			return;
		}
		$target = $this->resolver->resolveHref($currentDir, $node->getUrl());
		$node->setUrl($this->targetUrl($target));
	}

	private function targetUrl(LinkTarget $t): string {
		$fragment = $t->fragment === '' ? '' : '#' . $t->fragment;
		return match ($t->kind) {
			'page' => $this->urls->page($t->path) . $fragment,
			'asset' => $this->urls->asset($t->path),
			'external' => $t->path,
			'anchor' => $fragment,
			default => '#',
		};
	}
}
```

- [ ] **Step 4: Add fragments to wikilink URLs**

In `lib/Wiki/WikilinkInlineParser.php`, replace

```php
		$url = $target->kind === 'page' ? $this->urls->page($target->path) : '#';
```

with

```php
		$url = match ($target->kind) {
			'page' => $this->urls->page($target->path) . ($target->fragment === '' ? '' : '#' . $target->fragment),
			'anchor' => '#' . $target->fragment,
			default => '#',
		};
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (59 tests, …)`.

- [ ] **Step 6: Commit**

```bash
git add lib/Wiki/MarkdownRenderer.php lib/Wiki/WikilinkInlineParser.php tests/unit/Wiki/MarkdownRendererTest.php
git commit -m "feat: heading anchors and table of contents data"
```

---

### Task 4: Folder notes and `toc` in the API

**Files:**
- Modify: `lib/Service/ContentService.php`, `lib/Controller/PageController.php`
- Replace: `tests/unit/Service/ContentServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Replace `tests/unit/Service/ContentServiceTest.php` with (the first three tests are unchanged from plan A):

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

	/** A file mock named $name. */
	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	/**
	 * A folder mock named $name holding $children (File or Folder mocks).
	 * @param list<\OCP\Files\Node> $children
	 */
	private function folder(string $name, array $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	private function content(): ContentService {
		return new ContentService($this->createMock(IRootFolder::class));
	}

	public function testFolderNoteNamedLikeTheFolder(): void {
		$folder = $this->folder('Projects', [$this->file('Alpha.md'), $this->file('Projects.md')]);
		$this->assertSame('Projects.md', $this->content()->folderNote($folder));
	}

	public function testFolderNoteIndex(): void {
		$folder = $this->folder('Projects', [$this->file('index.md'), $this->file('Alpha.md')]);
		$this->assertSame('index.md', $this->content()->folderNote($folder));
	}

	public function testFolderNoteReadme(): void {
		$folder = $this->folder('Projects', [$this->file('README.md')]);
		$this->assertSame('README.md', $this->content()->folderNote($folder));
	}

	public function testFolderNotePriorityOrder(): void {
		$folder = $this->folder('Projects', [
			$this->file('README.md'), $this->file('index.md'), $this->file('Projects.md'),
		]);
		$this->assertSame('Projects.md', $this->content()->folderNote($folder));
		$folder = $this->folder('Projects', [$this->file('README.md'), $this->file('index.md')]);
		$this->assertSame('index.md', $this->content()->folderNote($folder));
	}

	public function testFolderNoteIsCaseInsensitiveAndKeepsRealName(): void {
		$folder = $this->folder('Projects', [$this->file('ReadMe.MD')]);
		$this->assertSame('ReadMe.MD', $this->content()->folderNote($folder));
		$folder = $this->folder('Projects', [$this->file('projects.md')]);
		$this->assertSame('projects.md', $this->content()->folderNote($folder));
	}

	public function testFolderNoteIgnoresSubfolderWithNoteName(): void {
		$folder = $this->folder('Projects', [$this->folder('index.md', [])]);
		$this->assertNull($this->content()->folderNote($folder));
	}

	public function testFolderNoteNone(): void {
		$folder = $this->folder('Projects', [$this->file('Alpha.md')]);
		$this->assertNull($this->content()->folderNote($folder));
	}

	public function testListTreeSetsNoteAndHidesItFromChildren(): void {
		$projects = $this->folder('Projects', [$this->file('Alpha.md'), $this->file('Projects.md')]);
		$empty = $this->folder('Empty', []);
		$root = $this->folder('Wiki', [$projects, $empty, $this->file('Home.md'), $this->file('.hidden.md')]);
		$this->assertSame([
			['name' => 'Empty', 'path' => 'Empty', 'type' => 'dir', 'children' => []],
			['name' => 'Projects', 'path' => 'Projects', 'type' => 'dir',
				'children' => [['name' => 'Alpha', 'path' => 'Projects/Alpha.md', 'type' => 'page']],
				'note' => 'Projects/Projects.md'],
			['name' => 'Home', 'path' => 'Home.md', 'type' => 'page'],
		], $this->content()->listTree($root));
	}
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --filter ContentServiceTest`
Expected: FAIL — 7 errors `Call to undefined method OCA\MarkdownSite\Service\ContentService::folderNote()` and 1 failure in `testListTreeSetsNoteAndHidesItFromChildren` (`Failed asserting that two arrays are identical`).

- [ ] **Step 3: Implement `folderNote()` and notes in `listTree()`**

In `lib/Service/ContentService.php`:

Add `use OCP\Files\File;` after `use OCA\MarkdownSite\Db\Site;`, and in `getPageContent()` replace `if ($node instanceof \OCP\Files\File) {` with `if ($node instanceof File) {`.

Replace the whole `listTree()` method (its docblock included) with:

```php
	/**
	 * Recursive tree of folders and .md files, paths relative to root. A
	 * folder with a folder note (see folderNote()) gets `note` = the note's
	 * path, and the note is left out of its children.
	 * @return array<int,array{name:string,path:string,type:string,children?:array,note?:string}>
	 */
	public function listTree(Folder $root, string $rel = ''): array {
		$base = $rel === '' ? $root : $root->get($rel);
		if (!($base instanceof Folder)) {
			return [];
		}
		return $this->listFolder($base, $rel, null);
	}

	/**
	 * File name of $folder's folder note, or null. Looked up case-insensitively
	 * in this order: `<FolderName>.md`, `index.md`, `README.md`.
	 */
	public function folderNote(Folder $folder): ?string {
		$files = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$files[mb_strtolower($node->getName())] ??= $node->getName();
			}
		}
		foreach ([mb_strtolower($folder->getName()) . '.md', 'index.md', 'readme.md'] as $candidate) {
			if (isset($files[$candidate])) {
				return $files[$candidate];
			}
		}
		return null;
	}

	/** @return array<int,array{name:string,path:string,type:string,children?:array,note?:string}> */
	private function listFolder(Folder $base, string $rel, ?string $skip): array {
		$out = [];
		foreach ($base->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (str_starts_with($name, '.') || $name === $skip) {
				continue;
			}
			$path = $rel === '' ? $name : $rel . '/' . $name;
			if ($node instanceof Folder) {
				$note = $this->folderNote($node);
				$entry = ['name' => $name, 'path' => $path, 'type' => 'dir',
					'children' => $this->listFolder($node, $path, $note)];
				if ($note !== null) {
					$entry['note'] = $path . '/' . $note;
				}
				$out[] = $entry;
			} elseif (preg_match('/\.md$/i', $name)) {
				$out[] = ['name' => preg_replace('/\.md$/i', '', $name), 'path' => $path, 'type' => 'page'];
			}
		}
		usort($out, function ($a, $b) {
			if ($a['type'] !== $b['type']) {
				return $a['type'] === 'dir' ? -1 : 1;
			}
			return strcasecmp($a['name'], $b['name']);
		});
		return $out;
	}
```

- [ ] **Step 4: Use them in `PageController`**

In `lib/Controller/PageController.php`:

Remove the line `use OCP\Files\NotFoundException;`.

In `page()`, replace

```php
			'meta' => $rendered['meta'],
		]);
```

with

```php
			'meta' => $rendered['meta'],
			'toc' => $rendered['toc'],
		]);
```

Replace the whole `homePath()` method with:

```php
	/** The root's folder note (`<RootName>.md`, `index.md`, `README.md`), else the first page. */
	private function homePath(\OCP\Files\Folder $root): ?string {
		$note = $this->content->folderNote($root);
		if ($note !== null) {
			return $note;
		}
		$md = $this->content->listMarkdownPaths($root);
		return $md[0] ?? null;
	}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit`
Expected: `OK (67 tests, …)`.

Run: `composer run lint`
Expected: exit code 0.

- [ ] **Step 6: Commit**

```bash
git add lib/Service/ContentService.php lib/Controller/PageController.php tests/unit/Service/ContentServiceTest.php
git commit -m "feat: folder notes in the tree and as the site home page"
```

---

### Task 5: `tocCollapsed` preference

**Files:**
- Create: `tests/unit/Controller/PreferencesControllerTest.php`
- Modify: `lib/Controller/PreferencesController.php`, `src/stores/prefs.js`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/Controller/PreferencesControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Tests\Unit\Controller;

use OCA\MarkdownSite\Controller\PreferencesController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class PreferencesControllerTest extends TestCase {
	/** @var array<string,string> stored values, keyed by config key */
	private array $store = [];

	private function controller(): PreferencesController {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = '') => $this->store[$key] ?? $default,
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, $value): void {
				$this->store[$key] = (string) $value;
			},
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new PreferencesController($this->createMock(IRequest::class), $config, $session);
	}

	public function testTocCollapsedDefaultsToFalse(): void {
		$data = $this->controller()->index()->getData();
		$this->assertFalse($data['tocCollapsed']);
	}

	public function testTocCollapsedIsSaved(): void {
		$data = $this->controller()->update('', true, true, true, true)->getData();
		$this->assertTrue($data['tocCollapsed']);
		$this->assertSame('1', $this->store['toc_collapsed']);
		$this->assertTrue($this->controller()->index()->getData()['tocCollapsed']);
	}
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit --filter PreferencesControllerTest`
Expected: FAIL — 2 failures, with PHP warnings `Undefined array key "tocCollapsed"`.

- [ ] **Step 3: Store the preference**

In `lib/Controller/PreferencesController.php`:

Replace the `update()` signature line with

```php
	public function update(string $linkColor = '', bool $linkUnderline = true, bool $linkBold = true, bool $revealActive = true, bool $tocCollapsed = false): JSONResponse {
```

After the line `$this->config->setUserValue($uid, self::APP, 'reveal_active', $revealActive ? '1' : '0');` add

```php
		$this->config->setUserValue($uid, self::APP, 'toc_collapsed', $tocCollapsed ? '1' : '0');
```

Replace the `read()` docblock with

```php
	/** @return array{linkColor:string,linkUnderline:bool,linkBold:bool,revealActive:bool,tocCollapsed:bool} */
```

and after the `'revealActive' => …,` line in `read()` add

```php
			'tocCollapsed' => $this->config->getUserValue($uid, self::APP, 'toc_collapsed', '0') === '1',
```

- [ ] **Step 4: Add it to the store**

In `src/stores/prefs.js`, add `tocCollapsed: false,` after `revealActive: true,` in `state`, and add `tocCollapsed: this.tocCollapsed,` after `revealActive: this.revealActive,` in `save()`.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit && npm run lint`
Expected: `OK (69 tests, …)`, lint exit code 0.

- [ ] **Step 6: Commit**

```bash
git add lib/Controller/PreferencesController.php tests/unit/Controller/PreferencesControllerTest.php src/stores/prefs.js
git commit -m "feat: remember whether the outline is collapsed"
```

---

### Task 6: TOC, breadcrumb and anchor helpers

**Files:**
- Create: `tests/js/toc.test.js`, `tests/js/breadcrumb.test.js`, `tests/js/anchors.test.js`, `src/services/toc.js`, `src/services/breadcrumb.js`, `src/services/anchors.js`

- [ ] **Step 1: Write the failing tests**

`tests/js/toc.test.js`:

```js
import { describe, expect, it } from 'vitest'
import { tocItems } from '../../src/services/toc.js'

const h = (level, id) => ({ level, text: id.toUpperCase(), id })

describe('tocItems', () => {
	it('renders nothing below three headings', () => {
		expect(tocItems([h(2, 'a'), h(2, 'b')])).toEqual([])
		expect(tocItems([])).toEqual([])
		expect(tocItems(undefined)).toEqual([])
	})

	it('shows h2-h4 when the page has exactly one h1', () => {
		const items = tocItems([h(1, 'title'), h(2, 'a'), h(3, 'b'), h(4, 'c'), h(5, 'd')])
		expect(items.map(i => [i.id, i.depth])).toEqual([['a', 0], ['b', 1], ['c', 2]])
	})

	it('shows h1-h4 otherwise', () => {
		const items = tocItems([h(1, 'one'), h(2, 'a'), h(1, 'two'), h(4, 'c'), h(6, 'z')])
		expect(items.map(i => [i.id, i.depth])).toEqual([['one', 0], ['a', 1], ['two', 0], ['c', 3]])
		const noH1 = tocItems([h(2, 'a'), h(2, 'b'), h(3, 'c')])
		expect(noH1.map(i => [i.id, i.depth])).toEqual([['a', 1], ['b', 1], ['c', 2]])
	})
})
```

`tests/js/breadcrumb.test.js`:

```js
import { describe, expect, it } from 'vitest'
import { breadcrumbSegments } from '../../src/services/breadcrumb.js'

const tree = [
	{
		name: 'Guides', path: 'Guides', type: 'dir', note: 'Guides/Guides.md',
		children: [
			{ name: 'Deep', path: 'Guides/Deep', type: 'dir', children: [
				{ name: 'Inner', path: 'Guides/Deep/Inner.md', type: 'page' },
			] },
		],
	},
	{ name: 'Home', path: 'Home.md', type: 'page' },
]

describe('breadcrumbSegments', () => {
	it('returns only the page at the root', () => {
		expect(breadcrumbSegments(tree, 'Home.md')).toEqual([
			{ name: 'Home', path: 'Home.md', current: true, note: null },
		])
	})

	it('lists each folder with its note, then the page', () => {
		expect(breadcrumbSegments(tree, 'Guides/Deep/Inner.md')).toEqual([
			{ name: 'Guides', path: 'Guides', current: false, note: 'Guides/Guides.md' },
			{ name: 'Deep', path: 'Guides/Deep', current: false, note: null },
			{ name: 'Inner', path: 'Guides/Deep/Inner.md', current: true, note: null },
		])
	})

	it('shows an open folder note as its folder', () => {
		expect(breadcrumbSegments(tree, 'Guides/Guides.md')).toEqual([
			{ name: 'Guides', path: 'Guides', current: true, note: 'Guides/Guides.md' },
		])
	})

	it('copes with folders missing from the tree', () => {
		expect(breadcrumbSegments([], 'A/B.md')).toEqual([
			{ name: 'A', path: 'A', current: false, note: null },
			{ name: 'B', path: 'A/B.md', current: true, note: null },
		])
	})
})
```

`tests/js/anchors.test.js`:

```js
import { describe, expect, it } from 'vitest'
import { safeDecode, splitHash } from '../../src/services/anchors.js'

describe('splitHash', () => {
	it('splits a path and its fragment', () => {
		expect(splitHash('Guides/Guide.md#first-step')).toEqual(['Guides/Guide.md', 'first-step'])
	})

	it('returns an empty fragment when there is none', () => {
		expect(splitHash('Guides/Guide.md')).toEqual(['Guides/Guide.md', ''])
	})
})

describe('safeDecode', () => {
	it('decodes percent-encoded text', () => {
		expect(safeDecode('caf%C3%A9-cr%C3%A8me')).toBe('café-crème')
	})

	it('returns malformed input unchanged', () => {
		expect(safeDecode('100%')).toBe('100%')
	})
})
```

- [ ] **Step 2: Run them to see them fail**

Run: `npm test`
Expected: FAIL — 3 test files fail with `Cannot find module`.

- [ ] **Step 3: Write the helpers**

`src/services/toc.js`:

```js
/** Prefix of heading ids in rendered pages (MarkdownRenderer::HEADING_ID_PREFIX). */
export const HEADING_ID_PREFIX = 'mds'

/** A page needs at least this many headings to get a table of contents. */
export const TOC_MIN_HEADINGS = 3

/**
 * Headings to show in the table of contents. h2-h4 when the page has exactly
 * one h1 (the page title), h1-h4 otherwise. `depth` is the indentation level,
 * 0 for the outermost level shown.
 *
 * @param {Array<{level: number, text: string, id: string}>} toc from GET /page
 * @return {Array<{level: number, text: string, id: string, depth: number}>}
 */
export function tocItems(toc) {
	if (!Array.isArray(toc) || toc.length < TOC_MIN_HEADINGS) {
		return []
	}
	const min = toc.filter(h => h.level === 1).length === 1 ? 2 : 1
	return toc
		.filter(h => h.level >= min && h.level <= 4)
		.map(h => ({ ...h, depth: h.level - min }))
}
```

`src/services/breadcrumb.js`:

```js
/**
 * Breadcrumb segments for `path`, site segment excluded: one per folder, then
 * the page. When the page is its folder's note, the folder stands for it and
 * no separate page segment is added.
 *
 * @param {Array} tree nodes from GET /s/{siteId}/tree
 * @param {string} path current page path
 * @return {Array<{name: string, path: string, current: boolean, note: string|null}>}
 */
export function breadcrumbSegments(tree, path) {
	const parts = path.split('/')
	const segments = []
	let nodes = tree || []
	let acc = ''
	for (const part of parts.slice(0, -1)) {
		acc = acc ? `${acc}/${part}` : part
		const node = nodes.find(n => n.type === 'dir' && n.path === acc)
		segments.push({ name: part, path: acc, current: false, note: node?.note || null })
		nodes = node?.children || []
	}
	const last = segments[segments.length - 1]
	if (last && last.note === path) {
		last.current = true
	} else {
		segments.push({ name: parts[parts.length - 1].replace(/\.md$/i, ''), path, current: true, note: null })
	}
	return segments
}
```

`src/services/anchors.js`:

```js
import { HEADING_ID_PREFIX } from './toc.js'

/** decodeURIComponent that returns its input when it is not valid encoding. */
export function safeDecode(text) {
	try {
		return decodeURIComponent(text)
	} catch (e) {
		return text
	}
}

/** Splits `path#fragment` into `[path, fragment]`. */
export function splitHash(href) {
	const i = href.indexOf('#')
	return i === -1 ? [href, ''] : [href.slice(0, i), href.slice(i + 1)]
}

/**
 * The heading inside `root` that a link fragment points at. The server gives
 * headings the id `mds-<slug>` (see MarkdownRenderer::HEADING_ID_PREFIX) while
 * links carry the bare slug; ids written by hand in the Markdown also match.
 */
export function headingElement(root, id) {
	if (!root || !id) {
		return null
	}
	for (const candidate of [`${HEADING_ID_PREFIX}-${id}`, id]) {
		const el = document.getElementById(candidate)
		if (el && root.contains(el)) {
			return el
		}
	}
	return null
}
```

- [ ] **Step 4: Run the tests**

Run: `npm test && npm run lint`
Expected: `Test Files  4 passed (4)`, `Tests  17 passed (17)`, lint exit code 0.

- [ ] **Step 5: Commit**

```bash
git add tests/js src/services/toc.js src/services/breadcrumb.js src/services/anchors.js
git commit -m "feat: outline, breadcrumb and anchor helpers"
```

---

### Task 7: Shared tree state and folder notes in `PageTree`

**Files:**
- Create: `src/stores/tree.js`
- Replace: `src/components/PageTree.vue`

- [ ] **Step 1: Create the store**

`src/stores/tree.js`:

```js
import { defineStore } from 'pinia'

/**
 * Open/closed state of folders in the page tree, shared by every PageTree
 * level so that other components (the breadcrumb) can reveal a folder.
 * A path missing from openMap falls back to PageTree's reveal default.
 */
export const useTreeStore = defineStore('tree', {
	state: () => ({ openMap: {} }),
	actions: {
		setOpen(path, open) {
			this.openMap = { ...this.openMap, [path]: open }
		},
		/** Opens `path` and every folder above it. */
		reveal(path) {
			const next = { ...this.openMap }
			let acc = ''
			for (const part of path.split('/')) {
				acc = acc ? `${acc}/${part}` : part
				next[acc] = true
			}
			this.openMap = next
		},
		clear() {
			this.openMap = {}
		},
	},
})
```

- [ ] **Step 2: Replace `src/components/PageTree.vue`**

```vue
<template>
	<ul class="mds-tree">
		<NcAppNavigationItem
			v-for="node in nodes"
			:key="node.path"
			:name="node.name"
			:allow-collapse="node.type === 'dir'"
			:open="isOpen(node)"
			:active="isActive(node)"
			:data-mds-active="isActive(node) || null"
			:data-mds-path="node.path"
			:to="routeFor(node)"
			@update:open="v => tree.setOpen(node.path, v)"
			@click="onItemClick(node)">
			<template #icon>
				<NcIconSvgWrapper v-if="node.type === 'dir'" :path="mdiFolder" :size="20" />
				<NcIconSvgWrapper v-else :path="mdiFileDocumentOutline" :size="20" />
			</template>
			<template v-if="node.type === 'dir'" #default>
				<PageTree :nodes="node.children || []" :site-id="siteId" :active-path="activePath" />
			</template>
		</NcAppNavigationItem>
	</ul>
</template>

<script>
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFolder, mdiFileDocumentOutline } from '@mdi/js'
import { useTreeStore } from '../stores/tree.js'

export default {
	name: 'PageTree',
	components: { NcAppNavigationItem, NcIconSvgWrapper },
	props: {
		nodes: { type: Array, default: () => [] },
		siteId: { type: [String, Number], required: true },
		// Path of the currently open page. When set, ancestor folders of
		// this path default to open. Pass '' to disable reveal entirely
		// (the "always show current open file" preference, off).
		activePath: { type: String, default: '' },
	},
	setup() { return { tree: useTreeStore(), mdiFolder, mdiFileDocumentOutline } },
	computed: {
		activeAncestors() {
			if (!this.activePath) { return new Set() }
			const parts = this.activePath.split('/').slice(0, -1)
			const set = new Set()
			let acc = ''
			for (const part of parts) {
				acc = acc ? `${acc}/${part}` : part
				set.add(acc)
			}
			return set
		},
	},
	methods: {
		isOpen(node) {
			// An explicit toggle (user click, breadcrumb reveal) wins over the reveal default.
			if (node.path in this.tree.openMap) { return this.tree.openMap[node.path] }
			return this.activeAncestors.has(node.path)
		},
		// A folder whose note is open counts as the active entry.
		isActive(node) {
			return !!this.activePath && (node.path === this.activePath || node.note === this.activePath)
		},
		routeFor(node) {
			const path = node.type === 'page' ? node.path : node.note
			return path ? { name: 'page', params: { siteId: this.siteId, path } } : undefined
		},
		onItemClick(node) {
			if (node.type !== 'dir') { return }
			// A folder with a note opens the note (via `to`) and expands;
			// a folder without one toggles, like the chevron.
			this.tree.setOpen(node.path, node.note ? true : !this.isOpen(node))
		},
	},
}
</script>

<style scoped>
.mds-tree { list-style: none; margin: 0; padding: 0; }
</style>
```

- [ ] **Step 3: Lint and build**

Run: `npm run lint && npm run build`
Expected: lint exit code 0; build ends with `compiled with 3 warnings` (the existing bundle-size warnings).

- [ ] **Step 4: Commit**

```bash
git add src/stores/tree.js src/components/PageTree.vue
git commit -m "feat: open folder notes from the tree"
```

---

### Task 8: `PageToc` component

**Files:**
- Create: `src/components/PageToc.vue`

- [ ] **Step 1: Write the component**

```vue
<template>
	<details v-if="items.length && variant === 'inline'" class="mds-toc mds-toc--inline">
		<summary class="mds-toc-title">{{ t('markdownsite', 'On this page') }}</summary>
		<ul class="mds-toc-list">
			<li v-for="item in items" :key="item.id" :style="{ '--mds-toc-depth': item.depth }">
				<a :href="hrefFor(item)" :class="{ 'is-active': item.id === activeId }" @click.prevent="select(item)">{{ item.text }}</a>
			</li>
		</ul>
	</details>

	<nav v-else-if="items.length"
		class="mds-toc mds-toc--side"
		:class="{ 'is-collapsed': collapsed }"
		:aria-label="t('markdownsite', 'On this page')">
		<div class="mds-toc-header">
			<span v-if="!collapsed" class="mds-toc-title">{{ t('markdownsite', 'On this page') }}</span>
			<NcButton variant="tertiary"
				:aria-label="collapsed ? t('markdownsite', 'Show outline') : t('markdownsite', 'Hide outline')"
				:title="collapsed ? t('markdownsite', 'Show outline') : t('markdownsite', 'Hide outline')"
				@click="$emit('update:collapsed', !collapsed)">
				<template #icon>
					<NcIconSvgWrapper :path="collapsed ? mdiTableOfContents : mdiChevronRight" :size="20" />
				</template>
			</NcButton>
		</div>
		<ul v-if="!collapsed" class="mds-toc-list">
			<li v-for="item in items" :key="item.id" :style="{ '--mds-toc-depth': item.depth }">
				<a :href="hrefFor(item)" :class="{ 'is-active': item.id === activeId }" @click.prevent="select(item)">{{ item.text }}</a>
			</li>
		</ul>
	</nav>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiChevronRight, mdiTableOfContents } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import { HEADING_ID_PREFIX, tocItems } from '../services/toc.js'

export default {
	name: 'PageToc',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		toc: { type: Array, default: () => [] },
		// 'side': sticky column (wide screens); 'inline': "On this page" block (narrow screens)
		variant: { type: String, default: 'side' },
		collapsed: { type: Boolean, default: false },
	},
	emits: ['navigate', 'update:collapsed'],
	setup() { return { t, mdiChevronRight, mdiTableOfContents } },
	data() { return { activeId: null } },
	computed: {
		items() { return tocItems(this.toc) },
	},
	watch: {
		// After the article with the new headings is in the DOM.
		items: { handler: 'observe', flush: 'post' },
	},
	mounted() { this.observe() },
	beforeUnmount() { this.observer?.disconnect() },
	methods: {
		select(item) {
			this.activeId = item.id
			this.$emit('navigate', item.id)
		},
		hrefFor(item) {
			return this.$router.resolve({ ...this.$route, hash: '#' + item.id }).href
		},
		/** Marks the first heading in the upper part of the viewport as active. */
		observe() {
			this.observer?.disconnect()
			this.activeId = this.items[0]?.id ?? null
			if (!this.items.length || typeof IntersectionObserver === 'undefined') { return }
			const visible = new Set()
			this.observer = new IntersectionObserver((entries) => {
				for (const entry of entries) {
					const id = entry.target.id.slice(HEADING_ID_PREFIX.length + 1)
					if (entry.isIntersecting) { visible.add(id) } else { visible.delete(id) }
				}
				const first = this.items.find(item => visible.has(item.id))
				if (first) { this.activeId = first.id }
			}, { rootMargin: '0px 0px -65% 0px' })
			for (const item of this.items) {
				const el = document.getElementById(`${HEADING_ID_PREFIX}-${item.id}`)
				if (el) { this.observer.observe(el) }
			}
		},
	},
}
</script>

<style scoped>
.mds-toc { font-size: 0.92em; }
.mds-toc-title { font-weight: 600; color: var(--color-text-maxcontrast); }
.mds-toc-list { list-style: none; margin: 0; padding: 0; }
.mds-toc-list li { padding-left: calc(var(--mds-toc-depth, 0) * 12px); }
.mds-toc-list a {
	display: block; padding: 3px 8px; border-left: 2px solid transparent;
	color: var(--color-main-text); text-decoration: none; line-height: 1.35;
}
.mds-toc-list a:hover { background: var(--color-background-hover); }
.mds-toc-list a.is-active { border-left-color: var(--color-primary-element); color: var(--color-primary-element); font-weight: 600; }

.mds-toc--side { padding: 16px 8px 32px 0; }
.mds-toc-header { display: flex; align-items: center; justify-content: space-between; gap: 4px; padding-left: 8px; margin-bottom: 4px; }
.mds-toc--side.is-collapsed .mds-toc-header { justify-content: center; padding-left: 0; }

.mds-toc--inline {
	margin: 8px 0 0; padding: 8px 12px; border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}
.mds-toc--inline summary { cursor: pointer; }
.mds-toc--inline .mds-toc-list { margin-top: 6px; }
</style>
```

- [ ] **Step 2: Lint**

Run: `npm run lint`
Expected: exit code 0.

- [ ] **Step 3: Commit**

```bash
git add src/components/PageToc.vue
git commit -m "feat: add the page outline component"
```

---

### Task 9: `PageBreadcrumb` and `PagePager` components

**Files:**
- Create: `src/components/PageBreadcrumb.vue`, `src/components/PagePager.vue`

- [ ] **Step 1: Write `src/components/PageBreadcrumb.vue`**

```vue
<template>
	<nav class="mds-crumbs" :aria-label="t('markdownsite', 'Breadcrumb')">
		<ol>
			<li class="mds-crumb">
				<RouterLink :to="{ name: 'site', params: { siteId } }">{{ siteName }}</RouterLink>
			</li>
			<li v-if="hasMiddle" class="mds-crumb mds-crumb--ellipsis" aria-hidden="true">…</li>
			<li v-for="(segment, i) in segments"
				:key="segment.path"
				class="mds-crumb"
				:class="{ 'mds-crumb--middle': i < middleCount }">
				<span v-if="segment.current" aria-current="page">{{ segment.name }}</span>
				<RouterLink v-else-if="segment.note" :to="{ name: 'page', params: { siteId, path: segment.note } }">
					{{ segment.name }}
				</RouterLink>
				<button v-else type="button" class="mds-crumb-folder" @click="$emit('reveal', segment.path)">
					{{ segment.name }}
				</button>
			</li>
		</ol>
	</nav>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { breadcrumbSegments } from '../services/breadcrumb.js'

export default {
	name: 'PageBreadcrumb',
	props: {
		siteId: { type: [String, Number], required: true },
		siteName: { type: String, default: '' },
		path: { type: String, required: true },
		tree: { type: Array, default: () => [] },
	},
	emits: ['reveal'],
	setup() { return { t } },
	computed: {
		segments() { return breadcrumbSegments(this.tree, this.path) },
		// Folders between the site and the last folder: hidden behind "…" on narrow screens.
		middleCount() { return Math.max(0, this.segments.length - 2) },
		hasMiddle() { return this.middleCount > 0 },
	},
}
</script>

<style scoped>
.mds-crumbs ol { display: flex; flex-wrap: wrap; align-items: center; list-style: none; margin: 0; padding: 0; color: var(--color-text-maxcontrast); font-size: 0.92em; }
.mds-crumb { display: flex; align-items: center; min-width: 0; }
.mds-crumb + .mds-crumb::before { content: '›'; margin: 0 6px; color: var(--color-text-maxcontrast); }
.mds-crumb a, .mds-crumb-folder { color: var(--color-text-maxcontrast); text-decoration: none; }
.mds-crumb a:hover, .mds-crumb-folder:hover { color: var(--color-main-text); text-decoration: underline; }
.mds-crumb-folder { border: none; background: none; padding: 0; margin: 0; min-height: 0; font: inherit; cursor: pointer; }
.mds-crumb [aria-current] { color: var(--color-main-text); }
.mds-crumb--ellipsis { display: none; }
@media (max-width: 1023px) {
	.mds-crumb--ellipsis { display: flex; }
	.mds-crumb--middle { display: none; }
}
</style>
```

- [ ] **Step 2: Write `src/components/PagePager.vue`**

```vue
<template>
	<nav v-if="prev || next" class="mds-pager" :aria-label="t('markdownsite', 'Previous and next page')">
		<RouterLink v-if="prev" class="mds-pager-card mds-pager-card--prev" :to="routeTo(prev)">
			<span class="mds-pager-label">{{ t('markdownsite', 'Previous') }}</span>
			<span class="mds-pager-title">← {{ prev.title }}</span>
		</RouterLink>
		<RouterLink v-if="next" class="mds-pager-card mds-pager-card--next" :to="routeTo(next)">
			<span class="mds-pager-label">{{ t('markdownsite', 'Next') }}</span>
			<span class="mds-pager-title">{{ next.title }} →</span>
		</RouterLink>
	</nav>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { flatten, neighbours } from '../services/pageOrder.js'

export default {
	name: 'PagePager',
	props: {
		siteId: { type: [String, Number], required: true },
		path: { type: String, required: true },
		tree: { type: Array, default: () => [] },
	},
	setup() { return { t } },
	computed: {
		around() { return neighbours(flatten(this.tree), this.path) },
		prev() { return this.around.prev },
		next() { return this.around.next },
	},
	methods: {
		routeTo(page) { return { name: 'page', params: { siteId: this.siteId, path: page.path } } },
	},
}
</script>

<style scoped>
.mds-pager { display: flex; gap: 12px; margin-top: 48px; }
.mds-pager-card {
	flex: 1 1 0; max-width: calc(50% - 6px); display: flex; flex-direction: column; gap: 2px; padding: 12px 16px;
	border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px);
	color: var(--color-main-text); text-decoration: none;
}
.mds-pager-card:hover { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.mds-pager-card--next { margin-left: auto; text-align: right; }
.mds-pager-label { font-size: 0.85em; color: var(--color-text-maxcontrast); }
.mds-pager-title { font-weight: 600; }
</style>
```

- [ ] **Step 3: Lint**

Run: `npm run lint`
Expected: exit code 0.

- [ ] **Step 4: Commit**

```bash
git add src/components/PageBreadcrumb.vue src/components/PagePager.vue
git commit -m "feat: add breadcrumb and previous/next components"
```

---

### Task 10: Wire everything into `WikiView`

**Files:**
- Modify: `src/views/WikiView.vue`

- [ ] **Step 1: Template**

Replace

```vue
			<article v-else ref="article" class="mds-content" :style="prefs.cssVars" v-html="html" @click="onClick" />
		</NcAppContent>
```

with

```vue
			<div v-else ref="page" class="mds-page" :class="{ 'mds-page--toc-collapsed': prefs.tocCollapsed }">
				<div class="mds-main">
					<PageBreadcrumb :site-id="activeSiteId"
						:site-name="store.current ? store.current.name : ''"
						:path="currentPath"
						:tree="tree"
						@reveal="revealFolder" />
					<PageToc class="mds-toc-narrow" variant="inline" :toc="toc" @navigate="goToHeading" />
					<article ref="article" class="mds-content" :style="prefs.cssVars" v-html="html" @click="onClick" />
					<PagePager :site-id="activeSiteId" :path="currentPath" :tree="tree" />
				</div>
				<PageToc class="mds-toc-wide"
					variant="side"
					:toc="toc"
					:collapsed="prefs.tocCollapsed"
					@update:collapsed="setTocCollapsed"
					@navigate="goToHeading" />
			</div>
		</NcAppContent>
```

- [ ] **Step 2: Imports and component registration**

After `import { decorateCallouts } from '../services/calloutCopy.js'` add

```js
import { headingElement, safeDecode, splitHash } from '../services/anchors.js'
```

After `import { usePrefsStore } from '../stores/prefs.js'` add

```js
import { useTreeStore } from '../stores/tree.js'
```

After `import PageTree from '../components/PageTree.vue'` add

```js
import PageToc from '../components/PageToc.vue'
import PageBreadcrumb from '../components/PageBreadcrumb.vue'
import PagePager from '../components/PagePager.vue'
```

Replace `SiteSwitcher, PageTree, NewSiteDialog, SettingsDialog,` with `SiteSwitcher, PageTree, PageToc, PageBreadcrumb, PagePager, NewSiteDialog, SettingsDialog,`.

In `setup()`, after `prefs: usePrefsStore(),` add `treeState: useTreeStore(),`.

In `data()`, add `toc: [],` after `html: '',`.

- [ ] **Step 3: Route watcher**

Replace

```js
	watch: {
		'$route': 'sync',
	},
```

with

```js
	watch: {
		$route(to, from) {
			// Only the #heading changed (TOC click, back/forward): scroll, don't reload.
			if (from && to.path === from.path && to.hash !== from.hash) {
				this.scrollToHash()
				return
			}
			this.sync()
		},
		activeSiteId() { this.treeState.clear() },
	},
```

- [ ] **Step 4: Page loading and scrolling**

In `loadPage()`, replace

```js
				this.html = data.html
			} catch (e) {
				this.error = true; this.html = ''
```

with

```js
				this.html = data.html
				this.toc = data.toc || []
			} catch (e) {
				this.error = true; this.html = ''; this.toc = []
```

Replace

```js
			decorateCallouts(this.$refs.article)
			enableTaskLists(this.$refs.article)
		},
```

with

```js
			decorateCallouts(this.$refs.article)
			enableTaskLists(this.$refs.article)
			this.scrollToHash()
		},
		/** Scrolls to the heading named by the URL hash, or to the top of the page. */
		scrollToHash() {
			this.$nextTick(() => {
				const id = this.$route.hash ? this.$route.hash.slice(1) : ''
				const target = (id && headingElement(this.$refs.article, id)) || (!id && this.$refs.page)
				if (target && target.scrollIntoView) {
					target.scrollIntoView({ block: 'start' })
				}
			})
		},
		async goToHeading(id) {
			await this.$router.push({ name: this.$route.name, params: this.$route.params, query: this.$route.query, hash: '#' + id })
			// Same hash again: the route does not change, so scroll explicitly.
			this.scrollToHash()
		},
		revealFolder(path) {
			this.treeState.reveal(path)
			this.$nextTick(() => {
				const el = [...document.querySelectorAll('.mds-tree [data-mds-path]')]
					.find(item => item.getAttribute('data-mds-path') === path)
				if (el && el.scrollIntoView) {
					el.scrollIntoView({ block: 'center', behavior: 'smooth' })
				}
			})
		},
		setTocCollapsed(collapsed) {
			this.prefs.tocCollapsed = collapsed
			this.prefs.save()
		},
```

- [ ] **Step 5: Link clicks**

In `onClick()`, replace

```js
			const href = a.getAttribute('href') || ''
			const prefix = generateUrl(`/apps/markdownsite/s/${this.activeSiteId}/page/`)
			if (href.startsWith(prefix)) {
				ev.preventDefault()
				const rel = decodeURIComponent(href.slice(prefix.length))
				this.$router.push({ name: 'page', params: { siteId: this.activeSiteId, path: rel } })
			}
```

with

```js
			const href = a.getAttribute('href') || ''
			if (href.startsWith('#') && href.length > 1) {
				// Heading on this page ([[#Heading]] or [text](#id)).
				ev.preventDefault()
				this.goToHeading(safeDecode(href.slice(1)))
				return
			}
			const prefix = generateUrl(`/apps/markdownsite/s/${this.activeSiteId}/page/`)
			if (href.startsWith(prefix)) {
				ev.preventDefault()
				const [rel, fragment] = splitHash(href.slice(prefix.length))
				this.$router.push({
					name: 'page',
					params: { siteId: this.activeSiteId, path: safeDecode(rel) },
					hash: fragment ? '#' + safeDecode(fragment) : '',
				})
			}
```

- [ ] **Step 6: Layout styles**

In the `<style scoped>` block, replace

```css
.mds-content {
	max-width: 760px;
	margin: 0 auto;
	padding: 32px 24px 96px;
	line-height: 1.6;
	color: var(--color-main-text);
}
```

with

```css
/* Page layout: main column (breadcrumb, article, pager) + sticky outline on wide
   screens. The left padding keeps the breadcrumb clear of the navigation toggle. */
.mds-page { display: flex; justify-content: center; align-items: flex-start; gap: 24px; padding: 0 24px 0 52px; }
.mds-main { flex: 0 1 760px; min-width: 0; padding: 24px 0 96px; }
.mds-toc-wide { flex: 0 0 240px; position: sticky; top: 0; max-height: 100vh; overflow-y: auto; }
.mds-page--toc-collapsed .mds-toc-wide { flex-basis: 44px; }
@media (max-width: 1023px) {
	.mds-toc-wide { display: none; }
}
@media (min-width: 1024px) {
	.mds-toc-narrow { display: none; }
}
.mds-content {
	max-width: 760px;
	margin: 0 auto;
	padding: 16px 0 0;
	line-height: 1.6;
	color: var(--color-main-text);
}
.mds-content :deep([id^='mds-']) { scroll-margin-top: 16px; }
```

- [ ] **Step 7: Lint, test, build**

Run: `npm run lint && npm test && npm run build`
Expected: lint exit code 0, `Tests  17 passed (17)`, build `compiled with 3 warnings`.

- [ ] **Step 8: Commit**

```bash
git add src/views/WikiView.vue
git commit -m "feat: outline, breadcrumb and previous/next around each page"
```

---

### Task 11: Fixture pages for the manual check

**Files:**
- Create: `tests/fixtures/smoke-site/Guides/Long page.md`, `tests/fixtures/smoke-site/Projects/Projects.md`, `tests/fixtures/smoke-site/Projects/Alpha.md`
- Modify: `tests/fixtures/smoke-site/README.md`

- [ ] **Step 1: Write the pages**

`tests/fixtures/smoke-site/Guides/Long page.md`:

```markdown
# Long page

A page with enough headings for an outline. Jump to the [[#Third section]].

## First section

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.


## Second section

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.


### A sub-section

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.


## Third section

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.

Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor
incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis
nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.
```

`tests/fixtures/smoke-site/Projects/Projects.md`:

```markdown
# Projects

This is the folder note of "Projects": clicking the folder opens it.
```

`tests/fixtures/smoke-site/Projects/Alpha.md`:

```markdown
# Alpha

A page inside the Projects folder.
```

Replace `tests/fixtures/smoke-site/README.md` with:

```markdown
# Smoke site

Home page of the smoke-test site. Read the [[Guide]] next, or jump to the
[[Long page#Second section|second section of the long page]].

> [!note] Callout
> This box should render with a blue border and an icon.
```

- [ ] **Step 2: Commit**

```bash
git add tests/fixtures/smoke-site
git commit -m "test: fixture pages for the outline and folder notes"
```

---

### Task 12: Release 1.3.0

**Files:**
- Modify: `appinfo/info.xml`, `CHANGELOG.md`, `README.md`, `js/markdownsite-main.js`, `js/markdownsite-main.js.map`, `js/markdownsite-main.js.LICENSE.txt`

- [ ] **Step 1: Bump the version**

In `appinfo/info.xml`, replace `<version>1.2.0</version>` with `<version>1.3.0</version>`.

- [ ] **Step 2: Changelog**

Insert above `## [1.2.0] - 2026-10-02` in `CHANGELOG.md`:

```markdown
## [1.3.0] - 2026-10-02

### Added
- Outline of the current page ("On this page") in a right-hand column, with the section in view highlighted. It can be collapsed; the choice is remembered.
- Breadcrumb above each page and previous/next links below it, in tree order.
- Links to a heading: `[[Page#Heading]]`, `[[Page#Heading|label]]`, `[[#Heading]]` and `[text](Page.md#heading)` open the page at that section.
- Folder notes: a folder containing `Folder.md`, `index.md` or `README.md` opens that page when clicked.

```

- [ ] **Step 3: README features**

In `README.md`, after the line starting with `- **Reveal-active-file navigation**`, add:

```markdown
- **Outline, breadcrumb, previous/next** — a sticky "On this page" outline, a breadcrumb, and previous/next links in tree order. Folder notes (`Folder/Folder.md`, `index.md`, `README.md`) open when their folder is clicked.
```

- [ ] **Step 4: Build and check everything**

Run: `vendor/bin/phpunit && composer run lint && npm run lint && npm test && npm run build`
Expected: `OK (69 tests, …)`, both linters exit 0, `Tests  17 passed (17)`, build `compiled with 3 warnings`.

- [ ] **Step 5: Commit**

```bash
git add appinfo/info.xml CHANGELOG.md README.md js/
git commit -m "release: 1.3.0"
```

---

### Task 13: Manual verification (owner)

À faire par toi, dans cet ordre. Chaque étape prend moins de deux minutes.

1. Demande à Claude, sur ton ordinateur : « lance `scripts/smoke.sh 31` ». Ouvre l'adresse donnée, connecte-toi en `admin` (mot de passe dans `.smoke/credentials.txt`) et crée un site à partir du dossier `smoke-site`.
2. Ouvre la page « Long page » (dossier Guides). À droite, la liste « On this page » doit apparaître. Clique sur « Third section » : la page descend jusqu'à ce titre. Fais défiler : la liste reste visible et le titre en cours est en couleur.
3. Clique sur la petite flèche en haut de cette liste pour la replier, recharge la page (touche F5) : elle doit rester repliée. Déplie-la à nouveau.
4. En haut de la page, vérifie le chemin « Smoke › Guides › Long page ». En bas, vérifie les deux cartes « Previous » et « Next » et clique sur l'une d'elles. Dans l'arbre à gauche, clique sur le dossier « Projects » : sa page d'accueil s'ouvre.
5. Rétrécis la fenêtre du navigateur à la moitié de l'écran : la liste de droite disparaît et un bloc « On this page » repliable apparaît au-dessus du texte. Demande ensuite à Claude « lance `scripts/smoke.sh stop` ».
