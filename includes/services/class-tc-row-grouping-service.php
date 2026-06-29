<?php
/**
 * Row Grouping service for Gravity Tables.
 *
 * Groups contiguous rows sharing the same value in a designated column and
 * produces the DataTables RowGroup extension configuration needed to render
 * collapsible group-header rows on the frontend.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Row_Grouping_Service {

    // -------------------------------------------------------------------------
    // Settings helpers
    // -------------------------------------------------------------------------

    /**
     * Whether row grouping is enabled for this table.
     *
     * @param array $settings Table settings array.
     */
    public static function is_enabled( array $settings ): bool {
        return ! empty( $settings['group_by_column'] );
    }

    /**
     * Return the column ID configured as the group-by column, or empty string.
     *
     * @param array $settings Table settings array.
     */
    public static function get_group_column( array $settings ): string {
        return sanitize_key( $settings['group_by_column'] ?? '' );
    }

    /**
     * Whether groups should start in the collapsed state.
     *
     * @param array $settings Table settings array.
     */
    public static function is_default_collapsed( array $settings ): bool {
        return ! empty( $settings['group_default_collapsed'] );
    }

    // -------------------------------------------------------------------------
    // Server-side grouping
    // -------------------------------------------------------------------------

    /**
     * Organise a flat list of rows into groups keyed by the group column value.
     *
     * @param array  $rows      Flat array of row data (each row is an assoc array).
     * @param string $column_id The column ID to group by.
     * @return array<string, array> Map of group_value => [ rows ].
     */
    public static function group_rows( array $rows, string $column_id ): array {
        $groups = [];

        foreach ( $rows as $row ) {
            $group_value = (string) ( $row[ $column_id ] ?? '' );
            $groups[ $group_value ][] = $row;
        }

        return $groups;
    }

    // -------------------------------------------------------------------------
    // DataTables RowGroup configuration
    // -------------------------------------------------------------------------

    /**
     * Build the DataTables rowGroup extension configuration object.
     *
     * The returned JSON string is safe to embed directly in a JS variable:
     *   var gtRowGroupConfig = <output>;
     *
     * @param array  $settings  Table settings.
     * @param array  $columns   Column definitions (each must have 'id' key).
     * @return string  JSON-encoded configuration for the DataTables rowGroup extension.
     */
    public static function get_datatables_config( array $settings, array $columns ): string {
        $column_id = self::get_group_column( $settings );
        $col_index = self::column_index( $column_id, $columns );
        $collapsed  = self::is_default_collapsed( $settings );

        $config = [
            'rowGroup' => [
                'dataSrc'        => $col_index,
                'startClassName' => 'gt-row-group-header',
                'endClassName'   => '',
            ],
            'groupCollapsed' => $collapsed,
        ];

        return wp_json_encode( $config );
    }

    // -------------------------------------------------------------------------
    // Group header HTML
    // -------------------------------------------------------------------------

    /**
     * Render a group-header row for a given group value.
     *
     * @param string $group_value The display value for this group (e.g. "Electronics").
     * @param int    $col_span    Number of table columns (for the colspan attribute).
     * @param array  $settings    Table settings (used for label prefix etc.).
     * @return string HTML for the <tr> group header row.
     */
    public static function get_group_header_html( string $group_value, int $col_span, array $settings = [] ): string {
        $col_span = max( 1, (int) $col_span );
        $label    = $settings['group_label_prefix'] ?? '';
        $display  = $label !== '' ? $label . ' ' . esc_html( $group_value ) : esc_html( $group_value );

        return sprintf(
            '<tr class="gt-row-group-header" data-group="%s">' .
            '<th colspan="%d" scope="colgroup" role="rowheader">%s</th>' .
            '</tr>',
            esc_attr( $group_value ),
            $col_span,
            $display
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Return the zero-based column index for a given column ID, or 0 as fallback.
     *
     * @param string $column_id
     * @param array  $columns
     * @return int
     */
    private static function column_index( string $column_id, array $columns ): int {
        foreach ( $columns as $i => $col ) {
            if ( ( $col['id'] ?? '' ) === $column_id ) {
                return (int) $i;
            }
        }
        return 0;
    }
}
