<?php
/**
 * DeepL Translator — GitHub Auto-Updater
 *
 * Hooks into the WordPress update system to check GitHub Releases for new versions.
 * Updates appear in the standard WP Admin → Updates screen.
 *
 * Expects the plugin's GitHub repo to publish releases with a zip asset named
 * deepl-translator.zip (created automatically by the release workflow).
 */

defined( 'ABSPATH' ) || exit;

class DT_Updater {

    const GITHUB_USER = 'DmytrivDev';  // ← change this
    const GITHUB_REPO = 'deepl-translator';
    const PLUGIN_SLUG = 'deepl-translator/deepl-translator.php';
    const CACHE_KEY   = 'dt_updater_release';
    const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

    public static function init(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'check_update' ] );
        add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_folder_name' ], 10, 4 );
    }

    /**
     * Fetch the latest release info from GitHub.
     * Cached for 12 hours to avoid hammering the API.
     */
    private static function get_latest_release(): ?array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $url      = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_USER,
            self::GITHUB_REPO
        );
        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) {
            return null;
        }

        // Find the zip asset
        $zip_url = '';
        foreach ( $data['assets'] ?? [] as $asset ) {
            if ( $asset['name'] === 'deepl-translator.zip' ) {
                $zip_url = $asset['browser_download_url'];
                break;
            }
        }

        // Fallback: use GitHub's auto-generated source zip
        if ( ! $zip_url ) {
            $zip_url = sprintf(
                'https://github.com/%s/%s/archive/refs/tags/%s.zip',
                self::GITHUB_USER,
                self::GITHUB_REPO,
                $data['tag_name']
            );
        }

        $release = [
            'version'      => ltrim( $data['tag_name'], 'v' ),
            'zip_url'      => $zip_url,
            'changelog'    => $data['body'] ?? '',
            'published_at' => $data['published_at'] ?? '',
        ];

        set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    /**
     * Inject update info into the WordPress update transient.
     */
    public static function check_update( object $transient ): object {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = self::get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $installed = $transient->checked[ self::PLUGIN_SLUG ] ?? '0.0.0';

        if ( version_compare( $release['version'], $installed, '>' ) ) {
            $transient->response[ self::PLUGIN_SLUG ] = (object) [
                'slug'        => 'deepl-translator',
                'plugin'      => self::PLUGIN_SLUG,
                'new_version' => $release['version'],
                'url'         => sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
                'package'     => $release['zip_url'],
                'icons'       => [],
                'banners'     => [],
                'tested'      => get_bloginfo( 'version' ),
                'requires_php'=> '8.1',
            ];
        }

        return $transient;
    }

    /**
     * Populate the "View version details" popup in the Updates screen.
     */
    public static function plugin_info( mixed $result, string $action, object $args ): mixed {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ( $args->slug ?? '' ) !== 'deepl-translator' ) {
            return $result;
        }

        $release = self::get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) [
            'name'          => 'DeepL Translator',
            'slug'          => 'deepl-translator',
            'version'       => $release['version'],
            'author'        => self::GITHUB_USER,
            'homepage'      => sprintf( 'https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO ),
            'requires_php'  => '8.1',
            'sections'      => [
                'changelog' => nl2br( esc_html( $release['changelog'] ) ),
            ],
            'download_link' => $release['zip_url'],
        ];
    }

    /**
     * After download, WordPress unpacks the zip into a folder named after the zip/tag,
     * e.g. "deepl-translator-2.1.0". We rename it to "deepl-translator" so it
     * replaces the existing plugin folder correctly.
     */
    public static function fix_folder_name( string $source, string $remote_source, object $upgrader, array $hook_extra ): string {
        $plugin = $hook_extra['plugin'] ?? '';
        if ( $plugin !== self::PLUGIN_SLUG ) {
            return $source;
        }

        $target = trailingslashit( $remote_source ) . 'deepl-translator/';

        if ( $source !== $target ) {
            global $wp_filesystem;
            if ( $wp_filesystem->move( $source, $target, true ) ) {
                return $target;
            }
        }

        return $source;
    }

    /**
     * Clear the cached release info.
     * Call this from Settings if you want to force an immediate check.
     */
    public static function clear_cache(): void {
        delete_transient( self::CACHE_KEY );
    }
}

DT_Updater::init();