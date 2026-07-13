<?php
/**
 * TC_Manual_Rows_Service
 *
 * Issue #2366 - P1-1a Manual tables: data-source foundation.
 *
 * Storage: a dedicated {prefix}tablecrafter_manual_rows table (one row per
 * data row, separate from wp_gravity_tables.settings). Rationale:
 *
 *   - Manual tables can have thousands of rows. Storing them inside the
 *     settings JSON would bloat wp_gravity_tables.settings on every load
 *     and would also bloat every revision snapshot (TC_Revisions_Service
 *     copies the settings column verbatim).
 *
 *   - Soft-delete (trash/restore) keeps the rows alive; only
 *     force-delete / cron-purge purges them. No orphan rows accumulate
 *     on the trash side of the lifecycle.
 *
 *   - Duplicate-table copies rows to the new table_id via copy_rows().
 *
 * Column definitions (manual_columns: [{key, label, type}]) stay in
 * settings JSON because they are small (typically <10 items) and snapshot
 * cleanly with the rest of the table config.
 *
 * Schema (created by dbDelta on activation / upgrade):
 *
 *   tablecrafter_manual_rows (
 *     id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 *     table_id   BIGINT UNSIGNED NOT NULL,
 *     row_index  BIGINT UNSIGNED NOT NULL DEFAULT 0,
 *     row_json   LONGTEXT NOT NULL,
 *     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 *     KEY (table_id, row_index)
 *   )
 *
 * @since 8.1.0 (#2366)
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) { exit; }
// @codeCoverageIgnoreEnd

class TC_Manual_Rows_Service {

    /** Database table name (without prefix). */
    const TABLE_SUFFIX = 'tablecrafter_manual_rows';

    /** dbDelta-compatible CREATE TABLE statement for the rows table. */
    public static function schema_sql( string $prefix ): string {
        $t = $prefix . self::TABLE_SUFFIX;
        // Two spaces before PRIMARY KEY - required by dbDelta.
        return "CREATE TABLE {$t} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  table_id BIGINT UNSIGNED NOT NULL,
  row_index BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY table_row (table_id,row_index)
);";
    }

    /** Run dbDelta to create / upgrade the rows table. */
    public static function install_schema( $db = null ): void {
        if ( $db === null ) {
            // @codeCoverageIgnoreStart
            global $wpdb;
            $db = $wpdb;
            // @codeCoverageIgnoreEnd
        }
        if ( ! function_exists( 'dbDelta' ) ) {
            // @codeCoverageIgnoreStart
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            // @codeCoverageIgnoreEnd
        }
        $charset = method_exists( $db, 'get_charset_collate' ) ? $db->get_charset_collate() : '';
        $sql     = self::schema_sql( $db->prefix ) . ' ' . $charset;
        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Fetch all rows for a table, optionally sorted and paginated.
     *
     * $args keys (all optional):
     *   search      string   simple substring filter across row_json
     *   sort_field  string   column key to sort by (applied in PHP; DB stores JSON)
     *   sort_order  string   'asc' | 'desc'
     *   page        int      1-indexed page number
     *   per_page    int      rows per page (0 = all)
     *
     * Returns: array with keys
     *   rows    array   the (possibly paged, sorted, filtered) data rows
     *   total   int     total matching rows before pagination
     *
     * Each element of 'rows' is an associative array (decoded row_json).
     *
     * @param int   $table_id
     * @param array $args
     * @param mixed $db       injected $wpdb (for tests)
     * @return array{rows: array, total: int}
     */
    public static function get_rows( int $table_id, array $args = array(), $db = null ): array {
        if ( $db === null ) {
            // @codeCoverageIgnoreStart
            global $wpdb;
            $db = $wpdb;
            // @codeCoverageIgnoreEnd
        }

        $t = $db->prefix . self::TABLE_SUFFIX;

        $db_rows = $db->get_results(
            $db->prepare(
                "SELECT row_index, row_json FROM {$t} WHERE table_id = %d ORDER BY row_index ASC",
                $table_id
            ),
            ARRAY_A
        );
        if ( ! is_array( $db_rows ) ) {
            $db_rows = array();
        }

        // Decode JSON for each row.
        $rows = array();
        foreach ( $db_rows as $r ) {
            $decoded = json_decode( (string) $r['row_json'], true );
            if ( is_array( $decoded ) ) {
                $rows[] = $decoded;
            }
        }

        // Search filter: substring match across the raw JSON string of each row.
        $search = isset( $args['search'] ) ? (string) $args['search'] : '';
        if ( $search !== '' ) {
            $lc = strtolower( $search );
            $rows = array_values( array_filter( $rows, function ( $row ) use ( $lc ) {
                return strpos( strtolower( (string) json_encode( $row ) ), $lc ) !== false;
            } ) );
        }

        $total = count( $rows );

        // Sort.
        $sort_field = isset( $args['sort_field'] ) ? (string) $args['sort_field'] : '';
        $sort_order = isset( $args['sort_order'] ) ? strtolower( (string) $args['sort_order'] ) : 'asc';
        if ( $sort_field !== '' ) {
            usort( $rows, function ( $a, $b ) use ( $sort_field, $sort_order ) {
                $va = isset( $a[ $sort_field ] ) ? (string) $a[ $sort_field ] : '';
                $vb = isset( $b[ $sort_field ] ) ? (string) $b[ $sort_field ] : '';
                $cmp = strnatcasecmp( $va, $vb );
                return $sort_order === 'desc' ? -$cmp : $cmp;
            } );
        }

        // Pagination.
        $per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 0;
        if ( $per_page > 0 ) {
            $page   = max( 1, (int) ( $args['page'] ?? 1 ) );
            $offset = ( $page - 1 ) * $per_page;
            $rows   = array_slice( $rows, $offset, $per_page );
        }

        return array(
            'rows'  => array_values( $rows ),
            'total' => $total,
        );
    }

    /**
     * Replace ALL rows for a table with the supplied data.
     *
     * Deletes existing rows first, then bulk-inserts. Runs in a transaction
     * when the database supports it so a partial write cannot leave the table
     * in an inconsistent state.
     *
     * @param int   $table_id
     * @param array $data     array of associative arrays (one per row)
     * @param mixed $db
     * @return int  number of rows written
     */
    public static function replace_rows( int $table_id, array $data, $db = null ): int {
        if ( $db === null ) {
            // @codeCoverageIgnoreStart
            global $wpdb;
            $db = $wpdb;
            // @codeCoverageIgnoreEnd
        }

        $t   = $db->prefix . self::TABLE_SUFFIX;
        $now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

        // Delete existing rows for this table.
        $db->delete( $t, array( 'table_id' => $table_id ), array( '%d' ) ); // DATA_INTEGRITY_OK: replace_rows replaces all rows for a specific table_id; called from duplicate, builder provisioning, and future import

        if ( empty( $data ) ) {
            return 0;
        }

        $count = 0;
        foreach ( array_values( $data ) as $idx => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $result = $db->insert(
                $t,
                array(
                    'table_id'   => $table_id,
                    'row_index'  => $idx,
                    'row_json'   => wp_json_encode( $row ),
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array( '%d', '%d', '%s', '%s', '%s' )
            );
            if ( $result !== false ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Append rows to the end of a table's existing rows.
     *
     * @param int   $table_id
     * @param array $data     array of associative arrays
     * @param mixed $db
     * @return int  number of rows appended
     */
    public static function append_rows( int $table_id, array $data, $db = null ): int {
        if ( $db === null ) {
            // @codeCoverageIgnoreStart
            global $wpdb;
            $db = $wpdb;
            // @codeCoverageIgnoreEnd
        }

        if ( empty( $data ) ) {
            return 0;
        }

        $t = $db->prefix . self::TABLE_SUFFIX;

        // Get the current max row_index so we start appending after it.
        $max_idx_result = $db->get_var(
            $db->prepare( "SELECT MAX(row_index) FROM {$t} WHERE table_id = %d", $table_id )
        );
        $next_idx = ( $max_idx_result !== null ) ? ( (int) $max_idx_result + 1 ) : 0;

        $now   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $count = 0;
        foreach ( array_values( $data ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $result = $db->insert(
                $t,
                array(
                    'table_id'   => $table_id,
                    'row_index'  => $next_idx,
                    'row_json'   => wp_json_encode( $row ),
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array( '%d', '%d', '%s', '%s', '%s' )
            );
            if ( $result !== false ) {
                $count++;
                $next_idx++;
            }
        }

        return $count;
    }

    /**
     * Permanently delete all rows for a table.
     *
     * Called from force_delete_table and purge_expired_trash (the table is
     * already gone from wp_gravity_tables; we clean up orphan rows here).
     * Soft-delete / trash-restore do NOT call this - rows must survive trash.
     *
     * @param int   $table_id
     * @param mixed $db
     * @return int  rows deleted
     */
    public static function delete_rows( int $table_id, $db = null ): int {
        if ( $db === null ) {
            // @codeCoverageIgnoreStart
            global $wpdb;
            $db = $wpdb;
            // @codeCoverageIgnoreEnd
        }

        $t      = $db->prefix . self::TABLE_SUFFIX;
        $result = $db->delete( $t, array( 'table_id' => $table_id ), array( '%d' ) ); // DATA_INTEGRITY_OK: delete_rows removes rows for a permanently-deleted table; called only from force_delete and cron purge paths

        return $result !== false ? (int) $result : 0;
    }

    /**
     * Copy all rows from one table to another.
     *
     * Used by the duplicate-table flow (#2366 - rows must survive duplication).
     *
     * @param int   $source_id
     * @param int   $dest_id
     * @param mixed $db
     * @return int  rows copied
     */
    public static function copy_rows( int $source_id, int $dest_id, $db = null ): int {
        if ( $db === null ) {
            // @codeCoverageIgnoreStart
            global $wpdb;
            $db = $wpdb;
            // @codeCoverageIgnoreEnd
        }

        $result = self::get_rows( $source_id, array(), $db );
        if ( empty( $result['rows'] ) ) {
            return 0;
        }

        return self::replace_rows( $dest_id, $result['rows'], $db );
    }

    // -------------------------------------------------------------------------
    // Provisioning helper
    // -------------------------------------------------------------------------

    /**
     * Provision an empty grid: $num_rows rows × $num_cols columns (Column A, B, …).
     *
     * Returns the array of column definitions (for settings JSON) and writes
     * the empty rows to the DB via replace_rows().
     *
     * @param int   $table_id
     * @param int   $num_rows  default 5
     * @param int   $num_cols  default 3
     * @param mixed $db
     * @return array column definitions: [ ['key' => 'col_a', 'label' => 'Column A', 'type' => 'text'], … ]
     */
    public static function provision_empty_grid( int $table_id, int $num_rows = 5, int $num_cols = 3, $db = null ): array {
        // Build column definitions.
        $cols = array();
        for ( $c = 0; $c < $num_cols; $c++ ) {
            $letter = strtoupper( chr( ord( 'A' ) + ( $c % 26 ) ) );
            $cols[] = array(
                'key'   => 'col_' . strtolower( $letter ),
                'label' => 'Column ' . $letter,
                'type'  => 'text',
            );
        }

        // Build empty row data.
        $row_template = array();
        foreach ( $cols as $col ) {
            $row_template[ $col['key'] ] = '';
        }
        $rows = array_fill( 0, max( 1, $num_rows ), $row_template );

        self::replace_rows( $table_id, $rows, $db );

        return $cols;
    }

    // -------------------------------------------------------------------------
    // Visibility filter helpers (#2370)
    // -------------------------------------------------------------------------

    /**
     * Filter a rows array to only visible rows.
     *
     * Rows with _tc_hidden set to true are excluded.
     *
     * @param array $rows  array of row associative arrays (decoded row_json)
     * @return array  rows without _tc_hidden: true entries, re-indexed
     */
    public static function filter_visible_rows( array $rows ): array {
        return array_values( array_filter( $rows, function ( $row ) {
            return empty( $row['_tc_hidden'] );
        } ) );
    }

    /**
     * Filter a manual_columns array to only visible columns.
     *
     * Column definitions with hidden set to true are excluded.
     *
     * @param array $columns  array of column definitions ({key, label, type, hidden})
     * @return array  column definitions without hidden: true entries, re-indexed
     */
    public static function filter_visible_columns( array $columns ): array {
        return array_values( array_filter( $columns, function ( $col ) {
            return empty( $col['hidden'] );
        } ) );
    }

    // -------------------------------------------------------------------------
    // Row count helper
    // -------------------------------------------------------------------------

    /**
     * Count rows for a table (fast: COUNT(*) query, no JSON decode).
     *
     * @param int   $table_id
     * @param mixed $db
     * @return int
     */
    public static function count_rows( int $table_id, $db = null ): int {
        if ( $db === null ) {
            // @codeCoverageIgnoreStart
            global $wpdb;
            $db = $wpdb;
            // @codeCoverageIgnoreEnd
        }

        $t      = $db->prefix . self::TABLE_SUFFIX;
        $result = $db->get_var(
            $db->prepare( "SELECT COUNT(*) FROM {$t} WHERE table_id = %d", $table_id )
        );
        return (int) $result;
    }
}
