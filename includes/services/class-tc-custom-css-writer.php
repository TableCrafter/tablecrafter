<?php
/**
 * Cross-platform custom CSS file writer for Gravity Tables.
 *
 * Writes per-table custom CSS to the uploads directory using the WP_Filesystem
 * API so the correct file abstraction layer is used on all server types - 
 * including Windows (IIS) and network-share (UNC) paths where the raw PHP
 * filesystem call fails silently.
 *
 * Failure path: if the CSS file cannot be written (permissions, locked FS,
 * UNC path issues), the service registers an admin notice and signals the
 * caller to fall back to inline <style> output.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Custom_CSS_Writer {

    private const CSS_DIR  = 'gravity-tables';
    private const NOTICE_OPTION = 'gt_css_write_error';

    /**
     * Write $css_content to a per-table CSS file in the uploads directory.
     *
     * @param int    $table_id    Table ID (used to build the filename).
     * @param string $css_content Sanitized CSS string.
     * @return bool  True on success, false if the file could not be written.
     */
    public static function write_css_file( int $table_id, string $css_content ): bool {
        $upload  = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            // @codeCoverageIgnoreStart
            self::register_notice( $upload['error'] );
            return false;
            // @codeCoverageIgnoreEnd
        }

        $base_dir = wp_normalize_path( $upload['basedir'] );
        $css_dir  = $base_dir . DIRECTORY_SEPARATOR . self::CSS_DIR;
        $css_file = $css_dir . DIRECTORY_SEPARATOR . 'table-' . (int) $table_id . '.css';

        if ( ! self::init_filesystem() ) {
            self::register_notice( __( 'WP_Filesystem could not be initialised. Custom CSS saved as inline style.', 'tc-data-tables' ) );
            return false;
        }

        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wp_filesystem;
        // @codeCoverageIgnoreEnd

        // Ensure directory exists.
        if ( ! $wp_filesystem->is_dir( $css_dir ) ) {
            if ( ! $wp_filesystem->mkdir( $css_dir, FS_CHMOD_DIR ) ) {
                self::register_notice( sprintf(
                    /* translators: %s: directory path */
                    __( 'Cannot create CSS directory "%s". Check server permissions. CSS saved as inline style.', 'tc-data-tables' ),
                    esc_html( $css_dir )
                ) );
                return false;
            }
            // Block direct URL access.
            $wp_filesystem->put_contents( $css_dir . DIRECTORY_SEPARATOR . 'index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
        }

        if ( ! $wp_filesystem->put_contents( $css_file, $css_content, FS_CHMOD_FILE ) ) {
            self::register_notice( sprintf(
                /* translators: %s: file path */
                __( 'Cannot write CSS file "%s". Check server permissions or whether the path is on a network share. CSS saved as inline style (fallback).', 'tc-data-tables' ),
                esc_html( $css_file )
            ) );
            return false;
        }

        // Clear any previous error notice on success.
        delete_option( self::NOTICE_OPTION );
        return true;
    }

    /**
     * Return the public URL for a table's CSS file, or null if it does not exist.
     *
     * @param int $table_id
     * @return string|null
     */
    public static function get_css_url( int $table_id ): ?string {
        $upload   = wp_upload_dir();
        $base_dir = wp_normalize_path( $upload['basedir'] );
        $css_file = $base_dir . DIRECTORY_SEPARATOR . self::CSS_DIR . DIRECTORY_SEPARATOR . 'table-' . (int) $table_id . '.css';

        if ( ! file_exists( $css_file ) ) {
            return null;
        }

        $base_url = trailingslashit( $upload['baseurl'] );
        return $base_url . self::CSS_DIR . '/table-' . (int) $table_id . '.css';
    }

    /**
     * Delete the CSS file for a table (e.g. on table deletion or CSS clear).
     *
     * @param int $table_id
     */
    public static function delete_css_file( int $table_id ): void {
        if ( ! self::init_filesystem() ) {
            return;
        }
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wp_filesystem;
        // @codeCoverageIgnoreEnd

        $upload   = wp_upload_dir();
        $base_dir = wp_normalize_path( $upload['basedir'] );
        $css_file = $base_dir . DIRECTORY_SEPARATOR . self::CSS_DIR . DIRECTORY_SEPARATOR . 'table-' . (int) $table_id . '.css';

        if ( $wp_filesystem->exists( $css_file ) ) {
            $wp_filesystem->delete( $css_file );
        }
    }

    /**
     * Emit an admin notice stored from a previous failed write attempt.
     * Hook to admin_notices.
     */
    public static function maybe_show_admin_notice(): void {
        $error = get_option( self::NOTICE_OPTION );
        if ( ! $error ) {
            return;
        }
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo '<strong>' . esc_html__( 'TableCrafter - CSS Write Warning:', 'tc-data-tables' ) . '</strong> ';
        echo esc_html( $error );
        echo '</p></div>';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Initialise the WP_Filesystem global.
     *
     * @return bool True if $wp_filesystem is now usable.
     */
    private static function init_filesystem(): bool {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wp_filesystem;
        // @codeCoverageIgnoreEnd

        if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
            return true;
        }

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            // @codeCoverageIgnoreStart
            require_once ABSPATH . 'wp-admin/includes/file.php';
            // @codeCoverageIgnoreEnd
        }

        return WP_Filesystem();
    }

    /**
     * Persist an error message as an option so it survives redirects and can
     * be displayed by maybe_show_admin_notice() on the next admin page load.
     */
    private static function register_notice( string $message ): void {
        update_option( self::NOTICE_OPTION, $message, false );

        // Also surface immediately if we're already in an admin context.
        if ( is_admin() ) {
            add_settings_error(
                'gt_custom_css',
                'gt_css_write_failed',
                $message,
                'warning'
            );
        }
    }
}
