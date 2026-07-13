<?php
/**
 * Renders per-column search/filter inputs beneath table headers.
 *
 * Filters are AND-combined: rows must match all active column filters simultaneously.
 * Column filter state is reflected in the URL via gt_col_{column_id} query parameters
 * so filtered views are bookmarkable.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Column_Filter_Service {

    const URL_PARAM_PREFIX = 'gt_col_';

    // ---------------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------------

    /**
     * Render the filter row HTML to be inserted after the <thead> header row.
     *
     * @param array $columns  Column definitions, each: { id, label, type, choices? }
     * @param array $settings Table settings (expects enable_column_filters => bool)
     * @return string HTML <tr class="gt-column-filters">…</tr> or empty string if disabled.
     */
    public static function render_filter_row(array $columns, array $settings): string {
        if (empty($settings['enable_column_filters'])) {
            return '';
        }

        $active_filters = self::parse_url_filters();

        $cells = '';
        foreach ($columns as $column) {
            $col_id    = sanitize_key($column['id'] ?? '');
            $col_label = $column['label'] ?? '';
            $col_type  = $column['type'] ?? 'text';
            $choices   = $column['choices'] ?? [];
            $value     = $active_filters[$col_id] ?? '';

            if (!empty($choices) || in_array($col_type, ['select', 'radio', 'checkbox'], true)) {
                $input = self::render_select($col_id, $col_label, $choices, $value);
            } else {
                $input = self::render_text_input($col_id, $col_label, $value);
            }

            $cells .= '<th class="gt-column-filter-cell">' . $input . '</th>';
        }

        return '<tr class="gt-column-filters" role="row">' . $cells . '</tr>';
    }

    /**
     * Render a text input filter for a text/number column.
     */
    private static function render_text_input(string $col_id, string $col_label, string $value): string {
        $param      = esc_attr(self::URL_PARAM_PREFIX . $col_id);
        $aria_label = sprintf(
            /* translators: %s: column label */
            esc_attr__('Filter by %s', 'tc-data-tables'),
            esc_attr($col_label)
        );

        return sprintf(
            '<input type="text" class="gt-col-filter" name="%s" value="%s" aria-label="%s" placeholder="%s" data-col-id="%s">',
            $param,
            esc_attr($value),
            $aria_label,
            esc_attr__('Filter…', 'tc-data-tables'),
            esc_attr($col_id)
        );
    }

    /**
     * Render a <select> filter for columns with a finite set of values.
     */
    private static function render_select(string $col_id, string $col_label, array $choices, string $value): string {
        $param      = esc_attr(self::URL_PARAM_PREFIX . $col_id);
        $aria_label = sprintf(
            /* translators: %s: column label */
            esc_attr__('Filter by %s', 'tc-data-tables'),
            esc_attr($col_label)
        );

        $options = '<option value="">' . esc_html__('All', 'tc-data-tables') . '</option>';
        foreach ($choices as $choice) {
            $choice_val  = is_array($choice) ? ($choice['value'] ?? $choice['text'] ?? '') : $choice;
            $choice_text = is_array($choice) ? ($choice['text'] ?? $choice_val) : $choice;
            $selected    = selected($value, $choice_val, false);
            $options    .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($choice_val),
                $selected,
                esc_html($choice_text)
            );
        }

        return sprintf(
            '<select class="gt-col-filter-select" name="%s" aria-label="%s" data-col-id="%s">%s</select>',
            $param,
            $aria_label,
            esc_attr($col_id),
            $options
        );
    }

    // ---------------------------------------------------------------------------
    // URL parameter handling
    // ---------------------------------------------------------------------------

    /**
     * Parse column filter values from the current request's GET parameters.
     *
     * Only parameters prefixed with gt_col_ are read; values are sanitized.
     * AND logic: all returned filters must be matched by a row to be shown.
     *
     * @return array Keyed by column ID (string after prefix), value is sanitized filter string.
     */
    public static function parse_url_filters(): array {
        $filters = [];
        $prefix  = self::URL_PARAM_PREFIX;
        $prefix_len = strlen($prefix);

        foreach ($_GET as $key => $raw_val) {
            if (strpos($key, $prefix) !== 0) {
                continue;
            }
            $col_id = sanitize_key(substr($key, $prefix_len));
            if ($col_id === '') {
                continue;
            }
            $filters[$col_id] = sanitize_text_field(wp_unslash($raw_val));
        }

        return $filters;
    }

    /**
     * Build a URL query string from an array of active column filters.
     *
     * @param array $filters  Keyed by column ID, value is filter string.
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

    // ---------------------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------------------

    /**
     * Check whether per-column filters are enabled for a given table settings array.
     */
    public static function is_enabled(array $settings): bool {
        return !empty($settings['enable_column_filters']);
    }

    // ---------------------------------------------------------------------------
    // Issue #506 - JS wiring that AND-combines column filters with global search.
    // ---------------------------------------------------------------------------

    /**
     * Emit inline JS that wires per-column filter inputs into DataTables.
     *
     * Behaviour:
     *  - Listens on `.gt-col-filter` and `.gt-col-filter-select` inputs inside the
     *    table identified by `gt-table-{$table_id}`.
     *  - Calls `column(idx).search(value).draw()` on the matching DataTables column.
     *  - Never calls the table-level `dt.search(...)` (that would clobber the
     *    global search widget; see Issue #506).
     *  - 250 ms setTimeout / clearTimeout debounce prevents races on rapid typing.
     *
     * @param int $table_id GT table id (matches the DOM id `gt-table-{$id}`).
     * @return string Inline JavaScript (no surrounding <script> tag).
     */
    public static function get_inline_script(int $table_id): string {
        $table_id_js = (int) $table_id;

        return <<<JS
(function () {
    'use strict';
    var tableId = {$table_id_js};
    if (!window.jQuery || typeof window.jQuery.fn.DataTable !== 'function') { return; }
    var \$ = window.jQuery;
    var tableEl = document.getElementById('gt-table-' + tableId);
    if (!tableEl) { return; }
    var api = \$(tableEl).DataTable();

    // Resolve the DataTables column index for an input by walking up to the parent
    // <th> inside the `.gt-column-filters` row and using its cell index.
    function columnIndexForInput(input) {
        var th = input.closest && input.closest('th');
        if (!th || !th.parentElement) { return -1; }
        var siblings = th.parentElement.children;
        for (var i = 0; i < siblings.length; i++) {
            if (siblings[i] === th) { return i; }
        }
        return -1;
    }

    var debounceTimers = {};
    function debouncedSearch(colId, idx, value) {
        clearTimeout(debounceTimers[colId]);
        debounceTimers[colId] = setTimeout(function () {
            if (idx >= 0) {
                // Per-column search only - never call the table-level global search here,
                // which would wipe the term set by the gravity_global_search widget.
                api.column(idx).search(value).draw();
            }
        }, 250);
    }

    // Bind change/input handlers within the table's wrapper so we don't pick up
    // unrelated `.gt-col-filter` inputs from other tables on the same page.
    var wrapper = api.table().container();
    \$(wrapper).on('input change', '.gt-col-filter, .gt-col-filter-select', function () {
        var colId = this.getAttribute('data-col-id') || '';
        var idx = columnIndexForInput(this);
        debouncedSearch(colId, idx, this.value);
    });
}());
JS;
    }
}
