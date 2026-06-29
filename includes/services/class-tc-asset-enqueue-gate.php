<?php
/**
 * TC_Asset_Enqueue_Gate
 *
 * Issue #546 — gate frontend asset enqueue on whether the current
 * request will actually render a Gravity Tables table. Mirrors a
 * recurring TablePress / WP Table Builder PageSpeed regression where
 * the table plugin's CSS / JS shipped to every page sitewide.
 *
 * Detection:
 *   - `has_shortcode($post->post_content, 'gravity_table')` — classic
 *     shortcode in the post content.
 *   - `has_block('gravity-tables/table', $post)` — Gutenberg block.
 *
 * Builders (Elementor, Divi, Beaver) embed the shortcode at render
 * time, so widget / template-part detection is harder. For those, the
 * `TC_Shortcode::render_table()` path enqueues the bundle directly the
 * moment the shortcode actually renders, so assets are still loaded
 * when needed. For pre-flight detection on builder pages, integrators
 * use the `gt_always_enqueue_assets` filter described below.
 *
 * Filter:
 *   `gt_always_enqueue_assets` — return true to force `page_has_table()`
 *   to true, e.g. for cache-plugin scenarios where the cached HTML is
 *   served before WordPress's `is_singular()` is decided. Default false.
 *
 * @since 4.7.19
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Asset_Enqueue_Gate {

    /**
     * Whether the current request will render at least one Gravity
     * Tables table — and therefore needs the frontend asset bundle.
     */
    public static function page_has_table(?\WP_Post $post = null): bool {
        if (apply_filters('gt_always_enqueue_assets', false)) {
            return true;
        }
        if ($post === null && function_exists('get_post')) {
            $post = get_post();
        }
        if (!$post || !is_object($post)) {
            return false;
        }
        $content = isset($post->post_content) ? (string) $post->post_content : '';
        if ($content === '') {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        if (function_exists('has_shortcode') && has_shortcode($content, 'gravity_table')) {
            return true;
        }
        if (function_exists('has_block') && has_block('gravity-tables/table', $post)) {
            return true;
        }
        return false;
    }
}
