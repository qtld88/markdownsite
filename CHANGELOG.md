# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
