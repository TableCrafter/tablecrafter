<?php
/**
 * Fuzzy (approximate) search service for Gravity Tables.
 *
 * When the per-table `enable_fuzzy_search` setting is on, the server-side
 * entry query expands each search word to also match via SOUNDEX so that
 * common typos and phonetically-similar strings surface results:
 *
 *   "Smth"   → matches rows containing "Smith"
 *   "colour" → matches "color" (shared SOUNDEX code C460)
 *   "Jon"    → matches "John" and "Joan"
 *
 * The LIKE sub-expression is always included so exact substring matches
 * remain fast and correct; SOUNDEX is appended as an OR so it only
 * widens the result set, never narrows it.
 *
 * SOUNDEX() is supported in MySQL 5.6+ and all MariaDB versions ≥ 5.1.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Fuzzy_Search_Service {

    /**
     * Whether fuzzy search is enabled for this table.
     *
     * @param array $settings Table configuration array.
     * @return bool
     */
    public static function is_enabled( array $settings ): bool {
        return ! empty( $settings['enable_fuzzy_search'] );
    }

    /**
     * Build the inner WHERE conditions for the meta-value EXISTS sub-query.
     *
     * Returns an array of SQL OR-conditions that the caller wraps inside
     * the EXISTS (SELECT 1 … WHERE entry_id = e.id AND (<conditions>)) block.
     *
     * The returned fragments use placeholders handled by the caller via
     * $wpdb->prepare() so no raw user input is ever interpolated directly.
     *
     * @param \wpdb  $db          WordPress database object.
     * @param string $search_term Raw, unsanitised search string from the request.
     * @param string $meta_alias  Alias for the gf_entry_meta table in the sub-query.
     * @return array{sql: string, params: list<string>}
     *   'sql' - comma-free SQL snippet ready to drop into WHERE … AND (…)
     *   'params' - ordered binding values for $wpdb->prepare()
     */
    public static function build_meta_where( \wpdb $db, string $search_term, string $meta_alias = 'em_search' ): array {
        $conditions = [];
        $params     = [];

        // Exact substring match - always present.
        $conditions[] = "{$meta_alias}.meta_value LIKE %s";
        $params[]     = '%' . $db->esc_like( $search_term ) . '%';

        // Per-word SOUNDEX match for approximate results.
        $words = array_filter( array_map( 'trim', preg_split( '/\s+/u', $search_term ) ) );
        foreach ( $words as $word ) {
            if ( mb_strlen( $word ) < 2 ) {
                continue; // Single characters produce too many false positives.
            }
            $conditions[] = "SOUNDEX({$meta_alias}.meta_value) = SOUNDEX(%s)";
            $params[]     = $word;
        }

        return [
            'sql'    => implode( ' OR ', $conditions ),
            'params' => $params,
        ];
    }
}
