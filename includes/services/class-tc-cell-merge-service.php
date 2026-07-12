<?php
/**
 * Cell merge support for the Gravity Tables admin builder and frontend renderer.
 *
 * Provides:
 *  - Validation of merge selections (rectangular, non-overlapping, at least 2 cells)
 *  - Apply/unmerge operations on the in-memory merges array stored in table settings
 *  - HTML attribute rendering for rowspan/colspan on <td>/<th> elements
 *  - Detection of "covered" cells that must be suppressed during render
 *  - Excel cell-range strings for PhpSpreadsheet Worksheet::mergeCells()
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Cell_Merge_Service {

    /**
     * Validate that a rectangular selection can be merged.
     *
     * @param int   $start_row     0-based row index of the top-left anchor.
     * @param int   $start_col     0-based column index of the top-left anchor.
     * @param int   $rowspan       Number of rows the merge will cover (must be >= 1).
     * @param int   $colspan       Number of columns the merge will cover (must be >= 1).
     * @param array $merges        Existing merge definitions [{row, col, rowspan, colspan}, ...].
     * @return true|\WP_Error      Returns true on success, WP_Error describing the problem on failure.
     */
    public static function validate_merge( int $start_row, int $start_col, int $rowspan, int $colspan, array $merges ): bool|\WP_Error {
        if ( $rowspan < 1 || $colspan < 1 ) {
            return new \WP_Error( 'invalid_range', __( 'Merge must cover at least one cell.', 'tc-data-tables' ) );
        }

        if ( $rowspan === 1 && $colspan === 1 ) {
            return new \WP_Error( 'no_op', __( 'Select more than one cell to merge.', 'tc-data-tables' ) );
        }

        foreach ( $merges as $existing ) {
            if ( self::ranges_overlap(
                $start_row, $start_col, $rowspan, $colspan,
                (int) $existing['row'], (int) $existing['col'],
                (int) $existing['rowspan'], (int) $existing['colspan']
            ) ) {
                return new \WP_Error( 'overlap', __( 'Selection overlaps with an existing merged cell.', 'tc-data-tables' ) );
            }
        }

        return true;
    }

    /**
     * Apply a new merge to the merges array.
     *
     * Runs validate_merge() first and propagates any WP_Error.
     *
     * @param array $merges      Current merges array.
     * @param int   $start_row
     * @param int   $start_col
     * @param int   $rowspan
     * @param int   $colspan
     * @return array|\WP_Error   Updated merges array, or WP_Error on validation failure.
     */
    public static function apply_merge( array $merges, int $start_row, int $start_col, int $rowspan, int $colspan ): array|\WP_Error {
        $validation = self::validate_merge( $start_row, $start_col, $rowspan, $colspan, $merges );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $merges[] = [
            'row'     => $start_row,
            'col'     => $start_col,
            'rowspan' => $rowspan,
            'colspan' => $colspan,
        ];

        return $merges;
    }

    /**
     * Remove the merge whose top-left anchor is at ($row, $col).
     *
     * Cells that were covered by the merge become independent again; their
     * content is expected to be managed by the caller (content is preserved
     * in the data layer — the UI restores empty cells on split).
     *
     * @param array $merges  Current merges array.
     * @param int   $row     0-based anchor row.
     * @param int   $col     0-based anchor column.
     * @return array         Updated merges array with the targeted entry removed.
     */
    public static function unmerge( array $merges, int $row, int $col ): array {
        return array_values( array_filter( $merges, static function ( array $m ) use ( $row, $col ): bool {
            return (int) $m['row'] !== $row || (int) $m['col'] !== $col;
        } ) );
    }

    /**
     * Render the HTML attribute string for a merged cell.
     *
     * Returns an empty string when both rowspan and colspan are 1 (no merge).
     * Attributes are space-prefixed so callers can append directly to a tag.
     *
     * @param int $rowspan
     * @param int $colspan
     * @return string  e.g. ' rowspan="2" colspan="3"'
     */
    public static function render_merged_cell_attrs( int $rowspan, int $colspan ): string {
        $attrs = '';
        if ( $rowspan > 1 ) {
            $attrs .= ' rowspan="' . (int) $rowspan . '"';
        }
        if ( $colspan > 1 ) {
            $attrs .= ' colspan="' . (int) $colspan . '"';
        }
        return $attrs;
    }

    /**
     * Determine whether a cell is covered by another cell's merge span.
     *
     * A covered cell must be omitted from the rendered HTML — its space is
     * already occupied by the anchor cell's rowspan/colspan.
     *
     * @param int   $row
     * @param int   $col
     * @param array $merges
     * @return bool
     */
    public static function is_covered_cell( int $row, int $col, array $merges ): bool {
        foreach ( $merges as $m ) {
            $mr = (int) $m['row'];
            $mc = (int) $m['col'];
            $rs = (int) $m['rowspan'];
            $cs = (int) $m['colspan'];

            // The anchor cell itself is not covered.
            if ( $mr === $row && $mc === $col ) {
                continue;
            }

            if ( $row >= $mr && $row < $mr + $rs && $col >= $mc && $col < $mc + $cs ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the Excel cell-range string for Worksheet::mergeCells().
     *
     * Converts 0-based row/column indices to 1-based Excel A1:C3 notation.
     * For CSV export, callers should flatten merged cells (copy anchor value
     * to covered cells) before serialising.
     *
     * @param int $row     0-based row index.
     * @param int $col     0-based column index.
     * @param int $rowspan Number of rows.
     * @param int $colspan Number of columns.
     * @return string  e.g. 'A1:C3'
     */
    public static function get_excel_merge_range( int $row, int $col, int $rowspan, int $colspan ): string {
        $col_letter_start = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $col + 1 );
        $col_letter_end   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $col + $colspan );
        $row_start        = $row + 1;
        $row_end          = $row + $rowspan;
        return "{$col_letter_start}{$row_start}:{$col_letter_end}{$row_end}";
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return true when two rectangular merge regions overlap.
     */
    private static function ranges_overlap(
        int $r1, int $c1, int $rs1, int $cs1,
        int $r2, int $c2, int $rs2, int $cs2
    ): bool {
        return $r1 <= ( $r2 + $rs2 - 1 ) && ( $r1 + $rs1 - 1 ) >= $r2
            && $c1 <= ( $c2 + $cs2 - 1 ) && ( $c1 + $cs1 - 1 ) >= $c2;
    }
}
