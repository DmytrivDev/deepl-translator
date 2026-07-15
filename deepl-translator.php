<?php
/**
 * Plugin Name: DeepL Translator
 * Description: Translates entire pages via DeepL API: title, native and custom Gutenberg blocks (Polylang). Uses block.json translate hints for precise field handling.
 * Version:     2.1.6
 * Author:      DmytrivDev
 * Author URI:  https://github.com/DmytrivDev
 * GitHub Plugin URI: DmytrivDev/deepl-translator
 * Requires PHP: 8.1
 * Text Domain: deepl-translator
 */

defined( 'ABSPATH' ) || exit;

define( 'DT_OPTION_API_KEY', 'dt_deepl_api_key' );
define( 'DT_OPTION_API_TYPE', 'dt_deepl_api_type' );
define( 'DT_OPTION_BLOCK_DIRS', 'dt_block_dirs' );
define( 'DT_CACHE_KEY', 'dt_block_translate_map' );
define( 'DT_CACHE_GROUP', 'deepl_translator' );

// Fallback: keys that look like URLs when no translate hint is present
define( 'DT_URL_KEY_PATTERNS', [ 'url', 'href', 'src', 'source' ] );

// Fallback: keys that look like post IDs when no translate hint is present
define( 'DT_POST_ID_KEYS', [ 'postId', 'pageId' ] ); // 'ref' handled by dt_sync_patterns(), 'id' excluded (usually media)

// Fallback: technical string keys to skip when no translate hint is present
define( 'DT_SKIP_STRING_KEYS', [
	'buttonType', 'buttonIcon', 'buttonTarget', 'buttonRel', 'buttonAnchor',
	'type', 'style', 'variant', 'size', 'align', 'layout',
	'tagName', 'fontFamily', 'fontSize', 'fontWeight',
	'textAlign', 'verticalAlignment', 'contentJustification',
	'className', 'anchor', 'rel', 'target', 'preload',
	'overlayType', 'backgroundType', 'borderStyle',
	'linkTarget', 'arrowIcon', 'icon', 'iconPosition',
	'videoType', 'mediaType', 'placeholder', 'sourceType',
] );

// ─────────────────────────────────────────────
// DEBUG
// ─────────────────────────────────────────────
/**
 * Diagnostic logging. Enable by adding to wp-config.php:
 *   define( 'DT_DEBUG', true );
 *   define( 'WP_DEBUG', true );
 *   define( 'WP_DEBUG_LOG', true );
 * Then read wp-content/debug.log after running a translation.
 */
function dt_log( string $message ): void {
	if ( defined( 'DT_DEBUG' ) && DT_DEBUG ) {
		error_log( '[DeepL-Translator] ' . $message );
	}
}

/**
 * Classify escape state of serialized content: are \u003c backslashes intact?
 */
function dt_escape_state( string $content ): string {
	$has_escaped = str_contains( $content, '\\u003c' );  // \u003c — correct
	$has_bare    = (bool) preg_match( '/(?<!\\\\)u003c/', $content ); // u003c without backslash — corrupted
	if ( $has_bare && $has_escaped ) return 'MIXED (partially corrupted)';
	if ( $has_bare )                 return 'CORRUPTED (bare u003c, backslashes lost)';
	if ( $has_escaped )              return 'OK (\\u003c intact)';
	return 'no-escapes (no HTML in attrs)';
}

// ─────────────────────────────────────────────
// MODULES
// ─────────────────────────────────────────────
require_once __DIR__ . '/dt-updater.php';
require_once __DIR__ . '/dt-terms.php';

// ─────────────────────────────────────────────
// 1. SETTINGS PAGE
// ─────────────────────────────────────────────
add_action( 'admin_menu', function () {
	add_options_page(
		'DeepL Translator',
		'DeepL Translator',
		'manage_options',
		'deepl-translator',
		'dt_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'dt_settings_group', DT_OPTION_API_KEY, [ 'sanitize_callback' => 'sanitize_text_field' ] );
	register_setting( 'dt_settings_group', DT_OPTION_API_TYPE, [ 'sanitize_callback' => 'sanitize_text_field' ] );
	register_setting( 'dt_settings_group', DT_OPTION_BLOCK_DIRS, [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
} );

// Clear cache when settings saved
add_action( 'update_option_' . DT_OPTION_BLOCK_DIRS, function () {
	wp_cache_delete( DT_CACHE_KEY, DT_CACHE_GROUP );
} );

function dt_settings_page(): void {
	// Handle manual cache clear
	if ( isset( $_POST['dt_clear_cache'] ) && check_admin_referer( 'dt_clear_cache' ) ) {
		wp_cache_delete( DT_CACHE_KEY, DT_CACHE_GROUP );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Cache cleared.', 'deepl-translator' ) . '</p></div>';
	}
	?>
	<div class="wrap">
		<h1>DeepL Translator</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'dt_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dt_api_key">DeepL API Key</label></th>
					<td>
						<input type="password" id="dt_api_key" name="<?php echo esc_attr( DT_OPTION_API_KEY ); ?>"
							value="<?php echo esc_attr( get_option( DT_OPTION_API_KEY, '' ) ); ?>" class="regular-text"
							autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row">API Type</th>
					<td>
						<?php $type = get_option( DT_OPTION_API_TYPE, 'free' ); ?>
						<label>
							<input type="radio" name="<?php echo esc_attr( DT_OPTION_API_TYPE ); ?>" value="free" <?php checked( $type, 'free' ); ?> />
							Free (api-free.deepl.com)
						</label>
						&nbsp;&nbsp;
						<label>
							<input type="radio" name="<?php echo esc_attr( DT_OPTION_API_TYPE ); ?>" value="pro" <?php checked( $type, 'pro' ); ?> />
							Pro (api.deepl.com)
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="dt_block_dirs">Block folders</label>
					</th>
					<td>
						<textarea id="dt_block_dirs" name="<?php echo esc_attr( DT_OPTION_BLOCK_DIRS ); ?>" rows="5"
							class="large-text code"
							placeholder="plugins/overchain-blocks/blocks&#10;themes/overchain/blocks"><?php echo esc_textarea( get_option( DT_OPTION_BLOCK_DIRS, '' ) ); ?></textarea>
						<p class="description">
							One folder per line, relative to <code>wp-content/</code>.<br>
							Each folder should contain block subfolders with a <code>translate.json</code> file.<br>
							Example: <code>plugins/overchain-blocks/blocks</code> →
							<code>blocks/hero/translate.json</code><br>
							Child blocks are also supported: <code>blocks/cards/card/translate.json</code>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save Settings' ); ?>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'dt_clear_cache' ); ?>
			<p>
				<button type="submit" name="dt_clear_cache" class="button">
					Clear block.json cache
				</button>
				<span style="color:#888;font-size:12px;margin-left:8px">
					Run this after editing block.json files.
				</span>
			</p>
		</form>
	</div>
	<?php
}

// ─────────────────────────────────────────────
// 2. BLOCK.JSON READER & CACHE
// ─────────────────────────────────────────────

/**
 * Returns the full translate map for all blocks found in configured folders.
 *
 * Reads translate.json files (separate from block.json to avoid WP REST API warnings).
 *
 * translate.json structure (sits next to block.json):
 * {
 *   "name": "overchain/hero",
 *   "attributes": {
 *     "title":      { "translate": "rich" },
 *     "buttonText": { "translate": "string" },
 *     "buttonLink": { "translate": "url" },
 *     "items": {
 *       "translate": "repeater",
 *       "items": {
 *         "label": { "translate": "string" },
 *         "iconId": { "translate": "ignore" }
 *       }
 *     }
 *   }
 * }
 *
 * The plugin merges translate hints from translate.json with type info from block.json
 * (or WP_Block_Type_Registry) so both sources are used together.
 */
function dt_get_translate_map(): array {
	$cached = wp_cache_get( DT_CACHE_KEY, DT_CACHE_GROUP );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$map = [];
	$dirs_raw = get_option( DT_OPTION_BLOCK_DIRS, '' );
	$dirs = array_filter( array_map( 'trim', explode( "\n", $dirs_raw ) ) );
	$wpc = trailingslashit( WP_CONTENT_DIR );

	foreach ( $dirs as $rel_dir ) {
		$abs_dir = $wpc . ltrim( $rel_dir, '/' );
		if ( ! is_dir( $abs_dir ) ) {
			continue;
		}

		// Scan depth-1 (blocks/hero/translate.json) and depth-2 (blocks/cards/card/translate.json)
		$patterns = [
			trailingslashit( $abs_dir ) . '*/translate.json',
			trailingslashit( $abs_dir ) . '*/*/translate.json',
		];

		$json_files = [];
		foreach ( $patterns as $pattern ) {
			$found = glob( $pattern );
			if ( $found ) {
				$json_files = array_merge( $json_files, $found );
			}
		}

		foreach ( $json_files as $json_path ) {
			$json = file_get_contents( $json_path );
			if ( ! $json )
				continue;

			$data = json_decode( $json, true );
			if ( ! $data || empty( $data['name'] ) )
				continue;

			$block_name = $data['name'];
			$attrs = $data['attributes'] ?? [];
			$map[ $block_name ] = $attrs;
		}
	}

	wp_cache_set( DT_CACHE_KEY, $map, DT_CACHE_GROUP, HOUR_IN_SECONDS * 24 );
	return $map;
}

/**
 * Get merged schema for a specific block.
 *
 * Merges two sources:
 * 1. WP_Block_Type_Registry — provides 'type', 'default' for each attribute
 * 2. translate.json map      — provides 'translate' hint for each attribute
 *
 * Result: each attribute has both 'default' (for dt_merge_with_defaults)
 * and 'translate' (for dt_collect_by_hint) in the same array.
 */
function dt_get_block_attrs( string $block_name ): array {
	// Source 1: WP registry — types and defaults
	$registry = WP_Block_Type_Registry::get_instance();
	$block_type = $registry->get_registered( $block_name );
	$registry_attrs = $block_type ? ( $block_type->attributes ?? [] ) : [];

	// Source 2: our translate.json map — translate hints
	$map = dt_get_translate_map();
	$translate_attrs = $map[ $block_name ] ?? [];

	if ( empty( $translate_attrs ) ) {
		return $registry_attrs;
	}

	if ( empty( $registry_attrs ) ) {
		return $translate_attrs;
	}

	// Merge: registry_attrs as base, overlay translate hints from translate.json
	return dt_merge_schemas( $registry_attrs, $translate_attrs );
}

/**
 * Recursively merge translate hints into registry schema.
 * Registry schema is the base (has type, default).
 * Translate schema overlays translate hints (and repeater items).
 */
function dt_merge_schemas( array $registry, array $translate ): array {
	$merged = $registry;

	foreach ( $translate as $key => $t_field ) {
		if ( ! isset( $merged[ $key ] ) ) {
			$merged[ $key ] = [];
		}

		// Copy translate hint
		if ( isset( $t_field['translate'] ) ) {
			$merged[ $key ]['translate'] = $t_field['translate'];
		}

		// For repeaters: merge items schemas recursively
		if ( isset( $t_field['items'] ) && is_array( $t_field['items'] ) ) {
			$existing_items = $merged[ $key ]['items'] ?? [];
			$merged[ $key ]['items'] = dt_merge_schemas( $existing_items, $t_field['items'] );
		}
	}

	return $merged;
}

// ─────────────────────────────────────────────
// 3. META BOX
// ─────────────────────────────────────────────
add_action( 'add_meta_boxes', function ( string $post_type, WP_Post $post ) {
	if ( ! function_exists( 'pll_get_post_language' ) )
		return;

	$default_lang = pll_default_language();
	$post_lang = pll_get_post_language( $post->ID );

	if ( ! $post_lang && function_exists( 'pll_current_language' ) ) {
		$post_lang = pll_current_language();
	}

	if ( ! $post_lang || $post_lang === $default_lang )
		return;

	add_meta_box(
		'dt_translate_metabox',
		'DeepL: Translate page',
		'dt_metabox_render',
		$post_type,
		'side',
		'low'
	);
}, 10, 2 );

function dt_metabox_render( WP_Post $post ): void {
	if ( ! function_exists( 'pll_default_language' ) ) {
		echo '<p>Polylang is not active.</p>';
		return;
	}

	$default_lang = pll_default_language();
	$post_lang = pll_get_post_language( $post->ID );
	if ( ! $post_lang && function_exists( 'pll_current_language' ) ) {
		$post_lang = pll_current_language();
	}

	$original_id = $post_lang ? pll_get_post( $post->ID, $default_lang ) : 0;

	// For new drafts created by Polylang, the link is not yet in DB.
	// Polylang passes the original post ID in the URL as ?from_post=N.
	if ( ! $original_id && isset( $_GET['from_post'] ) ) {
		$original_id = (int) $_GET['from_post'];
	}

	$is_new_draft = ( $post->post_status === 'auto-draft' );
	?>
	<p style="margin-top:0;font-size:12px;color:#555">
		Translates from
		<strong><?php echo esc_html( strtoupper( (string) $default_lang ) ); ?></strong>
		into
		<strong><?php echo esc_html( strtoupper( (string) $post_lang ) ); ?></strong>.
	</p>
	<?php if ( ! $original_id && ! $is_new_draft ) : ?>
		<p style="color:#c00;font-size:12px">
			Original post not found. Save and link this post to the original via Polylang first.
		</p>
	<?php else : ?>
		<button type="button" id="dt-translate-btn" class="button button-primary" style="width:100%"
			data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-original-id="<?php echo esc_attr( $original_id ); ?>"
			data-is-new-draft="<?php echo $is_new_draft ? '1' : '0'; ?>"
			data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'dt_translate_nonce' ) ); ?>">
			🌐 Translate entire page
		</button>
		<div id="dt-status" style="margin-top:8px;font-size:12px"></div>
		<p style="margin-top:8px;font-size:11px;color:#888">
			This will overwrite the current page content.
		</p>
	<?php endif;
}

// ─────────────────────────────────────────────
// 4. ADMIN JS
// ─────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', function ( string $hook ) {
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) )
		return;

	wp_enqueue_script( 'dt-admin', plugin_dir_url( __FILE__ ) . 'dt-admin.js', [ 'jquery' ], '2.0.0', true );
	wp_localize_script( 'dt-admin', 'DT', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'action' => 'dt_translate',
		'restUrl' => rest_url( 'wp/v2/' ),
		'restNonce' => wp_create_nonce( 'wp_rest' ),
		'i18n' => [
			'saving' => 'Saving draft…',
			'translating' => 'Translating…',
			'success' => 'Done! Reloading…',
			'error' => 'Error: ',
		],
	] );
} );

// ─────────────────────────────────────────────
// 5. AJAX HANDLER
// ─────────────────────────────────────────────
add_action( 'wp_ajax_dt_translate', 'dt_ajax_translate' );

function dt_ajax_translate(): void {
	check_ajax_referer( 'dt_translate_nonce', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Insufficient permissions.' );
	}

	$post_id = (int) ( $_POST['post_id'] ?? 0 );
	$original_id = (int) ( $_POST['original_id'] ?? 0 );

	if ( ! $post_id || ! $original_id ) {
		wp_send_json_error( 'Invalid parameters.' );
	}

	if ( ! function_exists( 'pll_get_post_language' ) ) {
		wp_send_json_error( 'Polylang is not active.' );
	}

	$target_lang_raw = pll_get_post_language( $post_id, 'locale' );
	$target_lang_deepl = dt_locale_to_deepl( $target_lang_raw );
	$target_lang_slug = pll_get_post_language( $post_id, 'slug' );

	if ( ! $target_lang_deepl ) {
		wp_send_json_error( 'Unknown language: ' . $target_lang_raw );
	}

	$original_post = get_post( $original_id );
	if ( ! $original_post ) {
		wp_send_json_error( 'Original post not found.' );
	}

	// ── Collect ───────────────────────────────────────────────────────────────
	$queue = new DT_TranslationQueue();
	$blocks = parse_blocks( $original_post->post_content );

	if ( $original_post->post_title !== '' ) {
		$queue->push( $original_post->post_title, [ 'type' => 'post_title' ] );
	}
	if ( $original_post->post_excerpt !== '' ) {
		$queue->push( $original_post->post_excerpt, [ 'type' => 'post_excerpt' ] );
	}

	dt_collect_blocks( $blocks, [], $queue );

	// ── Translate ─────────────────────────────────────────────────────────────
	$strings = $queue->get_strings();
	if ( empty( $strings ) ) {
		wp_send_json_success( 'No translatable text found.' );
	}

	$translations = dt_deepl_translate( $strings, $target_lang_deepl );
	if ( is_wp_error( $translations ) ) {
		wp_send_json_error( $translations->get_error_message() );
	}

	// ── Apply translations ────────────────────────────────────────────────────
	$post_update = [];
	foreach ( $queue->get_pointers() as $idx => $meta ) {
		$translated = $translations[ $idx ] ?? null;
		if ( $translated === null )
			continue;

		if ( $meta['type'] === 'post_title' ) {
			$post_update['post_title'] = $translated;
		} elseif ( $meta['type'] === 'post_excerpt' ) {
			$post_update['post_excerpt'] = $translated;
		} elseif ( $meta['type'] === 'block_innerhtml' ) {
			dt_set_inner_html( $blocks, $meta['block_path'], $translated, $meta['details'] ?? false );
		} elseif ( $meta['type'] === 'block_attr' ) {
			dt_set_attr( $blocks, $meta['block_path'], $meta['attr_path'], $translated );
		}
	}

	// ── Sync patterns (core/block ref) — must run BEFORE dt_sync_blocks ────────
	// so that ref IDs are not removed by the post ID sync logic
	dt_sync_patterns( $blocks, $target_lang_slug, $target_lang_deepl );

	// ── Sync IDs and URLs ─────────────────────────────────────────────────────
	dt_sync_blocks( $blocks, $target_lang_slug );

	// ── Translate theme meta fields ───────────────────────────────────────────
	dt_translate_theme_meta( $original_id, $post_id, $target_lang_deepl, $target_lang_slug );

	// ── Save ──────────────────────────────────────────────────────────────────
	$new_content = serialize_blocks( $blocks );
	dt_log( sprintf( 'page #%d: saving (content: %s)', $post_id, dt_escape_state( $new_content ) ) );
	dt_save_post_content( $post_id, $new_content, $post_update );

	$saved_page = get_post( $post_id );
	dt_log( sprintf(
		'page #%d: content in DB after save: %s',
		$post_id, $saved_page ? dt_escape_state( $saved_page->post_content ) : 'post missing'
	) );

	wp_send_json_success( 'Translation saved successfully.' );
}

/**
 * Save translated post content directly via $wpdb.
 *
 * IMPORTANT — slashing invariant for this plugin:
 * serialize_blocks() encodes attrs as JSON with \u003c / \u003e / \u0022
 * escape sequences. These backslashes MUST reach the database intact.
 *
 * - $wpdb->update()  → does NOT unslash. Pass data as-is (this function).
 * - wp_insert_post() / wp_update_post() / update_post_meta() /
 *   update_term_meta() / wp_update_term() → DO unslash internally.
 *   Always wrap their data in wp_slash().
 *
 * Never pass serialized block content through stripslashes()/wp_unslash().
 */
function dt_save_post_content( int $post_id, string $content, array $extra_fields = [] ): void {
	global $wpdb;
	$wpdb->update(
		$wpdb->posts,
		array_merge( [ 'post_content' => $content ], $extra_fields ),
		[ 'ID' => $post_id ]
	);
	clean_post_cache( $post_id );
}

// ─────────────────────────────────────────────
// PATTERN SYNC
// ─────────────────────────────────────────────

/**
 * Recursively find all core/block (synced pattern) blocks and ensure
 * a translated version exists, creating one if needed.
 * Updates the ref attribute in-place to point to the translated pattern.
 */
function dt_sync_patterns( array &$blocks, string $target_lang_slug, string $target_lang_deepl ): void {
	foreach ( $blocks as &$block ) {
		$block_name = $block['blockName'] ?? '';

		if ( $block_name === 'core/block' ) {
			$ref_id = (int) ( $block['attrs']['ref'] ?? 0 );
			if ( ! $ref_id )
				continue;

			// Check if translated version already exists
			$translated_ref_id = function_exists( 'pll_get_post' )
				? (int) pll_get_post( $ref_id, $target_lang_slug )
				: 0;

			if ( $translated_ref_id ) {
				$existing = get_post( $translated_ref_id );
				dt_log( sprintf(
					'pattern ref=%d: REUSING existing translation #%d "%s" (status=%s, content: %s)',
					$ref_id,
					$translated_ref_id,
					$existing->post_title ?? '?',
					$existing->post_status ?? '?',
					$existing ? dt_escape_state( $existing->post_content ) : 'post missing'
				) );
			}

			if ( ! $translated_ref_id ) {
				// Create translated pattern
				$translated_ref_id = dt_create_translated_pattern(
					$ref_id,
					$target_lang_slug,
					$target_lang_deepl
				);
			}

			// Swap ref to translated version
			if ( $translated_ref_id ) {
				$block['attrs']['ref'] = $translated_ref_id;
			}
			continue;
		}

		// Recurse into innerBlocks (pattern can be nested inside any container)
		if ( ! empty( $block['innerBlocks'] ) ) {
			dt_sync_patterns( $block['innerBlocks'], $target_lang_slug, $target_lang_deepl );
		}
	}
}

/**
 * Create a translated copy of a wp_block (synced pattern) post.
 *
 * - Translates all block content using the same pipeline as pages
 * - Appends language suffix to the pattern title: "My Pattern [LT]"
 * - Links the new pattern to the original via Polylang
 *
 * @return int New pattern post ID, or 0 on failure.
 */
function dt_create_translated_pattern( int $ref_id, string $target_lang_slug, string $target_lang_deepl ): int {
	$original = get_post( $ref_id );
	if ( ! $original || $original->post_type !== 'wp_block' )
		return 0;

	$pattern_blocks = parse_blocks( $original->post_content );
	$queue = new DT_TranslationQueue();

	dt_collect_blocks( $pattern_blocks, [], $queue );

	$strings = $queue->get_strings();
	if ( ! empty( $strings ) ) {
		$translations = dt_deepl_translate( $strings, $target_lang_deepl );

		if ( ! is_wp_error( $translations ) ) {
			foreach ( $queue->get_pointers() as $idx => $meta ) {
				$translated = $translations[ $idx ] ?? null;
				if ( $translated === null )
					continue;

				if ( $meta['type'] === 'block_innerhtml' ) {
					dt_set_inner_html( $pattern_blocks, $meta['block_path'], $translated, $meta['details'] ?? false );
				} elseif ( $meta['type'] === 'block_attr' ) {
					dt_set_attr( $pattern_blocks, $meta['block_path'], $meta['attr_path'], $translated );
				}
			}
		}
	}

	// Sync IDs and URLs inside the pattern
	dt_sync_blocks( $pattern_blocks, $target_lang_slug );

	$new_title = $original->post_title . ' [' . strtoupper( $target_lang_slug ) . ']';
	$new_content = serialize_blocks( $pattern_blocks );

	// wp_insert_post() expects slashed data and runs wp_unslash() internally.
	// Without wp_slash() the \u003c / \u0022 escapes produced by serialize_blocks()
	// lose their backslashes and rich attrs of custom blocks render as literal
	// "u003cem..." text. See dt_save_post_content() for the same rule.
	dt_log( sprintf(
		'pattern ref=%d: CREATING new translation "%s" (content before insert: %s)',
		$ref_id, $new_title, dt_escape_state( $new_content )
	) );

	// Create the post with EMPTY content first, then write the real content
	// via direct $wpdb->update(). wp_insert_post() runs the content through
	// wp_unslash() and content_save_pre filters (kses, third-party plugins),
	// any of which can strip the \u003c backslashes produced by
	// serialize_blocks(). Bypassing them entirely makes the pattern save
	// path identical to the page save path, which is known-good.
	$new_id = wp_insert_post( wp_slash( [
		'post_title' => $new_title,
		'post_content' => '',
		'post_status' => 'publish',
		'post_type' => 'wp_block',
	] ) );

	if ( ! $new_id || is_wp_error( $new_id ) )
		return 0;

	dt_save_post_content( $new_id, $new_content );

	// Verify what actually landed in the database
	$saved = get_post( $new_id );
	dt_log( sprintf(
		'pattern ref=%d: created #%d (content in DB after save: %s)',
		$ref_id, $new_id, $saved ? dt_escape_state( $saved->post_content ) : 'post missing'
	) );

	// Set language and link translation via Polylang
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $new_id, $target_lang_slug );
	}

	if ( function_exists( 'pll_save_post_translations' ) && function_exists( 'pll_get_post_translations' ) ) {
		$existing = pll_get_post_translations( $ref_id );
		$existing[ $target_lang_slug ] = $new_id;
		pll_save_post_translations( $existing );
	}

	return $new_id;
}

// ─────────────────────────────────────────────
// META TRANSLATION
// ─────────────────────────────────────────────

/**
 * Translate/copy all meta fields from the original post that match the
 * active theme's text domain prefix (e.g. '_overchain_*').
 *
 * Rules per value:
 * - Empty string / numeric / boolean → copy as-is
 * - Array                            → skip (not supported)
 * - Starts with '#'                  → copy as-is (anchor/color)
 * - Looks like internal URL          → resolve to target language, delete if not found
 * - Everything else                  → translate via DeepL as rich text
 */
function dt_translate_theme_meta( int $original_id, int $post_id, string $target_lang_deepl, string $target_lang_slug ): void {
	$prefix = dt_get_theme_meta_prefix();
	if ( ! $prefix )
		return;

	// Get all meta from the original post
	$all_meta = get_post_meta( $original_id );
	if ( empty( $all_meta ) )
		return;

	// Filter keys that contain the theme prefix
	$theme_meta = [];
	foreach ( $all_meta as $key => $values ) {
		if ( strpos( $key, $prefix ) !== false ) {
			$theme_meta[ $key ] = $values[0]; // get_post_meta returns arrays of values
		}
	}

	if ( empty( $theme_meta ) )
		return;

	// Separate into: copy, translate, url, skip
	$to_translate = []; // [ key => value ]
	$to_copy = []; // [ key => value ]
	$to_url = []; // [ key => value ]

	foreach ( $theme_meta as $key => $value ) {
		// Skip arrays (serialized meta)
		if ( is_array( $value ) || is_serialized( $value ) ) {
			continue;
		}

		$value = (string) $value;

		// Empty or numeric → copy
		if ( $value === '' || is_numeric( $value ) ) {
			$to_copy[ $key ] = $value;
			continue;
		}

		// Starts with # → copy (anchor or color)
		if ( str_starts_with( $value, '#' ) ) {
			$to_copy[ $key ] = $value;
			continue;
		}

		// Looks like internal URL → resolve
		$home = untrailingslashit( home_url() );
		if ( str_starts_with( $value, $home ) || str_starts_with( $value, '/' ) ) {
			$to_url[ $key ] = $value;
			continue;
		}

		// Everything else → translate as rich text
		$to_translate[ $key ] = $value;
	}

	// Copy fields
	foreach ( $to_copy as $key => $value ) {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}

	// Resolve URL fields
	foreach ( $to_url as $key => $value ) {
		$resolved = dt_resolve_url( $value, $target_lang_slug );
		if ( $resolved !== null ) {
			update_post_meta( $post_id, $key, wp_slash( $resolved ) );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}

	// Translate text fields
	if ( ! empty( $to_translate ) ) {
		$keys = array_keys( $to_translate );
		$strings = array_values( $to_translate );

		$translations = dt_deepl_translate( $strings, $target_lang_deepl );

		if ( ! is_wp_error( $translations ) ) {
			foreach ( $keys as $i => $key ) {
				$translated = $translations[ $i ] ?? null;
				if ( $translated !== null ) {
					update_post_meta( $post_id, $key, wp_slash( $translated ) );
				}
			}
		}
	}
}

/**
 * Get the active theme's text domain as a meta key prefix.
 * e.g. theme TextDomain 'overchain' → prefix '_overchain_'
 */
function dt_get_theme_meta_prefix(): string {
	$theme = wp_get_theme();
	$textdomain = $theme->get( 'TextDomain' );
	if ( ! $textdomain )
		return '';
	return '_' . sanitize_key( $textdomain ) . '_';
}

// ─────────────────────────────────────────────
// 6. TRANSLATION QUEUE
// ─────────────────────────────────────────────
class DT_TranslationQueue {
	private array $strings = [];
	private array $pointers = [];

	public function push( string $value, array $meta ): void {
		$meta['idx'] = count( $this->strings );
		$this->strings[] = $value;
		$this->pointers[] = $meta;
	}

	public function get_strings(): array {
		return $this->strings;
	}
	public function get_pointers(): array {
		return $this->pointers;
	}
}

// ─────────────────────────────────────────────
// 7. COLLECT: traverse blocks
// ─────────────────────────────────────────────
function dt_collect_blocks( array &$blocks, array $path_prefix, DT_TranslationQueue $queue ): void {
	foreach ( $blocks as $block_index => &$block ) {
		$block_path = array_merge( $path_prefix, [ $block_index ] );
		$block_name = $block['blockName'] ?? '';

		// ── Skip attrs entirely for pure media/technical core blocks ───────────
		if ( dt_is_core_skip_attrs_block( $block_name ) ) {
			// Recurse into innerBlocks just in case (e.g. gallery contains images)
			if ( ! empty( $block['innerBlocks'] ) ) {
				dt_collect_blocks(
					$block['innerBlocks'],
					array_merge( $block_path, [ '__ib' ] ),
					$queue
				);
			}
			continue;
		}

		// ── core/details: summary in innerContent + paragraph in innerBlocks ──
		if ( dt_is_core_details_block( $block_name ) ) {
			// Translate summary text (lives in innerContent before innerBlocks)
			$summary_html = dt_get_details_summary( $block );
			if ( $summary_html !== '' ) {
				$queue->push( $summary_html, [
					'type' => 'block_innerhtml',
					'block_path' => $block_path,
					'details' => true,
				] );
			}
			// Recurse into innerBlocks (the paragraph content)
			if ( ! empty( $block['innerBlocks'] ) ) {
				dt_collect_blocks(
					$block['innerBlocks'],
					array_merge( $block_path, [ '__ib' ] ),
					$queue
				);
			}
			continue;
		}

		// ── Core container blocks: skip innerContent, only recurse innerBlocks ─
		if ( dt_is_core_container_block( $block_name ) ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				dt_collect_blocks(
					$block['innerBlocks'],
					array_merge( $block_path, [ '__ib' ] ),
					$queue
				);
			}
			continue;
		}

		// ── Core text (leaf) blocks: translate all innerContent pieces ────────
		if ( dt_is_core_text_block( $block_name ) ) {
			$html = dt_get_inner_html_full( $block );
			if ( $html !== '' ) {
				$queue->push( $html, [
					'type' => 'block_innerhtml',
					'block_path' => $block_path,
				] );
			}
		}

		// ── Custom blocks: use translate map from block.json ──────────────────
		// Skip attr translation for pure technical/media core blocks
		if ( $block_name && ! empty( $block['attrs'] ) || $block_name ) {
			if ( dt_is_core_skip_attrs_block( $block_name ) ) {
				continue;
			}

			$schema = dt_get_block_attrs( $block_name );
			$block_attrs = $block['attrs'] ?? [];

			// Include defaults for attrs missing from block content
			$merged_attrs = dt_merge_with_defaults( $block_attrs, $schema );

			dt_collect_attrs( $merged_attrs, $schema, $block_path, [], $queue );

			// Write merged attrs back so defaults get saved with translation
			$block['attrs'] = $merged_attrs;
		}

		// ── Recurse into innerBlocks ───────────────────────────────────────────
		if ( ! empty( $block['innerBlocks'] ) ) {
			dt_collect_blocks(
				$block['innerBlocks'],
				array_merge( $block_path, [ '__ib' ] ),
				$queue
			);
		}
	}
	unset( $block );
}

/**
 * Merge block attrs with schema defaults for attrs that have a translate hint
 * but are missing from the block (i.e. using the default value implicitly).
 */
function dt_merge_with_defaults( array $attrs, array $schema ): array {
	foreach ( $schema as $key => $field ) {
		// Only process if field has a translate hint and key is absent from attrs
		if ( isset( $attrs[ $key ] ) )
			continue;
		$translate = $field['translate'] ?? null;
		if ( ! $translate || $translate === 'ignore' )
			continue;
		if ( ! array_key_exists( 'default', $field ) )
			continue;

		$default = $field['default'];

		// Skip empty/zero/false defaults — nothing to translate
		if ( $default === '' || $default === 0 || $default === false || $default === [] || $default === null )
			continue;

		$attrs[ $key ] = $default;
	}
	return $attrs;
}

/**
 * Recursively collect translatable strings from attrs.
 * Uses 'translate' hint from block.json when available, falls back to heuristics.
 */
function dt_collect_attrs(
	array $attrs,
	array $schema,
	array $block_path,
	array $attr_path,
	DT_TranslationQueue $queue
): void {
	foreach ( $attrs as $key => $value ) {
		$field = $schema[ $key ] ?? null;
		$translate = $field['translate'] ?? null;
		$current_path = array_merge( $attr_path, [ $key ] );

		if ( $translate !== null ) {
			// ── Explicit translate hint ────────────────────────────────────────
			dt_collect_by_hint( $key, $value, $translate, $field, $block_path, $current_path, $queue );
		} else {
			// ── No hint: fallback heuristic ───────────────────────────────────
			dt_collect_heuristic( $key, $value, $field, $block_path, $current_path, $queue );
		}
	}
}

/**
 * Handle collection based on explicit translate hint.
 */
function dt_collect_by_hint(
	string $key,
	$value,
	string $translate,
	?array $field,
	array $block_path,
	array $attr_path,
	DT_TranslationQueue $queue
): void {
	switch ( $translate ) {

		case 'rich':
		case 'string':
			if ( ! is_string( $value ) || $value === '' )
				break;
			$queue->push( $value, [
				'type' => 'block_attr',
				'block_path' => $block_path,
				'attr_path' => $attr_path,
			] );
			break;

		case 'url':
		case 'postobject':
		case 'ignore':
			// url and postobject are handled in dt_sync_blocks()
			// ignore = do nothing
			break;

		case 'repeater':
			if ( ! is_array( $value ) )
				break;
			// items schema lives under field['items'] as [ key => [ 'translate' => ... ] ]
			$items_schema = $field['items'] ?? [];
			foreach ( $value as $item_index => $item ) {
				if ( ! is_array( $item ) )
					continue;
				$item_path = array_merge( $attr_path, [ $item_index ] );
				dt_collect_attrs( $item, $items_schema, $block_path, $item_path, $queue );
			}
			break;
	}
}

/**
 * Heuristic collection for attrs without a translate hint.
 */
function dt_collect_heuristic(
	string $key,
	$value,
	?array $field,
	array $block_path,
	array $attr_path,
	DT_TranslationQueue $queue
): void {
	$field_type = $field['type'] ?? null;

	if ( is_string( $value ) && $value !== '' ) {
		// Skip known technical keys and enum values
		if ( in_array( $key, DT_SKIP_STRING_KEYS, true ) )
			return;
		if ( ! empty( $field['enum'] ) )
			return;
		if ( dt_key_is_url( $key ) )
			return;
		if ( str_starts_with( $value, '#' ) )
			return;
		if ( ! dt_value_looks_translatable( $value ) )
			return;

		$queue->push( $value, [
			'type' => 'block_attr',
			'block_path' => $block_path,
			'attr_path' => $attr_path,
		] );

	} elseif ( is_array( $value ) ) {

		if ( $field_type === 'object' ) {
			$props = $field['properties'] ?? [];
			dt_collect_attrs( $value, $props, $block_path, $attr_path, $queue );

		} elseif ( $field_type === 'array' ) {
			$items_schema = $field['items'] ?? null;

			if ( $items_schema === null ) {
				// Array of post IDs — handled in sync, skip here
				return;
			}

			$item_type = $items_schema['type'] ?? null;
			$item_props = $items_schema['properties'] ?? [];

			foreach ( $value as $item_index => $item ) {
				$item_path = array_merge( $attr_path, [ $item_index ] );
				if ( $item_type === 'object' && is_array( $item ) ) {
					// Has properties schema — use it
					if ( ! empty( $item_props ) ) {
						dt_collect_attrs( $item, $item_props, $block_path, $item_path, $queue );
					} else {
						// No properties schema — recurse with heuristics on each key
						foreach ( $item as $sub_key => $sub_value ) {
							dt_collect_heuristic(
								(string) $sub_key, $sub_value, null,
								$block_path,
								array_merge( $item_path, [ $sub_key ] ),
								$queue
							);
						}
					}
				} elseif ( $item_type === 'string' && is_string( $item ) && $item !== '' ) {
					if ( dt_value_looks_translatable( $item ) ) {
						$queue->push( $item, [
							'type' => 'block_attr',
							'block_path' => $block_path,
							'attr_path' => $item_path,
						] );
					}
				}
			}

		} else {
			// Unknown structure — recurse with heuristics
			foreach ( $value as $sub_key => $sub_value ) {
				dt_collect_heuristic(
					(string) $sub_key, $sub_value, null,
					$block_path,
					array_merge( $attr_path, [ $sub_key ] ),
					$queue
				);
			}
		}
	}
}

// ─────────────────────────────────────────────
// 8. SYNC: post IDs and URLs
// ─────────────────────────────────────────────
function dt_sync_blocks( array &$blocks, string $target_lang_slug ): void {
	foreach ( $blocks as &$block ) {
		$block_name = $block['blockName'] ?? '';

		// Skip attr sync for pure media/technical core blocks.
		// Their numeric attrs (id, mediaId, etc.) are media attachment IDs,
		// not translatable post IDs — syncing would delete them.
		if ( $block_name && ! empty( $block['attrs'] ) && ! dt_is_core_skip_attrs_block( $block_name ) ) {
			$schema = dt_get_block_attrs( $block_name );
			dt_sync_attrs( $block['attrs'], $schema, $target_lang_slug );
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			dt_sync_blocks( $block['innerBlocks'], $target_lang_slug );
		}
	}
}

function dt_sync_attrs( array &$attrs, array $schema, string $target_lang_slug ): void {
	foreach ( $attrs as $key => &$value ) {
		$field = $schema[ $key ] ?? null;
		$translate = $field['translate'] ?? null;
		$type = $field['type'] ?? null;

		// ── Explicit hint ──────────────────────────────────────────────────────
		if ( $translate === 'url' ) {
			if ( is_string( $value ) ) {
				$resolved = dt_resolve_url( $value, $target_lang_slug );
				if ( $resolved === null ) {
					unset( $attrs[ $key ] );
				} else {
					$value = $resolved;
				}
			}
			continue;
		}

		if ( $translate === 'postobject' ) {
			if ( is_int( $value ) && $value > 0 ) {
				$translated = dt_translate_post_id( $value, $target_lang_slug );
				if ( $translated !== null ) {
					$value = $translated;
				} else {
					unset( $attrs[ $key ] );
				}
			} elseif ( is_array( $value ) ) {
				$synced = [];
				foreach ( $value as $pid ) {
					if ( ! is_int( $pid ) || $pid <= 0 )
						continue;
					$translated = dt_translate_post_id( $pid, $target_lang_slug );
					if ( $translated !== null ) {
						$synced[] = $translated;
					}
					// No translation — omit
				}
				$value = $synced;
			}
			continue;
		}

		if ( $translate === 'repeater' ) {
			if ( is_array( $value ) ) {
				$items_schema = $field['items'] ?? [];
				foreach ( $value as &$item ) {
					if ( is_array( $item ) ) {
						dt_sync_attrs( $item, $items_schema, $target_lang_slug );
					}
				}
			}
			continue;
		}

		if ( in_array( $translate, [ 'rich', 'string', 'ignore' ], true ) ) {
			continue; // Already handled in collect/apply
		}

		// ── Heuristic fallback ─────────────────────────────────────────────────

		// URL by key name
		if ( is_string( $value ) && $value !== '' && dt_key_is_url( $key ) ) {
			$resolved = dt_resolve_url( $value, $target_lang_slug );
			if ( $resolved === null ) {
				unset( $attrs[ $key ] );
			} else {
				$value = $resolved;
			}
			continue;
		}

		// Integer that looks like a post ID
		if ( is_int( $value ) && $value > 0 && dt_key_is_post_id( $key ) ) {
			$translated = dt_translate_post_id( $value, $target_lang_slug );
			if ( $translated !== null ) {
				$value = $translated;
			} else {
				unset( $attrs[ $key ] );
			}
			continue;
		}

		// Array of post IDs (no items schema)
		if ( is_array( $value ) && $type === 'array' && ! isset( $field['items'] ) ) {
			$synced = [];
			foreach ( $value as $pid ) {
				if ( ! is_int( $pid ) || $pid <= 0 )
					continue;
				$translated = dt_translate_post_id( $pid, $target_lang_slug );
				if ( $translated !== null ) {
					$synced[] = $translated;
				}
			}
			$value = $synced;
			continue;
		}

		// Array with items schema: recurse (repeater without hint)
		if ( is_array( $value ) && $type === 'array' && isset( $field['items'] ) ) {
			$item_props = $field['items']['properties'] ?? [];
			foreach ( $value as &$item ) {
				if ( is_array( $item ) ) {
					dt_sync_attrs( $item, $item_props, $target_lang_slug );
				}
			}
			continue;
		}

		// Object with properties: recurse
		if ( is_array( $value ) && $type === 'object' ) {
			$props = $field['properties'] ?? [];
			dt_sync_attrs( $value, $props, $target_lang_slug );
		}
	}
}

// ─────────────────────────────────────────────
// 9. APPLY HELPERS
// ─────────────────────────────────────────────
function dt_set_inner_html( array &$blocks, array $block_path, string $new_html, bool $is_details_summary = false ): void {
	$ref = &dt_ref_block( $blocks, $block_path );
	if ( $ref === null )
		return;

	if ( $is_details_summary ) {
		// core/details: replace only the summary text inside innerContent
		foreach ( $ref['innerContent'] as $i => $piece ) {
			if ( is_string( $piece ) && trim( $piece ) !== '' ) {
				$ref['innerContent'][ $i ] = preg_replace(
					'/<summary([^>]*)>.*?<\/summary>/si',
					// addcslashes: "\\" and "$" in translated text are special
					// inside a preg_replace replacement string
					'<summary$1>' . addcslashes( $new_html, '\\$' ) . '</summary>',
					$piece
				);
				break;
			}
		}
		return;
	}

	$block_name = $ref['blockName'] ?? '';

	// Blocks where content spans multiple innerContent pieces (e.g. core/table, core/pullquote)
	$multi_piece_blocks = [
		'core/table', 'core/pullquote', 'core/verse',
		'core/preformatted', 'core/code',
	];

	if ( in_array( $block_name, $multi_piece_blocks, true ) ) {
		// Replace all non-null pieces with the translated content
		// Only replace the first non-null piece (the whole content is one string for these blocks)
		foreach ( $ref['innerContent'] as $i => $piece ) {
			if ( is_string( $piece ) && trim( $piece ) !== '' ) {
				$ref['innerContent'][ $i ] = $new_html;
				break;
			}
		}
		return;
	}

	// Default: replace first non-empty string piece
	foreach ( $ref['innerContent'] as $i => $piece ) {
		if ( is_string( $piece ) && trim( $piece ) !== '' ) {
			$ref['innerContent'][ $i ] = $new_html;
			break;
		}
	}

	// Some core blocks duplicate text in attrs['content']
	if ( isset( $ref['attrs']['content'] ) ) {
		$ref['attrs']['content'] = wp_strip_all_tags( $new_html );
	}
}

function dt_set_attr( array &$blocks, array $block_path, array $attr_path, string $value ): void {
	$ref = &dt_ref_block( $blocks, $block_path );
	if ( $ref === null )
		return;

	$target = &$ref['attrs'];
	$last = array_pop( $attr_path );
	foreach ( $attr_path as $step ) {
		if ( ! isset( $target[ $step ] ) )
			return;
		$target = &$target[ $step ];
	}
	$target[ $last ] = $value;
}

function &dt_ref_block( array &$blocks, array $path ): mixed {
	$null = null;
	$ref = &$blocks;
	foreach ( $path as $step ) {
		if ( $step === '__ib' ) {
			if ( ! isset( $ref['innerBlocks'] ) )
				return $null;
			$ref = &$ref['innerBlocks'];
		} elseif ( isset( $ref[ $step ] ) ) {
			$ref = &$ref[ $step ];
		} else {
			return $null;
		}
	}
	return $ref;
}

// ─────────────────────────────────────────────
// 10. URL RESOLVER
// ─────────────────────────────────────────────

/**
 * Resolve an internal URL to its target-language version.
 *
 * Rules:
 * - Starts with # → return as-is (anchor, not a URL)
 * - External domain → return as-is
 * - Internal + translation found → return translated permalink
 * - Internal + no translation → return null (caller removes the field)
 */
function dt_resolve_url( string $url, string $target_lang_slug ): ?string {
	// Anchor — leave as-is
	if ( str_starts_with( $url, '#' ) ) {
		return $url;
	}

	$home = untrailingslashit( home_url() );

	// Relative path starting with / — treat as internal
	$is_relative = str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' );
	$is_internal = $is_relative || str_starts_with( $url, $home );

	// External URL — leave as-is
	if ( ! $is_internal ) {
		return $url;
	}

	if ( ! function_exists( 'pll_get_post' ) ) {
		return $url;
	}

	$langs = function_exists( 'PLL' ) ? PLL()->model->get_languages_list() : [];

	// Strip language prefix from path
	$path = trim( parse_url( $url, PHP_URL_PATH ) ?? '', '/' );
	foreach ( $langs as $lang_obj ) {
		$prefix = trim( parse_url( $lang_obj->home_url ?? '', PHP_URL_PATH ) ?? '', '/' );
		if ( $prefix !== '' && str_starts_with( $path, $prefix . '/' ) ) {
			$path = substr( $path, strlen( $prefix ) + 1 );
			break;
		}
	}
	$slug = trim( $path, '/' );

	// Home page
	if ( $slug === '' ) {
		foreach ( $langs as $lang_obj ) {
			if ( $lang_obj->slug === $target_lang_slug ) {
				return trailingslashit( $lang_obj->home_url ?? home_url() );
			}
		}
		return null;
	}

	// Find post by path (pages, hierarchical CPTs)
	$found = get_page_by_path( $slug, OBJECT, get_post_types( [ 'public' => true ] ) );
	$post_id = $found ? $found->ID : 0;

	// Fallback: find by leaf slug (posts, flat CPTs)
	if ( ! $post_id ) {
		$parts = explode( '/', $slug );
		$leaf = end( $parts );
		global $wpdb;
		$post_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
             WHERE post_name = %s AND post_status = 'publish' AND post_type != 'attachment'
             LIMIT 1",
			$leaf
		) );
	}

	if ( $post_id ) {
		$translated_id = (int) pll_get_post( $post_id, $target_lang_slug );
		if ( $translated_id ) {
			return get_permalink( $translated_id ) ?: null;
		}
		// Internal but no translation — remove
		return null;
	}

	// CPT archives
	foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
		if ( ! $pt->has_archive )
			continue;
		$archive_slug = is_string( $pt->has_archive ) ? $pt->has_archive : ( $pt->rewrite['slug'] ?? $pt->name );
		if ( $slug !== $archive_slug )
			continue;
		foreach ( $langs as $lang_obj ) {
			if ( $lang_obj->slug === $target_lang_slug ) {
				return trailingslashit( $lang_obj->home_url ?? home_url() ) . trailingslashit( $archive_slug );
			}
		}
	}

	return null;
}

function dt_translate_post_id( int $post_id, string $target_lang_slug ): ?int {
	if ( ! function_exists( 'pll_get_post' ) )
		return null;
	$translated = (int) pll_get_post( $post_id, $target_lang_slug );
	return $translated > 0 ? $translated : null;
}

// ─────────────────────────────────────────────
// 11. CORE BLOCK HELPERS
// ─────────────────────────────────────────────
/**
 * Leaf blocks: entire translatable content lives in innerContent.
 * We read innerContent directly and send it to DeepL.
 */
function dt_is_core_text_block( string $block_name ): bool {
	return in_array( $block_name, [
		'core/paragraph',
		'core/heading',
		'core/list-item',
		'core/button',
		'core/freeform',
		'core/html',
		'core/pullquote',
		'core/verse',
		'core/preformatted',
		'core/code',
		'core/table',
	], true );
}

/**
 * Container blocks: content lives in innerBlocks (or mixed innerContent+innerBlocks).
 * We skip their own innerContent and only recurse into innerBlocks.
 * Exception: core/details has summary in innerContent — handled separately.
 */
function dt_is_core_container_block( string $block_name ): bool {
	return in_array( $block_name, [
		'core/quote',
		'core/list',
		'core/column',
		'core/columns',
		'core/group',
		'core/cover',
		'core/media-text',
		'core/buttons',
		'core/gallery',
	], true );
}

/**
 * Blocks whose attrs should never be translated (no schema, skip heuristics entirely).
 * These blocks have only technical/media attrs — translating them breaks enum values.
 */
function dt_is_core_skip_attrs_block( string $block_name ): bool {
	return in_array( $block_name, [
		'core/image',
		'core/gallery',
		'core/video',
		'core/audio',
		'core/file',
		'core/embed',
		'core/spacer',
		'core/separator',
		'core/icon',
		'core/math',
		'core/latest-posts',
		'core/latest-comments',
		'core/archives',
		'core/calendar',
		'core/categories',
		'core/search',
		'core/shortcode',
		'core/social-links',
		'core/social-link',
		'core/tag-cloud',
		'core/widget-group',
	], true );
}

/**
 * core/details has summary text in innerContent before innerBlocks.
 * We translate all non-null innerContent pieces AND recurse into innerBlocks.
 */
function dt_is_core_details_block( string $block_name ): bool {
	return $block_name === 'core/details';
}

/**
 * Get first non-empty innerContent string (for simple leaf blocks).
 */
function dt_get_inner_html( array $block ): string {
	foreach ( $block['innerContent'] as $piece ) {
		if ( is_string( $piece ) && trim( $piece ) !== '' )
			return $piece;
	}
	return '';
}

/**
 * Get full innerContent as a single string (for blocks like core/table, core/pullquote
 * where all content lives in innerContent with no innerBlocks).
 * Concatenates all string pieces, skipping null (innerBlock placeholders).
 */
function dt_get_inner_html_full( array $block ): string {
	$parts = [];
	foreach ( $block['innerContent'] as $piece ) {
		if ( is_string( $piece ) && trim( $piece ) !== '' ) {
			$parts[] = $piece;
		}
	}
	return implode( '', $parts );
}

/**
 * For core/details: extract summary text from innerContent.
 * The summary lives in the first innerContent string piece, inside <summary>...</summary>.
 */
function dt_get_details_summary( array $block ): string {
	foreach ( $block['innerContent'] as $piece ) {
		if ( is_string( $piece ) && trim( $piece ) !== '' ) {
			// Extract text inside <summary>...</summary>
			if ( preg_match( '/<summary[^>]*>(.*?)<\/summary>/si', $piece, $matches ) ) {
				return trim( $matches[1] );
			}
			return '';
		}
	}
	return '';
}

// ─────────────────────────────────────────────
// 12. HEURISTIC HELPERS
// ─────────────────────────────────────────────
function dt_key_is_url( string $key ): bool {
	foreach ( DT_URL_KEY_PATTERNS as $pattern ) {
		if ( stripos( $key, $pattern ) !== false )
			return true;
	}
	return false;
}

function dt_key_is_post_id( string $key ): bool {
	// Media attachment keys — never post IDs
	$media_keys = [
		'mediaId', 'imageId', 'iconId', 'videoId', 'posterId',
		'backgroundId', 'thumbnailId', 'attachmentId', 'avatarId',
		'decoLeftId', 'decoRightId', 'logoId', 'coverId',
	];
	if ( in_array( $key, $media_keys, true ) )
		return false;

	foreach ( DT_POST_ID_KEYS as $pattern ) {
		if ( strcasecmp( $key, $pattern ) === 0 )
			return true;
	}
	// Only match *LinkId pattern (e.g. buttonLinkId) — not generic *Id
	return (bool) preg_match( '/LinkId$/i', $key );
}

function dt_value_looks_translatable( string $value ): bool {
	if ( ! preg_match( '/\p{L}/u', $value ) )
		return false;
	if ( strlen( $value ) < 2 )
		return false;
	if ( preg_match( '/^https?:\/\//i', $value ) )
		return false;
	if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) )
		return false;
	if ( preg_match( '/^\d+(\.\d+)?(px|em|rem|%|vh|vw|pt)?$/', $value ) )
		return false;
	return true;
}

// ─────────────────────────────────────────────
// 13. DEEPL API
// ─────────────────────────────────────────────

/**
 * Harden cURL settings for DeepL requests only.
 *
 * - CONNECTTIMEOUT: WP/Requests defaults to 10s for the connect phase
 *   regardless of the 'timeout' arg — too tight for flaky local networks.
 * - IPRESOLVE V4: broken IPv6 routing is a classic cause of
 *   "cURL error 28: Connection timed out" during the connect phase
 *   (DNS returns AAAA, IPv6 is not routable, curl hangs until timeout).
 *   Disable via: add_filter( 'dt_force_ipv4', '__return_false' );
 */
add_action( 'http_api_curl', function ( $handle, $parsed_args, $url ) {
	if ( ! is_string( $url ) || stripos( $url, 'deepl.com' ) === false ) {
		return;
	}
	curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 30 );
	if ( apply_filters( 'dt_force_ipv4', true ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
		curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
	}
}, 10, 3 );

function dt_deepl_translate( array $texts, string $target_lang ) {
	$api_key = get_option( DT_OPTION_API_KEY, '' );
	$api_type = get_option( DT_OPTION_API_TYPE, 'free' );

	if ( empty( $api_key ) ) {
		return new WP_Error( 'no_api_key', 'DeepL API key is not set (Settings → DeepL Translator).' );
	}

	$endpoint = ( $api_type === 'pro' )
		? 'https://api.deepl.com/v2/translate'
		: 'https://api-free.deepl.com/v2/translate';

	$args = [
		'timeout' => 60,
		'headers' => [
			'Authorization' => 'DeepL-Auth-Key ' . $api_key,
			'Content-Type' => 'application/json',
		],
		'body' => wp_json_encode( [
			'target_lang' => strtoupper( $target_lang ),
			'tag_handling' => 'html',
			'text' => $texts,
		] ),
	];

	// Debug: if any INPUT string already contains u003 sequences, the source
	// content in the DB is corrupted — the bug is upstream of translation.
	foreach ( $texts as $i => $t ) {
		if ( str_contains( $t, 'u003' ) ) {
			dt_log( sprintf( 'DeepL INPUT[%d] contains u003 artifacts: %s', $i, mb_substr( $t, 0, 200 ) ) );
		}
	}

	// Transport-level errors (timeouts, DNS hiccups, connection resets) are
	// often transient — retry up to 3 times with a short backoff.
	$max_attempts = (int) apply_filters( 'dt_deepl_retries', 3 );
	$response = null;

	for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
		$response = wp_remote_post( $endpoint, $args );

		if ( ! is_wp_error( $response ) ) {
			break;
		}

		if ( $attempt < $max_attempts ) {
			sleep( $attempt ); // 1s, 2s backoff
		}
	}

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'deepl_network',
			sprintf(
				'%s (after %d attempts)',
				$response->get_error_message(),
				$max_attempts
			)
		);
	}

	$code = wp_remote_retrieve_response_code( $response );
	$json = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 ) {
		return new WP_Error( 'deepl_error',
			sprintf( 'DeepL error %d: %s', $code, $json['message'] ?? '' )
		);
	}

	$result = array_column( $json['translations'] ?? [], 'text' );

	foreach ( $result as $i => $t ) {
		if ( str_contains( $t, 'u003' ) ) {
			dt_log( sprintf( 'DeepL OUTPUT[%d] contains u003 artifacts: %s', $i, mb_substr( $t, 0, 200 ) ) );
		}
	}

	if ( count( $result ) !== count( $texts ) ) {
		return new WP_Error( 'deepl_mismatch', 'DeepL returned an unexpected number of translations.' );
	}

	return $result;
}

// ─────────────────────────────────────────────
// 14. LOCALE → DEEPL
// ─────────────────────────────────────────────
function dt_locale_to_deepl( string $locale ): string {
	$map = [
		'uk' => 'UK', 'uk_UA' => 'UK',
		'de_DE' => 'DE', 'de_CH' => 'DE', 'de_AT' => 'DE',
		'fr_FR' => 'FR', 'fr_BE' => 'FR', 'fr_CA' => 'FR',
		'es_ES' => 'ES', 'es_MX' => 'ES',
		'it_IT' => 'IT', 'pl_PL' => 'PL',
		'nl_NL' => 'NL', 'nl_BE' => 'NL',
		'pt_PT' => 'PT-PT', 'pt_BR' => 'PT-BR',
		'ru_RU' => 'RU', 'ja' => 'JA',
		'zh_CN' => 'ZH', 'zh_TW' => 'ZH',
		'ko_KR' => 'KO', 'tr_TR' => 'TR',
		'sv_SE' => 'SV', 'da_DK' => 'DA',
		'fi' => 'FI', 'nb_NO' => 'NB',
		'cs_CZ' => 'CS', 'sk_SK' => 'SK',
		'hu_HU' => 'HU', 'ro_RO' => 'RO',
		'bg_BG' => 'BG', 'el' => 'EL',
		'et_EE' => 'ET', 'lv' => 'LV',
		'lt_LT' => 'LT', 'sl_SI' => 'SL',
		'id_ID' => 'ID',
		'en_US' => 'EN-US', 'en_GB' => 'EN-GB', 'en' => 'EN-US',
	];
	return $map[ $locale ] ?? strtoupper( substr( $locale, 0, 2 ) );
}