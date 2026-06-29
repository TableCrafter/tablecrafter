<?php
/**
 * Manages the configurable default sort column and direction for each table.
 *
 * The default sort is applied both server-side (for the initial HTML render and
 * exports) and as a DataTables `order` init option (for client-side tables) so
 * there is no visible re-sort flash on page load.
 *
 * Visitors may override the sort by clicking column headers; their session
 * preference takes precedence without modifying the saved table configuration.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Default_Sort_Service {

    // -------------------------------------------------------------------------
    // Settings accessors
    // -------------------------------------------------------------------------

    /**
     * Return the configured default sort column ID (field key), or '' if none set.
     *
     * @param array $settings Table settings array.
     * @return string
     */
    public static function get_sort_column(array $settings): string {
        return sanitize_key($settings['default_sort_column'] ?? '');
    }

    /**
     * Return the configured default sort direction, normalised to 'asc' or 'desc'.
     * Defaults to 'asc' when the setting is absent or invalid.
     *
     * @param array $settings Table settings array.
     * @return string 'asc'|'desc'
     */
    public static function get_sort_direction(array $settings): string {
        $raw = strtolower(trim($settings['default_sort_direction'] ?? ''));
        return $raw === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Return a summary array of the current default sort settings.
     *
     * @param array $settings
     * @return array { column: string, direction: string }
     */
    public static function get_order_by(array $settings): array {
        return [
            'column'    => self::get_sort_column($settings),
            'direction' => self::get_sort_direction($settings),
        ];
    }

    // -------------------------------------------------------------------------
    // DataTables integration
    // -------------------------------------------------------------------------

    /**
     * Build a DataTables-compatible `order` array for the table init options.
     *
     * DataTables expects `[[columnIndex, 'asc'|'desc']]`.  We find the column
     * index by matching the configured sort column key against the ordered list
     * of rendered columns.
     *
     * @param array $columns  Ordered array of column definitions, each with 'id' key.
     * @param array $settings Table settings array.
     * @return array  e.g. [[2, 'desc']]  or [] when no default sort is configured.
     */
    public static function get_datatables_order(array $columns, array $settings): array {
        $col_key = self::get_sort_column($settings);
        if ($col_key === '') {
            return [];
        }

        foreach ($columns as $index => $column) {
            $col_id = sanitize_key($column['id'] ?? $column['field_id'] ?? '');
            if ($col_id === $col_key) {
                return [[(int) $index, self::get_sort_direction($settings)]];
            }
        }

        return [];
    }

    /**
     * Output a JSON-encoded DataTables `order` option string for inline use in JS.
     *
     * @param array $columns
     * @param array $settings
     * @return string  e.g. '[[2,"desc"]]' or '[]'
     */
    public static function get_datatables_order_json(array $columns, array $settings): string {
        return wp_json_encode(self::get_datatables_order($columns, $settings)) ?: '[]';
    }

    // -------------------------------------------------------------------------
    // Server-side query integration
    // -------------------------------------------------------------------------

    /**
     * Inject the default sort into a Gravity Forms GFAPI search criteria array.
     *
     * GFAPI::get_entries() accepts 'sorting' => [ 'key', 'direction', 'is_numeric' ].
     * If a default sort is set and no explicit sort is already present in $query_args,
     * the default is applied.
     *
     * @param array $query_args  Existing GFAPI / query args.
     * @param array $settings    Table settings array.
     * @return array             Modified query args.
     */
    public static function apply_to_query(array $query_args, array $settings): array {
        $col_key = self::get_sort_column($settings);
        if ($col_key === '') {
            return $query_args;
        }

        // Don't override an explicit visitor-driven sort already in args
        if (!empty($query_args['sorting']['key'])) {
            return $query_args;
        }

        $query_args['sorting'] = [
            'key'        => $col_key,
            'direction'  => strtoupper(self::get_sort_direction($settings)), // GFAPI uses 'ASC'/'DESC'
            'is_numeric' => false,
            'orderby'    => $col_key,
        ];

        return $query_args;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Whether a default sort column has been configured for this table.
     */
    public static function has_default_sort(array $settings): bool {
        return self::get_sort_column($settings) !== '';
    }
}
