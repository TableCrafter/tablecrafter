<?php
/**
 * TC_Cell_Content_Service
 *
 * Pure static helpers for sanitizing and rendering manual-table cell values.
 * Extracted so both TC_Ajax (save path) and TC_Shortcode (render path) can
 * delegate to the same logic, and so shim tests can load this file without
 * pulling in WP-dependent classes.
 *
 * Storage model: sanitization is performed per the capability of the LAST
 * EDITOR (the user making the save request), mirroring the TablePress model.
 * Defense-in-depth applies at render time via wp_kses_post regardless.
 *
 * @since 8.1.0 (#2369)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TC_Cell_Content_Service {

    /**
     * Sanitize a manual-table cell value at save time.
     *
     * - Users with `unfiltered_html` capability: stored raw (defense-in-depth
     *   at render via wp_kses_post).
     * - All other users: wp_kses_post preserves safe markup (b, strong, a,
     *   img, etc.) while stripping script tags and on* event-handler attributes.
     *
     * @param string $value           Raw cell value from POST.
     * @param bool   $can_unfiltered  Whether the current user has unfiltered_html.
     * @return string
     */
    public static function sanitize_cell_value( string $value, bool $can_unfiltered ): string {
        if ( $can_unfiltered ) {
            // Trusted user — store exactly as-is; render-time wp_kses_post
            // provides defense-in-depth.
            return $value;
        }
        // wp_kses_post preserves safe HTML while stripping script tags and
        // dangerous attributes such as onclick / onerror.
        return wp_kses_post( $value );
    }

    /**
     * Render a manual-table cell value for frontend output.
     *
     * When $render_shortcodes is true, do_shortcode expands any shortcodes in
     * the stored value BEFORE wp_kses_post is applied to the expanded output.
     * This ordering is intentional: shortcode output goes through kses just
     * like hand-authored HTML does. Shortcodes that emit iframes or scripts
     * will therefore be stripped — this is the safe default and is a known
     * accepted trade-off (follow-up issue for opt-out if needed).
     *
     * @param string $value             Stored cell value (already sanitized at save).
     * @param bool   $render_shortcodes Whether to expand shortcodes.
     * @return string  wp_kses_post-safe HTML, ready for direct output inside <td>.
     */
    public static function render_cell_value( string $value, bool $render_shortcodes ): string {
        if ( $render_shortcodes ) {
            // do_shortcode runs on the already-sanitized stored value.
            // wp_kses_post then applies to the expanded output for safety.
            $value = do_shortcode( $value );
        }
        return wp_kses_post( $value );
    }
}
