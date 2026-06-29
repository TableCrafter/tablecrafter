<?php
/**
 * RTL (right-to-left) language support service for Gravity Tables.
 *
 * Detects the current text direction via the WordPress is_rtl() function,
 * provides helper methods for injecting dir/class attributes into the table
 * wrapper, and conditionally enqueues the frontend-rtl.css stylesheet.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_RTL_Service {

    /**
     * Whether the current WP site/page is in RTL mode.
     *
     * Delegates to WordPress core is_rtl() so it respects the active language.
     *
     * @return bool
     */
    public static function is_rtl(): bool {
        return function_exists('is_rtl') && is_rtl();
    }

    /**
     * Return the HTML dir attribute for the table wrapper element.
     *
     * @return string e.g. 'dir="rtl"' or 'dir="ltr"'
     */
    public static function get_dir_attr(): string {
        return self::is_rtl() ? 'dir="rtl"' : 'dir="ltr"';
    }

    /**
     * Return extra CSS class names to apply to the table wrapper.
     *
     * @return string Space-separated class string, or empty string.
     */
    public static function get_wrapper_classes(): string {
        return self::is_rtl() ? 'gt-rtl' : 'gt-ltr';
    }

    /**
     * Enqueue the RTL-specific frontend stylesheet when the site is in RTL mode.
     *
     * Should be called on wp_enqueue_scripts (or inside the shortcode render
     * to late-enqueue only when the shortcode is present).
     */
    public static function enqueue_rtl_styles(): void {
        if (!self::is_rtl()) {
            return;
        }

        // Dependency must match the handle registered in
        // class-tc-shortcode.php::register_assets (since v4.8.14 the handle
        // is `gravity-tables-frontend`, not the legacy `gt-frontend`).
        // wp_enqueue_style silently skips a missing dep, so the legacy
        // string didn't break loading — it just didn't enforce load order.
        wp_enqueue_style(
            'gt-frontend-rtl',
            TC_PLUGIN_URL . 'assets/css/frontend-rtl.css',
            ['gravity-tables-frontend'],
            TC_VERSION
        );
    }
}
