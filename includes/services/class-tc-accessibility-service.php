<?php
/**
 * WCAG 2.1 AA accessibility service for Gravity Tables.
 *
 * Provides PHP helpers for injecting correct ARIA attributes, visually-hidden
 * screen-reader text spans, and enqueuing the accessibility CSS/JS assets.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Accessibility_Service {

    /**
     * Return the aria-sort attribute string for a sortable column header.
     *
     * @param string $current_sort_column  The column key currently being sorted.
     * @param string $column_key           The column key for this header.
     * @param string $current_sort_dir     'asc' or 'desc'.
     * @return string e.g. 'aria-sort="ascending"', 'aria-sort="descending"', or 'aria-sort="none"'
     */
    public static function get_aria_sort_attr(
        string $current_sort_column,
        string $column_key,
        string $current_sort_dir = 'asc'
    ): string {
        if ($current_sort_column !== $column_key) {
            return 'aria-sort="none"';
        }
        return $current_sort_dir === 'desc'
            ? 'aria-sort="descending"'
            : 'aria-sort="ascending"';
    }

    /**
     * Return a visually-hidden <span> containing screen-reader-only text.
     *
     * The span uses the .gt-sr-only class (defined in accessibility.css) which
     * clips the element to a 1×1 px box — visible to screen readers but not
     * sighted users.
     *
     * @param string $text Plain text to announce to screen readers.
     * @return string HTML string.
     */
    public static function get_visually_hidden_span(string $text): string {
        return '<span class="gt-sr-only">' . esc_html($text) . '</span>';
    }

    /**
     * Return an aria-label attribute string for icon-only buttons.
     *
     * @param string $label Descriptive label (e.g. "Export to CSV").
     * @return string e.g. 'aria-label="Export to CSV"'
     */
    public static function get_aria_label(string $label): string {
        return 'aria-label="' . esc_attr($label) . '"';
    }

    /**
     * Return aria-describedby attribute pointing to a live region element.
     *
     * @param string $region_id ID of the aria-live region element.
     * @return string
     */
    public static function get_aria_describedby(string $region_id): string {
        return 'aria-describedby="' . esc_attr($region_id) . '"';
    }

    /**
     * Enqueue accessibility CSS and JS assets.
     *
     * Hooked onto wp_enqueue_scripts from the main plugin init so the assets
     * are available on any page that renders a Gravity Tables shortcode.
     */
    public static function enqueue_a11y_assets(): void {
        // #546 — only ship a11y assets when the current page actually
        // renders a Gravity Tables table. Saves ~25KB CSS + ~15KB JS on
        // shortcode-less pages sitewide. The `gt_always_enqueue_assets`
        // filter inside the gate bypasses this for cache-plugin scenarios.
        if (!TC_Asset_Enqueue_Gate::page_has_table()) {
            return;
        }
        wp_enqueue_style(
            'gt-accessibility',
            TC_PLUGIN_URL . 'assets/css/accessibility.css',
            ['gravity-tables-frontend'],
            TC_VERSION
        );

        wp_enqueue_script(
            'gt-accessibility',
            TC_PLUGIN_URL . 'assets/js/gt-accessibility.js',
            ['jquery'],
            TC_VERSION,
            true
        );
    }
}
