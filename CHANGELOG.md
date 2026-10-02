# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.5.0] - 2026-10-02

### Added
- Search: a search field above the page tree finds pages in the current site or in all your sites. All words must appear; case and accents are ignored; `"quoted words"` search for an exact phrase. Results show where the words appear.
- The page opened from a result highlights the words and scrolls to the first one.
- Keyboard shortcut Ctrl+Shift+F (Cmd+Shift+F on a Mac) to search.

### Notes
- The first search on a large site prepares an index and can take a few seconds.

## [1.4.0] - 2026-10-02

### Added
- Syntax highlighting for code blocks, with the language name and a copy button on each block. Light and dark colours follow the Nextcloud theme.
- Mermaid diagrams (```` ```mermaid ````) are drawn. An invalid diagram shows "Invalid diagram" and its source.

### Changed
- The interface script is split into parts loaded on demand: pages without code or diagrams load less.

## [1.3.0] - 2026-10-02

### Added
- Outline of the current page ("On this page") in a right-hand column, with the section in view highlighted. It can be collapsed; the choice is remembered.
- Breadcrumb above each page and previous/next links below it, in tree order.
- Links to a heading: `[[Page#Heading]]`, `[[Page#Heading|label]]`, `[[#Heading]]` and `[text](Page.md#heading)` open the page at that section.
- Folder notes: a folder containing `Folder.md`, `index.md` or `README.md` opens that page when clicked.

## [1.2.0] - 2026-10-02

### Changed
- Supports Nextcloud 31 to 34 and PHP 8.1 to 8.5. Nextcloud 28–30 are no longer supported (the interface needs Nextcloud 31).

## [1.1.1] - 2026-10-02

### Fixed
- Callout copy button now appears (it was never attached to the page).
- Task list items wrap like normal text instead of splitting into columns.
- Task list checkboxes can be ticked (in the page only; the file is not modified).
- Tables shrink to their content instead of spanning the full width.

## [1.1.0] - 2026-10-01

### Added
- Obsidian callouts (`> [!note] Title`, foldable with `+` / `-`), rendered as styled boxes with icons.
- Tables, task lists (`- [ ]` / `- [x]`, styled checkboxes), `==highlight==` and `~~strikethrough~~`.
- Copy button on callouts, with a choice of Markdown (original source) or HTML (rich text for emails).
- Visible bullets and numbers on lists.

## [1.0.0] - 2026-07-17

### Added
- Read-only wiki view over any folder of Markdown files, with page tree navigation.
- Obsidian-style `[[wikilinks]]`, `[[Page|labels]]`, and embeds, resolved by page name.
- Per-site sharing with users and groups, independent of Files sharing. Owner-only management.
- Reveal-active-file navigation with a per-user toggle.
- Themeable link appearance (underline, bold, colour) with live preview.
- App icon and settings UI.
