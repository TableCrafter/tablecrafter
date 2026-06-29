<?php
/**
 * URL parameter-based table pre-filtering.
 *
 * When enabled per table, visitors can pre-filter a table by appending
 * ?gt_col_{column_id}=value query parameters to the page URL. Filters are
 * applied client-side via the DataTables search API — no raw SQL is involved.
 *
 * This feature is OFF by default (allow_url_filters = false) for security:
 * enabling it on a per-table basis is an explicit admin decision.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_URL_Filter_Service {

    const URL_PARAM_PREFIX = 'gt_col_';

    // -------------------------------------------------------------------------
    // Feature toggle
    // -------------------------------------------------------------------------

    /**
     * Whether URL-based pre-filtering is enabled for this table.
     *
     * Defaults to false — admins must opt in per table.
     *
     * @param array $settings Table settings array.
     * @return bool
     */
    public static function is_enabled(array $settings): bool {
        return !empty($settings['allow_url_filters']) && $settings['allow_url_filters'] !== false;
    }

    // -------------------------------------------------------------------------
    // Filter parsing
    // -------------------------------------------------------------------------

    /**
     * Read and sanitize active URL filter values from the current request.
     *
     * Returns an empty array when the feature is disabled for this table,
     * when no matching GET params are present, or when values are empty.
     *
     * All filter values are applied client-side (DataTables search), not via SQL.
     *
     * @param array $settings Table settings array.
     * @return array  Keyed by column ID, value is sanitized filter string.
     */
    public static function parse_filters(array $settings): array {
        if (!self::is_enabled($settings)) {
            return [];
        }

        $filters    = [];
        $prefix     = self::URL_PARAM_PREFIX;
        $prefix_len = strlen($prefix);

        foreach ($_GET as $key => $raw_val) {
            if (strpos($key, $prefix) !== 0) {
                continue;
            }
            $col_id = sanitize_key(substr($key, $prefix_len));
            if ($col_id === '') {
                continue;
            }
            $value = sanitize_text_field(wp_unslash((string) $raw_val));
            if ($value !== '') {
                $filters[$col_id] = $value;
            }
        }

        return $filters;
    }

    // -------------------------------------------------------------------------
    // DataTables / JS integration
    // -------------------------------------------------------------------------

    /**
     * Build a JSON-encoded DataTables column search state for the active URL filters.
     *
     * @param array $filters   Active filters from parse_filters() — keyed by column ID.
     * @param array $columns   Ordered column definitions, each with an 'id' key.
     * @return string  JSON array of { column: index, search: value } objects, or '[]'.
     */
    public static function get_datatables_search(array $filters, array $columns): string {
        if (empty($filters)) {
            return '[]';
        }

        $searches = [];
        foreach ($columns as $index => $column) {
            $col_id = sanitize_key($column['id'] ?? $column['field_id'] ?? '');
            if ($col_id !== '' && isset($filters[$col_id])) {
                $searches[] = [
                    'column' => (int) $index,
                    'search' => $filters[$col_id],
                ];
            }
        }

        // #537: JSON_HEX_TAG forces every `<`, `>` to `\u003c` / `\u003e`,
        // making it impossible for a user-supplied filter value to break out
        // of the surrounding `<script>...</script>` wrapper in get_script_output().
        // PHP's default json_encode already escapes forward slashes (so
        // `</script>` never appears as a literal substring), but JSON_HEX_TAG
        // documents the intent and survives any future refactor that adds
        // JSON_UNESCAPED_SLASHES for cleaner URL-shaped JSON elsewhere.
        return wp_json_encode($searches, JSON_HEX_TAG) ?: '[]';
    }

    /**
     * Generate an inline <script> snippet that applies URL filters to a DataTables instance.
     *
     * The snippet listens for the table's gt:table-ready event so it works with
     * both server-side and client-side table initialisation.
     *
     * @param int   $table_id
     * @param array $settings
     * @param array $columns
     * @return string  HTML <script> tag or empty string if no filters are active.
     */
    public static function get_script_output(int $table_id, array $settings, array $columns): string {
        $filters = self::parse_filters($settings);
        if (empty($filters)) {
            return '';
        }

        $searches_json = self::get_datatables_search($filters, $columns);
        $table_id_safe = (int) $table_id;

        $js = sprintf(
            "(function(){var searches=%s;if(!searches.length)return;" .
            "document.addEventListener('gt:table-ready',function(e){" .
            "if(e.detail&&e.detail.tableId===%d){var api=e.detail.api;" .
            "searches.forEach(function(s){api.column(s.column).search(s.search,false,false);});" .
            "api.draw();}});})();",
            $searches_json,
            $table_id_safe
        );

        return '<script>' . $js . '</script>';
    }

    // -------------------------------------------------------------------------
    // Documentation
    // -------------------------------------------------------------------------

    /**
     * Return help text explaining the URL parameter syntax for this feature.
     *
     * Intended for display in the table builder settings panel and shortcode docs.
     *
     * @return string
     */
    public static function get_help_text(): string {
        return sprintf(
            /* translators: %s: example URL parameter */
            __('When URL filtering is enabled, append %s query parameters to the page URL to pre-filter the table. Example: %s. Multiple parameters are AND-combined. Column IDs match the field keys shown in the column list.', 'tc-data-tables'),
            '<code>?gt_col_{column_id}=value</code>',
            '<code>?gt_col_status=Active&amp;gt_col_region=North</code>'
        );
    }

    // -------------------------------------------------------------------------
    // URL state helpers
    // -------------------------------------------------------------------------

    /**
     * Build a URL query string from an array of active column filters.
     *
     * Useful for generating bookmarkable links from JS filter state.
     *
     * @param array $filters  Keyed by column ID.
     * @return string  e.g. 'gt_col_status=Active&gt_col_region=North'
     */
    public static function build_url_params(array $filters): string {
        $params = [];
        foreach ($filters as $col_id => $value) {
            if ($value !== '') {
                $params[self::URL_PARAM_PREFIX . sanitize_key($col_id)] = $value;
            }
        }
        return http_build_query($params);
    }
}
