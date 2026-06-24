# DeepL Translator — Developer Documentation

## Table of Contents

1. [Overview](#overview)
2. [Installation](#installation)
3. [Updating](#updating)
4. [Settings](#settings)
5. [How It Works](#how-it-works)
6. [Supported Block Types](#supported-block-types)
7. [translate.json — Field Mapping](#translatejson--field-mapping)
   - [File Structure](#file-structure)
   - [Translate Values](#translate-values)
   - [Simple Block Example](#simple-block-example)
   - [Repeater Block Example](#repeater-block-example)
   - [Child Block Example](#child-block-example)
8. [Translation Logic](#translation-logic)
   - [Strings](#strings)
   - [Rich Text](#rich-text)
   - [URLs](#urls)
   - [Post Objects](#post-objects)
   - [Repeaters](#repeaters)
   - [Defaults](#defaults)
9. [Core Blocks](#core-blocks)
10. [Theme Meta Fields](#theme-meta-fields)
11. [Synced Patterns](#synced-patterns)
12. [Taxonomy Term Translation](#taxonomy-term-translation)
13. [Adding a New Custom Block](#adding-a-new-custom-block)
14. [Fallback Heuristics](#fallback-heuristics)
15. [GitHub Releases & Auto-Updater](#github-releases--auto-updater)
16. [Known Limitations](#known-limitations)

---

## Overview

**DeepL Translator** is a WordPress plugin that translates entire pages and taxonomy terms through the DeepL API using Polylang for language management. It handles:

- Page title and excerpt
- All native Gutenberg core blocks (`core/paragraph`, `core/heading`, `core/list`, etc.)
- All custom blocks — using a `translate.json` schema file for precise field control
- Repeaters at any depth of nesting
- Rich text with HTML tags (`<mark>`, `<br>`, `<strong>`) without breaking markup
- Internal URLs — resolves translated versions via Polylang
- Post object IDs — swaps for translated equivalents or removes if none found
- Theme meta fields — auto-detected by theme text domain prefix
- Synced patterns (`core/block`) — creates a translated pattern copy and swaps the `ref`
- Taxonomy terms — name, slug, and theme-prefixed term meta for all public taxonomies
- Works on newly created draft translations before they have content

---

## Installation

### Via TGMPA (recommended for theme bundling)

Add to your theme's required plugins list:

```php
array(
    'name'     => 'DeepL Translator',
    'slug'     => 'deepl-translator',
    'source'   => 'https://github.com/DmytrivDev/deepl-translator/releases/latest/download/deepl-translator.zip',
    'required' => true,
),
```

### Manual

1. Download `deepl-translator.zip` from the [latest release](https://github.com/DmytrivDev/deepl-translator/releases/latest)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → DeepL Translator** and enter your DeepL API key

---

## Updating

Updates appear automatically in the standard **WordPress Admin → Updates** screen when a new release is published on GitHub. Click **Update now** — no manual steps needed.

To force an immediate update check (bypasses the 12-hour cache):

```
wp-admin/update-core.php?force-check=1
```

---

## Settings

**Settings → DeepL Translator**

| Field | Description |
|-------|-------------|
| DeepL API Key | Your DeepL API key (free or pro) |
| API Type | `Free` uses `api-free.deepl.com`, `Pro` uses `api.deepl.com` |
| Block folders | One folder per line, relative to `wp-content/`. Each folder should contain block subfolders with a `translate.json` file |

**Block folders example:**
```
plugins/overchain-blocks/blocks
themes/overchain/blocks
```

This tells the plugin to scan:
- `wp-content/plugins/overchain-blocks/blocks/*/translate.json`
- `wp-content/plugins/overchain-blocks/blocks/*/*/translate.json` (child blocks)
- `wp-content/themes/overchain/blocks/*/translate.json`

**Clear cache** button — click after adding or editing any `translate.json` files. The plugin caches the translation map for 24 hours.

---

## How It Works

### Pages

When you click **🌐 Translate entire page** in the metabox:

1. The plugin reads the original post (default language) content
2. Parses all Gutenberg blocks via `parse_blocks()`
3. For each block, reads its `translate.json` schema to determine which fields to translate
4. Collects all translatable strings into a single batch
5. Sends the batch to DeepL API in one request
6. Applies translations back to blocks
7. Syncs patterns — creates translated copies if needed
8. Syncs internal URLs to their translated versions
9. Syncs post object IDs to their translated equivalents
10. Translates theme meta fields
11. Saves via `$wpdb->update()` directly (bypasses `wp_update_post()` double-encoding)

The metabox appears **only on non-default language posts**. It also works on newly created draft translations — the plugin saves the draft first (via the Gutenberg store), then runs translation, then reloads — all in one click.

### Taxonomy Terms

When you click **🌐 Translate entire term** in the term metabox:

1. Reads the original term (default language)
2. Translates the term name via DeepL
3. Generates a slug from the translated name
4. Translates all theme-prefixed term meta fields
5. Saves name, slug, and meta to the target-language term

For new terms (add form), the plugin creates the term via AJAX first, then translates it, then redirects to the edit screen — all in one click without manually saving first.

---

## Supported Block Types

### Core text blocks (translated via `innerHTML`)
`core/paragraph`, `core/heading`, `core/list-item`, `core/button`, `core/pullquote`, `core/verse`, `core/preformatted`, `core/code`, `core/table`, `core/freeform`, `core/html`

### Core container blocks (recursed, not translated directly)
`core/quote`, `core/list`, `core/column`, `core/columns`, `core/group`, `core/cover`, `core/media-text`, `core/buttons`, `core/gallery`

### Special blocks
- `core/details` — summary text extracted and translated separately; paragraph inside translated via recursion
- `core/block` — synced pattern; translated copy created and `ref` swapped

### Skipped blocks (no translation, attrs preserved as-is)
`core/image`, `core/gallery`, `core/video`, `core/audio`, `core/file`, `core/embed`, `core/spacer`, `core/separator`, `core/math`, `core/latest-posts`, `core/latest-comments`, `core/search`, and other pure media/technical blocks

---

## translate.json — Field Mapping

### File Structure

`translate.json` lives alongside `block.json` in the block folder:

```
blocks/
└── hero/
    ├── block.json       ← standard WP block definition (unchanged)
    ├── translate.json   ← translation hints (plugin reads this)
    ├── edit.js
    └── render.php
```

For child blocks:
```
blocks/
└── cards/
    ├── block.json
    ├── translate.json
    └── card/
        ├── block.json
        ├── translate.json   ← child block hints
        ├── edit.js
        └── render.php
```

### Translate Values

| Value | Description |
|-------|-------------|
| `rich` | Rich text — may contain HTML tags (`<mark>`, `<br>`, `<strong>`). Sent to DeepL with `tag_handling=html`. Tags are preserved. |
| `string` | Plain text — no HTML tags expected. Also uses `tag_handling=html` (safe for plain text too). |
| `url` | Internal URL — resolved to target language version. External URLs left as-is. Internal URLs without a translation are deleted. |
| `postobject` | Post ID (integer) or array of post IDs — swapped for translated equivalents. If no translation found, the field is removed. |
| `repeater` | Array of objects — each item processed recursively using the `items` schema. |
| `ignore` | Skip — field is not translated or modified. |

### Simple Block Example

```json
{
  "name": "overchain/hero",
  "attributes": {
    "title":            { "translate": "rich" },
    "subtitle":         { "translate": "rich" },
    "buttonText":       { "translate": "string" },
    "buttonType":       { "translate": "ignore" },
    "buttonLink":       { "translate": "url" },
    "buttonLinkId":     { "translate": "postobject" },
    "buttonLinkTitle":  { "translate": "string" },
    "buttonLinkNewTab": { "translate": "ignore" },
    "buttonAnchor":     { "translate": "ignore" },
    "buttonPopupId":    { "translate": "ignore" },
    "buttonIcon":       { "translate": "ignore" },
    "backgroundId":     { "translate": "ignore" },
    "backgroundUrl":    { "translate": "ignore" }
  }
}
```

**Rules of thumb:**
- Titles, headings, body text with possible `<br>` or `<mark>` → `rich`
- Button labels, plain labels → `string`
- Link/href/src/url fields → `url`
- `*LinkId`, `postId`, `pageId` → `postobject`
- Booleans, enums, type selectors, icon names, IDs of media → `ignore`
- Image/icon/video/background IDs and URLs → `ignore`

### Repeater Block Example

Repeaters use a nested `items` object that maps each sub-field to its translate hint:

```json
{
  "name": "overchain/badges",
  "attributes": {
    "title": { "translate": "rich" },
    "badges": {
      "translate": "repeater",
      "items": {
        "iconUrl": { "translate": "ignore" },
        "iconId":  { "translate": "ignore" },
        "label":   { "translate": "string" }
      }
    }
  }
}
```

Repeaters work at any depth — items can themselves contain nested repeaters:

```json
{
  "name": "overchain/accordion",
  "attributes": {
    "title": { "translate": "rich" },
    "items": {
      "translate": "repeater",
      "items": {
        "heading":         { "translate": "string" },
        "text":            { "translate": "rich" },
        "imageId":         { "translate": "ignore" },
        "imageUrl":        { "translate": "ignore" },
        "buttonText":      { "translate": "string" },
        "buttonType":      { "translate": "ignore" },
        "buttonLink":      { "translate": "url" },
        "buttonLinkId":    { "translate": "postobject" },
        "buttonLinkTitle": { "translate": "string" },
        "buttonNewTab":    { "translate": "ignore" },
        "buttonAnchor":    { "translate": "ignore" },
        "buttonPopupId":   { "translate": "ignore" }
      }
    }
  }
}
```

### Child Block Example

Child blocks have their own `translate.json` in their subfolder. The `name` must match the block's registered name:

```json
{
  "name": "overchain/card",
  "attributes": {
    "iconId":  { "translate": "ignore" },
    "iconUrl": { "translate": "ignore" },
    "title":   { "translate": "string" },
    "text":    { "translate": "rich" }
  }
}
```

---

## Translation Logic

### Strings

Fields with `translate: "string"` or `translate: "rich"` are collected into a batch and sent to DeepL in a single API request. DeepL is called with `tag_handling: "html"` for both types — this means HTML tags are preserved automatically even for `string` fields (safe, DeepL simply won't find any tags to process).

### Rich Text

Rich text fields (`translate: "rich"`) may contain inline HTML:

```
"The Settlement Layer<br>for <mark class=\"has-accent-color\">Global Money</mark>"
```

DeepL with `tag_handling: "html"` translates only the text nodes, leaving tags and their attributes untouched.

### URLs

Fields with `translate: "url"` go through `dt_resolve_url()`:

1. **Starts with `#`** → returned as-is (CSS anchor, not a URL)
2. **External domain** → returned as-is
3. **Internal URL** (same domain or starts with `/`) → strip language prefix → find post by path or slug → `pll_get_post()` → return translated permalink
4. **Internal URL, no translation found** → field is deleted (`unset`)
5. **CPT archive URL** → resolved to target language archive URL

### Post Objects

Fields with `translate: "postobject"` go through `pll_get_post()`:

- **Single integer** → translated post ID or field deleted if none found
- **Array of integers** → each ID translated individually; IDs without a translation are omitted from the result array

```json
"selectedPosts": [85, 84, 83]
```
becomes (if 85 has translation 142, 84 has 141, 83 has no translation):
```json
"selectedPosts": [142, 141]
```

### Repeaters

Fields with `translate: "repeater"` are iterated — each item is processed using the `items` schema:

```json
"steps": {
  "translate": "repeater",
  "items": {
    "iconUrl": { "translate": "ignore" },
    "label":   { "translate": "string" }
  }
}
```

Each `{ iconUrl, label }` object in the array is processed independently. Order is preserved.

### Defaults

WordPress stores only attributes that differ from `block.json` defaults. If `buttonText` was never changed from its default `"Book a Demo"`, it won't appear in `post_content` at all.

The plugin handles this via `dt_merge_with_defaults()` — before collecting strings, it merges schema defaults into the block's attributes for any field that:
- Has a `translate` hint that is not `ignore`
- Has a non-empty `default` in `block.json`
- Is not present in the block's current attrs

This ensures default button labels, titles, etc. are always translated and written back explicitly.

---

## Core Blocks

Core blocks do not use `translate.json`. Their content is translated differently based on block type:

**Leaf blocks** — translate `innerHTML` directly. The entire inner HTML is sent to DeepL as a single string with `tag_handling: "html"`:

```
<p>Move, manage and control funds across<br>Fiat &amp; Stablecoins.</p>
```

**Container blocks** — `innerHTML` is not touched. The plugin recurses into `innerBlocks` where leaf blocks handle their own content.

**`core/details`** — special case: the `<summary>` text is extracted with regex, translated, and written back. The inner paragraph is translated via `innerBlocks` recursion.

**Skipped blocks** — `core/image`, `core/gallery`, `core/video` and similar media blocks are skipped entirely — no attr translation, no sync. Their numeric attributes (`id`, `mediaId`) are media attachment IDs, not post IDs, and should not be modified.

---

## Theme Meta Fields

The plugin automatically detects the active theme's text domain from `style.css` and translates all post meta fields whose key contains that prefix.

For a theme with `Text Domain: overchain`, the prefix is `_overchain_`. All meta keys matching `*_overchain_*` on the original post are processed:

| Value type | Action |
|------------|--------|
| Empty string | Copy as-is |
| Numeric | Copy as-is |
| Starts with `#` | Copy as-is (color or anchor) |
| Internal URL | Resolve to target language; delete if no translation found |
| Everything else | Translate via DeepL as rich text |
| Serialized array | Skip |

**Example meta fields automatically handled:**

| Key | Action |
|-----|--------|
| `_overchain_reading_time` | Copy (numeric) |
| `_overchain_archive_posts_per_page` | Copy (numeric) |
| `_overchain_archive_title` | Translate as rich (HTML from TinyMCE) |
| `_overchain_archive_undertitle` | Translate as string |

No configuration needed — works automatically for any theme.

---

## Synced Patterns

When the plugin encounters a `core/block` block (synced pattern):

```
<!-- wp:block {"ref":312} /-->
```

1. Checks if a translated version already exists via `pll_get_post(312, 'lt')`
2. **If found** → swaps `ref` to the translated pattern ID. Content is not modified (preserves manual edits).
3. **If not found** → creates a new `wp_block` post:
   - Translates all block content inside the pattern using the same pipeline
   - Appends language suffix to the title: `"My Pattern [LT]"`
   - Sets the language via `pll_set_post_language()`
   - Links to the original via `pll_save_post_translations()`
   - Swaps `ref` to the new pattern ID

Pattern sync runs **before** URL/ID sync so the new `ref` is set correctly before any further processing.

Patterns can be nested inside any container block — the plugin finds them at any depth via recursion.

---

## Taxonomy Term Translation

The plugin adds a **🌐 Translate entire term** button to all public taxonomy edit and add screens.

### Edit screen

The button appears in a metabox at the bottom of the term edit form, only for non-default language terms that are linked to an original via Polylang.

Clicking it translates:
- **Term name** → via DeepL
- **Slug** → generated from the translated name via `sanitize_title()`
- **Theme-prefixed term meta** → same rules as post meta (copy numeric/empty, resolve URLs, translate everything else)

### Add screen

When Polylang creates a new term translation (URL contains `?from_tag=N&new_lang=xx`), the button appears immediately on the add form — no need to save first.

Clicking it:
1. Creates the term via AJAX (`wp_insert_term`)
2. Sets the Polylang language (`pll_set_term_language`)
3. Links it to the original (`pll_save_term_translations`)
4. Translates name and meta
5. Redirects to the edit screen with translated content already saved

### Supported meta rules (term meta)

| Value type | Action |
|------------|--------|
| Empty or numeric | Copy as-is |
| Starts with `#` | Copy as-is (color or anchor) |
| Internal URL | Resolve to target language; delete if not found |
| Serialized array | Skip |
| Everything else | Translate via DeepL |

No configuration needed — works automatically for all public taxonomies using the active theme's text domain prefix.

---

## Adding a New Custom Block

**Step 1 — Create `translate.json`** in the block's folder alongside `block.json`:

```json
{
  "name": "myplugin/my-block",
  "attributes": {
    "title":        { "translate": "rich" },
    "description":  { "translate": "rich" },
    "buttonText":   { "translate": "string" },
    "buttonLink":   { "translate": "url" },
    "buttonLinkId": { "translate": "postobject" },
    "buttonType":   { "translate": "ignore" },
    "imageId":      { "translate": "ignore" },
    "imageUrl":     { "translate": "ignore" }
  }
}
```

**Step 2 — Add the block folder to Settings** (if not already there):

```
plugins/myplugin/blocks
```

**Step 3 — Clear cache** in Settings → DeepL Translator → **Clear block.json cache**

That's it. No code changes needed.

**For a repeater field**, add `translate: "repeater"` and an `items` object:

```json
{
  "name": "myplugin/my-block",
  "attributes": {
    "items": {
      "translate": "repeater",
      "items": {
        "label":   { "translate": "string" },
        "iconUrl": { "translate": "ignore" },
        "iconId":  { "translate": "ignore" },
        "link":    { "translate": "url" }
      }
    }
  }
}
```

**For a child block**, create `translate.json` in the child block's subfolder:

```
blocks/
└── my-block/
    ├── block.json
    ├── translate.json
    └── my-card/
        ├── block.json
        └── translate.json   ← child block hints
```

Child blocks at depth 2 are automatically discovered — no additional configuration needed.

---

## Fallback Heuristics

When a block has no `translate.json` (or a specific attribute has no `translate` hint), the plugin falls back to heuristics:

**String values are translated if:**
- Not in the skip list (`buttonType`, `type`, `align`, `className`, etc.)
- Not a URL (key doesn't contain `url`, `href`, `src`, `link`, `source`)
- Does not start with `#`
- Contains at least one letter (`\p{L}`)
- Longer than 1 character
- Does not match: hex color (`#fff`), absolute URL (`https://`), CSS value (`12px`)

**Integer values are treated as post IDs if:**
- Key is `postId` or `pageId`
- Key ends with `LinkId` (e.g. `buttonLinkId`)
- Not in the media key list (`mediaId`, `imageId`, `iconId`, `videoId`, `backgroundId`, etc.)

**Arrays without items schema** are treated as post ID arrays and each element is synced individually.

It is strongly recommended to always provide a `translate.json` for custom blocks to avoid unexpected translations or missed fields.

---

## GitHub Releases & Auto-Updater

The plugin includes a built-in updater (`dt-updater.php`) that hooks into the standard WordPress update system.

### How it works

1. On every WordPress update check, the updater calls the GitHub Releases API
2. Compares the latest release version with the installed version
3. If a newer version exists — shows the standard WordPress update notification
4. Clicking **Update now** downloads the release zip from GitHub and installs it automatically

Release data is cached for 12 hours. To force an immediate check:
```
wp-admin/update-core.php?force-check=1
```

### Publishing a new release

1. Update `Version:` in `deepl-translator.php`
2. Commit and push
3. Create and push a tag:
   ```bash
   git tag v2.x.x
   git push origin v2.x.x
   ```
4. GitHub Actions automatically builds `deepl-translator.zip` and publishes the release

All sites with the plugin installed will see the update notification on their next check.

### Repository

```
https://github.com/DmytrivDev/deepl-translator
```

---

## Known Limitations

- **Arrays of objects without `translate.json`** — the plugin attempts heuristic recursion but results may be incomplete. Always provide `translate.json` for blocks with repeaters.
- **`core/math`** — `latex` attribute is skipped (technical formula, not translatable text).
- **Third-party accordion blocks** — processed via heuristics. Consider adding a `translate.json` if the block is used frequently.
- **Polylang required** — the plugin depends on Polylang functions (`pll_get_post`, `pll_get_post_language`, etc.). Deactivating Polylang will prevent translation.
- **Synced patterns** — Polylang does not natively manage `wp_block` post type. Translated patterns are created as standalone posts and linked manually. If Polylang's language list changes, existing links may need to be re-established.
- **Post meta arrays** — serialized meta values are skipped. Only scalar (string/numeric) meta values are processed.
- **Term meta arrays** — same as post meta: serialized values are skipped.
- **Re-translating** — running the translator again on an already-translated page or term overwrites existing content. For patterns, if a translated version already exists it is reused without updating.
- **GitHub API rate limit** — the updater uses unauthenticated requests (60/hour per IP). For private repos or high-traffic environments, add a GitHub token to the request headers.