# DeepL Translator

WordPress plugin that translates entire pages and taxonomy terms via the DeepL API, using Polylang for language management.

## Features

- Translates page title, excerpt, and all Gutenberg blocks in one click
- Custom blocks — uses `translate.json` schema for precise field control
- Repeater fields at any nesting depth
- Rich text with HTML tags preserved (`<mark>`, `<br>`, `<strong>`)
- Internal URLs resolved to target language via Polylang
- Post object IDs swapped for translated equivalents
- Synced patterns (`core/block`) — translated copies created automatically
- Theme meta fields — auto-detected by theme text domain prefix
- Taxonomy term translation — name, slug, and term meta
- Works on new draft translations before first save

## Requirements

- WordPress 6.3+
- PHP 8.1+
- [Polylang](https://wordpress.org/plugins/polylang/) (free or Pro)
- DeepL API key ([free or pro](https://www.deepl.com/pro-api))

## Installation

1. Go to [Releases](../../releases) and download the latest `deepl-translator.zip`
2. In WordPress admin: **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → DeepL Translator** and enter your DeepL API key

## Configuration

**Settings → DeepL Translator**

| Field | Description |
|-------|-------------|
| DeepL API Key | Your DeepL API key |
| API Type | `Free` or `Pro` |
| Block folders | One folder per line, relative to `wp-content/`. Each should contain block subfolders with a `translate.json` file |

**Block folders example:**
```
plugins/my-blocks/blocks
themes/my-theme/blocks
```

After adding or editing any `translate.json` files — click **Clear block.json cache**.

## Adding translation support for a custom block

Create `translate.json` in the block folder alongside `block.json`:

```json
{
  "name": "myplugin/my-block",
  "attributes": {
    "title":       { "translate": "rich" },
    "description": { "translate": "rich" },
    "buttonText":  { "translate": "string" },
    "buttonLink":  { "translate": "url" },
    "buttonLinkId":{ "translate": "postobject" },
    "buttonType":  { "translate": "ignore" },
    "imageId":     { "translate": "ignore" },
    "imageUrl":    { "translate": "ignore" }
  }
}
```

Then go to **Settings → DeepL Translator → Clear block.json cache**.

## Translate values

| Value | Description |
|-------|-------------|
| `rich` | Rich text — HTML tags preserved |
| `string` | Plain text |
| `url` | Internal URL — resolved to target language |
| `postobject` | Post ID — swapped for translated equivalent |
| `repeater` | Array of objects — each item processed recursively |
| `ignore` | Skip this field |

## Updating

Updates appear in the standard **WordPress Admin → Updates** screen when a new release is published on GitHub.

## License

MIT