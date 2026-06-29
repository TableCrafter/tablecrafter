<?php
/**
 * TC_Where_Used_Service
 *
 * Slice 1 of #542. Surfaces the "where am I using this table" view
 * admins repeatedly ask for before deleting tables. Scans post content
 * for all supported shortcode variants — `[tablecrafter id="X"]` (canonical),
 * `[gravity_table id="X"]` and `[gravity_tables id="X"]` (deprecated) —
 * plus the Gutenberg block prefix, and returns the matching posts so the
 * Tables list can render a count + collapsible list per row.
 *
 * Design notes:
 *   - On-render WP_Query is fine for the typical 5–50-table install.
 *     Save-time indexer + on-deactivate cleanup are deferred to slice 2
 *     once scale matters.
 *   - SQL LIKE narrows the candidate set; a precise PHP regex per id
 *     does the final filtering so we don't false-positive on adjacent
 *     ids (e.g. id="30" vs id="3").
 *   - Caches `wp_posts.post_content` LIKE results per request to keep
 *     the Tables list page snappy when there are many tables.
 *
 * @since 4.7.75
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Where_Used_Service {

    /** Per-request cache of candidate post rows that contain ANY
     *  gravity_table shortcode. Keyed by 'all'. Re-fetching once per
     *  page load is enough; no need to fetch per-table. */
    private static $candidates_cache = null;

    /**
     * Find every published / draft / private / future post whose content
     * contains a recognised shortcode or block for the given table id.
     *
     * @param int $table_id
     * @return array<int, array{post_id:int,title:string,edit_url:string,view_url:string,post_type:string,post_status:string}>
     */
    public static function find_usages(int $table_id): array {
        if ($table_id <= 0) {
            return [];
        }

        $candidates = self::get_candidate_posts();
        $hits = [];
        foreach ($candidates as $row) {
            if (self::content_matches((string) $row->post_content, $table_id)) {
                $hits[] = [
                    'post_id'     => (int) $row->ID,
                    'title'       => (string) $row->post_title,
                    'edit_url'    => function_exists('get_edit_post_link') ? (string) get_edit_post_link($row->ID, '') : '',
                    'view_url'    => function_exists('get_permalink') ? (string) get_permalink($row->ID) : '',
                    'post_type'   => (string) $row->post_type,
                    'post_status' => (string) $row->post_status,
                ];
            }
        }

        // Sites with custom post types can extend / override the list — useful
        // for builders that store shortcode in postmeta or a JSON field.
        return (array) apply_filters('gt_where_used_results', $hits, $table_id);
    }

    /**
     * Pure helper — true iff `$content` contains any recognised shortcode
     * or block targeting exactly `$table_id`. Recognised shortcode names:
     *   - [tablecrafter ...] (canonical)
     *   - [gravity_table ...]  (deprecated singular)
     *   - [gravity_tables ...] (deprecated plural)
     * Public so tests can exercise all variants without booting WordPress.
     */
    public static function content_matches(string $content, int $table_id): bool {
        if ($content === '' || $table_id <= 0) {
            return false;
        }

        // Cheap pre-filter: bail when none of the known embed substrings appear.
        if (strpos($content, '[tablecrafter') === false
            && strpos($content, '[gravity_table') === false
            && strpos($content, 'wp:gravity-tables') === false) {
            return false;
        }

        $id = preg_quote((string) $table_id, '/');

        // Classic shortcode — all three tag names, three quoting styles.
        // The trailing lookahead `(?=[\s\]])` ensures id="3" doesn't match id="30".
        $shortcode_pattern = '/\[(?:tablecrafter|gravity_tables?)\s+[^\]]*?id\s*=\s*'
                           . '(?:"' . $id . '"|\'' . $id . '\'|' . $id . '(?=[\s\]]))'
                           . '/';
        if (preg_match($shortcode_pattern, $content) === 1) {
            return true;
        }

        // #542 slice 1.3: Gutenberg block detection. The block comment marker
        // is `<!-- wp:gravity-tables/table {"id":N`. Matched with `(?=\D)`
        // so id=3 doesn't false-match id=30 (next char must NOT be a digit).
        $block_pattern = '/<!--\s*wp:gravity-tables\/table\s*\{[^}]*"id"\s*:\s*'
                       . $id . '(?=\D)/i';
        return preg_match($block_pattern, $content) === 1;
    }

    /**
     * Fetch every post whose content contains the literal `[gravity_table`
     * substring. SQL-side narrowing — PHP regex does the precise filter.
     *
     * @return array<int, object> Array of objects with ID, post_title,
     *                            post_content, post_type, post_status.
     */
    private static function get_candidate_posts(): array {
        if (self::$candidates_cache !== null) {
            return self::$candidates_cache;
        }
        if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
            return self::$candidates_cache = [];
        }
        global $wpdb;

        // Allowed statuses: published + scheduled + draft + private. Skip
        // trash / auto-draft / inherit (revisions). Filterable for sites
        // that want to include CPT-specific statuses.
        $statuses = (array) apply_filters('gt_where_used_post_statuses', [
            'publish', 'future', 'draft', 'private', 'pending',
        ]);
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        // #542 slice 1.2: cap raised from 200 to 500 (slice 1's 200 was too
        // tight for sites that embed a table in 200+ posts). Filterable via
        // `gt_where_used_candidate_limit` so sites with even larger usage
        // can extend it without a code patch. Sanitized to a sane bound to
        // avoid runaway SELECTs.
        $limit = (int) apply_filters('gt_where_used_candidate_limit', 500);
        if ($limit < 1) { $limit = 500; }
        if ($limit > 100000) { $limit = 100000; }

        // LIKE patterns cover all shortcode names + Gutenberg block prefix.
        // [gravity_table% catches both [gravity_table and [gravity_tables.
        // [tablecrafter% catches the canonical new shortcode.
        $like_tc        = '%[tablecrafter%';
        $like_shortcode = '%[gravity_table%';
        $like_block     = '%wp:gravity-tables/table%';
        $sql = $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type, post_status
             FROM {$wpdb->posts}
             WHERE (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)
               AND post_status IN ($placeholders)
             ORDER BY post_title ASC
             LIMIT %d",
            array_merge([$like_tc, $like_shortcode, $like_block], $statuses, [$limit])
        );
        $rows = $wpdb->get_results($sql);
        self::$candidates_cache = is_array($rows) ? $rows : [];
        return self::$candidates_cache;
    }

    /**
     * Test seam — let the test runner reset the in-memory cache between
     * scenarios without faking the wpdb global.
     */
    public static function _reset_cache_for_tests(): void {
        self::$candidates_cache = null;
    }
}
