# Changelog

## [2.1.3] — 2026-07-15
### Added
- Network hardening for DeepL requests: cURL connect timeout raised from the
  Requests default 10s to 30s, forced IPv4 resolution for deepl.com
  (opt out: `add_filter( 'dt_force_ipv4', '__return_false' )`).
- Automatic retries (3 attempts, 1s/2s backoff) on transport-level errors
  such as `cURL error 28`. Configurable via the `dt_deepl_retries` filter.

## [2.1.2] — 2026-07-15
### Fixed
- **Synced patterns: rich attrs of custom blocks were corrupted** (`\u003c` → `u003c`).
  `dt_create_translated_pattern()` passed serialized block content to `wp_insert_post()`
  without `wp_slash()`; `wp_insert_post()` unslashes internally, stripping the backslashes
  from JSON escape sequences produced by `serialize_blocks()`. Any custom block with HTML
  in a `rich` attribute (`<em>`, `<mark>`, `<br>`) inside a synced pattern rendered as
  literal `u003cem...` text. Now wrapped in `wp_slash()`.
- All `update_post_meta()` / `update_term_meta()` / `wp_update_term()` writes are now
  wrapped in `wp_slash()` (these functions expect slashed data and unslash internally).
- `dt_collect_blocks()` now iterates by reference, so `block.json` defaults merged via
  `dt_merge_with_defaults()` are actually written back into the block tree as documented.
- `core/details` summary replacement escapes `\` and `$` in translated text
  (special characters inside a `preg_replace()` replacement string).

### Notes
- Already-corrupted translated patterns are **not** auto-repaired: existing translated
  patterns are reused as-is by design. Delete the broken translated `wp_block` posts
  (titles ending in `[XX]`) and re-run page translation.

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