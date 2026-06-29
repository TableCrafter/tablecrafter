<?php
/**
 * TC_Cache_Invalidator
 *
 * Issue #550 — invalidate page-cache plugins when table data changes.
 * Mirrors a recurring TablePress / WP Table Builder complaint: tables
 * embedded as static rendered HTML are served from the page-cache
 * snapshot until the cache TTL expires, so users see stale data after
 * a save/import.
 *
 * Three cache plugins are integrated by firing each one's documented
 * post-purge action. Missing plugins are no-ops because the actions
 * have no listeners.
 *
 *   - WP Rocket            — `rocket_clean_post( $post_id )`
 *                            (function call; no-op when WP Rocket is not loaded)
 *   - W3 Total Cache       — `do_action( 'w3tc_flush_post', $post_id )`
 *   - LiteSpeed Cache      — `do_action( 'litespeed_purge_post', $post_id )`
 *   - WordPress core       — `clean_post_cache( $post_id )` (object cache)
 *
 * Filter:
 *   `gt_purge_post_ids( array $post_ids, int $table_id )` — lets users
 *   extend OR replace the post-id list before purging. Useful for
 *   builder pages (Elementor / Divi / Beaver) where the static
 *   shortcode-detection LIKE query misses, and for sites where a
 *   table is embedded in a template part rather than a post.
 *
 * Bootstrap:
 *   `tablecrafter.php` registers a single listener that subscribes
 *   to the table-altering hooks already fired elsewhere in the
 *   plugin:
 *     - gravity_tables_after_save_table     -> invalidate_for_table
 *     - gravity_tables_after_import         -> invalidate_for_table
 *     - gravity_tables_after_delete_table   -> invalidate_for_table
 *     - gravity_tables_entry_updated /
 *       gravity_tables_entry_created /
 *       gravity_tables_entry_deleted        -> resolve form_id -> table_id
 *
 * The new listener is purely additive — existing hook signatures and
 * fire-shapes are unchanged.
 *
 * @since 4.7.20
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Cache_Invalidator {

    /**
     * Find IDs of published posts whose post_content references the
     * given Gravity Tables table — either via classic shortcode
     * `[gravity_table id="N"]` or Gutenberg block
     * `<!-- wp:gravity-tables/table {"id":N`.
     *
     * @param int        $table_id  Gravity Tables table id.
     * @param ?\wpdb     $db        Optional $wpdb override (for tests).
     * @return array<int>           Post ids.
     */
    public static function find_posts_referencing_table(int $table_id, $db = null): array {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        if (!$db || !is_object($db)) {
            return [];
        }
        $shortcode_like = '%[gravity_table id=%"' . $table_id . '"%';
        $block_like     = '%<!-- wp:gravity-tables/table%"id":' . $table_id . '%';
        $shortcode_like_alt = '%[gravity_table id=' . $table_id . '%';
        $sql = $db->prepare(
            "SELECT ID FROM {$db->posts} WHERE post_status = 'publish' AND ("
            . " post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )",
            $shortcode_like,
            $block_like,
            $shortcode_like_alt
        );
        $rows = $db->get_col($sql);
        return array_map('intval', is_array($rows) ? $rows : []);
    }

    /**
     * Fire the post-purge hook of every supported page-cache plugin
     * for `$post_id`, plus WordPress core's object-cache purge.
     *
     * Each integration is independent — missing plugins simply have
     * no listener for their action and the call is a no-op.
     */
    public static function purge_post_caches(int $post_id): void {
        if ($post_id <= 0) {
            return;
        }
        // WP Rocket — function call when present; the hook is also
        // documented but the function-call path is canonical.
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }
        // W3 Total Cache.
        do_action('w3tc_flush_post', $post_id);
        // LiteSpeed Cache.
        do_action('litespeed_purge_post', $post_id);
        // WordPress core object cache.
        if (function_exists('clean_post_cache')) {
            clean_post_cache($post_id);
        }
    }

    /**
     * Find every post embedding the table and purge each one's
     * page-cache entries.
     */
    public static function invalidate_for_table(int $table_id): void {
        if ($table_id <= 0) {
            return;
        }

        // #1674 — clear the plugin's own object-cache entry for this table.
        // Entry create/update/delete + import route through here only, and the
        // page-cache purges below don't touch it, so on a persistent object
        // cache (Redis/Memcached) the gt_table_<id> config row could go stale.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('gt_table_' . $table_id, 'gravity_tables');
        }

        $ids = self::find_posts_referencing_table($table_id);
        // Allow integrators to extend / replace the list (e.g. builder pages).
        $ids = apply_filters('gt_purge_post_ids', $ids, $table_id);
        if (!is_array($ids)) {
            return;
        }
        foreach ($ids as $post_id) {
            self::purge_post_caches((int) $post_id);
        }
    }
}
