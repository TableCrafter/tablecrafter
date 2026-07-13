<?php
/**
 * TC_Table_Usage_Indexer
 *
 * Issue #542 - slice 1 of 3. Pure helper that extracts the list of
 * Gravity Tables ids referenced in a post's content (classic
 * shortcode + Gutenberg block). Substrate the future save_post hook
 * + admin "Where used" badge bind to.
 *
 * Slice 2 ships the DB migration creating `{prefix}gt_table_usage`
 * (post_id, table_id) plus the save_post / before_delete_post hooks
 * that call the indexer plus a bulk one-shot re-indexer button on
 * the settings page.
 *
 * Slice 3 ships the admin UI: count badge per table + collapsible
 * list of post titles with edit links + warning when deleting a
 * table that's still referenced.
 *
 * Note: the inverse direction (given a table id, find referencing
 * posts via LIKE query) already ships in
 * `TC_Cache_Invalidator::find_posts_referencing_table()` since #550
 * (v4.7.20). This indexer materializes the relation as a forward
 * lookup so the admin UI can render badges in O(1) per table.
 *
 * @since 4.7.45
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Table_Usage_Indexer {

    /**
     * Extract a deduped, sorted array of integer table ids referenced
     * in $content via either:
     *
     *   - Classic shortcode: [gravity_table id="N"]  (single OR
     *     double quotes, optional whitespace, also accepts bare
     *     numeric id without quotes).
     *   - Gutenberg block:   <!-- wp:gravity-tables/table {"id":N
     *     (with optional whitespace around the colon).
     *
     * Non-numeric ids are silently dropped.
     *
     * @param mixed $content
     * @return int[]
     */
    public static function extract_table_ids($content): array {
        if (!is_string($content) || $content === '') {
            return [];
        }
        $found = [];

        // Classic shortcode: [gravity_table id="N"] / id='N' / id=N
        if (preg_match_all(
            '/\[gravity_table\s+[^\]]*\bid\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|(\d+))/i',
            $content,
            $m
        )) {
            foreach ($m[0] as $idx => $_) {
                $candidate = $m[1][$idx] !== '' ? $m[1][$idx]
                    : ($m[2][$idx] !== '' ? $m[2][$idx] : $m[3][$idx]);
                if (ctype_digit((string) $candidate)) {
                    $found[(int) $candidate] = true;
                }
            }
        }

        // Gutenberg block: <!-- wp:gravity-tables/table {"id":N
        if (preg_match_all(
            '/<!--\s*wp:gravity-tables\/table\s*\{[^}]*"id"\s*:\s*(\d+)/i',
            $content,
            $m
        )) {
            foreach ($m[1] as $candidate) {
                $found[(int) $candidate] = true;
            }
        }

        $ids = array_keys($found);
        sort($ids);
        return $ids;
    }

    /**
     * Return the join-table rows the indexer should write for a
     * given post: one row per unique table id referenced.
     *
     * @return array<int,array{post_id:int,table_id:int}>
     */
    public static function index_post(int $post_id, string $content): array {
        $ids = self::extract_table_ids($content);
        $rows = [];
        foreach ($ids as $tid) {
            $rows[] = [
                'post_id'  => $post_id,
                'table_id' => $tid,
            ];
        }
        return $rows;
    }
}
