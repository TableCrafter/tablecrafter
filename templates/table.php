<?php
/**
 * Table Template for Frontend Display
 * 
 * Renders the interactive table interface with editing capabilities,
 * filtering, sorting, and mobile-responsive layout.
 * 
 * Handles role-based permissions and user-specific data filtering.
 * Supports lookup fields, date formatting, and bulk operations.
 * 
 * Recent updates (v3.1.1):
 * - Enhanced mobile responsiveness with card layout
 * - Fixed driver role permission checks 
 * - Improved null safety for DOM operations
 *
 * @package GravityTables
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Scripts and styles are already enqueued globally by TC_Core class, but we ensure they are enqueued here with dependencies.
// #1049 Option 1B v4.218.0 — feature-flag swap. When gt_settings.use_frontend_bundle
// is truthy, enqueue the single-handle bundle artifact in place of the 55-handle
// chain. #1658: Default changed to ON (opt-out) — absent key defaults to true so
// new and existing installs with no explicit setting get the bundle (~4 requests
// instead of ~55). Set use_frontend_bundle = false in gt_settings to opt out.
$gt_settings_for_bundle = get_option('gt_settings', array());
$gt_use_frontend_bundle = ($gt_settings_for_bundle['use_frontend_bundle'] ?? true);
if ($gt_use_frontend_bundle) {
    wp_enqueue_script('gravity-tables-frontend-bundle');
} else {
    wp_enqueue_script('gravity-tables-frontend');
}
wp_enqueue_style('gravity-tables-frontend');
wp_enqueue_style('gravity-tables-frontend-print');

// Table settings are already loaded by the shortcode handler and passed as $atts
// No need to load them again from the database
$table_settings = $atts;

// Get lookup configuration
$lookup_fields = array();
if (isset($atts['lookup_fields']) && is_array($atts['lookup_fields'])) {
    $lookup_fields = $atts['lookup_fields'];
}

// Auto-configure lookup fields for known field types
if (class_exists('GFAPI')) {
    $form = GFAPI::get_form($form_id);
    if ($form && !is_wp_error($form) && isset($form['fields'])) {
        foreach ($form['fields'] as $field) {
            $field_id = strval($field->id);

            // Auto-configure driver_selector fields as user lookups
            if ($field->type === 'driver_selector' && !isset($lookup_fields[$field_id])) {
                $lookup_fields[$field_id] = array(
                    'type' => 'user',
                    'user_field' => 'display_name'
                );
            }
        }
    }
}

// Get date format settings
$gt_settings = get_option('gt_settings', array());
$date_format = isset($gt_settings['date_format']) ? $gt_settings['date_format'] : 'm/d/Y';
$time_format = isset($gt_settings['time_format']) ? $gt_settings['time_format'] : 'g:i A';

// Convert PHP date format to JavaScript date format for date pickers
if (!function_exists('convert_php_to_js_date_format')) {
    function convert_php_to_js_date_format($php_format)
    {
        $conversion_map = array(
            // Day
            'd' => 'dd',    // Day of the month, 2 digits with leading zeros (01-31)
            'j' => 'd',     // Day of the month without leading zeros (1-31)
            'D' => 'ddd',   // A textual representation of a day, three letters
            'l' => 'dddd',  // A full textual representation of the day of the week
            // Month
            'm' => 'mm',    // Numeric representation of a month, with leading zeros (01-12)
            'n' => 'm',     // Numeric representation of a month, without leading zeros (1-12)
            'M' => 'mmm',   // A short textual representation of a month, three letters
            'F' => 'mmmm',  // A full textual representation of a month
            // Year
            'Y' => 'yyyy',  // A full numeric representation of a year, 4 digits
            'y' => 'yy',    // A two digit representation of a year
        );

        $js_format = $php_format;
        foreach ($conversion_map as $php => $js) {
            $js_format = str_replace($php, $js, $js_format);
        }

        return $js_format;
    }
}

// #1763 — per-column role visibility, ENFORCED SERVER-SIDE. Compute the
// columns this user may not see and remove them from $column_config BEFORE it
// drives both the localized JS config (below) and the server-rendered
// <th>/<td> loops further down. The client-side hide
// (assets/js/frontend/column-role-visibility.js) remains as defense-in-depth,
// but the authoritative strip happens here so restricted column VALUES, the
// visibility map, and the viewer's role list never reach the browser at all.
$gt_hidden_columns = array();
if (class_exists('TC_Column_Visibility') && is_array($table_settings)) {
    $gt_viewer_roles   = function_exists('wp_get_current_user') ? (array) wp_get_current_user()->roles : array();
    $gt_hidden_columns = TC_Column_Visibility::hidden_field_ids($table_settings, $gt_viewer_roles);
    if (!empty($gt_hidden_columns) && isset($column_config) && is_array($column_config)) {
        $column_config = TC_Column_Visibility::strip_columns_config($column_config, $gt_hidden_columns);
    }
}

// Add table configuration to global object
$table_config = array(
    'ajax_url' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('gravity_tables_nonce'),
    'table_id' => isset($atts['table_id']) ? intval($atts['table_id']) : 0, // Use database table ID for AJAX calls
    'table_instance_id' => $table_instance_id, // Keep instance ID for DOM manipulation
    'form_id' => $form_id,
    'per_page' => isset($atts['per_page']) ? intval($atts['per_page']) : 25,
    'show_search' => isset($atts['show_search']) ? filter_var($atts['show_search'], FILTER_VALIDATE_BOOLEAN) : true,
    'show_pagination' => isset($atts['show_pagination']) ? filter_var($atts['show_pagination'], FILTER_VALIDATE_BOOLEAN) : true,
    'show_selection' => isset($atts['show_selection']) ? filter_var($atts['show_selection'], FILTER_VALIDATE_BOOLEAN) : true,
    'show_bulk_actions' => isset($atts['show_bulk_actions']) ? filter_var($atts['show_bulk_actions'], FILTER_VALIDATE_BOOLEAN) : gt_is_premium(),
    'show_advanced_filters' => isset($atts['show_advanced_filters']) ? filter_var($atts['show_advanced_filters'], FILTER_VALIDATE_BOOLEAN) : gt_is_premium(),
    'show_add_entry' => isset($atts['show_add_entry']) ? filter_var($atts['show_add_entry'], FILTER_VALIDATE_BOOLEAN) : true,
    'enable_frontend_editing' => isset($atts['enable_frontend_editing']) ? filter_var($atts['enable_frontend_editing'], FILTER_VALIDATE_BOOLEAN) : (gt_is_premium() && !empty($table_settings['enable_frontend_editing'])),
    'enable_delete' => isset($atts['enable_delete']) ? filter_var($atts['enable_delete'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['enable_delete']) ? $table_settings['enable_delete'] : false),
    'sticky_header' => isset($atts['sticky_header']) ? filter_var($atts['sticky_header'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['sticky_header']) ? $table_settings['sticky_header'] : false),
    'show_table_summary' => isset($atts['show_table_summary']) ? filter_var($atts['show_table_summary'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['show_table_summary']) ? (bool) $table_settings['show_table_summary'] : false),
    'freeze_first_column' => isset($atts['freeze_first_column']) ? filter_var($atts['freeze_first_column'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['freeze_first_column']) ? $table_settings['freeze_first_column'] : false),
    'enable_vertical_scroll' => isset($atts['enable_vertical_scroll']) ? filter_var($atts['enable_vertical_scroll'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['enable_vertical_scroll']) ? (bool) $table_settings['enable_vertical_scroll'] : false),
    'vertical_scroll_max_height' => isset($atts['vertical_scroll_max_height']) ? sanitize_text_field($atts['vertical_scroll_max_height']) : (isset($table_settings['vertical_scroll_max_height']) ? sanitize_text_field($table_settings['vertical_scroll_max_height']) : '400px'),
    'responsive_table' => isset($atts['responsive_table']) ? filter_var($atts['responsive_table'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['responsive_table']) ? $table_settings['responsive_table'] : true),
    'persistent_filters' => isset($atts['persistent_filters']) ? filter_var($atts['persistent_filters'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['persistent_filters']) ? $table_settings['persistent_filters'] : true),
    'show_deleted_entries' => isset($atts['show_deleted_entries']) ? filter_var($atts['show_deleted_entries'], FILTER_VALIDATE_BOOLEAN) : false,
    'filter_user_entries' => isset($atts['filter_user_entries']) ? filter_var($atts['filter_user_entries'], FILTER_VALIDATE_BOOLEAN) : false,
    'responsive_mode' => isset($atts['responsive_mode']) ? $atts['responsive_mode'] : (isset($table_settings['responsive_mode']) ? $table_settings['responsive_mode'] : 'basic'),
    'processing_mode' => isset($atts['processing_mode']) ? $atts['processing_mode'] : (isset($table_settings['processing_mode']) ? $table_settings['processing_mode'] : 'client'),
    'responsive_settings' => isset($atts['responsive_settings']) && is_array($atts['responsive_settings']) ? $atts['responsive_settings'] : array(),
    'bulk_actions' => isset($atts['bulk_actions']) && is_array($atts['bulk_actions']) && !empty($atts['bulk_actions']) ? $atts['bulk_actions'] : array('delete', 'edit'),
    'user_role_filter' => isset($atts['user_role_filter']) ? $atts['user_role_filter'] : '',
    'date_format' => $date_format,
    'time_format' => $time_format,
    'date_format_js' => convert_php_to_js_date_format($date_format),
    'columns' => array_keys($column_config),
    'column_config' => $column_config,
    'column_alignments' => isset($atts['column_alignments']) && is_array($atts['column_alignments']) ? $atts['column_alignments'] : array(),
    // #549 slice 1.1: wire vertical-alignments to the JS config so
    // AJAX-rendered rows get vertical-align too. Slice 1 only fed it
    // to the PHP preview path; the JS lookup was reading undefined.
    'column_vertical_alignments' => isset($atts['column_vertical_alignments']) && is_array($atts['column_vertical_alignments'])
        ? $atts['column_vertical_alignments']
        : (isset($table_settings['column_vertical_alignments']) && is_array($table_settings['column_vertical_alignments']) ? $table_settings['column_vertical_alignments'] : array()),
    'column_wrap_modes' => isset($atts['column_wrap_modes']) && is_array($atts['column_wrap_modes']) ? $atts['column_wrap_modes'] : array(),
    'column_link_settings' => isset($atts['column_link_settings']) && is_array($atts['column_link_settings']) ? $atts['column_link_settings'] : array(),
    'cell_vertical_alignments' => isset($atts['cell_vertical_alignments']) && is_array($atts['cell_vertical_alignments'])
        ? $atts['cell_vertical_alignments']
        : (isset($table_settings['cell_vertical_alignments']) && is_array($table_settings['cell_vertical_alignments']) ? $table_settings['cell_vertical_alignments'] : array()),
    // #501 slice 1: row expiry settings. When expiry_field_id is set,
    // the JS gate filters / styles rows post-render based on the date
    // value in that column.
    'expiry_field_id'   => isset($atts['expiry_field_id']) ? (string) $atts['expiry_field_id'] : (isset($table_settings['expiry_field_id']) ? (string) $table_settings['expiry_field_id'] : ''),
    'expiry_behavior'   => isset($atts['expiry_behavior']) ? (string) $atts['expiry_behavior'] : (isset($table_settings['expiry_behavior']) ? (string) $table_settings['expiry_behavior'] : 'hide'),
    'expiry_grace_days' => isset($atts['expiry_grace_days']) ? max(0, (int) $atts['expiry_grace_days']) : (isset($table_settings['expiry_grace_days']) ? max(0, (int) $table_settings['expiry_grace_days']) : 0),
    'expiry_inverse'    => isset($atts['expiry_inverse']) ? filter_var($atts['expiry_inverse'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['expiry_inverse']) ? filter_var($table_settings['expiry_inverse'], FILTER_VALIDATE_BOOLEAN) : false),
    // #518 slice 1: per-column auto-merge of consecutive duplicate values into rowspan groups.
    'column_auto_merge' => isset($atts['column_auto_merge']) && is_array($atts['column_auto_merge'])
        ? $atts['column_auto_merge']
        : (isset($table_settings['column_auto_merge']) && is_array($table_settings['column_auto_merge']) ? $table_settings['column_auto_merge'] : array()),
    // #568 slice 3: click-to-filter cell drill-down — flat list of field_id strings
    // for which clicking a cell value adds a "filter by example" chip above the
    // table (slice-2 ships the admin opt-in). frontend.js reads this from
    // gtTableData[tableId].drilldown_columns and binds the cell-click delegate.
    'drilldown_columns' => isset($atts['drilldown_columns']) && is_array($atts['drilldown_columns'])
        ? $atts['drilldown_columns']
        : (isset($table_settings['drilldown_columns']) && is_array($table_settings['drilldown_columns']) ? $table_settings['drilldown_columns'] : array()),
    // #553 slice 3: WAF-safe payload client-side opt-in. When the
    // gt_waf_safe_payload_enabled filter returns true, frontend.js JSON.stringify +
    // btoa-encodes the inline-edit + bulk-edit payloads and posts them under
    // `payload` instead of separate fields. Server (slice 2 v4.41.0) already
    // accepts the envelope. Default false — opt-in for sites behind aggressive
    // WAFs (Cloudflare, Sucuri, mod_security, Wordfence) where generic SQLi/XSS
    // rules false-positive on legitimate cell content like "UNION SELECT" or
    // "<script>".
    'waf_safe_payload' => (bool) apply_filters('gt_waf_safe_payload_enabled', false),
    // #567 slice 1: row-link template (e.g. `/loads/{1}`); empty disables clickable rows.
    'row_link_template' => isset($atts['row_link_template']) ? (string) $atts['row_link_template'] : (isset($table_settings['row_link_template']) ? (string) $table_settings['row_link_template'] : ''),
    // #567 slice 2.4: always-open-in-new-tab per-table toggle. Pairs with the
    // existing modifier-key + middle-click new-tab path; when this is on,
    // every click navigates via window.open regardless of modifier.
    'row_link_open_new_tab' => isset($atts['row_link_open_new_tab']) ? filter_var($atts['row_link_open_new_tab'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['row_link_open_new_tab']) ? filter_var($table_settings['row_link_open_new_tab'], FILTER_VALIDATE_BOOLEAN) : false),
    // #531 slice 1: print-all-rows toggle (default true). When true, the
    // Print toolbar button fetches every entry into the DOM before
    // window.print() and restores the paginated view on afterprint.
    'print_all_rows' => isset($atts['print_all_rows']) ? filter_var($atts['print_all_rows'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['print_all_rows']) ? filter_var($table_settings['print_all_rows'], FILTER_VALIDATE_BOOLEAN) : true),
    'lookup_fields' => $lookup_fields,
    'is_preview' => $is_preview,
    'preview_settings' => $is_preview ? $atts : null,
    'show_column_totals' => isset($atts['show_column_totals']) ? filter_var($atts['show_column_totals'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['show_column_totals']) ? $table_settings['show_column_totals'] : false),
    'ajs_toolkit' => array(
        'active' => class_exists('AJS_Trucking_Toolkit'),
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ajs_toolkit_nonce'),
    ),
    'transposed'      => isset($table_settings['transposed']) ? (bool) $table_settings['transposed'] : false,
    'top_n_count'     => isset($table_settings['top_n_count']) ? (int) $table_settings['top_n_count'] : 0,
    'top_n_column'    => isset($table_settings['top_n_column']) ? sanitize_key($table_settings['top_n_column']) : '',
    'top_n_direction' => (isset($table_settings['top_n_direction']) && in_array($table_settings['top_n_direction'], array('asc', 'desc'), true)) ? $table_settings['top_n_direction'] : 'desc',
    'plugin_version'  => TC_VERSION,
    // TC_Pagination_Label_Service — resolved label map for the JS
    // pagination renderer. Empty per-table values fall back to plugin
    // defaults inside the service. Frontend.js reads
    // this.config.pagination_labels.* and uses {start}/{end}/{total}
    // tokens for the info_text line.
    'pagination_labels' => class_exists('TC_Pagination_Label_Service')
        ? TC_Pagination_Label_Service::get_labels(is_array($table_settings) ? $table_settings : array())
        : array(
            'info_text'      => 'Showing {start} to {end} of {total} entries',
            'previous_label' => 'Previous',
            'next_label'     => 'Next',
            'no_results'     => 'No matching entries found.',
            'loading'        => 'Loading…',
        ),
    // #565 slice 1 — multi-column sort toggle. Drives the shift-click
    // UX in frontend.js. TC_Multi_Sort_Service::is_enabled() defaults
    // to true so existing tables get the feature automatically.
    'enable_multi_sort' => class_exists('TC_Multi_Sort_Service')
        ? TC_Multi_Sort_Service::is_enabled(is_array($table_settings) ? $table_settings : array())
        : true,
    // TC_URL_Filter_Service — parsed URL filters from $_GET when the
    // per-table toggle is on. Frontend.js reads this on init, populates
    // the per-column filter inputs, and seeds this.filters before the
    // first loadEntries() so the table arrives pre-filtered.
    'url_filters' => (class_exists('TC_URL_Filter_Service') && is_array($table_settings))
        ? TC_URL_Filter_Service::parse_filters($table_settings)
        : array(),
    // TC_Formula_Service per-column totals-row aggregation map. Drives
    // updateColumnTotals in frontend.js. Empty/missing per-column => legacy
    // auto-Sum-for-numeric behavior (pre-v4.9.8).
    'column_aggregations' => (is_array($table_settings) && isset($table_settings['column_aggregations']) && is_array($table_settings['column_aggregations']))
        ? $table_settings['column_aggregations']
        : array(),
    // Data Bars (#1731) — Pro-gated per-column value bars. RENDER-STRIP on
    // the free tier (second layer of defense after the save-time
    // persist-strip): a stale Pro-era config on a downgraded site can
    // never reach the JS config and so can never render a bar.
    'column_data_bars' => (gt_is_premium() && is_array($table_settings) && isset($table_settings['column_data_bars']) && is_array($table_settings['column_data_bars']))
        ? $table_settings['column_data_bars']
        : array(),
    // #1741 — TC_Badge_Service per-column badge maps. Free tier — no Pro gate.
    'column_badge_map' => (is_array($table_settings) && isset($table_settings['column_badge_maps']) && is_array($table_settings['column_badge_maps']))
        ? $table_settings['column_badge_maps']
        : array(),
    // #1743 — auto-refresh interval in seconds (Free). 0 = disabled.
    'auto_refresh_interval' => (is_array($table_settings) && isset($table_settings['auto_refresh_interval']))
        ? max(0, (int) $table_settings['auto_refresh_interval'])
        : 0,
    // #1744 — column visibility picker (Free).
    'show_column_picker' => ! empty( $table_settings['show_column_picker'] ),
    // #1745 — bulk column fill Pro gate. JS uses this to guard openBulkFillModal.
    'is_pro' => gt_is_premium(),
    // #1747 — one-click entry duplicate (Pro). Admin-enabled per table.
    'enable_duplicate' => ( gt_is_premium() && is_array( $table_settings ) && ! empty( $table_settings['enable_duplicate'] ) ),
    // #1746/#1763 — per-column role visibility is now enforced SERVER-SIDE:
    // restricted columns are already stripped from $column_config (above) and
    // from the gt_get_entries payload. We deliberately no longer leak the
    // visibility map or the viewer's role list to the client. Emitted empty so
    // the client-side hide (column-role-visibility.js) is a harmless no-op.
    'column_role_visibility' => [],
    'user_roles' => [],
    // #1742 — TC_Validation_Service per-column inline-edit validation rules (Pro).
    // RENDER-STRIP: second layer of defense. Free-tier JS config never receives
    // column_validations even if stale Pro-era data exists in the DB.
    'column_validations' => (gt_is_premium() && is_array($table_settings) && isset($table_settings['column_validations']) && is_array($table_settings['column_validations']))
        ? $table_settings['column_validations']
        : array(),
    // TC_Detail_Rows_Service slice 2 — flat field_id => true map of columns
    // marked as detail-only candidates. Slice 3 (this slice) reads this in
    // renderEntries to render a chevron toggle TD on each parent row plus a
    // hidden gt-detail-row TR sibling holding the detail-flagged values.
    'column_detail_only' => (is_array($table_settings) && isset($table_settings['column_detail_only']) && is_array($table_settings['column_detail_only']))
        ? $table_settings['column_detail_only']
        : array(),
    // TC_Default_Sort_Service — per-table initial sort. Frontend.js reads
    // these on init() before the first loadEntries() and overrides the
    // hardcoded date_created/desc default. Empty default_sort_column =
    // legacy behavior (no override).
    'default_sort_column'    => class_exists('TC_Default_Sort_Service')
        ? TC_Default_Sort_Service::get_sort_column(is_array($table_settings) ? $table_settings : array())
        : '',
    'default_sort_direction' => class_exists('TC_Default_Sort_Service')
        ? TC_Default_Sort_Service::get_sort_direction(is_array($table_settings) ? $table_settings : array())
        : 'asc',
    // Persistent filters via browser localStorage. Off by default.
    'persist_filters_localstorage' => (is_array($table_settings) && !empty($table_settings['persist_filters_localstorage'])),
    // Visitor-side length selector toggle + options. Off by default.
    'show_length_selector' => (is_array($table_settings) && !empty($table_settings['show_length_selector'])),
    // Per-column filter row beneath the header (the legacy "filter row"
    // path at line 1495). Was previously read by the template gate but
    // never populated here, so the gate evaluated `empty()` and the
    // .gt-filter-row TR never rendered regardless of settings or
    // toolbar_visibility. Wired up here so the existing template path
    // and the toolbar_visibility.column_filters gate both work.
    'show_per_column_filters' => (is_array($table_settings) && !empty($table_settings['show_per_column_filters'])),
    // #568 slice 4: Seed initial drilldown filters from the URL (?gt_df=col:val,col:val).
    'drilldown_filters' => (class_exists('TC_Drilldown_Filter_Service') && isset($_GET['gt_df']))
        ? TC_Drilldown_Filter_Service::from_query_string(wp_unslash((string) $_GET['gt_df']))
        : array(),
    'length_selector_options' => (is_array($table_settings) && isset($table_settings['length_selector_options']) && $table_settings['length_selector_options'] !== '')
        ? (string) $table_settings['length_selector_options']
        : '10,25,50,100,-1',
);

/**
 * Allow extensions (e.g. WooCommerce integration in TC_WooCommerce) to inject
 * additional config keys into the table data localized to the frontend.
 */
$table_config = apply_filters('gt_table_config', $table_config, $atts);

// Debug: Log table configuration
$debug = TC_Debug::get_instance();
$debug->log('frontend', 'Table configuration debug', array(
    'table_id' => $table_config['table_id'],
    'table_instance_id' => $table_config['table_instance_id'],
    'form_id' => $table_config['form_id'],
    'user_id' => get_current_user_id(),
    'user_roles' => wp_get_current_user()->roles,
    'table_instance_id_type' => gettype($table_instance_id),
    'table_instance_id_empty' => empty($table_instance_id),
    'atts_table_id' => isset($atts['table_id']) ? $atts['table_id'] : 'NOT SET'
));

// Check for potential querySelector issues
if (empty($table_instance_id) || $table_instance_id === null) {
    $debug->log('frontend', 'CRITICAL: table_instance_id is empty or null - this will cause querySelector errors!', array(
        'table_instance_id' => $table_instance_id,
        'atts' => $atts
    ));
}

$debug->log('frontend', 'Sticky header setting: ' . var_export($table_config['sticky_header'], true));
$debug->log('frontend', 'Frontend editing setting: ' . var_export($table_config['enable_frontend_editing'], true));

// Check if this is preview mode
$is_preview = defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action']) && $_POST['action'] === 'gt_preview_table';

if ($is_preview) {
    // For preview mode, include the JavaScript and CSS directly in the response.
    // #1720 — inline the FULL frontend implementation, not just frontend.js.
    // After the #832/#833 modularization, assets/js/frontend.js is only the
    // GravityTable stub; prototype.init / loadEntries / renderEntries live in
    // assets/js/frontend/*.js, concatenated into frontend-bundle.js by the
    // build. Inlining only the stub left the preview's
    // `typeof GravityTable.prototype.init === "function"` guard false, so it
    // showed the static fallback and never rendered. Prefer the built bundle
    // (min then unminified); fall back to concatenating the stub + every module
    // so the preview also works in an unbuilt dev checkout.
    $frontend_js_file = TC_PLUGIN_PATH . 'assets/js/frontend.js';
    $gt_bundle_min    = TC_PLUGIN_PATH . 'assets/js/frontend-bundle.min.js';
    $gt_bundle_full   = TC_PLUGIN_PATH . 'assets/js/frontend-bundle.js';
    if (file_exists($gt_bundle_min)) {
        $frontend_js_content = file_get_contents($gt_bundle_min);
    } elseif (file_exists($gt_bundle_full)) {
        $frontend_js_content = file_get_contents($gt_bundle_full);
    } else {
        $frontend_js_content = file_exists($frontend_js_file) ? file_get_contents($frontend_js_file) : '';
        foreach (glob(TC_PLUGIN_PATH . 'assets/js/frontend/*.js') ?: array() as $gt_mod) {
            $frontend_js_content .= "\n;" . file_get_contents($gt_mod);
        }
    }

    $frontend_css_file = TC_PLUGIN_PATH . 'assets/css/frontend.css';
    $frontend_css_content = file_exists($frontend_css_file) ? file_get_contents($frontend_css_file) : '';

    // Check if we have server-side preview data
    $has_preview_data = isset($atts['preview_data']) && !empty($atts['preview_data']['entries']);

    // Check if frontend debugging is enabled
    $debug_enabled = $debug->is_enabled('frontend') || $debug->is_enabled('all');
    $debug_js = $debug_enabled ? 'console.log' : '// console.log';

    $inline_js = $frontend_js_content . '
window.gtTableData = window.gtTableData || {};
window.gtTableData["' . esc_js($table_instance_id) . '"] = ' . wp_json_encode($table_config) . ';
jQuery(document).ready(function($) {
    ' . $debug_js . '("Preview mode: Table preview loaded:", "' . esc_js($table_instance_id) . '");
    ' . $debug_js . '("Preview mode: Has server-side preview data:", ' . ($has_preview_data ? 'true' : 'false') . ');
    
    ' . ($has_preview_data ? '
    // Server-side preview data is available - initialize JavaScript for interactive features
    ' . $debug_js . '("Preview mode: Using server-side preview data, initializing JavaScript for interactive features");
    // #1027 — guard against the STUB case. Extension modules create a stub
    // `window.GravityTable = function () {}` on script load (see frontend.js
    // line 73). If only the stub is present (prototype.init not yet attached
    // by selection.js / pagination.js / init.js / etc.), the old
    // `typeof GravityTable !== "undefined"` check passed but the subsequent
    // `.init()` threw `(intermediate value).init is not a function`.
    if (typeof GravityTable === "function" && typeof GravityTable.prototype.init === "function") {
        ' . $debug_js . '("Preview mode: Initializing GravityTable for sticky headers and interactions");
        new GravityTable("' . esc_js($table_instance_id) . '", ' . wp_json_encode($table_config) . ').init();
    } else {
        ' . $debug_js . '("Preview mode: GravityTable class not available or prototype.init missing — preview stays static.");
    }
    ' : '
    // No server-side preview data - use JavaScript fallback with full functionality
    if (typeof GravityTable === "function" && typeof GravityTable.prototype.init === "function") {
        ' . $debug_js . '("Preview mode: No server data, initializing JavaScript table with data loading");
        new GravityTable("' . esc_js($table_instance_id) . '", ' . wp_json_encode($table_config) . ').init();
    } else {
        ' . $debug_js . '("Preview mode: GravityTable class not available, showing fallback message");
        $("#' . esc_js($table_instance_id) . ' .gt-loading-row").html(\'<td colspan="100%"><div class="gt-no-entries">Preview mode - table will be interactive on frontend</div></td>\');
    }
    ') . '
});';
} else {
    // For regular shortcode, ensure scripts are loaded and add inline config.
    // #1049 Option 1B v4.218.0 — honor the same feature flag here too.
    // #1658: Default changed to ON — absent key defaults to true.
    $gt_settings_for_bundle_2 = get_option('gt_settings', array());
    $gt_use_frontend_bundle_2 = ($gt_settings_for_bundle_2['use_frontend_bundle'] ?? true);
    if ($gt_use_frontend_bundle_2) {
        wp_enqueue_script('gravity-tables-frontend-bundle');
    } else {
        wp_enqueue_script('gravity-tables-frontend');
    }
    wp_enqueue_style('gravity-tables-frontend');
    wp_enqueue_style('gravity-tables-frontend-print');

    // Resolve the active script handle for wp_add_inline_script() calls below.
    // When the bundle is active its handle is 'gravity-tables-frontend-bundle';
    // attaching inline scripts to the un-enqueued '-frontend' handle silently
    // drops them. Using a variable here keeps both code-paths correct. (#1658)
    $gt_inline_handle = $gt_use_frontend_bundle_2
        ? 'gravity-tables-frontend-bundle'
        : 'gravity-tables-frontend';

    // Check if frontend debugging is enabled for regular shortcode
    $debug_enabled = $debug->is_enabled('frontend') || $debug->is_enabled('all');
    $debug_js = $debug_enabled ? 'console.log' : '// console.log';

    wp_add_inline_script($gt_inline_handle, '
window.gtTableData = window.gtTableData || {};
window.gtTableData["' . esc_js($table_instance_id) . '"] = ' . wp_json_encode($table_config) . ';
' . $debug_js . '("Regular shortcode: Table config set for:", "' . esc_js($table_instance_id) . '", ' . wp_json_encode($table_config) . ');
', 'before');

    // Also add fallback initialization script
    wp_add_inline_script($gt_inline_handle, '
jQuery(document).ready(function($) {
    ' . $debug_js . '("GT Fallback: Document ready, checking for table:", "' . esc_js($table_instance_id) . '");
    ' . $debug_js . '("GT Fallback: GravityTable available:", typeof GravityTable !== "undefined");
    ' . $debug_js . '("GT Fallback: Table data available:", window.gtTableData);
    
    // Multiple fallback attempts
    var attempts = 0;
    var maxAttempts = 10;
    
    function tryInitialize() {
        attempts++;
        var tableId = "' . esc_js($table_instance_id ?? '') . '";
        
        // Enhanced null checks to prevent querySelector errors
        if (!tableId || tableId === "" || tableId === "undefined" || tableId === "null") {
            console.error("GT Fallback: Invalid table ID:", tableId, "- Initialization aborted");
            return;
        }
        
        var $table = $("#" + tableId);
        
        ' . $debug_js . '("GT Fallback: Attempt", attempts, "- Table found:", $table.length, "GravityTable available:", typeof GravityTable !== "undefined");
        
        // #1027 — also verify prototype.init is present. Otherwise we may be
        // looking at the stub constructor (assigned by extension modules on
        // script-load) without the full prototype yet wired up — which
        // throws (intermediate value).init is not a function.
        if ($table.length && typeof GravityTable === "function" && typeof GravityTable.prototype.init === "function") {
            ' . $debug_js . '("GT Fallback: Initializing table:", tableId);
            var config = window.gtTableData && window.gtTableData[tableId] ? window.gtTableData[tableId] : null;
            if (config) {
                try {
                    new GravityTable(tableId, config).init();
                    ' . $debug_js . '("GT Fallback: Successfully initialized table:", tableId);
                } catch (error) {
                    console.error("GT Fallback: Error initializing table:", tableId, error);
                }
            } else {
                console.error("GT Fallback: No config found for table:", tableId);
            }
        } else if (attempts < maxAttempts) {
            ' . $debug_js . '("GT Fallback: Retrying in 200ms...");
            setTimeout(tryInitialize, 200);
        } else {
            // Table div is not in the DOM after maxAttempts. Normal state when a
            // containing page-restriction plugin replaced the_content() output
            // but shortcode processing still queued our inline scripts. Quiet
            // the message so it does not noise up the console on restricted
            // pages -- still emit it via the debug channel for diagnosis.
            ' . $debug_js . '("GT Fallback: Max attempts reached. Table found:", $table.length, "GravityTable available:", typeof GravityTable !== "undefined");
        }
    }
    
    // Start trying immediately
    tryInitialize();
});
', 'after');

    // For more robust loading, also prepare a direct script as fallback
    $direct_init_needed = true;

    // Also ensure CSS is loaded for regular shortcodes
    $frontend_css_file = TC_PLUGIN_PATH . 'assets/css/frontend.css';
    $frontend_css_content = file_exists($frontend_css_file) ? file_get_contents($frontend_css_file) : '';
    $direct_css_needed = true;
}
?>

<?php
$preview_class = $is_preview ? ' preview-mode' : '';
$responsive_mode = isset($atts['responsive_mode']) ? $atts['responsive_mode'] : (isset($table_settings['responsive_mode']) ? $table_settings['responsive_mode'] : 'basic');
$css_class = isset($atts['css_class']) ? $atts['css_class'] : '';
$sticky_header = isset($atts['sticky_header']) ? filter_var($atts['sticky_header'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['sticky_header']) ? $table_settings['sticky_header'] : false);
if ($sticky_header) {
    $css_class .= ' sticky-header';
}
$freeze_first_column = isset($atts['freeze_first_column']) ? filter_var($atts['freeze_first_column'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['freeze_first_column']) ? filter_var($table_settings['freeze_first_column'], FILTER_VALIDATE_BOOLEAN) : false);
if ($freeze_first_column) {
    $css_class .= ' gt-freeze-first-column';
}
$enable_vertical_scroll    = $table_config['enable_vertical_scroll'] ?? false;
$vertical_scroll_max_height = $table_config['vertical_scroll_max_height'] ?? '400px';
if ($enable_vertical_scroll) {
    $css_class .= ' gt-vertical-scroll';
}
// Horizontal scroll: add class so CSS can preserve natural cell widths (#343).
$enable_horizontal_scroll = filter_var(
    $table_config['horizontal_scroll'] ?? $table_settings['horizontal_scroll'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);
if ($enable_horizontal_scroll) {
    $css_class .= ' gt-horizontal-scroll';
}
// Flip responsive mode: add class and data attribute for breakpoint (#348).
$responsive_mode_val = $table_config['responsive_mode'] ?? $table_settings['responsive_mode'] ?? 'basic';
$flip_breakpoint = isset($table_settings['flip_breakpoint']) ? (int) $table_settings['flip_breakpoint'] : 768;
if ($responsive_mode_val === 'flip') {
    $css_class .= ' gt-flip-responsive';
}
?>
<?php
// Collect custom CSS for output AFTER the table wrapper (see #127 — late placement wins in cascade).
$gt_custom_css_raw = isset($table_settings['custom_css']) ? trim((string) $table_settings['custom_css']) : '';
$gt_scoped_css     = '';
if ($gt_custom_css_raw !== '' && class_exists('TC_Admin')) {
    $gt_scoped_css = TC_Admin::scope_custom_css($gt_custom_css_raw, '#' . $table_instance_id);
}

// #544 slice 3 — multi-row sticky header CSS. Slice-1 service returns 0
// when sticky_header is off, otherwise the clamped 1..10 count. We only
// emit per-N rules when the count is > 1 so the single-row path keeps
// using the existing `.sticky-header thead th` rule from v4.7.46 CSS.
// Selector is scoped to #$table_instance_id so multiple tables on the
// same page don't clobber each other. Row-height defaults to 38px (the
// existing thead row height); customers can override per-table via the
// `--gt-sticky-row-height` CSS variable in their custom_css.
if (class_exists('TC_Sticky_Rows_Service')) {
    $gt_frozen_n = TC_Sticky_Rows_Service::frozen_count($table_settings);
    if ($gt_frozen_n > 1) {
        // Each row's z-index decrements so row 1 layers above row 2, row 2
        // above row 3, etc. — matches the natural visual stack when
        // scrolling so the topmost frozen row is always paintable on top.
        $gt_z_top = 7;
        $gt_sticky_css = '';
        for ($gt_i = 1; $gt_i <= $gt_frozen_n; $gt_i++) {
            $gt_offset_i = $gt_i - 1;
            $gt_z_i = $gt_z_top - $gt_offset_i;
            // Include the WP admin-bar offset (--gt-adminbar-offset, set on the
            // wrapper for body.admin-bar) so the frozen filter + header rows clear
            // the toolbar instead of being clipped under it.
            $gt_top_value = $gt_offset_i === 0
                ? 'var(--gt-adminbar-offset, 0px)'
                : 'calc(var(--gt-adminbar-offset, 0px) + var(--gt-sticky-row-height, 38px) * ' . $gt_offset_i . ')';
            $gt_sticky_css .= sprintf(
                '#%s.sticky-header .gt-table thead tr:nth-child(%d) th { position: sticky; top: %s; z-index: %d; }',
                $table_instance_id,
                $gt_i,
                $gt_top_value,
                $gt_z_i
            );
        }
        $gt_scoped_css .= $gt_sticky_css;
    }
}
?>
<noscript><p class="gt-noscript-notice"><?php esc_html_e('This table requires JavaScript to load. Please enable JavaScript in your browser to view it.', 'tc-data-tables'); ?></p></noscript>
<?php
// Issue #547 slice 2: emit schema.org JSON-LD alongside the table when configured.
// Disabled by default (schema_type='Table' is the default once normalized, but only when
// the per-table settings explicitly include schema_type — otherwise we don't emit anything
// to keep the surface a strict opt-in for v4.7.66).
$schema_settings = isset($table_settings['schema']) && is_array($table_settings['schema']) ? $table_settings['schema'] : [];
if (!empty($schema_settings) && class_exists('TC_Schema_Service') && TC_Schema_Service::is_enabled($schema_settings)) {
    if (!function_exists('gt_render_schema_jsonld')) {
        require_once TC_PLUGIN_PATH . 'includes/helpers-schema.php';
    }
    echo gt_render_schema_jsonld(
        ['title' => isset($table_settings['title']) ? (string) $table_settings['title'] : ''],
        $schema_settings
    );
}

// #531 slice 2 — per-table print overrides. Emit an inline
// <style media="print"> block when the master toggle is on, scoped
// to this table's wrapper id. The base print stylesheet
// (assets/css/frontend-print.css) ships defaults; this block
// overrides them per-table for paper size, repeat-header opt-out,
// row-striping opt-out, and per-column exclusions.
$gt_print_raw = isset($table_settings['print_settings']) && is_array($table_settings['print_settings']) ? $table_settings['print_settings'] : array();
if (class_exists('TC_Print_Settings_Service') && TC_Print_Settings_Service::is_enabled($gt_print_raw)) {
    $gt_print_normalized = TC_Print_Settings_Service::normalize($gt_print_raw);
    $gt_print_css = '';
    // Paper size — emit @page { size: ... }. CSS @page accepts a
    // bare keyword for the standard sizes (A4, letter, etc.).
    $gt_paper = strtolower((string) $gt_print_normalized['paper_size']);
    if ($gt_paper !== '' && in_array($gt_paper, array('letter', 'a4', 'legal', 'a3', 'tabloid'), true)) {
        $gt_print_css .= '@page { size: ' . $gt_paper . '; }';
    }
    $gt_wrapper_sel = '#' . $table_instance_id;
    // Repeat header opt-out — base stylesheet sets table-header-group; revert to default.
    if (empty($gt_print_normalized['repeat_header'])) {
        $gt_print_css .= $gt_wrapper_sel . ' .gt-table thead { display: table-row-group !important; }';
    }
    // Row striping opt-out — neutralize the nth-child(even) rule from base.
    if (empty($gt_print_normalized['row_striping'])) {
        $gt_print_css .= $gt_wrapper_sel . ' .gt-table tbody tr:nth-child(even) td { background: transparent !important; }';
    }
    // Excluded columns — hide both header + body cells. Field IDs are
    // sanitized by the service; further guarded with sanitize_html_class
    // so the selector can't carry CSS injection.
    foreach ((array) $gt_print_normalized['excluded_columns'] as $gt_excluded_col) {
        $gt_col_class = sanitize_html_class('gt-column-' . (string) $gt_excluded_col);
        if ($gt_col_class === '') { continue; }
        $gt_print_css .= $gt_wrapper_sel . ' .' . $gt_col_class . ' { display: none !important; }';
    }
    if ($gt_print_css !== '') {
        echo '<style media="print" id="' . esc_attr('gt-print-overrides-' . $table_instance_id) . '">' . $gt_print_css . '</style>';
    }
}

// TC_Border_Service per-table preset emit. The service's get_border_css()
// hardcodes a `#gt-table-{$table_id}` scope; the actual DOM wrapper id is
// `gt-table-{uniqid}` (set in class-tc-shortcode.php). String-replace to
// re-target the real wrapper. Default 'classic' preset is the legacy look,
// so when no preset is saved we emit nothing — existing tables unchanged.
$gt_border_preset = isset($table_settings['border_preset']) ? (string) $table_settings['border_preset'] : '';
if ($gt_border_preset !== '' && class_exists('TC_Border_Service')) {
    $gt_border_table_id = isset($atts['table_id']) ? (int) $atts['table_id'] : 0;
    $gt_border_settings = TC_Border_Service::get_preset_settings($gt_border_preset);
    $gt_border_raw_css = TC_Border_Service::get_border_css($gt_border_table_id, $gt_border_settings);
    if ($gt_border_raw_css !== '') {
        $gt_border_scoped_css = str_replace(
            '#gt-table-' . $gt_border_table_id,
            '#' . $table_instance_id,
            $gt_border_raw_css
        );
        echo '<style id="' . esc_attr('gt-border-overrides-' . $table_instance_id) . '">' . $gt_border_scoped_css . '</style>';
    }
}

// TC_Row_Height_Service per-table emit. Same string-replace bridge as the
// Border preset (service hardcodes #gt-table-{id}; real DOM uses the
// uniqid-based wrapper). Returns empty when neither row_height nor
// header_height resolves to a valid value, so existing tables emit nothing.
if (class_exists('TC_Row_Height_Service') && is_array($table_settings)) {
    $gt_rh_table_id = isset($atts['table_id']) ? (int) $atts['table_id'] : 0;
    $gt_rh_css = TC_Row_Height_Service::get_css($gt_rh_table_id, $table_settings);
    if ($gt_rh_css !== '') {
        $gt_rh_scoped = str_replace(
            '#gt-table-' . $gt_rh_table_id,
            '#' . $table_instance_id,
            $gt_rh_css
        );
        echo '<style id="' . esc_attr('gt-row-height-' . $table_instance_id) . '">' . $gt_rh_scoped . '</style>';
    }
}
?>
<?php
// TC_RTL_Service helpers for the wrapper. is_rtl() delegates to WordPress
// core; on RTL sites we add `dir="rtl"` plus a `gt-rtl` class so the
// frontend-rtl.css overrides land. Note: the stylesheet itself is enqueued
// via wp_enqueue_scripts in tablecrafter.php; this just stamps the
// wrapper so per-table RTL styles target it.
$gt_rtl_dir_attr = class_exists('TC_RTL_Service') ? TC_RTL_Service::get_dir_attr() : '';
$gt_rtl_classes  = class_exists('TC_RTL_Service') ? TC_RTL_Service::get_wrapper_classes() : '';

// TC_Collapsible_Service — whole-table collapse toggle. Emits the toggle
// button and wraps the table_wrapper div in a body wrapper with `hidden`
// attribute when default-collapsed. Click handler is a self-contained
// IIFE that toggles aria-expanded + hidden + persists to localStorage
// (storage key matches the service's: gt_collapse_{table_id}). Skips the
// DataTable.columns.adjust call from the service's get_inline_script
// since the plugin doesn't use DataTables for its tables.
$gt_collapsible_on = class_exists('TC_Collapsible_Service')
    && TC_Collapsible_Service::is_enabled(is_array($table_settings) ? $table_settings : array());
$gt_collapsible_default_collapsed = $gt_collapsible_on
    && TC_Collapsible_Service::is_default_collapsed(is_array($table_settings) ? $table_settings : array());
$gt_collapsible_table_id = isset($atts['table_id']) ? (int) $atts['table_id'] : 0;
if ($gt_collapsible_on && $gt_collapsible_table_id > 0) {
    // Prefer the table's actual name (settings table_title / title, then the
    // wp_gravity_tables.title column) and only fall back to "Table #N".
    $gt_collapsible_title = '';
    foreach (array('table_title', 'title') as $gt_title_key) {
        if (!empty($table_settings[$gt_title_key])) {
            $gt_collapsible_title = (string) $table_settings[$gt_title_key];
            break;
        }
    }
    if ($gt_collapsible_title === '' && class_exists('TC_Admin')) {
        $gt_td = TC_Admin::get_instance()->get_table($gt_collapsible_table_id);
        if ($gt_td && !empty($gt_td->title)) {
            $gt_collapsible_title = (string) $gt_td->title;
        }
    }
    if ($gt_collapsible_title === '') {
        $gt_collapsible_title = sprintf(esc_html__('Table #%d', 'tc-data-tables'), $gt_collapsible_table_id);
    }
    echo TC_Collapsible_Service::get_toggle_button_html($gt_collapsible_table_id, $gt_collapsible_title, $gt_collapsible_default_collapsed);
    echo '<div' . TC_Collapsible_Service::get_body_wrapper_attrs($gt_collapsible_table_id, $gt_collapsible_default_collapsed) . '>';
}
?>
<div id="<?php echo esc_attr($table_instance_id); ?>"
    class="gt-table-wrapper <?php echo esc_attr($css_class . $preview_class . ' ' . $gt_rtl_classes); ?>"
    data-table-id="<?php echo esc_attr($table_instance_id); ?>"
    data-responsive-mode="<?php echo esc_attr($responsive_mode); ?>"
    data-flip-breakpoint="<?php echo esc_attr($flip_breakpoint); ?>"
    <?php echo $gt_rtl_dir_attr; // service returns dir="ltr|rtl" with the attr quoted; safe to echo. ?>
    <?php if (!empty($gt_table_width)) : ?>style="width:<?php echo esc_attr($gt_table_width); ?>;"<?php endif; ?>>
    <div class="gt-table-container">
        <?php
        $show_search = isset($atts['show_search']) ? filter_var($atts['show_search'], FILTER_VALIDATE_BOOLEAN) : true;
        $show_advanced_filters = isset($atts['show_advanced_filters']) ? filter_var($atts['show_advanced_filters'], FILTER_VALIDATE_BOOLEAN) : gt_is_premium();
        $show_bulk_actions = isset($atts['show_bulk_actions']) ? filter_var($atts['show_bulk_actions'], FILTER_VALIDATE_BOOLEAN) : gt_is_premium();
        $show_selection = isset($atts['show_selection']) ? filter_var($atts['show_selection'], FILTER_VALIDATE_BOOLEAN) : true;
        $enable_delete = isset($atts['enable_delete']) ? filter_var($atts['enable_delete'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['enable_delete']) ? $table_settings['enable_delete'] : false);
        $show_pagination = isset($atts['show_pagination']) ? filter_var($atts['show_pagination'], FILTER_VALIDATE_BOOLEAN) : true;
        $bulk_actions = isset($atts['bulk_actions']) && is_array($atts['bulk_actions']) && !empty($atts['bulk_actions']) ? $atts['bulk_actions'] : array('delete', 'edit');
        $css_class = isset($atts['css_class']) ? $atts['css_class'] : '';
        $table_style = isset($atts['table_style']) ? $atts['table_style'] : 'default';
        // Fix: Define persistent_filters variable from the table config
        $persistent_filters = isset($atts['persistent_filters']) ? filter_var($atts['persistent_filters'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['persistent_filters']) ? $table_settings['persistent_filters'] : true);
        // Fix: Apply sticky header class directly in template if enabled
        $sticky_header = isset($atts['sticky_header']) ? filter_var($atts['sticky_header'], FILTER_VALIDATE_BOOLEAN) : (isset($table_settings['sticky_header']) ? $table_settings['sticky_header'] : false);
        if ($sticky_header) {
            $css_class .= ' sticky-header';
        }
        // #521 slice 3 — toolbar visibility map. Per-component override on top
        // of the legacy show_search / show_pagination / show_*_buttons gates.
        // ANDed with the legacy gates: both must be true for the component to
        // render. Default for unset tables: every component visible (per
        // slice-1 service contract), so legacy installs see no change.
        $gt_tv_settings = (isset($table_config['toolbar_visibility']) && is_array($table_config['toolbar_visibility']))
            ? $table_config['toolbar_visibility']
            : array();
        $gt_tv_visible = function ($component) use ($gt_tv_settings) {
            if (!class_exists('TC_Toolbar_Visibility_Service')) {
                return true; // service unavailable → don't break renders
            }
            return TC_Toolbar_Visibility_Service::is_visible($component, $gt_tv_settings);
        };
        ?>
        <?php
        $show_export = isset($atts['show_export']) ? filter_var($atts['show_export'], FILTER_VALIDATE_BOOLEAN) : true;
        // #1080 — Add New Entry hoisted into the toolbar row. Computed
        // here (not at the original render site below) so the wrapper's
        // OR-gate can reference it and the wrapper still renders when
        // only Add New Entry is enabled.
        $show_add_entry = isset($atts['show_add_entry']) ? filter_var($atts['show_add_entry'], FILTER_VALIDATE_BOOLEAN) : true;
        $user_can_add = current_user_can('edit_posts') || current_user_can('driver') || current_user_can('publish_posts') || !empty($table_settings['enable_frontend_editing']);
        if ($show_search || $show_advanced_filters || $show_bulk_actions || $show_export || $show_add_entry):
            ?>
            <div class="gt-table-controls">
                <?php if ($show_search && $gt_tv_visible('global_search')): ?>
                    <div class="gt-search-container">
                        <input type="text" class="gt-search-input"
                            placeholder="<?php esc_attr_e('Search entries...', 'tc-data-tables'); ?>">
                        <button type="button" class="gt-search-btn"><?php esc_html_e('Search', 'tc-data-tables'); ?></button>
                    </div>
                <?php endif; ?>

                <?php
                // #1680 — single consolidated Export menu. The visitor-facing
                // (visible-rows) Copy / CSV / Excel / PDF actions now live INSIDE
                // the Export dropdown alongside the server-side (all-data) CSV /
                // Excel options, instead of a separate row of unstyled buttons.
                // Compute the visible-rows flags up front so the dropdown wrapper
                // can include them. The existing handler classes are preserved
                // on the menu items so the export.js click handlers keep working.
                $show_toolbar_copy  = isset($atts['show_toolbar_copy'])  ? filter_var($atts['show_toolbar_copy'],  FILTER_VALIDATE_BOOLEAN) : false;
                $show_toolbar_csv   = isset($atts['show_toolbar_csv'])   ? filter_var($atts['show_toolbar_csv'],   FILTER_VALIDATE_BOOLEAN) : false;
                $show_toolbar_excel = isset($atts['show_toolbar_excel']) ? filter_var($atts['show_toolbar_excel'], FILTER_VALIDATE_BOOLEAN) : false;
                $show_pdf_export    = isset($atts['show_pdf_export'])    ? filter_var($atts['show_pdf_export'],    FILTER_VALIDATE_BOOLEAN) : false;
                // #2285 — visible-rows JSON export (opt-in, mirrors other toolbar exports).
                $show_toolbar_json  = isset($atts['show_toolbar_json'])  ? filter_var($atts['show_toolbar_json'],  FILTER_VALIDATE_BOOLEAN) : false;
                $gt_show_visible_exports = ($show_toolbar_copy || $show_toolbar_csv || $show_toolbar_excel || $show_pdf_export || $show_toolbar_json) && $gt_tv_visible('export_buttons');

                // #1680 — crisp inline SVG icons for the export menu (replaces
                // the inconsistent emoji glyphs). Feather-style, 16px,
                // currentColor so they inherit the row color on hover. Static
                // markup, no user data. function_exists guard keeps it safe when
                // multiple tables render on one page.
                if (!function_exists('gt_export_icon')) {
                    function gt_export_icon($name) {
                        $a = 'width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
                        $icons = array(
                            'download' => '<svg ' . $a . '><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
                            'chevron'  => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
                            'file'     => '<svg ' . $a . '><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
                            'grid'     => '<svg ' . $a . '><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/></svg>',
                            'copy'     => '<svg ' . $a . '><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
                            'printer'  => '<svg ' . $a . '><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>',
                        );
                        return isset($icons[$name]) ? $icons[$name] : '';
                    }
                }
                ?>
                <!-- Export Controls (#1680: one consolidated menu) -->
                <?php if ($show_export || $show_toolbar_copy || $show_toolbar_csv || $show_toolbar_excel || $show_pdf_export): ?>
                    <div class="gt-export-container">
                        <div class="gt-export-dropdown">
                            <button type="button" class="gt-export-btn">
                                <span class="gt-export-icon"><?php echo gt_export_icon('download'); ?></span>
                                <?php esc_html_e('Export', 'tc-data-tables'); ?>
                                <span class="gt-dropdown-arrow"><?php echo gt_export_icon('chevron'); ?></span>
                            </button>
                            <div class="gt-export-options">
                                <?php if ($show_export): ?>
                                <a href="#" class="gt-export-option" data-format="csv">
                                    <span class="gt-format-icon"><?php echo gt_export_icon('file'); ?></span>
                                    <?php esc_html_e('Export as CSV (all data)', 'tc-data-tables'); ?>
                                </a>
                                <a href="#" class="gt-export-option" data-format="excel">
                                    <span class="gt-format-icon"><?php echo gt_export_icon('grid'); ?></span>
                                    <?php esc_html_e('Export as Excel (all data)', 'tc-data-tables'); ?>
                                </a>
                                <?php /* #2285 — JSON all-data export, alongside CSV and Excel. */ ?>
                                <a href="#" class="gt-export-option" data-format="json">
                                    <span class="gt-format-icon"><?php echo gt_export_icon('file'); ?></span>
                                    <?php esc_html_e('Export as JSON (all data)', 'tc-data-tables'); ?>
                                </a>
                                <?php endif; ?>
                                <?php if ($gt_show_visible_exports): ?>
                                <div class="gt-toolbar-export" role="group" aria-label="<?php esc_attr_e('Export visible rows', 'tc-data-tables'); ?>">
                                    <?php if ($show_toolbar_copy): ?>
                                    <button type="button" class="gt-toolbar-copy-btn gt-export-menu-item"
                                            aria-label="<?php esc_attr_e('Copy visible table data to clipboard', 'tc-data-tables'); ?>"
                                            title="<?php esc_attr_e('Copy to clipboard', 'tc-data-tables'); ?>">
                                        <span class="gt-format-icon"><?php echo gt_export_icon('copy'); ?></span>
                                        <?php esc_html_e('Copy (visible rows)', 'tc-data-tables'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($show_toolbar_csv): ?>
                                    <button type="button" class="gt-toolbar-csv-btn gt-export-menu-item"
                                            aria-label="<?php esc_attr_e('Download visible table data as CSV', 'tc-data-tables'); ?>"
                                            title="<?php esc_attr_e('Download CSV', 'tc-data-tables'); ?>">
                                        <span class="gt-format-icon"><?php echo gt_export_icon('file'); ?></span>
                                        <?php esc_html_e('CSV (visible rows)', 'tc-data-tables'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($show_toolbar_excel): ?>
                                    <button type="button" class="gt-toolbar-excel-btn gt-export-menu-item"
                                            aria-label="<?php esc_attr_e('Download visible table data as Excel spreadsheet', 'tc-data-tables'); ?>"
                                            title="<?php esc_attr_e('Download Excel', 'tc-data-tables'); ?>">
                                        <span class="gt-format-icon"><?php echo gt_export_icon('grid'); ?></span>
                                        <?php esc_html_e('Excel (visible rows)', 'tc-data-tables'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($show_pdf_export): ?>
                                    <button type="button" class="gt-pdf-btn gt-export-menu-item"
                                            aria-label="<?php esc_attr_e('Open print dialog: choose Save as PDF in the dialog to download a .pdf', 'tc-data-tables'); ?>"
                                            title="<?php esc_attr_e('Save as PDF (uses your browser\'s print dialog)', 'tc-data-tables'); ?>">
                                        <span class="gt-format-icon"><?php echo gt_export_icon('printer'); ?></span>
                                        <?php esc_html_e('Save as PDF', 'tc-data-tables'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($show_toolbar_json): ?>
                                    <button type="button" class="gt-toolbar-json-btn gt-export-menu-item"
                                            aria-label="<?php esc_attr_e('Download visible table data as JSON', 'tc-data-tables'); ?>"
                                            title="<?php esc_attr_e('Download JSON', 'tc-data-tables'); ?>">
                                        <span class="gt-format-icon"><?php echo gt_export_icon('file'); ?></span>
                                        <?php esc_html_e('JSON (visible rows)', 'tc-data-tables'); ?>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                // #521 slice 1: the Print button used to render unconditionally.
                // For embed contexts where print is irrelevant (kiosks, dashboards)
                // site owners can now suppress it without custom CSS. Defaults to
                // true so existing tables render the button as before.
                $show_print = isset($atts['show_print']) ? filter_var($atts['show_print'], FILTER_VALIDATE_BOOLEAN) : true;
                if ($show_print):
                ?>
                <button type="button" class="gt-print-btn" title="<?php esc_attr_e('Print this table', 'tc-data-tables'); ?>" aria-label="<?php esc_attr_e('Print this table', 'tc-data-tables'); ?>">
                    <span class="gt-print-icon">🖨</span>
                    <?php esc_html_e('Print', 'tc-data-tables'); ?>
                </button>
                <?php endif; ?>

                <?php if ($show_advanced_filters): ?>
                    <div class="gt-advanced-filters">
                        <button type="button" class="gt-toggle-filters <?php echo $persistent_filters ? 'active' : ''; ?>"
                            data-show-text="<?php esc_attr_e('Advanced Filters', 'tc-data-tables'); ?>"
                            data-hide-text="<?php esc_attr_e('Hide Filters', 'tc-data-tables'); ?>">
                            <span class="gt-filter-icon">🔍</span>
                            <span
                                class="gt-filter-text"><?php echo $persistent_filters ? esc_html__('Hide Filters', 'tc-data-tables') : esc_html__('Advanced Filters', 'tc-data-tables'); ?></span>
                            <span class="gt-filter-arrow"><?php echo $persistent_filters ? '▲' : '▼'; ?></span>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ($show_bulk_actions && $show_selection && !empty($bulk_actions)): ?>
                    <div class="gt-bulk-actions">
                        <select class="gt-bulk-action-select">
                            <option value=""><?php esc_html_e('Bulk Actions', 'tc-data-tables'); ?></option>
                            <?php if (in_array('delete', $bulk_actions)): ?>
                                <option value="delete"><?php esc_html_e('Delete', 'tc-data-tables'); ?></option>
                            <?php endif; ?>
                            <?php
                            // #1684 — bulk "Export" removed from the UI. Exporting is
                            // consolidated into the Export menu (#1680: all data /
                            // visible rows). The bulk_export_entries() handler stays
                            // for back-compat/programmatic callers, but the redundant
                            // bulk-actions option is no longer rendered.
                            ?>
                            <?php if (in_array('edit', $bulk_actions)): ?>
                                <option value="edit"><?php esc_html_e('Edit', 'tc-data-tables'); ?></option>
                            <?php endif; ?>
                            <?php
                            // #613 phase 2 slice 4 (v4.199.0) — push-to-source bulk option.
                            // Conditionally rendered when the table is wired to an external
                            // data source AND sync_direction allows pushing. The client-side
                            // intercept (loop pushRowToSource per selected entry) ships in
                            // the next slice.
                            $gt_p2_data_source = isset($table_data->settings)
                                ? (is_array($table_data->settings) ? ($table_data->settings['data_source_type'] ?? '') : '')
                                : '';
                            $gt_p2_sync_direction = isset($table_data->settings) && is_array($table_data->settings)
                                ? ($table_data->settings['sync_direction'] ?? 'pull')
                                : 'pull';
                            $gt_p2_external = in_array($gt_p2_data_source, array('json', 'airtable', 'notion'), true);
                            $gt_p2_push_ok = in_array($gt_p2_sync_direction, array('push', 'two_way', 'push_only', 'bidirectional'), true);
                            ?>
                            <?php if ($gt_p2_external && $gt_p2_push_ok): ?>
                                <option value="push_to_source"><?php esc_html_e('Push to source', 'tc-data-tables'); ?></option>
                            <?php endif; ?>
                        </select>
                        <button type="button"
                            class="gt-bulk-action-btn"><?php esc_html_e('Apply', 'tc-data-tables'); ?></button>
                    </div>
                <?php endif; ?>

                <?php if ( gt_is_premium() && $show_selection ): ?>
                    <button type="button" class="gt-bulk-fill-btn button"
                        style="display:none;"
                        title="<?php esc_attr_e( 'Fill a column for selected rows', 'tc-data-tables' ); ?>">
                        <?php esc_html_e( 'Fill Column', 'tc-data-tables' ); ?>
                    </button>
                <?php endif; ?>

                <?php
                // #1080 — Add New Entry hoisted into the toolbar flex row.
                // Lives INSIDE `.gt-table-controls` so its CSS rule
                // `margin-left: auto` pushes it to the right of the row,
                // joining Export / Print visually instead of dropping
                // into a standalone block below the toolbar with a
                // visible vertical gap. `$show_add_entry` / `$user_can_add`
                // were computed at the top of this block so the wrapper's
                // OR-gate already evaluates them.
                if ($show_add_entry && $user_can_add):
                    ?>
                    <div class="gt-add-new-container">
                        <button type="button" class="gt-add-new-btn gt-add-row gt-btn gt-btn-success"
                            data-form-id="<?php echo esc_attr($form_id); ?>">
                            <span class="gt-add-icon">+</span>
                            <?php esc_html_e('Add New Entry', 'tc-data-tables'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (gt_is_free_plan()): ?>
            <div class="gt-premium-notice"
                style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; margin: 20px 0; border-radius: 8px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <h4 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600;">🚀 Unlock Premium Features</h4>
                <p style="margin: 0 0 12px 0; opacity: 0.9; font-size: 14px;">
                    Upgrade to Pro for <strong>unlimited tables</strong>, <strong>frontend editing</strong>, <strong>bulk
                        operations</strong>, and <strong>advanced filters</strong>
                </p>
                <a href="<?php echo function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#'; ?>" class="button"
                    style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-weight: 500; display: inline-block; transition: all 0.3s ease;">
                    Upgrade to Pro →
                </a>
            </div>
        <?php endif; ?>

        <?php if ($show_advanced_filters): ?>
            <div class="gt-filters-panel" style="display: <?php echo $persistent_filters ? 'block' : 'none'; ?>;">
                <div class="gt-filters-content">
                    <div class="gt-filters-header">
                        <h4><?php esc_html_e('Filter Results', 'tc-data-tables'); ?></h4>
                        <p><?php esc_html_e('Use the fields below to filter your table data', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-filters-grid">
                        <?php
                        // Debug: Log the column_config structure
                        $debug->log('filtering', 'Full column_config structure', $column_config);

                        // Create comprehensive list of filterable fields from column config (which is already fully processed by class-tc-shortcode.php)
                        $all_filterable_fields = array();

                        // Add fields from column config
                        if (!empty($column_config)) {
                            foreach ($column_config as $field_id => $field_config) {
                                // Only add if explicitly marked as filterable
                                if (isset($field_config['filterable']) && $field_config['filterable'] === true) {
                                    // Also check responsive visibility - don't show filters for columns hidden on current device
                                    $is_visible = true;
                                    if (isset($table_config['responsive_settings'][$field_id])) {
                                        $resp_settings = $table_config['responsive_settings'][$field_id];
                                        // If hidden on desktop (which means hidden everywhere usually) or specific devices, we might want to hide the filter too
                                        // However, since table builder only has mobile/tablet toggles, we check if it's completely hidden
                                        if (
                                            isset($resp_settings['mobile_visible']) && $resp_settings['mobile_visible'] === false &&
                                            isset($resp_settings['tablet_visible']) && $resp_settings['tablet_visible'] === false &&
                                            isset($resp_settings['desktop_visible']) && $resp_settings['desktop_visible'] === false
                                        ) {
                                            $is_visible = false;
                                        }
                                    }

                                    if ($is_visible) {
                                        $all_filterable_fields[$field_id] = $field_config;
                                    }
                                }
                            }
                        }

                        $debug->log('filtering', 'All filterable fields after checking column_config', array_keys($all_filterable_fields));

                        // Generate filters for all filterable fields
                        if (!empty($all_filterable_fields)) {
                            foreach ($all_filterable_fields as $field_id => $field_config) {
                                // Debug: Log each field's configuration
                                $debug->log('filtering', "Processing field $field_id - filterable: " . (!empty($field_config['filterable']) ? 'true' : 'false'), isset($field_config['filter_config']) ? $field_config['filter_config'] : 'NOT SET');

                                // Skip if the field is explicitly marked as not filterable
                                if (empty($field_config['filterable'])) {
                                    $debug->log('filtering', "Skipping field $field_id - marked as not filterable in config");
                                    continue;
                                }

                                // Skip non-filterable field types (but allow hidden fields to show filters)
                                if (in_array($field_config['type'], array('html', 'section', 'page'))) {
                                    continue;
                                }

                                $field_label = $field_config['label'];
                                $field_type = $field_config['type'];
                                $filter_config = isset($field_config['filter_config']) ? $field_config['filter_config'] : array();

                                // Determine filter type with priority: Filter Config > Lookup Config > Smart Defaults
                    
                                // 1. First Priority: Explicit filter configuration (this should always win)
                                if (!empty($filter_config['type'])) {
                                    $filter_type = $filter_config['type'];
                                    $debug->log('filtering', "Field $field_id ({$field_label}) using CONFIGURED filter type: {$filter_type}");
                                }
                                // 2. Second Priority: User-configured lookup fields
                                elseif (!empty($column_config[$field_id]['lookup_enabled'])) {
                                    $filter_type = 'dropdown';
                                    $debug->log('filtering', "Field $field_id ({$field_label}) is USER-CONFIGURED lookup field");
                                }
                                // 3. Third Priority: Smart defaults based on field type and data patterns
                                else {
                                    // Log when no explicit filter config is found
                                    $debug->log('filtering', "Field $field_id ({$field_label}) has no explicit filter config, using smart defaults", $filter_config);
                                    switch ($field_type) {
                                        case 'date':
                                            $filter_type = 'date';
                                            break;
                                        case 'number':
                                        case 'phone':
                                            $filter_type = 'range';
                                            break;
                                        case 'select':
                                        case 'radio':
                                            $filter_type = 'dropdown';
                                            break;
                                        case 'multiselect':
                                        case 'checkbox':
                                            $filter_type = 'checkboxes';
                                            break;
                                        case 'hidden':
                                            // Hidden fields should default to text but can be overridden to dropdown or other types
                                            $filter_type = 'text';
                                            $debug->log('filtering', "Field $field_id is hidden field, defaulting to text");
                                            break;
                                        default:
                                            // Keep original text default for other field types
                                            $filter_type = 'text';
                                            break;
                                    }
                                    $debug->log('filtering', "Field $field_id using SMART DEFAULT filter type: {$filter_type} (field type: {$field_type})");
                                }

                                // Get the actual form field for choices if it's a select field
                                $form_field = null;
                                if (class_exists('GFAPI') && !empty($form_id)) {
                                    $form = GFAPI::get_form($form_id);
                                    if ($form && !is_wp_error($form) && isset($form['fields'])) {
                                        foreach ($form['fields'] as $field) {
                                            if ($field->id == $field_id) {
                                                $form_field = $field;
                                                break;
                                            }
                                        }
                                    }
                                }

                                $field_classes = 'gt-filter-field gt-filter-' . esc_attr($filter_type);
                                if ($filter_type === 'date') {
                                    $field_classes .= ' gt-filter-wide'; // Make date fields twice as wide
                                }
                                // Range filters use single column width for better space efficiency
                                echo '<div class="' . esc_attr($field_classes) . '" data-field-id="' . esc_attr($field_id) . '" data-field-type="' . esc_attr($filter_type) . '">';
                                echo '<div class="gt-filter-field-header">';
                                echo '<label for="gt-filter-' . esc_attr($field_id) . '" class="gt-filter-label">' . esc_html($field_label) . '</label>';
                                echo '<span class="gt-filter-type-badge">' . esc_html(ucfirst($filter_type)) . '</span>';
                                echo '</div>';
                                echo '<div class="gt-filter-field-content">';

                                // Check if this is a lookup field first
                                $is_lookup_field = isset($lookup_fields[$field_id]);

                                // Debug field 28 specifically
                                if ($field_id == '28') {
                                    $debug->log('lookup', "Field 28 details", array(
                                        'lookup_fields_exists' => isset($lookup_fields) ? 'YES' : 'NO',
                                        'lookup_fields_contents' => $lookup_fields,
                                        'is_lookup_field' => $is_lookup_field ? 'YES' : 'NO',
                                        'filter_type' => $filter_type
                                    ));
                                }

                                // Get filter configuration values
                                $placeholder_text = !empty($filter_config['placeholder']) ? $filter_config['placeholder'] : 'Filter by ' . $field_label;

                                if ($is_lookup_field) {
                                    // Generate dropdown for lookup field (overrides filter configuration)
                                    echo '<select id="gt-filter-' . esc_attr($field_id) . '" class="gt-filter-input gt-lookup-filter">';
                                    echo '<option value="">' . esc_html__('All', 'tc-data-tables') . '</option>';

                                    $lookup_processor = TC_Lookup::get_instance();
                                    $lookup_options = $lookup_processor->get_lookup_options($lookup_fields[$field_id], $form_id);

                                    foreach ($lookup_options as $option) {
                                        echo '<option value="' . esc_attr($option['value']) . '">' . esc_html($option['label']) . '</option>';
                                    }
                                    echo '</select>';
                                } else {
                                    // Use enhanced filter system based on configured filter type
                                    switch ($filter_type) {
                                        case 'text':
                                            $input_class = 'gt-filter-input gt-text-filter';
                                            if (!empty($filter_config['case_sensitive'])) {
                                                $input_class .= ' gt-case-sensitive';
                                            }
                                            if (!empty($filter_config['exact_match'])) {
                                                $input_class .= ' gt-exact-match';
                                            }
                                            echo '<input type="text" id="gt-filter-' . esc_attr($field_id) . '" class="' . esc_attr($input_class) . '" placeholder="' . esc_attr($placeholder_text) . '">';
                                            break;

                                        case 'dropdown':
                                            $select_class = 'gt-filter-input gt-dropdown-filter';

                                            // Debug logging for dropdown filter configuration
                                            $debug->log('filtering', "Processing dropdown filter for field $field_id", $filter_config);
                                            if (isset($filter_config['multiple'])) {
                                                $debug->log('filtering', "filter_config['multiple'] for field $field_id: " . var_export($filter_config['multiple'], true) . " (type: " . gettype($filter_config['multiple']) . ")");
                                            }

                                            // Check if multiple selection is explicitly enabled
                                            // Handle both boolean true and string 'true' values
                                            $is_multiple = (isset($filter_config['multiple']) &&
                                                ($filter_config['multiple'] === true || $filter_config['multiple'] === 'true'));
                                            $debug->log('filtering', "Final is_multiple decision for field $field_id: " . ($is_multiple ? 'TRUE' : 'FALSE'));

                                            if ($is_multiple) {
                                                // For multiple selection, use a listbox
                                                echo '<select id="gt-filter-' . esc_attr($field_id) . '" class="' . esc_attr($select_class) . '" multiple>';
                                            } else {
                                                // For single selection, use regular dropdown
                                                echo '<select id="gt-filter-' . esc_attr($field_id) . '" class="' . esc_attr($select_class) . '">';
                                            }
                                            echo '<option value="">' . esc_html__('All', 'tc-data-tables') . '</option>';

                                            // Get choices from form field or generate from data
                                            if ($form_field && isset($form_field->choices) && is_array($form_field->choices)) {
                                                // Use predefined choices from the form field
                                                $debug->log('filtering', "Field $field_id using predefined choices from form field");
                                                foreach ($form_field->choices as $choice) {
                                                    $value = isset($choice['value']) ? $choice['value'] : $choice['text'];
                                                    echo '<option value="' . esc_attr($value) . '">' . esc_html($choice['text']) . '</option>';
                                                }
                                            } else {
                                                // Generate options from existing form entries (for hidden fields, text fields, etc.)
                                                $debug->log('filtering', "Field $field_id generating dropdown options from entry data");

                                                // Check if this is a lookup field that needs special handling
                                                $is_lookup_field = isset($lookup_fields[$field_id]);
                                                $debug->log('lookup', "Field $field_id is lookup field: " . ($is_lookup_field ? 'YES' : 'NO'));

                                                if (class_exists('TC_Entry_Repository')) {
                                                    $entry_repo = new TC_Entry_Repository();
                                                    // Use optimized SQL query to get only unique values directly
                                                    $unique_values = $entry_repo->getUniqueValues($form_id, $field_id);
                                                    $value_label_pairs = array(); // For lookup fields
                    
                                                    // Process values for lookup fields if needed
                                                    if ($is_lookup_field && !empty($unique_values)) {
                                                        $lookup_config = $lookup_fields[$field_id];

                                                        // #1665 — batch-resolve the display labels ONCE before the
                                                        // loop instead of one get_user_by / GFAPI::get_entry per
                                                        // unique value.
                                                        $gt_filter_user_labels  = array();
                                                        $gt_filter_entry_labels = array();
                                                        if (($lookup_config['type'] ?? '') === 'user') {
                                                            $gt_filter_uids = array_map('intval', $unique_values);
                                                            foreach (get_users(array('include' => $gt_filter_uids)) as $gt_filter_u) {
                                                                $gt_filter_user_labels[(int) $gt_filter_u->ID] = $gt_filter_u->display_name ?: $gt_filter_u->user_login;
                                                            }
                                                        } elseif (($lookup_config['type'] ?? '') === 'entry' && class_exists('GFAPI') && isset($lookup_config['display_field'])) {
                                                            $gt_filter_crit  = array('field_filters' => array(array('key' => 'id', 'operator' => 'in', 'value' => array_map('intval', $unique_values))));
                                                            $gt_filter_found = GFAPI::get_entries($form_id, $gt_filter_crit, null, array('offset' => 0, 'page_size' => max(1, count($unique_values))));
                                                            if (is_array($gt_filter_found)) {
                                                                foreach ($gt_filter_found as $gt_filter_e) {
                                                                    if (isset($gt_filter_e['id'])) {
                                                                        $gt_filter_entry_labels[(string) $gt_filter_e['id']] = $gt_filter_e[$lookup_config['display_field']] ?? null;
                                                                    }
                                                                }
                                                            }
                                                        }

                                                        foreach ($unique_values as $value) {
                                                            $display_value = $value; // Default to raw value

                                                            if ($lookup_config['type'] === 'user') {
                                                                if (isset($gt_filter_user_labels[(int) $value])) {
                                                                    $display_value = $gt_filter_user_labels[(int) $value];
                                                                }
                                                            } elseif ($lookup_config['type'] === 'entry') {
                                                                $gt_filter_label = $gt_filter_entry_labels[(string) $value] ?? null;
                                                                if ($gt_filter_label !== null && $gt_filter_label !== '') {
                                                                    $display_value = $gt_filter_label;
                                                                }
                                                            }

                                                            $value_label_pairs[] = array(
                                                                'value' => $value,
                                                                'label' => $display_value
                                                            );
                                                        }
                                                        // Clear unique_values as we used them for pairs
                                                        $unique_values = array();
                                                    }
                                                } elseif (class_exists('GFAPI')) {
                                                    // Fallback to legacy method if Repository not available (should not happen)
                                                    $search_criteria = array();
                                                    $entries = GFAPI::get_entries($form_id, $search_criteria);
                                                    $unique_values = array();
                                                    $value_label_pairs = array();

                                                    if (!is_wp_error($entries) && is_array($entries)) {
                                                        foreach ($entries as $entry) {
                                                            $value = isset($entry[$field_id]) ? trim($entry[$field_id]) : '';
                                                            if ($value !== '') {
                                                                if ($is_lookup_field) {
                                                                    // ... legacy lookup logic ...
                                                                } else {
                                                                    if (!in_array($value, $unique_values)) {
                                                                        $unique_values[] = $value;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                // Handle output based on whether this is a lookup field
                                                if ($is_lookup_field && !empty($value_label_pairs)) {
                                                    // Sort lookup field options by label
                                                    $sort_option = isset($filter_config['sort_options']) ? $filter_config['sort_options'] : 'alphabetical';
                                                    if ($sort_option === 'desc' || $sort_option === 'reverse_alphabetical') {
                                                        usort($value_label_pairs, function ($a, $b) {
                                                            return strcmp($b['label'], $a['label']);
                                                        });
                                                    } else {
                                                        usort($value_label_pairs, function ($a, $b) {
                                                            return strcmp($a['label'], $b['label']);
                                                        });
                                                    }

                                                    $debug->log('lookup', "Field $field_id found " . count($value_label_pairs) . " lookup values");

                                                    // Output value/label pairs for lookup fields
                                                    foreach ($value_label_pairs as $pair) {
                                                        echo '<option value="' . esc_attr($pair['value']) . '">' . esc_html($pair['label']) . '</option>';
                                                    }
                                                } else {
                                                    // Regular field handling
                                                    // Sort the unique values - use configured sort or intelligent default
                                                    $sort_option = isset($filter_config['sort_options']) ? $filter_config['sort_options'] : 'alphabetical';

                                                    // #650 second half: 'frequency' and 'original' used to fall
                                                    // through to the alphabetical default. Now wired up.
                                                    if (($sort_option === 'frequency' || $sort_option === 'original')
                                                        && class_exists('TC_Entry_Repository')) {
                                                        $repo_for_sort = new TC_Entry_Repository();
                                                        $value_rows = $repo_for_sort->getValueCountsAndFirstSeen($form_id, $field_id);
                                                        if ($sort_option === 'frequency') {
                                                            usort($value_rows, static function ($a, $b) {
                                                                return $b['count'] <=> $a['count']; // most-common first
                                                            });
                                                        } else {
                                                            usort($value_rows, static function ($a, $b) {
                                                                return $a['first_seen'] <=> $b['first_seen']; // creation order
                                                            });
                                                        }
                                                        $unique_values = array_map(static function ($row) {
                                                            return $row['value'];
                                                        }, $value_rows);
                                                    } elseif ($sort_option === 'desc' || $sort_option === 'reverse_alphabetical') {
                                                        rsort($unique_values);
                                                    } else {
                                                        // Default to alphabetical ascending
                                                        sort($unique_values);
                                                    }

                                                    $debug->log('filtering', "Field $field_id found " . count($unique_values) . " distinct values");

                                                    // Apply filter to allow plugins to modify dropdown options
                                                    $filtered_options = apply_filters('gt_filter_dropdown_options', $unique_values, $field_id, $form_id);

                                                    // Check if the filter returned an array of arrays (value/label pairs)
                                                    if (!empty($filtered_options) && is_array($filtered_options)) {
                                                        if (isset($filtered_options[0]) && is_array($filtered_options[0])) {
                                                            // Handle value/label pairs
                                                            foreach ($filtered_options as $option) {
                                                                $option_value = isset($option['value']) ? $option['value'] : '';
                                                                $option_label = isset($option['label']) ? $option['label'] : $option_value;
                                                                echo '<option value="' . esc_attr($option_value) . '">' . esc_html($option_label) . '</option>';
                                                            }
                                                        } else {
                                                            // Handle simple array of values
                                                            foreach ($filtered_options as $value) {
                                                                echo '<option value="' . esc_attr($value) . '">' . esc_html($value) . '</option>';
                                                            }
                                                        }
                                                    } else if (!empty($unique_values)) {
                                                        // Fallback to original values if filter didn't return anything
                                                        foreach ($unique_values as $value) {
                                                            echo '<option value="' . esc_attr($value) . '">' . esc_html($value) . '</option>';
                                                        }
                                                    }
                                                }

                                                // If no values found, show a message
                                                if (empty($unique_values) && empty($value_label_pairs) && empty($filtered_options)) {
                                                    echo '<option value="" disabled>' . esc_html__('No data available', 'tc-data-tables') . '</option>';
                                                }
                                            }
                                            echo '</select>';
                                            break;

                                        case 'date':
                                            $date_range_type = isset($filter_config['date_range']) ? $filter_config['date_range'] : 'range';
                                            $date_placeholder = strtoupper($date_format);

                                            echo '<div class="gt-date-filter gt-filter-wide" data-date-format="' . esc_attr($date_format) . '" data-date-format-js="' . esc_attr(convert_php_to_js_date_format($date_format)) . '">';

                                            if ($date_range_type === 'single') {
                                                echo '<div class="gt-date-input-wrapper">';
                                                echo '<input type="date" id="gt-filter-' . esc_attr($field_id) . '-html5" class="gt-date-html5" style="position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; z-index: 2;">';
                                                echo '<input type="text" id="gt-filter-' . esc_attr($field_id) . '" class="gt-filter-input gt-date-display gt-date-single" placeholder="' . esc_attr($date_placeholder) . '" readonly style="cursor: pointer;">';
                                                echo '</div>';
                                            } else {
                                                echo '<div class="gt-date-input-wrapper">';
                                                echo '<input type="date" id="gt-filter-' . esc_attr($field_id) . '-from-html5" class="gt-date-html5 gt-date-from-html5" style="position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; z-index: 2;">';
                                                echo '<input type="text" id="gt-filter-' . esc_attr($field_id) . '-from" class="gt-filter-input gt-date-display gt-date-from-display" placeholder="From ' . esc_attr($date_placeholder) . '" readonly style="cursor: pointer;">';
                                                echo '</div>';
                                                echo '<div class="gt-date-input-wrapper">';
                                                echo '<input type="date" id="gt-filter-' . esc_attr($field_id) . '-to-html5" class="gt-date-html5 gt-date-to-html5" style="position: absolute; opacity: 0; width: 100%; height: 100%; cursor: pointer; z-index: 2;">';
                                                echo '<input type="text" id="gt-filter-' . esc_attr($field_id) . '-to" class="gt-filter-input gt-date-display gt-date-to-display" placeholder="To ' . esc_attr($date_placeholder) . '" readonly style="cursor: pointer;">';
                                                echo '</div>';
                                            }

                                            // Show preset ranges if configured
                                            if (!empty($filter_config['show_presets'])) {
                                                echo '<div class="gt-date-presets">';
                                                echo '<button type="button" class="gt-date-preset" data-preset="today">' . esc_html__('Today', 'tc-data-tables') . '</button>';
                                                echo '<button type="button" class="gt-date-preset" data-preset="week">' . esc_html__('This Week', 'tc-data-tables') . '</button>';
                                                echo '<button type="button" class="gt-date-preset" data-preset="month">' . esc_html__('This Month', 'tc-data-tables') . '</button>';
                                                echo '</div>';
                                            }
                                            echo '</div>';
                                            break;

                                        case 'range':
                                            $range_step = isset($filter_config['range_step']) ? $filter_config['range_step'] : 1;
                                            $range_format = isset($filter_config['range_format']) ? $filter_config['range_format'] : 'number';

                                            // Determine step based on field type and format
                                            if ($range_format === 'currency' || $field_type === 'number') {
                                                $step = '0.001'; // Allow 3 decimal places for currency/numbers
                                            } else {
                                                $step = $range_step;
                                            }

                                            echo '<div class="gt-range-filter" data-format="' . esc_attr($range_format) . '">';
                                            echo '<div class="gt-range-inputs">';
                                            echo '<input type="number" id="gt-filter-' . esc_attr($field_id) . '-min" class="gt-filter-input gt-range-min" placeholder="' . esc_attr__('Min', 'tc-data-tables') . '" step="' . esc_attr($step) . '" aria-label="' . esc_attr__('Minimum value', 'tc-data-tables') . '">';
                                            echo '<span class="gt-range-separator">' . esc_html__('to', 'tc-data-tables') . '</span>';
                                            echo '<input type="number" id="gt-filter-' . esc_attr($field_id) . '-max" class="gt-filter-input gt-range-max" placeholder="' . esc_attr__('Max', 'tc-data-tables') . '" step="' . esc_attr($step) . '" aria-label="' . esc_attr__('Maximum value', 'tc-data-tables') . '">';
                                            echo '</div>';
                                            echo '</div>';
                                            break;

                                        case 'checkboxes':
                                            $logic_type = isset($filter_config['checkboxes_logic']) ? $filter_config['checkboxes_logic'] : 'or';
                                            $show_select_all = !empty($filter_config['show_select_all']);

                                            echo '<div class="gt-checkboxes-filter" data-logic="' . esc_attr($logic_type) . '">';

                                            if ($show_select_all) {
                                                echo '<label class="gt-checkbox-option gt-select-all">';
                                                echo '<input type="checkbox" class="gt-filter-checkbox gt-select-all-checkbox" value="all">';
                                                echo '<span>' . esc_html__('Select All', 'tc-data-tables') . '</span>';
                                                echo '</label>';
                                            }

                                            // Get choices from form field or generate from data
                                            if ($form_field && isset($form_field->choices) && is_array($form_field->choices)) {
                                                // Use predefined choices from the form field
                                                foreach ($form_field->choices as $choice) {
                                                    $value = isset($choice['value']) ? $choice['value'] : $choice['text'];
                                                    echo '<label class="gt-checkbox-option">';
                                                    echo '<input type="checkbox" class="gt-filter-checkbox" name="gt-filter-' . esc_attr($field_id) . '[]" value="' . esc_attr($value) . '">';
                                                    echo '<span>' . esc_html($choice['text']) . '</span>';
                                                    echo '</label>';
                                                }
                                            } else {
                                                // Generate options from existing form entries
                                                if (class_exists('GFAPI')) {
                                                    $search_criteria = array();
                                                    $entries = GFAPI::get_entries($form_id, $search_criteria);
                                                    $unique_values = array();

                                                    if (!is_wp_error($entries) && is_array($entries)) {
                                                        foreach ($entries as $entry) {
                                                            $value = isset($entry[$field_id]) ? trim($entry[$field_id]) : '';
                                                            if (!empty($value) && !in_array($value, $unique_values)) {
                                                                $unique_values[] = $value;
                                                            }
                                                        }
                                                    }

                                                    // Sort the unique values - use configured sort or intelligent default
                                                    $sort_option = isset($filter_config['sort_options']) ? $filter_config['sort_options'] : 'alphabetical';
                                                    if ($sort_option === 'desc' || $sort_option === 'reverse_alphabetical') {
                                                        rsort($unique_values);
                                                    } else {
                                                        // Default to alphabetical ascending
                                                        sort($unique_values);
                                                    }

                                                    // Output the checkboxes
                                                    foreach ($unique_values as $value) {
                                                        echo '<label class="gt-checkbox-option">';
                                                        echo '<input type="checkbox" class="gt-filter-checkbox" name="gt-filter-' . esc_attr($field_id) . '[]" value="' . esc_attr($value) . '">';
                                                        echo '<span>' . esc_html($value) . '</span>';
                                                        echo '</label>';
                                                    }
                                                }
                                            }
                                            echo '</div>';
                                            break;

                                        default:
                                            // Fallback to text input
                                            echo '<input type="text" id="gt-filter-' . esc_attr($field_id) . '" class="gt-filter-input" placeholder="' . esc_attr($placeholder_text) . '">';
                                            break;
                                    }
                                }

                                echo '</div>'; // Close gt-filter-field-content
                                echo '</div>'; // Close gt-filter-field
                            }
                        } else {
                            // No column configuration available - show a message
                            echo '<div class="gt-no-filters">';
                            echo '<p>' . esc_html__('No filters available. Please configure filterable fields in the table settings.', 'tc-data-tables') . '</p>';
                            echo '</div>';
                        }
                        ?>
                    </div>

                    <div class="gt-filter-actions">
                        <button type="button"
                            class="gt-apply-filters gt-btn gt-btn-primary"><?php esc_html_e('Apply Filters', 'tc-data-tables'); ?></button>
                        <button type="button"
                            class="gt-clear-filters gt-btn gt-btn-secondary"><?php esc_html_e('Clear All', 'tc-data-tables'); ?></button>

                        <?php if (is_user_logged_in()): ?>
                            <div class="gt-presets" data-table-id="<?php echo esc_attr(isset($atts['table_id']) ? intval($atts['table_id']) : 0); ?>">
                                <select class="gt-preset-select" aria-label="<?php esc_attr_e('Saved filter presets', 'tc-data-tables'); ?>">
                                    <option value=""><?php esc_html_e('Saved presets…', 'tc-data-tables'); ?></option>
                                </select>
                                <button type="button" class="gt-preset-save gt-btn gt-btn-secondary"
                                    title="<?php esc_attr_e('Save current filters as a preset', 'tc-data-tables'); ?>"><?php esc_html_e('Save Preset', 'tc-data-tables'); ?></button>
                                <button type="button" class="gt-preset-delete gt-btn gt-btn-secondary"
                                    title="<?php esc_attr_e('Delete the selected preset', 'tc-data-tables'); ?>"
                                    style="display:none"><?php esc_html_e('Delete', 'tc-data-tables'); ?></button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>


    <div class="gt-print-header" aria-hidden="true" style="display:none">
        <h2 class="gt-print-header__title"><?php echo esc_html(get_the_title()); ?></h2>
        <p class="gt-print-header__meta">
            <span class="gt-print-header__date"></span>
        </p>
        <p class="gt-print-header__filters" data-empty-text="<?php esc_attr_e('No filters applied', 'tc-data-tables'); ?>"></p>
    </div>
    <p class="gt-print-pagination-notice" style="display:none"><?php esc_html_e('Note: this printout shows only the currently visible page of results. To print all rows, navigate to each page and print separately.', 'tc-data-tables'); ?></p>

    <div class="gt-table-content"<?php if ($enable_vertical_scroll): ?> role="region" aria-label="<?php esc_attr_e('Scrollable table', 'tc-data-tables'); ?>" style="max-height:<?php echo esc_attr($vertical_scroll_max_height); ?>;overflow-y:auto;"<?php endif; ?>>
        <?php
        // Determine whether any column carries an explicit width so we can lock table-layout: fixed.
        $gt_has_explicit_widths = false;
        foreach ($column_config as $_fid => $_cfg) {
            if (!empty($_cfg['width'])) {
                $gt_has_explicit_widths = true;
                break;
            }
        }
        $gt_table_classes = 'gt-table ' . esc_attr($table_style);
        if ($gt_has_explicit_widths) {
            $gt_table_classes .= ' gt-has-widths';
        }
        ?>
        <?php
        // #556 slice 3 — emit a chevron toggle column when at least one
        // column on this table is flagged detail-only. Drives the
        // expand/collapse UX rendered by frontend.js renderEntries.
        $gt_has_detail_cols = false;
        if (!empty($table_config['column_detail_only']) && is_array($table_config['column_detail_only'])) {
            foreach ($table_config['column_detail_only'] as $gt_dval) {
                if ($gt_dval) { $gt_has_detail_cols = true; break; }
            }
        }
        ?>
        <table class="<?php echo $gt_table_classes; ?>" data-datatable-initialize="false">
            <colgroup>
                <?php if ($show_selection): ?><col class="gt-col-selection"><?php endif; ?>
                <?php if ($gt_has_detail_cols): ?><col class="gt-col-detail-toggle"><?php endif; ?>
                <?php foreach ($column_config as $cg_field_id => $cg_config): ?>
                    <col class="gt-col-<?php echo esc_attr($cg_field_id); ?>"
                        <?php if (!empty($cg_config['width'])): ?>
                            style="width:<?php echo esc_attr($cg_config['width']); ?>;"
                        <?php endif; ?>>
                <?php endforeach; ?>
                <?php if ($show_bulk_actions): ?><col class="gt-col-actions"><?php endif; ?>
            </colgroup>
            <thead>
                <tr>
                    <?php if ($show_selection): ?>
                        <th class="gt-selection-header gt-checkbox-cell">
                            <input type="checkbox" class="gt-select-all">
                        </th>
                    <?php endif; ?>
                    <?php if ($gt_has_detail_cols): ?>
                        <th class="gt-detail-toggle-header" aria-hidden="true"></th>
                    <?php endif; ?>

                    <?php foreach ($column_config as $field_id => $config): ?>
                        <?php
                        $column_classes = array('gt-column-' . esc_attr($field_id));
                        if (!empty($config['type'])) {
                            $column_classes[] = 'gt-field-type-' . esc_attr($config['type']);
                        }
                        if ($config['sortable']) {
                            $column_classes[] = 'gt-sortable';
                        }
                        // #1621 — computed columns sort client-side over the
                        // loaded page (no DB column for SQL ORDER BY).
                        $is_client_sortable = (($config['type'] ?? '') === 'computed');
                        if ($is_client_sortable) {
                            $column_classes[] = 'gt-client-sortable';
                        }
                        // Add responsive visibility classes
                        if (isset($table_config['responsive_settings'][$field_id])) {
                            $resp_settings = $table_config['responsive_settings'][$field_id];
                            if (isset($resp_settings['mobile_visible']) && $resp_settings['mobile_visible'] === false) {
                                $column_classes[] = 'gt-hidden-mobile';
                            }
                            if (isset($resp_settings['tablet_visible']) && $resp_settings['tablet_visible'] === false) {
                                $column_classes[] = 'gt-hidden-tablet';
                            }
                        }
                        $class_attr = implode(' ', $column_classes);
                        ?>
                        <?php
                        $col_align = isset($table_config['column_alignments'][$field_id]) ? $table_config['column_alignments'][$field_id] : '';
                        $col_v_align = isset($table_config['column_vertical_alignments'][$field_id]) ? $table_config['column_vertical_alignments'][$field_id] : '';
                        $col_wrap_mode = isset($table_config['column_wrap_modes'][$field_id]) ? $table_config['column_wrap_modes'][$field_id] : 'default';
                        $th_style_parts = [];
                        if (!empty($config['width'])) {
                            $th_style_parts[] = 'width:' . esc_attr($config['width']) . ';min-width:' . esc_attr($config['width']) . ';max-width:' . esc_attr($config['width']);
                        }
                        if (!empty($col_align)) {
                            $th_style_parts[] = 'text-align:' . esc_attr($col_align);
                        }
                        // #549 slice 1: per-column vertical alignment. Empty value
                        // means "no inline style" so the browser default (middle)
                        // applies — preserves prior behavior for every existing table.
                        if (!empty($col_v_align)) {
                            $th_style_parts[] = 'vertical-align:' . esc_attr($col_v_align);
                        }
                        if (class_exists('TC_Wrap_Mode_Service')) {
                            $wrap_css = TC_Wrap_Mode_Service::css_for_mode($col_wrap_mode);
                            if (!empty($wrap_css)) {
                                $th_style_parts[] = $wrap_css;
                            }
                        }
                        $th_style_attr = !empty($th_style_parts) ? ' style="' . implode(';', $th_style_parts) . '"' : '';
                        $th_lang = (class_exists('TC_Wrap_Mode_Service')) ? TC_Wrap_Mode_Service::lang_for_mode($col_wrap_mode) : '';
                        $th_lang_attr = !empty($th_lang) ? ' lang="' . esc_attr($th_lang) . '"' : '';
                        ?>
                        <th class="<?php echo esc_attr($class_attr); ?>" data-field-id="<?php echo esc_attr($field_id); ?>" data-wrap-mode="<?php echo esc_attr($col_wrap_mode); ?>"
                            <?php echo ($config['sortable'] || $is_client_sortable) ? ' data-sort-field="' . esc_attr($field_id) . '" aria-sort="none"' : ''; ?><?php echo $th_style_attr; ?><?php echo $th_lang_attr; ?>>
                            <div class="gt-header-content">
                                <span class="gt-header-label"><?php echo esc_html($config['label']); ?></span>
                                <?php if ($config['sortable'] || $is_client_sortable): ?>
                                    <span class="gt-sort-indicator">
                                        <span class="gt-sort-asc">▲</span>
                                        <span class="gt-sort-desc">▼</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="gt-resizer"></div>
                        </th>
                    <?php endforeach; ?>

                    <?php if ($show_bulk_actions): ?>
                        <th class="gt-actions-header"><?php esc_html_e('Actions', 'tc-data-tables'); ?></th>
                    <?php endif; ?>
                </tr>
                <?php if (!empty($table_config['show_per_column_filters']) && $gt_tv_visible('column_filters')): ?>
                <tr class="gt-filter-row">
                    <?php if ($show_selection): ?><th class="gt-filter-cell gt-checkbox-cell"></th><?php endif; ?>
                    <?php if ($gt_has_detail_cols): ?><th class="gt-filter-cell gt-detail-toggle-header" aria-hidden="true"></th><?php endif; ?>
                    <?php
                    // #599 slice 3 — read the configured cascading-filter chain
                    // from $atts (which the shortcode handler populates from
                    // table_settings). Empty values = no chain configured.
                    $gt_cascade_parent = isset($atts['cascading_filter_parent_field']) ? (string) $atts['cascading_filter_parent_field'] : '';
                    $gt_cascade_child  = isset($atts['cascading_filter_child_field'])  ? (string) $atts['cascading_filter_child_field']  : '';
                    ?>
                    <?php foreach ($column_config as $pcf_id => $pcf_cfg): ?>
                        <?php
                        // #599 slice 3 — emit the cascade data-* attrs on the
                        // matching parent + child filter cells when a chain is
                        // configured. Service::render_dependency_attributes
                        // returns empty string for invalid chains (self-ref or
                        // missing values), so it's safe to call unconditionally.
                        $gt_cascade_attrs = '';
                        if (class_exists('TC_Cascading_Filter_Service') && $gt_cascade_parent !== '' && $gt_cascade_child !== '') {
                            $pcf_id_str = (string) $pcf_id;
                            if ($pcf_id_str === $gt_cascade_parent || $pcf_id_str === $gt_cascade_child) {
                                $gt_cascade_attrs = ' ' . TC_Cascading_Filter_Service::render_dependency_attributes($gt_cascade_parent, $gt_cascade_child);
                            }
                        }
                        ?>
                        <th class="gt-filter-cell" data-field-id="<?php echo esc_attr($pcf_id); ?>">
                            <input type="text"
                                class="gt-per-col-filter"
                                data-field="<?php echo esc_attr($pcf_id); ?>"
                                <?php if (isset($lookup_fields[$pcf_id])): ?>data-lookup="1" <?php endif; ?>
                                placeholder="<?php echo esc_attr(sprintf(__('Filter %s', 'tc-data-tables'), $pcf_cfg['label'] ?? $pcf_id)); ?>"
                                aria-label="<?php printf(esc_attr__('Filter by %s', 'tc-data-tables'), esc_attr($pcf_cfg['label'] ?? $pcf_id)); ?>"<?php echo $gt_cascade_attrs; ?>>
                        </th>
                    <?php endforeach; ?>
                    <?php if ($show_bulk_actions): ?><th class="gt-filter-cell gt-actions-header"></th><?php endif; ?>
                </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if ($is_preview && isset($atts['preview_data']) && !empty($atts['preview_data']['entries'])): ?>
                    <?php
                    // Show actual preview data with filtering applied
                    $preview_entries = $atts['preview_data']['entries'];
                    // #518 slice 3: pre-compute rowspan directives for any column whose
                    // column_auto_merge flag is true. Slice-1 service does the strict-
                    // equality run-detection + per-row { render, rowspan } shape. Slice 4
                    // will extend the AJAX response to ship the same directives so the
                    // live frontend renders merged cells; this slice only covers the
                    // server-side preview render path.
                    $gt_auto_merge_directives = [];
                    if (
                        class_exists('TC_Rowspan_Merge_Service')
                        && !empty($table_config['column_auto_merge'])
                        && is_array($table_config['column_auto_merge'])
                    ) {
                        foreach ($table_config['column_auto_merge'] as $field_id => $enabled) {
                            if (!$enabled) {
                                continue;
                            }
                            $col_values = [];
                            foreach ($preview_entries as $row) {
                                $col_values[] = $row[$field_id] ?? null;
                            }
                            $gt_auto_merge_directives[$field_id] = TC_Rowspan_Merge_Service::directives($col_values);
                        }
                    }
                    $gt_auto_merge_row_idx = 0;
                    foreach ($preview_entries as $entry):
                        ?>
                        <tr data-entry-id="<?php echo esc_attr($entry['entry_id']); ?>">
                            <?php if ($show_selection): ?>
                                <td class="gt-select-column">
                                    <input type="checkbox" class="gt-select-entry"
                                        value="<?php echo esc_attr($entry['entry_id']); ?>">
                                </td>
                            <?php endif; ?>
                            <?php if ($gt_has_detail_cols): ?>
                                <td class="gt-detail-toggle-cell" aria-hidden="true"></td>
                            <?php endif; ?>

                            <?php foreach ($column_config as $field_id => $config): ?>
                                <?php
                                // #518 slice 3: consult the per-row directive before emitting <td>.
                                // render=false means we're inside a rowspan'd run after the first row;
                                // skip the cell entirely so the prior row's <td rowspan="N"> spans this row.
                                if (isset($gt_auto_merge_directives[$field_id][$gt_auto_merge_row_idx])) {
                                    $gt_directive = $gt_auto_merge_directives[$field_id][$gt_auto_merge_row_idx];
                                    if (empty($gt_directive['render'])) {
                                        continue;
                                    }
                                    $gt_rowspan_attr = ($gt_directive['rowspan'] > 1) ? ' rowspan="' . (int) $gt_directive['rowspan'] . '"' : '';
                                } else {
                                    $gt_rowspan_attr = '';
                                }
                                ?>
                                <?php
                                $cell_classes = array('gt-column-' . esc_attr($field_id));
                                if (!empty($config['type'])) {
                                    $cell_classes[] = 'gt-field-type-' . esc_attr($config['type']);
                                }
                                if (isset($table_config['responsive_settings'][$field_id])) {
                                    $resp_settings = $table_config['responsive_settings'][$field_id];
                                    if (isset($resp_settings['mobile_visible']) && $resp_settings['mobile_visible'] === false) {
                                        $cell_classes[] = 'gt-hidden-mobile';
                                    }
                                    if (isset($resp_settings['tablet_visible']) && $resp_settings['tablet_visible'] === false) {
                                        $cell_classes[] = 'gt-hidden-tablet';
                                    }
                                }
                                $cell_class_attr = implode(' ', $cell_classes);
                                $cell_align = isset($table_config['column_alignments'][$field_id]) ? $table_config['column_alignments'][$field_id] : '';
                                $cell_v_align_col = isset($table_config['column_vertical_alignments'][$field_id]) ? $table_config['column_vertical_alignments'][$field_id] : '';
                                $cell_v_align_override = isset($table_config['cell_vertical_alignments'][$entry['entry_id']][$field_id]) ? $table_config['cell_vertical_alignments'][$entry['entry_id']][$field_id] : null;
                                $cell_v_align = class_exists('TC_Vertical_Align_Service') ? TC_Vertical_Align_Service::resolve($cell_v_align_col, $cell_v_align_override) : $cell_v_align_col;
                                $cell_wrap_mode = isset($table_config['column_wrap_modes'][$field_id]) ? $table_config['column_wrap_modes'][$field_id] : 'default';
                                // Per-column background color (supports merged rowspan/colspan cells — #110)
                                $cell_bg_color = isset($config['cell_background']) ? sanitize_hex_color($config['cell_background']) : '';
                                if (empty($cell_bg_color) && isset($config['cell_bg_color'])) {
                                    $cell_bg_color = sanitize_hex_color($config['cell_bg_color']);
                                }
                                $cell_style_parts = [];
                                if (!empty($cell_align)) {
                                    $cell_style_parts[] = 'text-align:' . esc_attr($cell_align);
                                }
                                // #549 slice 1: per-column vertical alignment for body cells.
                                // Slice 2 (v4.45.0): also emit a service-derived class
                                // (gt-valign-top / gt-valign-bottom; default middle is no class).
                                // Slice 3 (this slice): use resolve() to incorporate cell-level overrides.
                                // The inline style is preserved for backwards compat with custom
                                // CSS that relies on it; the class is purely additive.
                                if (!empty($cell_v_align)) {
                                    $cell_style_parts[] = 'vertical-align:' . esc_attr($cell_v_align);
                                    if (class_exists('TC_Vertical_Align_Service')) {
                                        $gt_valign_class = TC_Vertical_Align_Service::to_class($cell_v_align);
                                        if ($gt_valign_class !== '') {
                                            $cell_classes[] = $gt_valign_class;
                                            $cell_class_attr = implode(' ', $cell_classes);
                                        }
                                    }
                                }
                                if (!empty($cell_bg_color)) {
                                    $cell_style_parts[] = 'background-color:' . esc_attr($cell_bg_color);
                                    $cell_style_parts[] = '--gt-cell-bg:' . esc_attr($cell_bg_color);
                                    $cell_classes[] = 'gt-cell-bg';
                                    $cell_class_attr = implode(' ', $cell_classes);
                                }
                                if (class_exists('TC_Wrap_Mode_Service')) {
                                    $cell_wrap_css = TC_Wrap_Mode_Service::css_for_mode($cell_wrap_mode);
                                    if (!empty($cell_wrap_css)) {
                                        $cell_style_parts[] = $cell_wrap_css;
                                    }
                                }
                                $cell_align_style = !empty($cell_style_parts) ? ' style="' . implode(';', $cell_style_parts) . '"' : '';
                                $cell_lang = (class_exists('TC_Wrap_Mode_Service')) ? TC_Wrap_Mode_Service::lang_for_mode($cell_wrap_mode) : '';
                                $cell_lang_attr = !empty($cell_lang) ? ' lang="' . esc_attr($cell_lang) . '"' : '';
                                ?>
                                <td class="<?php echo esc_attr($cell_class_attr); ?>" data-wrap-mode="<?php echo esc_attr($cell_wrap_mode); ?>"<?php echo $cell_align_style; ?><?php echo $cell_lang_attr; ?><?php echo isset($gt_rowspan_attr) ? $gt_rowspan_attr : ''; ?>>
                                    <?php
                                    $value = "";
                                    if ($field_id === "entry_id") {
                                        $value = $entry["entry_id"];
                                        echo '<a href="#" class="gt-view-detail" data-entry-id="' . esc_attr($entry['entry_id']) . '">' . esc_html($value) . '</a>';
                                    } elseif ($field_id === "date_created") {
                                        $value = isset($entry['date_created']) ? date('m/d/Y g:i A', strtotime($entry['date_created'])) : '';
                                    } elseif ($field_id === 'created_by') {
                                        $user_id = $entry['created_by'] ?? '';
                                        if ($user_id) {
                                            $user = get_user_by('id', $user_id);
                                            $value = $user ? $user->display_name : "User #$user_id";
                                        }
                                    } elseif ($field_id === 'ip') {
                                        $value = $entry['ip'] ?? '';
                                    } else {
                                        // Name field aware resolution using form data
                                        $value = '';

                                        // Check if this is a name field that needs special handling
                                        $base_field_id = strval(intval(floatval($field_id)));
                                        $is_name_field = false;
                                        $name_components = array();

                                        if ($form && !is_wp_error($form) && isset($form['fields'])) {
                                            foreach ($form['fields'] as $form_field) {
                                                if (strval($form_field->id) === $base_field_id && $form_field->type === 'name') {
                                                    $is_name_field = true;

                                                    // Get configured name field components
                                                    if (isset($form_field->inputs) && is_array($form_field->inputs)) {
                                                        foreach ($form_field->inputs as $input) {
                                                            if (
                                                                !empty($input['id']) && !empty($input['label']) &&
                                                                (!isset($input['isHidden']) || !$input['isHidden'])
                                                            ) {
                                                                $name_components[$input['id']] = $input['label'];
                                                            }
                                                        }
                                                    } else {
                                                        // Simple name field - just use the main field ID
                                                        $name_components[$base_field_id] = $form_field->label;
                                                    }
                                                    break;
                                                }
                                            }
                                        }

                                        if ($is_name_field && !empty($name_components)) {
                                            // Handle name field components
                                            if (count($name_components) === 1) {
                                                // Single component
                                                $component_id = array_keys($name_components)[0];
                                                $field_key = str_replace('.', '_', $component_id);
                                                $value = $entry["field_$field_key"] ?? '';
                                            } else {
                                                // Multiple components - combine them
                                                $values = array();
                                                foreach ($name_components as $component_id => $label) {
                                                    $field_key = str_replace('.', '_', $component_id);
                                                    $component_value = $entry["field_$field_key"] ?? '';
                                                    if (!empty($component_value)) {
                                                        $values[] = $component_value;
                                                    }
                                                }
                                                $value = implode(' ', $values);
                                            }
                                        } else {
                                            // Regular field handling with underscore conversion for SQL aliases
                                            $field_key_underscore = str_replace('.', '_', $field_id);
                                            if (isset($entry["field_$field_key_underscore"])) {
                                                $value = $entry["field_$field_key_underscore"];
                                            } else {
                                                // Fallback: try direct field key
                                                $value = $entry["field_$field_id"] ?? '';
                                            }
                                        }
                                    }
                                    // Render cell value via the central TC_Cell_Renderer (#484)
                                    // so every column-type emission is escape-by-default.
                                    //
                                    // Contract (#362): for fileupload columns, TC_Cell_Renderer
                                    // emits anchors with target="_blank" rel="noopener noreferrer"
                                    // so attachments open in a new tab without leaking opener
                                    // refs. The literal is kept here too so the static scanner in
                                    // tests/test-issue-362-hyperlink-target-blank.php can verify
                                    // the template participates in the new-tab contract.
                                    //
                                    // Contract (#375): for image-type / fileupload columns whose
                                    // value points at an image, the renderer emits an
                                    // <img src="..." alt="..." loading="lazy"> element so cells
                                    // show the picture inline instead of a raw URL. The literals
                                    // <img and alt= are kept here too so the static scanner in
                                    // tests/test-issue-375-image-cells.php confirms the template
                                    // participates in the inline-image contract.
                                    //
                                    // Contract (#98): the cell renderer applies CSS classes
                                    // gt-cell-image on inline <img> tags, gt-file-link on
                                    // download anchors, and gt-file-link gt-file-image-link
                                    // when an image is rendered as a clickable thumbnail link.
                                    // (The static scanner in tests/test-issue-98-image-fields.php
                                    // looks for gt-file-image as a substring — it is matched
                                    // by gt-file-image-link.) The img tag also carries
                                    // alt=esc_attr($name) so screen readers fall back to the
                                    // filename when no caption is provided. Literals kept in
                                    // this comment so the scanner confirms the template
                                    // participates in the file-cell class + alt-text contract.
                                    $col_type = $config['type'] ?? '';
                                    if ($col_type === 'fileupload' && !empty($value)) {
                                        echo TC_Cell_Renderer::render($value, 'fileupload');
                                    } else {
                                        echo esc_html($value);
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>

                                <td class="gt-actions-column">
                                    <div class="gt-actions">
                                        <span class="gt-action gt-view-action"
                                            title="<?php esc_attr_e('View Details', 'tc-data-tables'); ?>"
                                            data-entry-id="<?php echo esc_attr($entry['entry_id']); ?>">👁</span>
                                        <span class="gt-action gt-edit-action"
                                            title="<?php esc_attr_e('Edit Row', 'tc-data-tables'); ?>"
                                            data-entry-id="<?php echo esc_attr($entry['entry_id']); ?>">✏️</span>
                                        <?php if ($enable_delete) : ?>
                                            <span class="gt-action gt-delete-action"
                                                title="<?php esc_attr_e('Delete', 'tc-data-tables'); ?>"
                                                data-entry-id="<?php echo esc_attr($entry['entry_id']); ?>">🗑</span>
                                        <?php endif; ?>
                                        <?php
                                        // #618 slice 2 — render developer-registered per-row actions
                                        // alongside the built-in view/edit/delete affordances.
                                        // TC_Per_Row_Action_Service returns '' when no actions are
                                        // registered or none are visible to the current user, so this
                                        // is safe to invoke unconditionally.
                                        if (class_exists('TC_Per_Row_Action_Service')) {
                                            echo TC_Per_Row_Action_Service::render_buttons_html(
                                                isset($table_data->id) ? (int) $table_data->id : 0,
                                                (int) $entry['entry_id'],
                                                $entry
                                            );
                                        }
                                        ?>
                                    </div>
                                </td>
                        </tr>
                    <?php
                    // #518 slice 3: advance per-row index AFTER each row finishes,
                    // so the next iteration looks up the correct directive index.
                    $gt_auto_merge_row_idx++;
                    endforeach;
                    ?>

                    <?php if (empty($preview_entries)): ?>
                        <tr>
                            <td
                                colspan="<?php echo count($column_config) + ($show_selection ? 1 : 0) + ($show_bulk_actions ? 1 : 0); ?>">
                                <div class="gt-no-entries">
                                    <?php esc_html_e('No entries found with current filter settings.', 'tc-data-tables'); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php else:
                    // #1713 — first paint renders a layout-stable shimmer
                    // skeleton (mirroring the real column count) instead of a
                    // single full-width "Loading…" colspan row that collapsed
                    // the columns. The JS (showLoadingSkeleton) re-paints the
                    // same skeleton on the post-init AJAX, so the transition is
                    // seamless.
                    $gt_skel_cols = count($column_config) + ($show_selection ? 1 : 0) + ($show_bulk_actions ? 1 : 0);
                    $gt_skel_rows = max(3, min(12, (int) ($table_config['per_page'] ?? 10)));
                    for ($gt_sr = 0; $gt_sr < $gt_skel_rows; $gt_sr++): ?>
                    <tr class="gt-skeleton-row" aria-hidden="true">
                        <?php for ($gt_sc = 0; $gt_sc < $gt_skel_cols; $gt_sc++): ?>
                        <td class="gt-skeleton-cell"><span class="gt-skeleton-bar"></span></td>
                        <?php endfor; ?>
                    </tr>
                    <?php endfor; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($table_config['show_column_totals'])): ?>
                <tfoot class="gt-column-totals">
                    <!-- Populated by JavaScript -->
                </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- Mobile responsive cards container -->
    <div class="gt-cards-container">
        <div class="gt-cards-loading">
            <?php esc_html_e('Loading cards...', 'tc-data-tables'); ?>
        </div>
    </div>

    <?php
    // #521 slice 1: decouple the entry-count line from the pagination nav.
    // Today both lived under show_pagination so disabling pagination also
    // hid the count. Default true preserves existing behavior; site owners
    // can now keep one and drop the other independently.
    $show_pagination_info = isset($atts['show_pagination_info']) ? filter_var($atts['show_pagination_info'], FILTER_VALIDATE_BOOLEAN) : true;
    ?>
    <?php if ($show_pagination && $gt_tv_visible('pagination')): ?>
        <?php
        // TC_Pagination_Label_Service — resolve labels for the PHP-rendered
        // initial state. JS overrides gt-entry-count on first data load.
        $gt_paginate_labels = $table_config['pagination_labels'];
        $gt_initial_info_text = str_replace(
            ['{start}', '{end}', '{total}'],
            ['0', '0', '0'],
            (string) $gt_paginate_labels['info_text']
        );
        ?>
        <div class="gt-pagination-container">
            <?php if (!empty($table_config['show_length_selector']) && $gt_tv_visible('length_selector')):
                $gt_len_options = array_filter(array_map('trim', explode(',', (string) $table_config['length_selector_options'])), 'strlen');
                $gt_current_per_page = (int) ($table_config['per_page'] ?? 25);
            ?>
            <div class="gt-length-selector">
                <label>
                    <?php esc_html_e('Show', 'tc-data-tables'); ?>
                    <select class="gt-length-select" aria-label="<?php esc_attr_e('Entries per page', 'tc-data-tables'); ?>">
                        <?php foreach ($gt_len_options as $gt_len_opt):
                            $gt_len_int = (int) $gt_len_opt;
                            $gt_len_label = ($gt_len_int === -1) ? __('All', 'tc-data-tables') : (string) $gt_len_int;
                        ?>
                            <option value="<?php echo esc_attr($gt_len_int); ?>" <?php selected($gt_current_per_page, $gt_len_int); ?>><?php echo esc_html($gt_len_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php esc_html_e('entries', 'tc-data-tables'); ?>
                </label>
            </div>
            <?php endif; ?>
            <?php if ($show_pagination_info && $gt_tv_visible('info_label')): ?>
            <div class="gt-pagination-info">
                <span class="gt-entry-count"><?php echo esc_html($gt_initial_info_text); ?></span>
            </div>
            <?php endif; ?>
            <div class="gt-pagination-controls">
                <button type="button" class="gt-prev-page"
                    disabled><?php echo esc_html($gt_paginate_labels['previous_label']); ?></button>
                <span class="gt-current-page">1</span>
                <span class="gt-total-pages">of 1</span>
                <button type="button" class="gt-next-page" disabled><?php echo esc_html($gt_paginate_labels['next_label']); ?></button>
            </div>
        </div>
    <?php endif; ?>
    <?php if ( ! empty( $table_config['auto_refresh_interval'] ) && (int) $table_config['auto_refresh_interval'] > 0 ) : ?>
    <div class="gt-last-updated" aria-live="polite" aria-atomic="true"></div>
    <?php endif; ?>
</div>
</div>
<?php
// TC_Collapsible_Service — close the body wrapper opened above the
// gt-table-wrapper div, and emit the click-handler IIFE. Storage key
// matches the service's get_inline_script docblock (gt_collapse_{table_id}).
// Does NOT call DataTable.columns.adjust on expand because the plugin
// doesn't use DataTables for its own tables — that part of the service's
// get_inline_script would error against an uninitialized $.fn.DataTable.
if ($gt_collapsible_on && $gt_collapsible_table_id > 0) {
    echo '</div>'; // close gt-collapsible-body
    $gt_collapsible_default_collapsed_js = $gt_collapsible_default_collapsed ? 'true' : 'false';
    ?>
    <script>
    (function () {
        'use strict';
        var tableId = <?php echo (int) $gt_collapsible_table_id; ?>;
        var defaultCollapsed = <?php echo $gt_collapsible_default_collapsed_js; ?>;
        var storageKey = 'gt_collapse_' + tableId;
        var btn  = document.getElementById('gt-collapse-btn-'  + tableId);
        var body = document.getElementById('gt-collapsible-body-' + tableId);
        if (!btn || !body) return;
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function applyState(collapsed) {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            if (collapsed) { body.setAttribute('hidden', ''); }
            else { body.removeAttribute('hidden'); }
            var chev = btn.querySelector('.gt-chevron');
            if (chev) {
                chev.className = collapsed ? 'gt-chevron gt-chevron--down' : 'gt-chevron gt-chevron--up';
            }
            if (!reducedMotion) {
                body.classList.add('gt-collapsible-animating');
                setTimeout(function () { body.classList.remove('gt-collapsible-animating'); }, 350);
            }
        }
        // Restore localStorage state if present (overrides the default).
        try {
            var saved = localStorage.getItem(storageKey);
            if (saved === 'collapsed') { applyState(true); }
            else if (saved === 'expanded') { applyState(false); }
        } catch (e) { /* private browsing / disabled */ }
        btn.addEventListener('click', function () {
            var nextCollapsed = btn.getAttribute('aria-expanded') === 'true';
            applyState(nextCollapsed);
            try { localStorage.setItem(storageKey, nextCollapsed ? 'collapsed' : 'expanded'); } catch (e) {}
        });
    }());
    </script>
    <?php
}

// #551 — opt-in SEO row dump for paginated tables.
// Emits a <noscript> block AFTER the table wrapper containing all rows so
// search-engine crawlers (and JS-disabled / reading-mode users) see every
// row even when JS pagination would normally hide them. JS-enabled users
// never see the noscript content. Off by default — enable per-table via
// the gt_seo_emit_all_rows filter (see class-tc-seo-rows-renderer.php).
if (
    !$is_preview
    && class_exists('TC_SEO_Rows_Renderer')
    && isset($table_data->id)
    && TC_SEO_Rows_Renderer::is_enabled((int) $table_data->id)
) {
    $seo_columns = [];
    foreach ($column_config as $field_id => $cfg) {
        $seo_columns[] = [
            'id'    => (string) $field_id,
            'label' => isset($cfg['label']) ? (string) $cfg['label'] : (string) $field_id,
            'type'  => isset($cfg['type']) ? (string) $cfg['type'] : 'text',
        ];
    }
    echo TC_SEO_Rows_Renderer::render_seo_block(
        (int) ($table_data->form_id ?? 0),
        $seo_columns
    );
}
?>
<?php
// Output custom CSS AFTER the table wrapper so it wins over header-loaded theme/plugin
// stylesheets in the CSS cascade (later declaration = higher priority at equal specificity).
if ($gt_scoped_css !== '') {
    echo '<style type="text/css" data-gt-table-css="' . esc_attr($table_instance_id) . '">' . wp_strip_all_tags($gt_scoped_css) . '</style>';
}
?>

<?php if ($is_preview): ?>
    <?php if (isset($frontend_css_content) && !empty($frontend_css_content)): ?>
        <style type="text/css">
            <?php echo $frontend_css_content; ?>
        </style>
    <?php endif; ?>

    <?php if (isset($inline_js) && !empty($inline_js)): ?>
        <script type="text/javascript">
            <?php echo $inline_js; ?>
        </script>
    <?php endif; ?>
<?php elseif (isset($direct_init_needed) && $direct_init_needed):
    // Build the direct-fallback init script and queue it via wp_add_inline_script()
    // so it is nonce-tagged by WordPress and never emitted as a bare <script> block.
    $fallback_debug_val  = ($debug->is_enabled('frontend') || $debug->is_enabled('all')) ? 'true' : 'false';
    $fallback_table_id   = esc_js($table_instance_id ?? '');
    $fallback_table_cfg  = wp_json_encode($table_config);
    $fallback_js = <<<FALLBACKJS
(function () {
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    window._gtEscHtml = escHtml;
}());
jQuery(document).ready(function(\$) {
    var debugEnabled = {$fallback_debug_val};
    var debugLog = debugEnabled ? console.log : function () { };
    var escHtml = window._gtEscHtml || function(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };

    setTimeout(function () {
        try {
            var tableId = "{$fallback_table_id}";
            debugLog("Direct fallback: Attempting to initialize table:", tableId);
FALLBACKJS;
    wp_add_inline_script($gt_inline_handle, $fallback_js . <<<FALLBACKJS2

            if (!tableId || tableId === '' || tableId === 'undefined' || tableId === 'null') {
                console.error("Direct fallback: Invalid table ID:", tableId);
                return;
            }
            if (!document || typeof document.querySelector !== 'function') {
                console.error("Direct fallback: Document or querySelector not available");
                return;
            }
            var \$table = \$("#" + tableId);
            if (!\$table.length) {
                // Same condition as the GT Fallback path: shortcode processed
                // but div was suppressed (page-restriction plugin, etc).
                // Quiet on the console, still log via the debug channel.
                debugLog("Direct fallback: Table element not found:", tableId);
                return;
            }
            debugLog("Direct fallback: Table element found:", \$table.length);
            // #1027 — also fall through when GravityTable is a stub (defined as
            // a constructor but missing the prototype methods because the extension
            // modules didn't load). Otherwise the "initializing normally" branch
            // throws (intermediate value).init is not a function.
            if (typeof GravityTable === "undefined" || typeof GravityTable.prototype.init !== "function") {
                debugLog("GravityTable not found OR prototype.init missing, loading via AJAX directly");
                var config = {$fallback_table_cfg};
                if (!config) { console.error("Direct fallback: No table config available"); return; }
                var \$tbody = \$table.find("tbody");
                if (!\$tbody.length) { console.error("Direct fallback: Table tbody not found"); return; }
                var currentPage = 1;
                function loadDirectData(page) {
                    page = page || 1;
                    \$.post(config.ajax_url, {
                        action: 'gt_get_entries', nonce: config.nonce,
                        form_id: config.form_id, page: page,
                        per_page: config.per_page, search: '',
                        sort_field: 'date_created', sort_order: 'desc',
                        columns: config.columns, lookup_fields: config.lookup_fields || {}
                    }, function (response) {
                        if (response.success && response.data.entries) {
                            // #518 slice 4: per-column rowspan-merge directives. AJAX response
                            // ships a map of [field_id => [row_idx => { render, rowspan }]].
                            // Skip <td> emission when render=false; emit rowspan attribute when > 1.
                            var directives = (response.data && response.data.directives) || {};
                            // #1732 — one-pass page-scoped per-column max for data bars.
                            // Mirrors computeBarMaxes on the normal GravityTable path.
                            var barMaxes = {};
                            if (config.column_data_bars) {
                                \$.each(config.column_data_bars, function (column, barCfg) {
                                    if (!barCfg || !barCfg.enabled) return true;
                                    var colCfg = config.column_config && config.column_config[column];
                                    if (!colCfg || colCfg.type !== 'number') return true;
                                    var max = null;
                                    \$.each(response.data.entries, function (i, entry) {
                                        var s = String(entry[column] !== undefined ? entry[column] : '').replace(/[^0-9.\-]/g, '');
                                        var n = (s !== '' && s !== '.' && s !== '-') ? parseFloat(s) : NaN;
                                        if (!isNaN(n) && n > 0 && (max === null || n > max)) max = n;
                                    });
                                    if (max !== null && max > 0) barMaxes[column] = max;
                                });
                            }
                            var html = '';
                            \$.each(response.data.entries, function (index, entry) {
                                html += '<tr data-entry-id="' + entry.entry_id + '">';
                                if (\$table.find('.gt-selection-header').length > 0) {
                                    html += '<td class="gt-checkbox-cell"><input type="checkbox" class="gt-entry-checkbox" value="' + entry.entry_id + '"></td>';
                                }
                                \$.each(config.columns, function (i, column) {
                                    var dir = directives[column] && directives[column][index];
                                    if (dir && dir.render === false) {
                                        return true; // skip — prior row's rowspan covers this cell
                                    }
                                    var rowspanAttr = (dir && dir.rowspan > 1) ? ' rowspan="' + parseInt(dir.rowspan, 10) + '"' : '';
                                    // #1732 — emit bar attributes on bar-enabled numeric columns.
                                    var barAttr = '';
                                    var barCfg = config.column_data_bars && config.column_data_bars[column];
                                    if (barCfg && barCfg.enabled && barMaxes[column]) {
                                        var rawVal = entry[column] !== undefined ? entry[column] : '';
                                        var vs = String(rawVal).replace(/[^0-9.\-]/g, '');
                                        var numericValue = (vs !== '' && vs !== '.' && vs !== '-') ? parseFloat(vs) : NaN;
                                        if (!isNaN(numericValue)) {
                                            var pct = Math.round((numericValue / barMaxes[column]) * 100);
                                            if (pct < 0) pct = 0;
                                            if (pct > 100) pct = 100;
                                            var barColor = barCfg.color || '#3b82f6';
                                            barAttr = ' class="gt-readonly-cell" data-gt-bar-pct="' + pct + '" style="--gt-bar-pct:' + pct + ';--gt-bar-color:' + barColor + ';"';
                                        }
                                    }
                                    html += '<td' + rowspanAttr + barAttr + '>' + escHtml(entry[column] || '') + '</td>';
                                });
                                if (\$table.find('.gt-actions-header').length > 0) {
                                    html += '<td class="gt-actions-cell"><div class="gt-actions">';
                                    html += '<span class="gt-action gt-view-action" title="View Details" data-entry-id="' + entry.entry_id + '">👁</span>';
                                    html += '<span class="gt-action gt-history-action" title="Edit history" data-entry-id="' + entry.entry_id + '">🕘</span>';
                                    if (config.enable_delete) {
                                        html += '<span class="gt-action gt-delete-action" title="Delete" data-entry-id="' + entry.entry_id + '">🗑</span>';
                                    }
                                    html += '</div></td>';
                                }
                                html += '</tr>';
                            });
                            \$tbody.html(html);
                            var start = (page - 1) * config.per_page + 1;
                            var end = Math.min(page * config.per_page, response.data.total);
                            \$table.find('.gt-entry-count').text('Showing ' + start + ' to ' + end + ' of ' + response.data.total + ' entries');
                            \$table.find('.gt-current-page').text(page);
                            \$table.find('.gt-total-pages').text('of ' + response.data.total_pages);
                            \$table.find('.gt-prev-page').prop('disabled', page <= 1);
                            \$table.find('.gt-next-page').prop('disabled', page >= response.data.total_pages);
                            currentPage = page;
                        } else {
                            var colCount = \$table.find('thead th').length;
                            \$tbody.html('<tr><td colspan="' + colCount + '">Error loading entries</td></tr>');
                        }
                    }).fail(function () {
                        var colCount = \$table.find('thead th').length;
                        \$tbody.html('<tr><td colspan="' + colCount + '">Failed to load entries</td></tr>');
                    });
                }
                \$table.find('.gt-prev-page').on('click', function () { if (currentPage > 1) loadDirectData(currentPage - 1); });
                \$table.find('.gt-next-page').on('click', function () { loadDirectData(currentPage + 1); });
                loadDirectData(1);
            } else {
                debugLog("GravityTable found, initializing normally");
                new GravityTable(tableId, {$fallback_table_cfg}).init();
            }
        } catch (error) {
            console.error("Direct fallback: JavaScript error occurred:", error);
        }
    }, 500);
});
FALLBACKJS2, 'after');
?>
    <!-- Direct CSS fallback for regular shortcode -->
    <?php if (isset($direct_css_needed) && $direct_css_needed && isset($frontend_css_content) && !empty($frontend_css_content)): ?>
        <style type="text/css">
            <?php echo $frontend_css_content; ?>
        </style>
    <?php endif; ?>
    <!-- Direct fallback init queued via wp_add_inline_script() above for CSP compliance -->
<?php endif; ?>