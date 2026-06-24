# Changelog

## [2.1.0] — 2025-06-24
### Added
- Taxonomy term translation: name, slug, and theme-prefixed term meta
- Translate button on term add form (new term created via AJAX before translating)
- Translate button on term edit form for all public taxonomies

## [2.0.0] — 2025-06-23
### Added
- Full Gutenberg block translation via DeepL API
- Custom block support via `translate.json` schema files
- Synced patterns (`core/block`) — translated copies created and linked automatically
- Internal URL resolution to target language via Polylang
- Post object ID sync to translated equivalents
- Theme meta fields auto-translated by text domain prefix
- Repeater fields at any nesting depth
- Translate button available on new auto-draft translations (no save required first)
- Fallback heuristics for blocks without `translate.json`