<?php
/**
 * AMP plugin compatibility layer.
 *
 * When the official AMP plugin (or AMPforWP) serves an AMP version of a page,
 * external JS files are blocked and external CSS files may be stripped. This
 * class detects AMP context and switches the table to:
 *   1. Inline CSS (safe for AMP's <style amp-custom> block)
 *   2. A plain, dependency-free HTML table as a JS fallback
 *
 * Non-AMP pages are unaffected.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_AMP_Compat {

    private static $instance = null;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Inject AMP-safe inline CSS via the official AMP plugin's hook.
        add_action( 'amp_post_template_css', [ $this, 'output_amp_inline_css' ] );

        // Guard JS enqueue so scripts are not registered on AMP pages.
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_dequeue_scripts' ], 99 );
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Detect whether the current request is served in AMP mode.
     *
     * Supports:
     * - Official AMP plugin (amp_is_request / is_amp_endpoint)
     * - AMPforWP (ampforwp_is_amp_endpoint)
     * - Manual ?amp=1 query parameter as a last-resort fallback
     *
     * @return bool
     */
    public static function is_amp_request(): bool {
        if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
            return true;
        }
        if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
            return true;
        }
        if ( function_exists( 'ampforwp_is_amp_endpoint' ) && ampforwp_is_amp_endpoint() ) {
            return true;
        }
        // Fallback: ?amp query param (used by the official AMP plugin in paired mode).
        if ( isset( $_GET['amp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }
        return false;
    }

    /**
     * Return AMP-safe inline CSS for table rendering.
     *
     * Uses only basic, AMP-allowed CSS properties (no external fonts,
     * no @import, no behaviour/expression). Sizing is relative so the
     * table adapts to any viewport.
     *
     * @return string  CSS without <style> wrapper.
     */
    public static function get_inline_styles(): string {
        return '
.gt-table-wrap { width: 100%; overflow-x: auto; }
.gt-table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
.gt-table th,
.gt-table td { padding: 8px 12px; text-align: left; border: 1px solid #ddd; vertical-align: top; }
.gt-table thead th { background: #f5f5f5; font-weight: 600; }
.gt-table tbody tr:nth-child(even) { background: #fafafa; }
@media (max-width: 640px) {
  .gt-table th, .gt-table td { padding: 6px 8px; font-size: 0.85em; }
}
';
    }

    /**
     * Return a clean, dependency-free HTML table for use on AMP pages.
     *
     * The returned markup uses only block-level HTML elements with no
     * inline event handlers, no script references, and no external CSS links
     * — safe to embed in any AMP page.
     *
     * @param array $columns     Column definitions: [ ['id' => ..., 'label' => ...], ... ]
     * @param array $rows        Data rows: [ [ column_id => value, ... ], ... ]
     * @param int   $table_id
     * @return string  AMP-safe HTML table string.
     */
    public static function get_fallback_table_html( array $columns, array $rows, int $table_id = 0 ): string {
        $table_id = (int) $table_id;
        $out      = '<div class="gt-table-wrap">';
        $out     .= '<table class="gt-table" id="gt-table-' . $table_id . '">';

        // Header.
        $out .= '<thead><tr>';
        foreach ( $columns as $col ) {
            $out .= '<th>' . esc_html( $col['label'] ?? '' ) . '</th>';
        }
        $out .= '</tr></thead>';

        // Body.
        $out .= '<tbody>';
        foreach ( $rows as $row ) {
            $out .= '<tr>';
            foreach ( $columns as $col ) {
                $value = $row[ $col['id'] ] ?? '';
                $out  .= '<td>' . esc_html( $value ) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody>';

        $out .= '</table></div>';
        return $out;
    }

    // -------------------------------------------------------------------------
    // WP hooks
    // -------------------------------------------------------------------------

    /**
     * Output inline CSS into the AMP plugin's <style amp-custom> block.
     */
    public function output_amp_inline_css(): void {
        echo self::get_inline_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Dequeue GT frontend scripts on AMP pages (AMP does not allow arbitrary JS).
     */
    public function maybe_dequeue_scripts(): void {
        if ( ! self::is_amp_request() ) {
            return;
        }
        wp_dequeue_script( 'gravity-tables-frontend' );
        wp_dequeue_script( 'gt-datatables' );
        wp_dequeue_script( 'datatables' );
    }
}
