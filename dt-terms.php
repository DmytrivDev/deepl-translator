<?php
/**
 * DeepL Translator — Taxonomy Term Translation
 *
 * Adds a "Translate term" metabox to all public taxonomy edit screens.
 * Translates: term name, slug, and all theme-prefixed term meta fields.
 *
 * Requires Polylang and the main deepl-translator.php to be loaded first
 * (uses dt_deepl_translate(), dt_get_theme_meta_prefix(), dt_resolve_url(),
 * dt_locale_to_deepl()).
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────
// 1. METABOX — inject into term edit form
// ─────────────────────────────────────────────

add_action( 'admin_init', function () {
    if ( ! function_exists( 'pll_default_language' ) ) {
        return;
    }

    $taxonomies = get_taxonomies( [ 'public' => true ], 'names' );
    foreach ( $taxonomies as $taxonomy ) {
        // Edit form: term object is available
        add_action( "{$taxonomy}_edit_form", 'dt_term_metabox_render', 99, 2 );
        // Add form: term not yet saved, but from_tag is in URL
        add_action( "{$taxonomy}_add_form_fields", 'dt_term_metabox_render_add', 99 );
    }
} );

/**
 * Metabox on the "Add new term" screen.
 * Term does not exist yet — we rely entirely on ?from_tag=N and ?new_lang=xx from the URL.
 */
function dt_term_metabox_render_add( string $taxonomy ): void {
    if ( ! function_exists( 'pll_default_language' ) ) {
        return;
    }

    $original_id  = isset( $_GET['from_tag'] ) ? (int) $_GET['from_tag'] : 0;
    $new_lang     = isset( $_GET['new_lang'] )  ? sanitize_key( $_GET['new_lang'] ) : '';
    $default_lang = pll_default_language();

    // Only show when Polylang is creating a translation (from_tag + new_lang present)
    if ( ! $original_id || ! $new_lang || $new_lang === $default_lang ) {
        return;
    }

    $lang_upper        = strtoupper( $default_lang );
    $lang_target_upper = strtoupper( $new_lang );
    ?>
    <div class="form-field">
        <label><?php esc_html_e( 'DeepL: Translate term', 'deepl-translator' ); ?></label>
        <p style="margin:0 0 4px;font-size:12px;color:#555">
            <?php printf(
                esc_html__( 'Translates from %1$s into %2$s.', 'deepl-translator' ),
                '<strong>' . esc_html( $lang_upper ) . '</strong>',
                '<strong>' . esc_html( $lang_target_upper ) . '</strong>'
            ); ?>
        </p>
        <button type="button" id="dt-term-translate-btn" class="button button-primary"
            data-term-id="0"
            data-original-id="<?php echo esc_attr( $original_id ); ?>"
            data-new-lang="<?php echo esc_attr( $new_lang ); ?>"
            data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
            data-is-new-term="1"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'dt_translate_term_nonce' ) ); ?>">
            🌐 <?php esc_html_e( 'Translate entire term', 'deepl-translator' ); ?>
        </button>
        <span id="dt-term-status" style="margin-left:10px;font-size:12px"></span>
        <p style="margin-top:6px;font-size:11px;color:#888">
            <?php esc_html_e( 'Saves the term first, then fills in the translation automatically.', 'deepl-translator' ); ?>
        </p>
    </div>
    <?php
}

/**
 * Metabox on the "Edit term" screen.
 */
function dt_term_metabox_render( WP_Term $term, string $taxonomy ): void {
    if ( ! function_exists( 'pll_get_term_language' ) || ! function_exists( 'pll_default_language' ) ) {
        return;
    }

    $default_lang = pll_default_language();
    $term_lang    = pll_get_term_language( $term->term_id );

    if ( ! $term_lang || $term_lang === $default_lang ) {
        return;
    }

    $original_id = function_exists( 'pll_get_term' )
        ? (int) pll_get_term( $term->term_id, $default_lang )
        : 0;

    $lang_upper        = strtoupper( $default_lang );
    $lang_target_upper = strtoupper( $term_lang );
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'DeepL: Translate term', 'deepl-translator' ); ?></th>
            <td>
                <p style="margin-top:0;font-size:12px;color:#555">
                    <?php printf(
                        esc_html__( 'Translates from %1$s into %2$s.', 'deepl-translator' ),
                        '<strong>' . esc_html( $lang_upper ) . '</strong>',
                        '<strong>' . esc_html( $lang_target_upper ) . '</strong>'
                    ); ?>
                </p>
                <?php if ( ! $original_id ) : ?>
                    <p style="color:#c00;font-size:12px">
                        <?php esc_html_e( 'Original term not found. Link this term to the original via Polylang first.', 'deepl-translator' ); ?>
                    </p>
                <?php else : ?>
                    <button type="button" id="dt-term-translate-btn" class="button button-primary"
                        data-term-id="<?php echo esc_attr( $term->term_id ); ?>"
                        data-original-id="<?php echo esc_attr( $original_id ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'dt_translate_term_nonce' ) ); ?>">
                        🌐 <?php esc_html_e( 'Translate entire term', 'deepl-translator' ); ?>
                    </button>
                    <span id="dt-term-status" style="margin-left:10px;font-size:12px"></span>
                    <p style="margin-top:8px;font-size:11px;color:#888">
                        <?php esc_html_e( 'This will overwrite the current term name, slug, and meta.', 'deepl-translator' ); ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}

// ─────────────────────────────────────────────
// 2. ADMIN JS — enqueue on taxonomy screens
// ─────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', function ( string $hook ) {
    if ( ! in_array( $hook, [ 'term.php', 'edit-tags.php' ], true ) ) {
        return;
    }

    if ( ! function_exists( 'pll_default_language' ) ) {
        return;
    }

    wp_enqueue_script(
        'dt-terms-admin',
        plugin_dir_url( __FILE__ ) . 'dt-terms-admin.js',
        [ 'jquery' ],
        '1.0.0',
        true
    );

    wp_localize_script( 'dt-terms-admin', 'DT_TERMS', [
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'termEditUrl' => admin_url( 'term.php?action=edit' ),
        'action'      => 'dt_translate_term',
        'i18n'        => [
            'saving'      => __( 'Saving…', 'deepl-translator' ),
            'translating' => __( 'Translating…', 'deepl-translator' ),
            'success'     => __( 'Done! Reloading…', 'deepl-translator' ),
            'error'       => __( 'Error: ', 'deepl-translator' ),
        ],
    ] );
} );

// ─────────────────────────────────────────────
// 3. AJAX: SAVE NEW TERM
// ─────────────────────────────────────────────

/**
 * Creates a new term via AJAX (called before translation on the add-form screen).
 * Returns the new term_id so JS can immediately run translation on it.
 */
add_action( 'wp_ajax_dt_save_new_term', function (): void {
    check_ajax_referer( 'dt_translate_term_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_categories' ) ) {
        wp_send_json_error( __( 'Insufficient permissions.', 'deepl-translator' ) );
    }

    $taxonomy    = sanitize_key( $_POST['taxonomy']    ?? '' );
    $new_lang    = sanitize_key( $_POST['new_lang']    ?? '' );
    $original_id = (int) ( $_POST['original_id'] ?? 0 );

    if ( ! $taxonomy || ! $new_lang || ! $original_id ) {
        wp_send_json_error( __( 'Invalid parameters.', 'deepl-translator' ) );
    }

    if ( ! taxonomy_exists( $taxonomy ) ) {
        wp_send_json_error( __( 'Unknown taxonomy.', 'deepl-translator' ) );
    }

    // Get original term name as placeholder
    $original_term = get_term( $original_id, $taxonomy );
    $placeholder   = ( $original_term && ! is_wp_error( $original_term ) )
        ? $original_term->name . ' [' . strtoupper( $new_lang ) . ']'
        : 'term-' . $new_lang . '-' . time();

    // Create the term
    $result = wp_insert_term( $placeholder, $taxonomy );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    $term_id = (int) $result['term_id'];

    // Set Polylang language and link to original
    if ( function_exists( 'pll_set_term_language' ) ) {
        pll_set_term_language( $term_id, $new_lang );
    }

    if ( function_exists( 'pll_save_term_translations' ) && function_exists( 'pll_get_term_language' ) ) {
        $default_lang = pll_default_language();
        pll_save_term_translations( [
            $default_lang => $original_id,
            $new_lang     => $term_id,
        ] );
    }

    wp_send_json_success( [ 'term_id' => $term_id ] );
} );

// ─────────────────────────────────────────────
// 4. AJAX: TRANSLATE TERM
// ─────────────────────────────────────────────

add_action( 'wp_ajax_dt_translate_term', 'dt_ajax_translate_term' );

function dt_ajax_translate_term(): void {
    check_ajax_referer( 'dt_translate_term_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_categories' ) ) {
        wp_send_json_error( __( 'Insufficient permissions.', 'deepl-translator' ) );
    }

    $term_id     = (int) ( $_POST['term_id']     ?? 0 );
    $original_id = (int) ( $_POST['original_id'] ?? 0 );

    if ( ! $term_id || ! $original_id ) {
        wp_send_json_error( __( 'Invalid parameters.', 'deepl-translator' ) );
    }

    if ( ! function_exists( 'pll_get_term_language' ) ) {
        wp_send_json_error( __( 'Polylang is not active.', 'deepl-translator' ) );
    }

    // Determine target language
    $target_lang_raw   = pll_get_term_language( $term_id, 'locale' );
    $target_lang_deepl = dt_locale_to_deepl( $target_lang_raw );
    $target_lang_slug  = pll_get_term_language( $term_id, 'slug' );

    if ( ! $target_lang_deepl ) {
        wp_send_json_error(
            sprintf( __( 'Unknown language: %s', 'deepl-translator' ), $target_lang_raw )
        );
    }

    // Get original term
    $original_term = get_term( $original_id );
    if ( ! $original_term || is_wp_error( $original_term ) ) {
        wp_send_json_error( __( 'Original term not found.', 'deepl-translator' ) );
    }

    // ── Collect strings ───────────────────────────────────────────────────────

    $strings = [];
    $jobs    = []; // [ 'type' => 'name'|'meta', 'key' => string ]

    // Term name
    if ( $original_term->name !== '' ) {
        $jobs[]    = [ 'type' => 'name' ];
        $strings[] = $original_term->name;
    }

    // Theme-prefixed term meta
    $prefix    = dt_get_theme_meta_prefix();
    $meta_jobs = []; // separately track meta that needs URL resolve

    if ( $prefix ) {
        $all_meta = get_term_meta( $original_id );
        foreach ( $all_meta as $key => $values ) {
            if ( strpos( $key, $prefix ) === false ) {
                continue;
            }

            $value = $values[0];

            // Skip arrays / serialized
            if ( is_array( $value ) || is_serialized( $value ) ) {
                continue;
            }

            $value = (string) $value;

            // Empty or numeric → copy as-is immediately
            if ( $value === '' || is_numeric( $value ) ) {
                update_term_meta( $term_id, $key, $value );
                continue;
            }

            // Starts with # → copy as-is (anchor or color)
            if ( str_starts_with( $value, '#' ) ) {
                update_term_meta( $term_id, $key, $value );
                continue;
            }

            // Internal URL → resolve, don't translate
            $home = untrailingslashit( home_url() );
            if ( str_starts_with( $value, $home ) || str_starts_with( $value, '/' ) ) {
                $resolved = dt_resolve_url( $value, $target_lang_slug );
                if ( $resolved !== null ) {
                    update_term_meta( $term_id, $key, $resolved );
                } else {
                    delete_term_meta( $term_id, $key );
                }
                continue;
            }

            // Everything else → translate
            $jobs[]    = [ 'type' => 'meta', 'key' => $key ];
            $strings[] = $value;
        }
    }

    // ── Translate ─────────────────────────────────────────────────────────────

    if ( empty( $strings ) ) {
        wp_send_json_success( __( 'No translatable text found.', 'deepl-translator' ) );
    }

    $translations = dt_deepl_translate( $strings, $target_lang_deepl );

    if ( is_wp_error( $translations ) ) {
        wp_send_json_error( $translations->get_error_message() );
    }

    // ── Apply translations ────────────────────────────────────────────────────

    $term_update = [];

    foreach ( $jobs as $idx => $job ) {
        $translated = $translations[ $idx ] ?? null;
        if ( $translated === null ) {
            continue;
        }

        if ( $job['type'] === 'name' ) {
            $term_update['name'] = $translated;
            // Generate slug from translated name, respecting target language
            $term_update['slug'] = sanitize_title( $translated );
        } elseif ( $job['type'] === 'meta' ) {
            update_term_meta( $term_id, $job['key'], $translated );
        }
    }

    // ── Save term name + slug ─────────────────────────────────────────────────

    if ( ! empty( $term_update ) ) {
        $taxonomy   = get_term( $term_id )->taxonomy;
        $wp_result  = wp_update_term( $term_id, $taxonomy, $term_update );

        if ( is_wp_error( $wp_result ) ) {
            wp_send_json_error( $wp_result->get_error_message() );
        }
    }

    wp_send_json_success( __( 'Term translated successfully.', 'deepl-translator' ) );
}