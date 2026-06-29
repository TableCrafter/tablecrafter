<?php
/**
 * Global cross-table search widget for Gravity Tables.
 *
 * Renders a single search input (via [gravity_global_search] shortcode) that
 * simultaneously filters multiple Gravity Tables DataTables instances on the
 * same page.
 *
 * Features:
 *  - Targets all GT tables on the page (tables="all") or a specific subset.
 *  - 300 ms debounce prevents firing on every keystroke.
 *  - Clear button resets every linked table at once.
 *  - Optionally syncs the search term to ?gt_search= in the URL.
 *  - Accessible: <label>, aria-label, focus management.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Global_Search_Service {

    /** Query-parameter name used for URL sync. */
    const URL_PARAM = 'gt_search';

    /** Default debounce delay in milliseconds. */
    const DEBOUNCE_MS = 300;

    private function __construct() {
        add_shortcode( 'gravity_global_search', [ $this, 'render_shortcode' ] );
    }

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    /**
     * Render the [gravity_global_search] shortcode.
     *
     * Attributes:
     *  - tables   : Comma-separated table IDs or "all" (default: "all").
     *  - label    : Visible label text (default: "Search tables").
     *  - placeholder: Input placeholder (default: "Search…").
     *  - debounce : Debounce delay in ms (default: 300).
     *  - url_sync : "true"/"false" (default: "false").
     *
     * @param array $atts
     * @return string
     */
    public function render_shortcode( array $atts ): string {
        $atts = shortcode_atts( [
            'tables'      => 'all',
            'label'       => __( 'Search tables', 'tc-data-tables' ),
            'placeholder' => __( 'Search…', 'tc-data-tables' ),
            'debounce'    => self::DEBOUNCE_MS,
            'url_sync'    => 'false',
        ], $atts, 'gravity_global_search' );

        $table_ids   = self::get_table_ids( sanitize_text_field( $atts['tables'] ) );
        $debounce_ms = max( 0, (int) $atts['debounce'] );
        $url_sync    = filter_var( $atts['url_sync'], FILTER_VALIDATE_BOOLEAN );
        $widget_id   = 'gt-global-search-' . wp_unique_id();

        $html   = self::get_widget_html( $widget_id, $atts['label'], $atts['placeholder'] );
        $script = self::get_inline_script( $widget_id, $table_ids, $debounce_ms, $url_sync );

        return $html . '<script>' . $script . '</script>';
    }

    // -------------------------------------------------------------------------
    // HTML
    // -------------------------------------------------------------------------

    /**
     * Return the accessible widget HTML: label + input + clear button.
     *
     * @param string $widget_id  Unique widget element ID.
     * @param string $label      Visible label text.
     * @param string $placeholder Input placeholder.
     * @return string
     */
    public static function get_widget_html( string $widget_id, string $label = 'Search tables', string $placeholder = 'Search…' ): string {
        $input_id   = $widget_id . '-input';
        $clear_id   = $widget_id . '-clear';
        $safe_label = esc_html( $label );
        $safe_ph    = esc_attr( $placeholder );

        return sprintf(
            '<div id="%s" class="gt-global-search-wrap">' .
            '<label for="%s" class="gt-global-search-label">%s</label>' .
            '<div class="gt-global-search-inner">' .
            '<input id="%s" type="search" class="gt-global-search-input" placeholder="%s" aria-label="%s" autocomplete="off" />' .
            '<button id="%s" class="gt-global-search-clear" type="button" aria-label="%s" hidden>&times;</button>' .
            '</div>' .
            '</div>',
            esc_attr( $widget_id ),
            esc_attr( $input_id ),
            $safe_label,
            esc_attr( $input_id ),
            $safe_ph,
            $safe_label,
            esc_attr( $clear_id ),
            esc_attr__( 'Clear search', 'tc-data-tables' )
        );
    }

    // -------------------------------------------------------------------------
    // Table ID resolution
    // -------------------------------------------------------------------------

    /**
     * Parse the tables attribute into an array of integer IDs.
     *
     * Returns an empty array when tables="all", signalling the JS to target
     * every DataTable on the page with the gt-table-* ID convention.
     *
     * @param string $tables_attr  "all" or "1,2,3".
     * @return int[]  Empty array = all tables; non-empty = specific IDs.
     */
    public static function get_table_ids( string $tables_attr ): array {
        $tables_attr = trim( $tables_attr );
        if ( $tables_attr === 'all' || $tables_attr === '' ) {
            return [];
        }

        return array_values( array_filter( array_map(
            static fn( string $id ) => (int) trim( $id ),
            explode( ',', $tables_attr )
        ) ) );
    }

    // -------------------------------------------------------------------------
    // JavaScript
    // -------------------------------------------------------------------------

    /**
     * Generate the inline JavaScript that wires up the global search widget.
     *
     * Behaviours:
     *  - 300 ms debounce (configurable) on keyup via setTimeout.
     *  - Calls DataTables .search().draw() on each linked table.
     *  - Shows/hides the clear button; clear resets all tables.
     *  - Optionally syncs term to ?gt_search= via URLSearchParams + history.pushState.
     *  - Reads ?gt_search= on load and pre-fills the input if present.
     *
     * @param string $widget_id   ID attribute of the widget container.
     * @param int[]  $table_ids   Specific table IDs (empty = all).
     * @param int    $debounce_ms Debounce delay in ms.
     * @param bool   $url_sync    Whether to sync to ?gt_search=.
     * @return string  JavaScript (no surrounding <script> tag).
     */
    public static function get_inline_script(
        string $widget_id,
        array  $table_ids   = [],
        int    $debounce_ms = self::DEBOUNCE_MS,
        bool   $url_sync    = false
    ): string {
        $ids_json    = wp_json_encode( $table_ids );
        $debounce    = max( 0, $debounce_ms );
        $url_sync_js = $url_sync ? 'true' : 'false';
        $url_param   = self::URL_PARAM;

        return <<<JS
(function () {
    'use strict';

    var widgetId   = '{$widget_id}';
    var tableIds   = {$ids_json};
    var debounceMs = {$debounce};
    var urlSync    = {$url_sync_js};
    var urlParam   = '{$url_param}';

    var wrap  = document.getElementById(widgetId);
    if (!wrap) { return; }

    var input = wrap.querySelector('.gt-global-search-input');
    var clear = wrap.querySelector('.gt-global-search-clear');
    if (!input) { return; }

    // Collect the DataTables instances to target.
    function getTables() {
        if (!window.jQuery || typeof window.jQuery.fn.DataTable !== 'function') { return []; }
        var $ = window.jQuery;
        if (tableIds.length > 0) {
            return tableIds.map(function (id) {
                var el = document.getElementById('gt-table-' + id);
                return el ? $(el).DataTable() : null;
            }).filter(Boolean);
        }
        // 'all' mode: every initialised DataTable on the page.
        return $.fn.DataTable.tables({ visible: true, api: true }).toArray();
    }

    function applySearch(term) {
        getTables().forEach(function (dt) {
            dt.search(term).draw();
        });
        if (clear) {
            clear.hidden = term === '';
        }
        if (urlSync) {
            try {
                var params = new URLSearchParams(window.location.search);
                if (term) { params.set(urlParam, term); }
                else       { params.delete(urlParam); }
                var newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                history.pushState({}, '', newUrl);
            } catch (e) { /* URLSearchParams may be unavailable in very old browsers */ }
        }
    }

    // Debounce helper.
    var debounceTimer = 0;
    function debounced(fn, wait) {
        return function () {
            var args = arguments;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () { fn.apply(null, args); }, wait);
        };
    }

    // Keyup with debounce.
    input.addEventListener('keyup', debounced(function () {
        applySearch(input.value);
    }, debounceMs));

    // Clear button.
    if (clear) {
        clear.addEventListener('click', function () {
            input.value = '';
            input.focus();
            applySearch('');
        });
    }

    // Pre-fill from ?gt_search= on load.
    if (urlSync) {
        try {
            var initial = new URLSearchParams(window.location.search).get(urlParam);
            if (initial) {
                input.value = initial;
                // Wait for DataTables to initialise before applying the stored search.
                setTimeout(function () { applySearch(initial); }, 0);
            }
        } catch (e) {}
    }
}());
JS;
    }
}
