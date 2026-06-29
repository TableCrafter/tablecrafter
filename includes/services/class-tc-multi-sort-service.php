<?php
/**
 * Multi-column sorting service.
 *
 * Supports up to 3 simultaneous sort columns (shift-click on column header
 * appends to the sort stack; a plain click resets to single-column sort).
 * Provides both DataTables client-side `order` config and server-side SQL
 * ORDER BY generation. Field IDs are whitelisted against the column config
 * to prevent SQL injection.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Multi_Sort_Service {

    const MAX_SORT_COLUMNS = 3;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return true when multi-column sort is enabled for this table.
     * Defaults to true so existing tables get the feature automatically.
     *
     * @param array $settings
     * @return bool
     */
    public static function is_enabled( array $settings ): bool {
        return (bool) ( $settings['enable_multi_sort'] ?? true );
    }

    /**
     * Sanitize and cap a sort stack at MAX_SORT_COLUMNS entries.
     *
     * Each entry must be an array with 'column_id' (string) and 'direction'
     * (string: 'asc'|'desc').
     *
     * @param array $sort_stack  Raw sort stack from client (e.g. from URL params or JS state).
     * @return array             Cleaned sort stack.
     */
    public static function validate_sort_stack( array $sort_stack ): array {
        $clean = [];

        foreach ( $sort_stack as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $column_id = sanitize_key( $entry['column_id'] ?? '' );
            $direction = strtolower( $entry['direction'] ?? 'asc' );

            if ( $column_id === '' ) {
                continue;
            }

            if ( ! in_array( $direction, [ 'asc', 'desc' ], true ) ) {
                $direction = 'asc';
            }

            $clean[] = [
                'column_id' => $column_id,
                'direction' => $direction,
            ];
        }

        // Cap at max_columns entries.
        return array_slice( $clean, 0, self::MAX_SORT_COLUMNS );
    }

    /**
     * Generate a DataTables `order` config JSON array.
     *
     * Returns an array of [columnIndex, direction] pairs, e.g.
     * [[1, "asc"], [3, "desc"]] — ready to pass as the DataTables `order` init option.
     *
     * @param array $sort_stack  Validated sort stack (from validate_sort_stack()).
     * @param array $columns     Table column definitions (used to resolve column index).
     * @return string  JSON-encoded order array.
     */
    public static function get_datatables_order( array $sort_stack, array $columns = [] ): string {
        $order = [];

        foreach ( $sort_stack as $entry ) {
            $index = self::column_index( $entry['column_id'], $columns );
            if ( $index !== false ) {
                $order[] = [ $index, $entry['direction'] ];
            }
        }

        return wp_json_encode( $order );
    }

    /**
     * Build a SQL ORDER BY clause from a validated sort stack.
     *
     * Column IDs are validated against the allowed column list (whitelist)
     * so user input can never inject arbitrary SQL.
     *
     * @param array $sort_stack  Validated sort stack.
     * @param array $columns     Column definitions — provides the whitelist of valid field IDs.
     * @return string  e.g. "field_123 ASC, field_456 DESC" (without the "ORDER BY" keyword).
     */
    public static function build_order_by( array $sort_stack, array $columns ): string {
        if ( empty( $sort_stack ) ) {
            return '';
        }

        // Build a whitelist of allowed field IDs from the column config.
        $allowed = array_column( $columns, 'id' );

        $parts = [];

        foreach ( $sort_stack as $entry ) {
            $column_id = $entry['column_id'];

            // Strict whitelist check — only columns declared in the table config.
            if ( ! in_array( $column_id, $allowed, true ) ) {
                continue;
            }

            $direction = strtoupper( $entry['direction'] ) === 'DESC' ? 'DESC' : 'ASC';
            // Column IDs are passed through sanitize_key() in validate_sort_stack(),
            // so they contain only [a-z0-9_-]. Safe to interpolate directly.
            $parts[] = sanitize_key( $column_id ) . ' ' . $direction;
        }

        if ( empty( $parts ) ) {
            return '';
        }

        return 'ORDER BY ' . implode( ', ', $parts );
    }

    /**
     * Apply a multi-column sort stack to a GFAPI-style query args array.
     *
     * @param array $query_args  Existing query args.
     * @param array $sort_stack  Validated sort stack.
     * @return array  Updated query args with a 'sorting' key containing the first sort
     *                column (GFAPI only natively supports single-column sort; secondary
     *                sorts are applied client-side or via custom SQL).
     */
    public static function apply_to_query( array $query_args, array $sort_stack ): array {
        if ( empty( $sort_stack ) ) {
            return $query_args;
        }

        $primary = $sort_stack[0];
        $query_args['sorting'] = [
            'key'        => sanitize_key( $primary['column_id'] ),
            'direction'  => strtolower( $primary['direction'] ) === 'desc' ? 'DESC' : 'ASC',
            'is_numeric' => false,
        ];

        return $query_args;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a column ID to its zero-based index in the columns array.
     *
     * @param string $column_id
     * @param array  $columns
     * @return int|false
     */
    private static function column_index( string $column_id, array $columns ) {
        foreach ( $columns as $index => $col ) {
            if ( ( $col['id'] ?? '' ) === $column_id ) {
                return $index;
            }
        }
        return false;
    }
}
