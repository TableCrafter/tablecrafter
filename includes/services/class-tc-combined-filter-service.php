<?php
/**
 * Combined filter service: AND-combines a global search term with per-column filters.
 *
 * Server-side fallback / canonical row-matching logic shared by the AJAX endpoint and
 * any non-DataTables renderers. Client-side DataTables already AND-combines its global
 * .search() with column().search() natively; this class is the source of truth for the
 * same behaviour on the server, and exists so the contract is testable in isolation.
 *
 * Issue: #506 — Global search and per-column filter inputs become desynchronized.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Combined_Filter_Service {

    /**
     * Decide whether a single row passes the combined filter set.
     *
     * AND-combines:
     *  - Global search term (matches if any cell contains the term, case-insensitive substring).
     *  - Each column filter (matches if that column's cell contains the value, case-insensitive substring).
     *
     * Empty / whitespace-only values are treated as "no filter" and never veto a row.
     *
     * @param array  $row             Row data keyed by column id (string|scalar values).
     * @param string $global_term     Global search term. Empty / whitespace = no global filter.
     * @param array  $column_filters  Map of column_id => filter value. Empty values = no filter for that column.
     * @return bool                   True if row should be visible, false if any active filter rejects it.
     */
    public static function matches_row( array $row, string $global_term, array $column_filters ): bool {
        // Column filters first (cheaper, more selective on average).
        foreach ( $column_filters as $col_id => $value ) {
            if ( ! is_string( $value ) || trim( $value ) === '' ) {
                continue; // empty filter = no filter
            }
            $cell = $row[ $col_id ] ?? '';
            if ( ! self::cell_contains( $cell, $value ) ) {
                return false;
            }
        }

        // Global term: empty = pass.
        $global_term = trim( $global_term );
        if ( $global_term === '' ) {
            return true;
        }

        foreach ( $row as $cell ) {
            if ( self::cell_contains( $cell, $global_term ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply matches_row() across an array of rows.
     *
     * @param array[] $rows
     * @param string  $global_term
     * @param array   $column_filters
     * @return array[]  Rows where matches_row() returned true (preserves original keys).
     */
    public static function filter_rows( array $rows, string $global_term, array $column_filters ): array {
        $out = [];
        foreach ( $rows as $key => $row ) {
            if ( self::matches_row( (array) $row, $global_term, $column_filters ) ) {
                $out[ $key ] = $row;
            }
        }
        return $out;
    }

    /**
     * Case-insensitive substring containment for a scalar cell value.
     */
    private static function cell_contains( $cell, string $needle ): bool {
        if ( is_array( $cell ) ) {
            // @codeCoverageIgnoreStart
            $cell = implode( ' ', array_map( 'strval', $cell ) );
            // @codeCoverageIgnoreEnd
        }
        $cell = (string) $cell;
        return stripos( $cell, $needle ) !== false;
    }
}
