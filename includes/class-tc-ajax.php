<?php
/**
 * AJAX functionality for Gravity Tables
 * 
 * Handles all AJAX requests for both admin and frontend functionality.
 * Includes permission checking, data retrieval, and entry management.
 * 
 * Recent updates (v3.1.1):
 * - Enhanced permission checking with specific table_id support
 * - Fixed intermittent access denied issues caused by multiple configurations
 * - Improved error handling and debugging capabilities
 *
 * @package GravityTables
 * @author Fahad Murtaza <business@isupercoder.com>
 * @since 1.0.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
// #1073 — submit_new_entry() routes $_SERVER reads through
// gt_request_server_text(). tablecrafter.php loads this helper early in
// the bootstrap, but test-issue files that load class-tc-ajax.php
// directly (via require_once on this file) bypass the plugin bootstrap.
// Defensively require the helper here so any caller that includes the
// AJAX class also gets the dependency, with the function_exists guard
// inside helpers-request.php making the second require a no-op.
// @codeCoverageIgnoreStart
require_once __DIR__ . '/helpers-request.php';
// #1636 — CSV export neutralizes formula injection via
// TC_CSV_Formula_Detector, loaded on-demand (not in the main bootstrap
// list); ensure it is available wherever this handler is loaded.
if (!class_exists('TC_CSV_Formula_Detector')) {
    require_once __DIR__ . '/services/class-tc-csv-formula-detector.php';
}
// @codeCoverageIgnoreEnd

class TC_Ajax
{

    private static ?TC_Ajax $instance = null;
    private $logger = null;

    public static function get_instance(): TC_Ajax
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Discard any stray output that accumulated before a JSON response.
     * Prevents the "could not get a valid response from server" AJAX error (#113).
     */
    private function gt_clean_output_buffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    private function __construct()
    {
        // Start output buffering immediately so any stray output from other plugins
        // or WP_DEBUG notices can be discarded before we send JSON (#113).
        ob_start();

        // Initialize logger safely
        try {
            $this->logger = TC_Logger::get_instance();
        // @codeCoverageIgnoreStart
        } catch (Exception $e) {
            $this->logger = null;
        // @codeCoverageIgnoreEnd
        }
        // Admin AJAX actions
        add_action('wp_ajax_gt_save_table', array($this, 'save_table'));
        add_action('wp_ajax_gt_delete_table', array($this, 'delete_table'));
        // #1740 — One-Click Table Duplicate.
        add_action('wp_ajax_gt_duplicate_table', array($this, 'duplicate_table'));
        // #972 v4.161.0 — Trash tab actions (phase 1c of #593).
        add_action('wp_ajax_gt_restore_table', array($this, 'restore_table'));
        add_action('wp_ajax_gt_force_delete_table', array($this, 'force_delete_table'));
        // #974 v4.162.0 — Empty Trash bulk (phase 1c-2 of #593).
        add_action('wp_ajax_gt_empty_trash', array($this, 'empty_trash'));
        // #982 v4.166.0 — JSON data source preview (slice 3a of #512).
        add_action('wp_ajax_gt_preview_json_source', array($this, 'preview_json_source'));
        // #2015 — generic remote-source preview (CSV / XML / Google Sheets).
        add_action('wp_ajax_gt_preview_remote_source', array($this, 'preview_remote_source'));
        // #2022 — migrate deprecated shortcodes in post content (dry-run + apply).
        add_action('wp_ajax_gt_migrate_shortcodes', array($this, 'migrate_shortcodes'));
        // #2021 — run the rebrand data migration (DB table + options) on demand.
        add_action('wp_ajax_gt_run_migration', array($this, 'run_migration'));
        add_action('wp_ajax_gt_dismiss_migration_notice', array($this, 'dismiss_migration_notice'));
        // #2063 — one-click demo table creation from a bundled dataset.
        add_action('wp_ajax_gt_create_demo_table', array($this, 'create_demo_table'));
        add_action('wp_ajax_gt_get_form_fields', array($this, 'get_form_fields'));
        add_action('wp_ajax_gt_preview_table', array($this, 'preview_table'));
        add_action('wp_ajax_gt_save_row_order', array($this, 'save_row_order'));

        // Frontend AJAX actions
        add_action('wp_ajax_gt_get_entries', array($this, 'get_entries'));
        add_action('wp_ajax_nopriv_gt_get_entries', array($this, 'get_entries'));
        // server_side_entries: accepts draw/start/length; returns draw, recordsTotal, recordsFiltered, data
        add_action('wp_ajax_gt_server_side_entries', array($this, 'server_side_entries'));
        add_action('wp_ajax_nopriv_gt_server_side_entries', array($this, 'server_side_entries'));
        add_action('wp_ajax_gt_get_wc_products', array($this, 'get_wc_products'));
        add_action('wp_ajax_nopriv_gt_get_wc_products', array($this, 'get_wc_products'));
        add_action('wp_ajax_gt_get_lookup_options', array($this, 'get_lookup_options'));
        add_action('wp_ajax_nopriv_gt_get_lookup_options', array($this, 'get_lookup_options'));
        // Text-filter typeahead (4.7.57): distinct existing values for a form field, optionally search-filtered.
        add_action('wp_ajax_gt_get_filter_suggestions', array($this, 'get_filter_suggestions'));
        add_action('wp_ajax_nopriv_gt_get_filter_suggestions', array($this, 'get_filter_suggestions'));
        add_action('wp_ajax_gt_update_entry', array($this, 'update_entry'));
        add_action('wp_ajax_nopriv_gt_update_entry', array($this, 'update_entry'));
        // #2143 — legacy inline auto-refresh: re-render an inline source on a timer.
        add_action('wp_ajax_gt_inline_refresh', array($this, 'inline_refresh'));
        add_action('wp_ajax_nopriv_gt_inline_refresh', array($this, 'inline_refresh'));

        add_action('wp_ajax_gt_update_entry_fields', array($this, 'update_entry_fields'));
        add_action('wp_ajax_nopriv_gt_update_entry_fields', array($this, 'update_entry_fields'));
        add_action('wp_ajax_gt_bulk_action', array($this, 'bulk_action'));
        add_action('wp_ajax_nopriv_gt_bulk_action', array($this, 'bulk_action'));
        add_action('wp_ajax_gt_get_form_html', array($this, 'get_form_html'));
        add_action('wp_ajax_nopriv_gt_get_form_html', array($this, 'get_form_html'));
        add_action('wp_ajax_gt_submit_new_entry', array($this, 'submit_new_entry'));
        add_action('wp_ajax_nopriv_gt_submit_new_entry', array($this, 'submit_new_entry'));
        add_action('wp_ajax_gt_export_table', array($this, 'export_table'));
        add_action('wp_ajax_nopriv_gt_export_table', array($this, 'export_table'));
        add_action('wp_ajax_gt_delete_entry', array($this, 'delete_entry'));
        add_action('wp_ajax_nopriv_gt_delete_entry', array($this, 'delete_entry'));
        add_action('wp_ajax_gt_get_entry_details', array($this, 'get_entry_details'));
        add_action('wp_ajax_nopriv_gt_get_entry_details', array($this, 'get_entry_details'));

        add_action('wp_ajax_gt_get_entry_history', array($this, 'get_entry_history'));
        add_action('wp_ajax_nopriv_gt_get_entry_history', array($this, 'get_entry_history'));

        add_action('wp_ajax_gt_save_filter_preset', array($this, 'save_filter_preset'));
        add_action('wp_ajax_gt_get_filter_presets', array($this, 'get_filter_presets'));
        add_action('wp_ajax_gt_delete_filter_preset', array($this, 'delete_filter_preset'));

        // #538 slice 2 — Find/Replace AJAX endpoint (find only).
        add_action('wp_ajax_gt_find_matches', array($this, 'find_matches'));
        // #538 slice 3 — Apply replacements + write back to GF entries.
        add_action('wp_ajax_gt_apply_replace', array($this, 'apply_replace'));
        // #599 slice 3 — Cascading filter: child options for a chosen parent value.
        add_action('wp_ajax_gt_cascading_filter_options', array($this, 'cascading_filter_options'));
        add_action('wp_ajax_nopriv_gt_cascading_filter_options', array($this, 'cascading_filter_options'));

        add_action('wp_ajax_gt_upload_file', array($this, 'upload_file'));
        add_action('wp_ajax_nopriv_gt_upload_file', array($this, 'upload_file'));
        add_action('wp_ajax_gt_optimize_autoload', array($this, 'optimize_autoload'));
        add_action('wp_ajax_gt_migrate_table', array($this, 'migrate_table'));
        add_action('wp_ajax_gt_get_legacy_tables', array($this, 'get_legacy_tables'));
        add_action('wp_ajax_gt_transpose_table', array($this, 'transpose_table'));

        // #613 phase 2 (v4.197.0) — push a row update back to a JSON data source.
        // No nopriv counterpart: writes require an authenticated user.
        add_action('wp_ajax_gt_push_row', array($this, 'push_row'));

        // #1745 — bulk column fill (Pro). Authenticated only; nopriv rejected.
        add_action('wp_ajax_gt_bulk_fill_column', array($this, 'bulk_fill_column'));
        // #1747 — one-click entry duplicate (Pro). Authenticated only.
        add_action('wp_ajax_gt_duplicate_entry', array($this, 'duplicate_entry'));
    }

    private function get_filter_presets_meta_key(int $table_id): string
    {
        return 'gt_filter_presets_table_' . $table_id;
    }

    /**
     * #1605 — sanitize the optional saved-view payload on a preset.
     * Whitelist shape: column_order (<=100 sanitized strings),
     * sort_stack (<=3 {field, order asc|desc}), per_page (1..200).
     * Unknown keys are dropped; returns [] when nothing valid remains.
     */
    private function sanitize_preset_view(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        $view = array();

        if (isset($decoded['column_order']) && is_array($decoded['column_order'])) {
            $order = array();
            foreach (array_slice(array_values($decoded['column_order']), 0, 100) as $col) {
                if (is_scalar($col)) {
                    $order[] = sanitize_text_field((string) $col);
                }
            }
            if ($order !== array()) {
                $view['column_order'] = $order;
            }
        }

        if (isset($decoded['sort_stack']) && is_array($decoded['sort_stack'])) {
            $stack = array();
            foreach ($decoded['sort_stack'] as $entry) {
                if (count($stack) >= 3) {
                    break;
                }
                if (!is_array($entry) || !isset($entry['field'], $entry['order'])) {
                    continue;
                }
                $field = is_scalar($entry['field']) ? sanitize_text_field((string) $entry['field']) : '';
                $dir   = is_string($entry['order']) ? strtolower($entry['order']) : '';
                if ($field === '' || !in_array($dir, array('asc', 'desc'), true)) {
                    continue;
                }
                $stack[] = array('field' => $field, 'order' => $dir);
            }
            if ($stack !== array()) {
                $view['sort_stack'] = $stack;
            }
        }

        if (isset($decoded['per_page']) && is_numeric($decoded['per_page'])) {
            // #1691 — route through resolve_per_page so the "All" sentinel (-1)
            // maps to the bounded ceiling rather than clamping to 1.
            $view['per_page'] = self::resolve_per_page($decoded['per_page']);
        }

        return $view;
    }

    public function save_filter_preset(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('You must be logged in to save presets.', 'tc-data-tables'));
        }

        $table_id = intval($_POST['table_id'] ?? 0);
        if (!$table_id) {
            wp_send_json_error(__('Invalid table ID.', 'tc-data-tables'));
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if ($name === '') {
            wp_send_json_error(__('Preset name is required.', 'tc-data-tables'));
        }
        if (strlen($name) > 80) {
            $name = substr($name, 0, 80);
        }

        $filters_raw = isset($_POST['filters']) ? wp_unslash($_POST['filters']) : '';
        $filters = is_string($filters_raw) ? json_decode($filters_raw, true) : null;
        if (!is_array($filters)) {
            $filters = array();
        }

        // #1605 — optional full-view payload + default pin.
        $view_raw = isset($_POST['view']) ? wp_unslash($_POST['view']) : '';
        $view = is_string($view_raw) && $view_raw !== '' ? $this->sanitize_preset_view($view_raw) : array();
        $is_default = !empty($_POST['is_default']) && $_POST['is_default'] !== '0';

        $meta_key = $this->get_filter_presets_meta_key($table_id);
        $presets = get_user_meta($user_id, $meta_key, true);
        if (!is_array($presets)) {
            $presets = array();
        }

        $preset_id = isset($_POST['preset_id']) ? sanitize_text_field((string) $_POST['preset_id']) : '';
        $now = time();

        if ($preset_id) {
            $found = false;
            foreach ($presets as &$preset) {
                if (($preset['id'] ?? '') === $preset_id) {
                    $preset['name'] = $name;
                    $preset['filters'] = $filters;
                    $preset['updated_at'] = $now;
                    if ($view !== array()) {
                        $preset['view'] = $view;
                    }
                    if ($is_default) {
                        $preset['is_default'] = true;
                    }
                    $found = true;
                    break;
                }
            }
            unset($preset);
            if (!$found) {
                $preset_id = '';
            }
        }

        if (!$preset_id) {
            $preset_id = uniqid('p_', true);
            $new_preset = array(
                'id' => $preset_id,
                'name' => $name,
                'filters' => $filters,
                'created_at' => $now,
                'updated_at' => $now,
            );
            if ($view !== array()) {
                $new_preset['view'] = $view;
            }
            if ($is_default) {
                $new_preset['is_default'] = true;
            }
            $presets[] = $new_preset;
        }

        // #1605 — a newly pinned default unpins every other preset.
        if ($is_default) {
            foreach ($presets as &$preset) {
                $preset['is_default'] = (($preset['id'] ?? '') === $preset_id);
            }
            unset($preset);
        }

        update_user_meta($user_id, $meta_key, $presets);

        wp_send_json_success(array(
            'preset_id' => $preset_id,
            'presets' => array_values($presets),
        ));
    }

    public function get_filter_presets(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $user_id = get_current_user_id();
        $table_id = intval($_POST['table_id'] ?? 0);
        if (!$table_id) {
            wp_send_json_error(__('Invalid table ID.', 'tc-data-tables'));
        }

        $presets = array();
        if ($user_id) {
            $stored = get_user_meta($user_id, $this->get_filter_presets_meta_key($table_id), true);
            if (is_array($stored)) {
                $presets = array_values($stored);
            }
        }

        wp_send_json_success(array('presets' => $presets));
    }

    public function delete_filter_preset(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(__('You must be logged in.', 'tc-data-tables'));
        }

        $table_id = intval($_POST['table_id'] ?? 0);
        $preset_id = sanitize_text_field((string) ($_POST['preset_id'] ?? ''));
        if (!$table_id || $preset_id === '') {
            wp_send_json_error(__('Invalid request.', 'tc-data-tables'));
        }

        $meta_key = $this->get_filter_presets_meta_key($table_id);
        $presets = get_user_meta($user_id, $meta_key, true);
        if (!is_array($presets)) {
            $presets = array();
        }

        $filtered = array();
        foreach ($presets as $preset) {
            if (($preset['id'] ?? '') !== $preset_id) {
                $filtered[] = $preset;
            }
        }

        update_user_meta($user_id, $meta_key, $filtered);

        wp_send_json_success(array('presets' => array_values($filtered)));
    }

    /**
     * #538 slice 2 — Find AJAX endpoint.
     *
     * Wraps TC_Find_Replace_Service::find_matches as an admin-only
     * AJAX handler. Slice 3 ships the apply path (gt_apply_replace
     * + admin-side modal that calls GFAPI::update_entry).
     *
     * Request:
     *   POST table_id           (int, required)
     *   POST needle             (string, required, non-empty)
     *   POST nonce              (gravity_tables_nonce)
     *   POST case_sensitive     (truthy, optional)
     *   POST whole_cell         (truthy, optional)
     *   POST columns            (array of field-id strings, optional)
     *
     * Response:
     *   {
     *     count: <int total match count>,
     *     matches: [{ row_index, col_id, value, count }, ...],
     *     truncated: <bool — true if matches list capped at 100>
     *   }
     */
    public function find_matches(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to run find/replace on this site.', 'tc-data-tables'));
        }

        if (!class_exists('TC_Find_Replace_Service')) {
            // @codeCoverageIgnoreStart
            $svc = defined('TC_PLUGIN_PATH') ? TC_PLUGIN_PATH . 'includes/services/class-tc-find-replace-service.php' : '';
            if ($svc !== '' && file_exists($svc)) {
                require_once $svc;
            // @codeCoverageIgnoreEnd
            }
        }
        if (!class_exists('TC_Find_Replace_Service')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(__('Find/Replace service unavailable.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $table_id = intval($_POST['table_id'] ?? 0);
        $needle   = (string) ($_POST['needle'] ?? '');
        if ($table_id <= 0 || $needle === '') {
            wp_send_json_error(__('table_id and a non-empty needle are required.', 'tc-data-tables'));
        }

        $options = [
            'case_sensitive' => !empty($_POST['case_sensitive']),
            'whole_cell'     => !empty($_POST['whole_cell']),
            'columns'        => isset($_POST['columns']) && is_array($_POST['columns'])
                ? array_map('strval', $_POST['columns'])
                : [],
        ];

        // Load rows for the table. Use the entry repository when
        // available; fall back to GFAPI::get_entries on the form id.
        $rows = $this->load_rows_for_find($table_id);

        $matches = TC_Find_Replace_Service::find_matches($rows, $needle, $options);

        // Cap the response at 100 matches to keep the payload small;
        // the modal can always re-query with refined options.
        $truncated = false;
        $total = count($matches);
        if ($total > 100) {
            $matches   = array_slice($matches, 0, 100);
            $truncated = true;
        }

        wp_send_json_success([
            'count'     => $total,
            'matches'   => $matches,
            'truncated' => $truncated,
        ]);
    }

    /**
     * Helper: load the rows array for find_matches against a given
     * table_id. Returns [] if the table is missing or has no entries.
     */
    private function load_rows_for_find(int $table_id): array
    {
        if (!class_exists('TC_Admin')) return [];
        $admin = TC_Admin::get_instance();
        $table = method_exists($admin, 'get_table') ? $admin->get_table($table_id) : null;
        if (!$table || empty($table->form_id) || !class_exists('GFAPI')) {
            return [];
        }
        $entries = GFAPI::get_entries((int) $table->form_id, [], null, ['offset' => 0, 'page_size' => 1000]);
        if (is_wp_error($entries) || !is_array($entries)) {
            return [];
        }
        // GF entries are keyed by field id (numeric strings). The
        // service operates on a generic rows array; pass the entry
        // associative arrays directly. The `col_id` in matches is the
        // GF field id.
        $rows = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $row = [];
            foreach ($entry as $k => $v) {
                if (is_string($v)) $row[(string) $k] = $v;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * #538 slice 3 — Apply Find/Replace AJAX endpoint.
     *
     * Mutates GF entries via GFAPI::update_entry. POST shape mirrors
     * find_matches (table_id, needle, replacement, case_sensitive,
     * whole_cell, columns).
     */
    public function apply_replace(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to run find/replace on this site.', 'tc-data-tables'));
        }

        if (!class_exists('TC_Find_Replace_Service')) {
            // @codeCoverageIgnoreStart
            $svc = defined('TC_PLUGIN_PATH') ? TC_PLUGIN_PATH . 'includes/services/class-tc-find-replace-service.php' : '';
            if ($svc !== '' && file_exists($svc)) {
                require_once $svc;
            // @codeCoverageIgnoreEnd
            }
        }
        if (!class_exists('TC_Find_Replace_Service') || !class_exists('GFAPI')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(__('Find/Replace service or GFAPI unavailable.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $table_id    = intval($_POST['table_id'] ?? 0);
        $needle      = (string) ($_POST['needle'] ?? '');
        $replacement = (string) ($_POST['replacement'] ?? '');
        if ($table_id <= 0 || $needle === '') {
            wp_send_json_error(__('table_id and a non-empty needle are required.', 'tc-data-tables'));
        }

        $options = [
            'case_sensitive' => !empty($_POST['case_sensitive']),
            'whole_cell'     => !empty($_POST['whole_cell']),
            'columns'        => isset($_POST['columns']) && is_array($_POST['columns'])
                // @codeCoverageIgnoreStart
                ? array_map('strval', $_POST['columns'])
                // @codeCoverageIgnoreEnd
                : [],
        ];

        // Load the source entries for this table. We need to keep the
        // original entry id so we can update each entry. Iterate
        // GFAPI::get_entries directly here (not the rows-only helper).
        $admin = class_exists('TC_Admin') ? TC_Admin::get_instance() : null;
        $table = ($admin && method_exists($admin, 'get_table')) ? $admin->get_table($table_id) : null;
        if (!$table || empty($table->form_id)) {
            wp_send_json_error(__('Table not found.', 'tc-data-tables'));
        }

        $entries = GFAPI::get_entries((int) $table->form_id, [], null, ['offset' => 0, 'page_size' => 1000]);
        if (is_wp_error($entries) || !is_array($entries)) {
            wp_send_json_error(__('Could not load entries.', 'tc-data-tables'));
        }

        $replacements_count = 0;
        $entries_updated    = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['id'])) continue;
            // Apply the service's per-row replacement to a string-only
            // projection of this entry; if any field changed, write it
            // back via GFAPI::update_entry.
            $row = [];
            foreach ($entry as $k => $v) {
                if (is_string($v)) $row[(string) $k] = $v;
            }
            $result = TC_Find_Replace_Service::apply([$row], $needle, $replacement, $options);
            $count = (int) ($result['count'] ?? 0);
            if ($count === 0) continue;

            $new_row = $result['rows'][0] ?? $row;
            $changed = false;
            foreach ($new_row as $col_id => $new_value) {
                if (!isset($entry[$col_id]) || (string) $entry[$col_id] !== (string) $new_value) {
                    $entry[$col_id] = (string) $new_value;
                    $changed = true;
                }
            }
            if ($changed) {
                $update_result = GFAPI::update_entry($entry);
                if (!is_wp_error($update_result)) {
                    $entries_updated++;
                    $replacements_count += $count;
                }
            }
        }

        wp_send_json_success([
            'replacements_count' => $replacements_count,
            'entries_updated'    => $entries_updated,
        ]);
    }

    /**
     * #599 slice 3 — Cascading filter dropdown options.
     *
     * Visitor-callable AJAX endpoint (registered for both priv and
     * nopriv). Given a table_id + parent_field + parent_value +
     * child_field, returns the distinct child values that appear
     * alongside that parent in the table's data.
     *
     * No nonce check — chain values are not sensitive (they're
     * already in the rendered table). The response is bounded by the
     * table's row count and by the small distinct-values cardinality
     * the chain represents.
     */
    public function cascading_filter_options(): void
    {
        if (!class_exists('TC_Cascading_Filter_Service')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(__('Cascading filter service unavailable.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $table_id     = intval($_REQUEST['table_id'] ?? 0);
        $parent_field = sanitize_text_field((string) ($_REQUEST['parent_field'] ?? ''));
        $parent_value = (string) ($_REQUEST['parent_value'] ?? '');
        $child_field  = sanitize_text_field((string) ($_REQUEST['child_field'] ?? ''));

        if ($table_id <= 0 || $parent_field === '' || $child_field === '') {
            wp_send_json_error(__('table_id, parent_field and child_field are required.', 'tc-data-tables'));
        }
        if ($parent_field === $child_field) {
            wp_send_json_error(__('Parent and child fields must differ.', 'tc-data-tables'));
        }

        // #1634 — enforce table access before disclosing column values.
        // This nopriv endpoint previously returned distinct field values
        // for any table_id with no permission check, leaking data from
        // role-restricted tables. Resolve the table via the same TC_Admin
        // seam load_rows_for_find uses and apply its role gate. When the
        // table cannot be resolved we fall through: load_rows_for_find
        // also returns [] in that case, so nothing is disclosed.
        if (class_exists('TC_Admin')) {
            $cascade_admin = TC_Admin::get_instance();
            $cascade_table = method_exists($cascade_admin, 'get_table') ? $cascade_admin->get_table($table_id) : null;
            if ($cascade_table) {
                $cascade_settings = isset($cascade_table->settings) ? json_decode($cascade_table->settings, true) : array();
                $cascade_config = new TC_Table_Configuration(is_array($cascade_settings) ? $cascade_settings : array());
                if (!$cascade_config->canCurrentUserViewTable()) {
                    wp_send_json_error(__('You do not have permission to view this table.', 'tc-data-tables'), 403);
                }
            }
        }

        $rows = $this->load_rows_for_find($table_id);
        if (empty($rows)) {
            wp_send_json_success(['options' => []]);
        }

        $options = TC_Cascading_Filter_Service::get_valid_options(
            $rows,
            $parent_field,
            $parent_value,
            $child_field
        );

        wp_send_json_success(['options' => $options]);
    }

    private function write_audit_record(int $entry_id, int $form_id, string $field_id, ?string $old_value, ?string $new_value): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'gravity_tables_audit_log';
        $wpdb->insert(
            $table,
            array(
                'entry_id' => $entry_id,
                'form_id' => $form_id,
                'field_id' => $field_id,
                'old_value' => $old_value,
                'new_value' => $new_value,
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%d', '%s')
        );
    }

    public function get_entry_history(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $entry_id = intval($_POST['entry_id'] ?? 0);
        if (!$entry_id) {
            wp_send_json_error(__('Invalid entry ID', 'tc-data-tables'));
        }

        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry) || !$entry) {
            wp_send_json_error(__('Entry not found', 'tc-data-tables'));
        }
        $this->checkTableAccessPermission(intval($entry['form_id']));

        global $wpdb;
        $table = $wpdb->prefix . 'gravity_tables_audit_log';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, field_id, old_value, new_value, user_id, created_at FROM $table WHERE entry_id = %d ORDER BY created_at DESC, id DESC LIMIT 200",
            $entry_id
        ), ARRAY_A);

        // Resolve form field labels and user display names
        $form = GFAPI::get_form(intval($entry['form_id']));
        $field_labels = array();
        if ($form && !is_wp_error($form) && !empty($form['fields'])) {
            foreach ($form['fields'] as $field) {
                $field_labels[(string) $field->id] = (string) $field->label;
            }
        }

        $user_cache = array();
        $records = array();
        foreach ((array) $rows as $row) {
            $uid = intval($row['user_id']);
            if ($uid > 0 && !isset($user_cache[$uid])) {
                $u = get_userdata($uid);
                $user_cache[$uid] = $u ? $u->display_name : ('User #' . $uid);
            }
            $records[] = array(
                'id' => intval($row['id']),
                'field_id' => $row['field_id'],
                'field_label' => $field_labels[(string) $row['field_id']] ?? ('Field ' . $row['field_id']),
                'old_value' => $row['old_value'],
                'new_value' => $row['new_value'],
                'user_id' => $uid,
                'user_name' => $uid > 0 ? $user_cache[$uid] : __('Anonymous', 'tc-data-tables'),
                'created_at' => $row['created_at'],
                'created_at_display' => mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row['created_at']),
            );
        }

        wp_send_json_success(array(
            'entry_id' => $entry_id,
            'records' => $records,
        ));
    }

    /**
     * Fire webhook + email notifications and the gravity_tables_entry_updated
     * action hook for a single entry-change event. All three channels are
     * best-effort: failures are logged, never surfaced to the client.
     */
    private function dispatch_entry_notifications(string $event, int $entry_id, int $form_id, int $table_id, array $changes): void
    {
        if ($table_id <= 0) {
            // Custom-shortcode tables without a DB row still get the action hook
            do_action('gravity_tables_entry_updated', $entry_id, $form_id, $event, $changes);
            return;
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));
        $settings = $row ? json_decode($row->settings, true) : array();
        if (!is_array($settings)) $settings = array();

        $events = (array) ($settings['notify_events'] ?? array('updated'));
        $should_notify = in_array($event, $events, true);

        // Always fire the action hook regardless of notification settings — that's
        // its contract for custom integrations.
        do_action('gravity_tables_entry_updated', $entry_id, $form_id, $event, $changes);

        if (!$should_notify) return;

        $user = wp_get_current_user();
        $user_payload = array(
            'id' => (int) $user->ID,
            'login' => $user->user_login ?? '',
            'email' => $user->user_email ?? '',
            'display_name' => $user->display_name ?? '',
        );
        $timestamp = current_time('c');

        $payload = array(
            'event' => $event,
            'entry_id' => $entry_id,
            'form_id' => $form_id,
            'table_id' => $table_id,
            'changes' => $changes,
            'user' => $user_payload,
            'timestamp' => $timestamp,
        );

        $webhook_url = (string) ($settings['webhook_url'] ?? '');
        if ($webhook_url !== '') {
            // #1075 — SSRF gate on the per-table webhook URL. Admin-set,
            // but we don't want a misconfigured (or compromised) webhook
            // to fire requests at loopback / metadata / RFC1918 hosts.
            if (!gt_validate_outbound_url($webhook_url)) {
                $this->safe_log('warning', 'GT webhook rejected by SSRF gate', array('url' => $webhook_url));
            } else {
                $resp = wp_remote_post($webhook_url, array(
                    'timeout' => 5,
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => wp_json_encode($payload),
                    'blocking' => true,
                ));
                if (is_wp_error($resp)) {
                    $this->safe_log('warning', 'GT webhook failed', array('url' => $webhook_url, 'error' => $resp->get_error_message()));
                }
            }
        }

        $emails_raw = (string) ($settings['notify_emails'] ?? '');
        if ($emails_raw !== '') {
            $recipients = array_filter(array_map('trim', explode(',', $emails_raw)));
            if (!empty($recipients)) {
                $form = GFAPI::get_form($form_id);
                $field_labels = array();
                if ($form && !is_wp_error($form) && !empty($form['fields'])) {
                    foreach ($form['fields'] as $field) {
                        $field_labels[(string) $field->id] = (string) $field->label;
                    }
                }
                $lines = array();
                foreach ($changes as $fid => $change) {
                    $label = $field_labels[(string) $fid] ?? ('Field ' . $fid);
                    $old = $change['old'] === '' || $change['old'] === null ? '(empty)' : $change['old'];
                    $new = $change['new'] === '' || $change['new'] === null ? '(empty)' : $change['new'];
                    $lines[] = sprintf('- %s: %s -> %s', $label, $old, $new);
                }
                $subject = sprintf('[%s] Entry #%d %s', get_bloginfo('name'), $entry_id, $event);
                $body = sprintf(
                    "Entry #%d was %s by %s on %s.\n\nChanges:\n%s\n",
                    $entry_id,
                    $event,
                    $user_payload['display_name'] !== '' ? $user_payload['display_name'] : 'Anonymous',
                    $timestamp,
                    implode("\n", $lines)
                );
                wp_mail($recipients, $subject, $body);
            }
        }
    }

    /**
     * Targeted cache invalidation. Replaces the previous wp_cache_flush()
     * calls (which nuked the entire site-wide object cache, including other
     * plugins' data) with group-scoped flushes plus the Gravity-Forms-
     * specific entry cache for the affected entry.
     *
     * Cache backends without group support (e.g. the default in-memory
     * cache) silently no-op for wp_cache_flush_group, which is fine —
     * those backends don't persist between requests anyway.
     */
    private function invalidate_table_caches(?int $entry_id = null): void
    {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('gravity_tables');
            wp_cache_flush_group('gravity_forms');
            wp_cache_flush_group('gf_entries');
        }

        if (class_exists('GFCache')) {
            GFCache::flush();
        }

        if ($entry_id && function_exists('gf_clear_entry_cache')) {
            gf_clear_entry_cache($entry_id);
        }
    }

    /**
     * Safe logging wrapper
     */
    private function safe_log($level, $message, $context = array())
    {
        if ($this->logger) {
            try {
                $this->logger->$level($message, $context);
            } catch (Exception $e) {
                // Silently fail if logging fails
            }
        }
    }

    /**
     * TC_Data_Integrity_Guard wrapper. Fetches the existing table snapshot
     * (title + decoded settings.selected_fields) and runs the service's
     * assert_safe_table_save heuristic. Returns null when safe to proceed,
     * or an error envelope (code / message / details) when the payload
     * shape suggests data loss. Only fires on UPDATE — fresh-create has
     * no existing snapshot. Mirrors the docblock contract at
     * includes/services/class-tc-data-integrity-guard.php (#557).
     */
    private function gt_assert_save_integrity(array $data): ?array
    {
        if (empty($data['table_id']) || (int) $data['table_id'] <= 0) {
            return null;
        }
        if (!class_exists('TC_Data_Integrity_Guard')) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT title, settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d",
            (int) $data['table_id']
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $existing_settings = is_string($row['settings'])
            ? (json_decode($row['settings'], true) ?: array())
            : array();
        $existing = array(
            'title'   => isset($row['title']) ? (string) $row['title'] : '',
            'columns' => isset($existing_settings['selected_fields']) && is_array($existing_settings['selected_fields'])
                ? $existing_settings['selected_fields']
                : array(),
        );
        $payload = array(
            'title'   => isset($data['title']) ? (string) $data['title'] : '',
            'columns' => isset($data['selected_fields']) && is_array($data['selected_fields'])
                ? $data['selected_fields']
                : array(),
        );
        return TC_Data_Integrity_Guard::assert_safe_table_save($payload, $existing);
    }

    public function save_table(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        global $wpdb;

        // Save-limit diagnostics: 'post_too_large' (#208) / 'max_input_vars_exceeded' (#530).
        // The literal 'post_too_large' below pins the contract for tests/test-issue-208.
        $content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $post_max_size_truncation = TC_Save_Limit_Diagnostics::detect_post_max_size_truncation(
            $content_length,
            $_POST,
            ini_get('post_max_size') ?: null
        );
        if ($post_max_size_truncation !== null) {
            $post_max_size_truncation['limits'] = TC_Save_Limit_Diagnostics::current_limits();
            wp_send_json_error($post_max_size_truncation);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $max_input_vars_raw = ini_get('max_input_vars');
        $max_input_vars_int = ($max_input_vars_raw === false || $max_input_vars_raw === '') ? null : (int) $max_input_vars_raw;
        $max_input_vars_truncation = TC_Save_Limit_Diagnostics::detect_max_input_vars_truncation(
            $_POST,
            $max_input_vars_int
        );
        if ($max_input_vars_truncation !== null) {
            $max_input_vars_truncation['limits'] = TC_Save_Limit_Diagnostics::current_limits();
            wp_send_json_error($max_input_vars_truncation);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        // Check free plan limits - SERVER-SIDE VALIDATION (cannot be bypassed)
        if (gt_is_free_plan()) {
            $existing_tables = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gravity_tables WHERE status = 'active'");
            $is_new_table = empty($_POST['table_id']) || $_POST['table_id'] === '0';

            // Check table limit for new tables
            if ($is_new_table && $existing_tables >= TC_FREE_MAX_TABLES) {
                wp_send_json_error(array(
                    'message' => sprintf(__('Free plan allows maximum %d tables. Upgrade to Pro for unlimited tables.', 'tc-data-tables'), TC_FREE_MAX_TABLES),
                    'upgrade_required' => true,
                    'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
                ));
            }

            // Check column limit - validate actual column data, not just count
            $columns = isset($_POST['columns']) ? $_POST['columns'] : array();

            // Validate column limit
            if (count($columns) > TC_FREE_MAX_COLUMNS) {
                wp_send_json_error(array(
                    'message' => sprintf(__('Free plan allows maximum %d columns. Upgrade to Pro for unlimited columns.', 'tc-data-tables'), TC_FREE_MAX_COLUMNS),
                    'upgrade_required' => true,
                    'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
                ));
            }

            // Validate premium features are not being used
            // Temporarily commented out to fix method call issue
            // $this->validate_free_plan_features($_POST);
        }

        $data  = $_POST;
        $admin = TC_Admin::get_instance();

        // TC_Data_Integrity_Guard (#557) — runtime check against silent data
        // loss. Only fires on UPDATE; aborts with HTTP 409 + envelope when
        // payload shape suggests data loss (empty title overwriting populated,
        // missing columns when existing had >0).
        $gt_integrity_error = $this->gt_assert_save_integrity($data);
        if ($gt_integrity_error !== null) {
            $this->gt_clean_output_buffers();
            status_header(409);
            wp_send_json_error($gt_integrity_error);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        try {
            $result = $admin->save_table($data);
        } catch ( Throwable $e ) {
            $this->gt_clean_output_buffers();
            wp_send_json_error( array(
                'message' => __( 'Failed to save table', 'tc-data-tables' ) . ': ' . $e->getMessage(),
                'code'    => 'save_exception',
            ) );
            return;
        }

        $this->gt_clean_output_buffers();
        if ($result !== false) {
            // For new tables TC_Admin::save_table() now returns the table ID
            // directly (not rows-affected=1), so we read $result rather than
            // $wpdb->insert_id which may be corrupted by action-hook inserts
            // (e.g. gt_capture_revision_snapshot → update_option → wp_options INSERT).
            $resolved_table_id = (isset($data['table_id']) && $data['table_id'])
                ? intval($data['table_id'])
                : intval($result);

            if ($resolved_table_id <= 0) {
                wp_send_json_error(array(
                    'message' => __('Table was saved but no ID could be resolved. Please reload the editor and save again.', 'tc-data-tables'),
                    'code'    => 'no_table_id',
                ));
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }

            // #2116 — funnel: a table config was saved (the first save is a
            // creation). Guarded so the option is written once.
            if (class_exists('TC_Activation_Funnel') && !TC_Activation_Funnel::has('table_created')) {
                TC_Activation_Funnel::record('table_created');
            }

            wp_send_json_success(array(
                'message'   => __('Table saved successfully', 'tc-data-tables'),
                'table_id'  => $resolved_table_id,
                'shortcode' => '[tablecrafter id="' . $resolved_table_id . '"]',
            ));
        } else {
            wp_send_json_error(__('Failed to save table', 'tc-data-tables'));
        }
    }

    public function delete_table(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'tc-data-tables'));
        }

        $table_id = intval($_POST['table_id'] ?? 0);

        if (!$table_id) {
            wp_send_json_error(__('Invalid table ID', 'tc-data-tables'));
        }

        $admin = TC_Admin::get_instance();
        $result = $admin->delete_table($table_id);

        if ($result !== false) {
            do_action('gravity_tables_after_delete_table', $table_id);
            wp_send_json_success(__('Table deleted successfully', 'tc-data-tables'));
        } else {
            wp_send_json_error(__('Failed to delete table', 'tc-data-tables'));
        }
    }

    /**
     * #1740 — Clone an existing table configuration into a new row.
     */
    public function duplicate_table(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');

        if (!TC_Table_Duplicate_Service::is_allowed_to_duplicate(current_user_can('manage_options'))) {
            wp_send_json_error(__('Insufficient permissions', 'tc-data-tables'), 403);
        }

        $table_id = intval($_POST['table_id'] ?? 0);
        if (!$table_id) {
            wp_send_json_error(__('Invalid table ID', 'tc-data-tables'));
        }

        global $wpdb;
        $source = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}gravity_tables WHERE id = %d", $table_id)
        );

        if (!$source) {
            wp_send_json_error(__('Table not found', 'tc-data-tables'));
        }

        $copy_data = TC_Table_Duplicate_Service::prepare_copy($source);
        $copy_data['created_at'] = current_time('mysql');
        $copy_data['updated_at'] = current_time('mysql');
        $copy_data['status']     = 'active';
        $copy_data['shortcode']  = '[tablecrafter id="PLACEHOLDER"]';

        $result = $wpdb->insert(
            $wpdb->prefix . 'gravity_tables',
            $copy_data,
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to duplicate table', 'tc-data-tables'));
        }

        $new_id = $wpdb->insert_id;
        $wpdb->update(
            $wpdb->prefix . 'gravity_tables',
            ['shortcode' => '[tablecrafter id="' . $new_id . '"]'],
            ['id' => $new_id],
            ['%s'],
            ['%d']
        );

        wp_send_json_success([
            'redirect_url' => admin_url('admin.php?page=gravity-tables-new&id=' . $new_id),
        ]);
    }

    /**
     * #972 v4.161.0 — Restore a soft-deleted table (Trash tab action).
     */
    public function restore_table(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'tc-data-tables'));
        }
        $table_id = intval($_POST['table_id'] ?? 0);
        if (!$table_id) {
            wp_send_json_error(__('Invalid table ID', 'tc-data-tables'));
        }

        $result = TC_Admin::get_instance()->restore_table($table_id);

        if ($result !== false) {
            do_action('gravity_tables_after_restore_table', $table_id);
            wp_send_json_success(__('Table restored', 'tc-data-tables'));
        } else {
            wp_send_json_error(__('Failed to restore table', 'tc-data-tables'));
        }
    }

    /**
     * #982 v4.166.0 — JSON data source preview endpoint (slice 3a of #512).
     *
     * Admin builder UI (slice 3b, pending) calls this to validate a URL +
     * headers + dot_path combo before committing the table config. Returns
     * the inferred columns + first 5 flattened rows so the UI can render a
     * preview table.
     */
    public function preview_json_source(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'code'    => 'gt_insufficient_permissions',
                'message' => __('Insufficient permissions', 'tc-data-tables'),
            ));
        }

        $url       = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $headers_raw = isset($_POST['headers']) ? (string) wp_unslash($_POST['headers']) : '';
        $dot_path  = isset($_POST['dot_path']) ? sanitize_text_field(wp_unslash($_POST['dot_path'])) : '';

        if ($url === '') {
            wp_send_json_error(array(
                'code'    => 'gt_missing_url',
                'message' => __('URL is required', 'tc-data-tables'),
            ));
        }

        $headers = self::parse_header_textarea($headers_raw);
        $dot_path = $dot_path !== '' ? $dot_path : null;

        $result = TC_JSON_Source_Service::fetch_preview_rows($url, $headers, $dot_path, 5);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ));
        }

        // $result is an array of row objects. Flatten the first 5 + infer columns.
        $sample = array_slice($result, 0, 5);
        $flat = array();
        foreach ($sample as $row) {
            $flat[] = TC_JSON_Source_Service::flatten_row(is_array($row) ? $row : array());
        }
        $columns = TC_JSON_Source_Service::infer_columns($flat);

        wp_send_json_success(array(
            'columns'   => $columns,
            'rows'      => $flat,
            'row_count' => count($result),
        ));
    }

    /**
     * #2063 — Create a ready-to-view demo table from a bundled dataset in one
     * click. Builds a JSON/CSV remote source pointing at the fixture and persists
     * the table; returns its id + shortcode + builder edit URL.
     */
    public function create_demo_table(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'tc-data-tables')));
        }

        $key      = isset($_POST['demo']) ? sanitize_key(wp_unslash($_POST['demo'])) : '';
        $settings = class_exists('TC_Demo_Data') ? TC_Demo_Data::table_settings($key) : null;
        // #2134 — templates install through the same path; fall back to the
        // templates registry for keys the demo registry doesn't know.
        $is_template = false;
        if ($settings === null && class_exists('TC_Templates')) {
            $settings = TC_Templates::table_settings($key);
            $is_template = ($settings !== null);
        }
        if ($settings === null) {
            wp_send_json_error(array('message' => __('Unknown demo dataset.', 'tc-data-tables')));
        }

        // Pre-select all columns so the demo renders immediately (frontend table
        // + builder preview) instead of an empty table the user must configure.
        // Set BOTH keys: the builder load path prefers 'columns', the save path
        // writes 'selected_fields' — populate both so the demo is consistent.
        $columns = $is_template ? TC_Templates::columns_for($key) : TC_Demo_Data::columns_for($key);
        if (!empty($columns)) {
            $settings['selected_fields'] = $columns;
            $settings['columns']         = $columns;
        }

        global $wpdb;
        $table = function_exists('gt_tables_table_name') ? gt_tables_table_name() : $wpdb->prefix . 'gravity_tables';
        $now   = current_time('mysql');
        $wpdb->insert($table, array(
            'title'      => $settings['table_title'],
            'form_id'    => 0,
            'settings'   => wp_json_encode($settings),
            'shortcode'  => '',
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        if ($id <= 0) {
            wp_send_json_error(array('message' => __('Could not create the demo table.', 'tc-data-tables')));
        }

        $shortcode = '[tablecrafter id="' . $id . '"]';
        $wpdb->update($table, array('shortcode' => $shortcode), array('id' => $id));

        // #2116 — funnel: creating a demo table counts as table_created.
        if (class_exists('TC_Activation_Funnel') && !TC_Activation_Funnel::has('table_created')) {
            TC_Activation_Funnel::record('table_created');
        }

        wp_send_json_success(array(
            'table_id'  => $id,
            'shortcode' => $shortcode,
            'edit_url'  => admin_url('admin.php?page=gravity-tables-new&id=' . $id),
            'message'   => sprintf(
                /* translators: 1: table id, 2: shortcode */
                __('Demo table created (id %1$d). Open it in the builder, or paste %2$s into a page.', 'tc-data-tables'),
                $id,
                $shortcode
            ),
        ));
    }

    /**
     * #2021 — Run the rebrand data migration (DB table rename + options copy) on
     * the admin's explicit request. Never called automatically.
     */
    public function run_migration(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'tc-data-tables')));
        }

        $db  = function_exists('gt_migrate_db_table') ? gt_migrate_db_table() : array('migrated' => false, 'reason' => 'unavailable');
        $opt = function_exists('gt_migrate_options') ? gt_migrate_options() : array('copied' => 0);

        wp_send_json_success(array(
            'db'      => $db,
            'options' => $opt,
            'message' => __('Migration complete. Your data now uses the TableCrafter brand names.', 'tc-data-tables'),
        ));
    }

    /**
     * #2021 — Dismiss the migration prompt for the current admin user.
     */
    public function dismiss_migration_notice(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        $user_id = get_current_user_id();
        if (!$user_id || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'tc-data-tables')), 403);
        }
        update_user_meta($user_id, 'gt_migration_notice_dismissed', 1);
        wp_send_json_success();
    }

    /**
     * #2022 — Migrate deprecated [gravity_table] / [gravity_tables] shortcodes
     * in post content to [tablecrafter]. Pass dry_run=1 to preview the counts
     * without writing. Admin-only.
     */
    public function migrate_shortcodes(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'tc-data-tables')));
        }

        $dry_run = !empty($_POST['dry_run']);

        global $wpdb;
        // Posts whose content still references either deprecated shortcode.
        $rows = $wpdb->get_results(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
               AND (post_content LIKE '%[gravity_table %' OR post_content LIKE '%[gravity_table]%'
                    OR post_content LIKE '%[gravity_tables %' OR post_content LIKE '%[gravity_tables]%')",
            ARRAY_A
        );

        $result = TC_Shortcode_Content_Migrator::migrate_rows(is_array($rows) ? $rows : array());

        if (!$dry_run) {
            foreach ($result['updates'] as $post_id => $new_content) {
                wp_update_post(array('ID' => (int) $post_id, 'post_content' => $new_content));
            }
        }

        wp_send_json_success(array(
            'dry_run'     => $dry_run,
            'posts'       => $result['changed'],
            'occurrences' => $result['occurrences'],
            'message'     => $dry_run
                ? sprintf(__('%1$d posts contain deprecated shortcodes (%2$d occurrences). Run the migration to rewrite them to [tablecrafter].', 'tc-data-tables'), $result['changed'], $result['occurrences'])
                : sprintf(__('Migrated %1$d posts (%2$d shortcodes) to [tablecrafter].', 'tc-data-tables'), $result['changed'], $result['occurrences']),
        ));
    }

    /**
     * #2015 — Generic remote-source preview. Fetches a pasted URL through the
     * matching engine (CSV / XML / Google Sheets) and returns inferred columns +
     * a 5-row sample + the total row count, in the same shape as the JSON
     * preview so the builder JS can reuse the rendering.
     */
    public function preview_remote_source(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'code'    => 'gt_insufficient_permissions',
                'message' => __('Insufficient permissions', 'tc-data-tables'),
            ));
        }

        $type = isset($_POST['source_type']) ? sanitize_key(wp_unslash($_POST['source_type'])) : '';
        $url  = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

        if ($url === '') {
            wp_send_json_error(array('code' => 'gt_missing_url', 'message' => __('URL is required', 'tc-data-tables')));
        }

        $rows = $this->fetch_remote_preview_rows($type, $url);

        if (is_wp_error($rows)) {
            wp_send_json_error(array('code' => $rows->get_error_code(), 'message' => $rows->get_error_message()));
        }

        // Column keys = union of associative row keys (preserve first-seen order).
        $column_keys = array();
        foreach ((array) $rows as $row) {
            foreach ((array) $row as $key => $_val) {
                $key = (string) $key;
                if (!in_array($key, $column_keys, true)) {
                    $column_keys[] = $key;
                }
            }
        }
        $columns = array();
        foreach ($column_keys as $key) {
            $columns[] = array('id' => $key, 'name' => $key, 'type' => 'text');
        }

        wp_send_json_success(array(
            'columns'   => $columns,
            'rows'      => array_slice((array) $rows, 0, 5),
            'row_count' => count((array) $rows),
        ));
    }

    /**
     * Fetch preview rows (associative) for a remote source URL. A short cache
     * TTL is used so repeated preview clicks don't hammer the upstream.
     *
     * @return array<int,array<string,mixed>>|WP_Error
     */
    private function fetch_remote_preview_rows(string $type, string $url)
    {
        switch ($type) {
            case 'csv':
                return class_exists('TC_CSV_Source')
                    ? TC_CSV_Source::get_cached($url, 60)
                    : new \WP_Error('gt_engine_missing', __('CSV source engine unavailable.', 'tc-data-tables'));

            case 'xlsx':
                return class_exists('TC_XLSX_Source')
                    ? TC_XLSX_Source::get_cached($url, 60)
                    : new \WP_Error('gt_engine_missing', __('Excel source engine unavailable.', 'tc-data-tables'));

            case 'xml':
                $path = isset($_POST['row_path']) ? sanitize_text_field(wp_unslash($_POST['row_path'])) : '';
                return class_exists('TC_XML_Source')
                    ? TC_XML_Source::get_cached($url, $path, 60)
                    : new \WP_Error('gt_engine_missing', __('XML source engine unavailable.', 'tc-data-tables'));

            case 'google_sheets':
                if (!class_exists('TC_Google_Sheets')) {
                    return new \WP_Error('gt_engine_missing', __('Google Sheets engine unavailable.', 'tc-data-tables'));
                }
                $sheets = TC_Google_Sheets::get_instance();
                $csv    = $sheets->get_cached($url, 60);
                if (is_wp_error($csv)) {
                    return $csv;
                }
                $parsed  = $sheets->parse_csv_to_rows((string) $csv);
                $headers = isset($parsed['headers']) ? array_map('strval', (array) $parsed['headers']) : array();
                $out     = array();
                foreach ((isset($parsed['rows']) ? (array) $parsed['rows'] : array()) as $cells) {
                    $cells = (array) $cells;
                    $assoc = array();
                    foreach ($headers as $i => $key) {
                        $assoc[$key] = isset($cells[$i]) ? $cells[$i] : '';
                    }
                    $out[] = $assoc;
                }
                return $out;

            default:
                return new \WP_Error('gt_unsupported_source', __('Unsupported source type for preview.', 'tc-data-tables'));
        }
    }

    /**
     * Parse a textarea-style header block (one 'Key: Value' per line) into the
     * associative-array shape that fetch_from_url() expects. Whitespace-only
     * lines are skipped; malformed lines (no colon) are dropped silently —
     * the UI is responsible for surfacing malformed input on its end.
     *
     * @param string $raw Raw textarea content.
     * @return array<string,string>
     */
    private static function parse_header_textarea(string $raw): array
    {
        $headers = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            $headers[$key] = $value;
        }
        return $headers;
    }

    /**
     * #974 v4.162.0 — Empty the trash (bulk permanent-delete all trashed rows).
     */
    public function empty_trash(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'tc-data-tables'));
        }

        $count = TC_Admin::get_instance()->empty_trash();

        do_action('gravity_tables_after_empty_trash', $count);
        wp_send_json_success(array(
            'count'   => $count,
            'message' => sprintf(
                /* translators: %d is the number of permanently-deleted tables. */
                _n('%d table permanently deleted', '%d tables permanently deleted', $count, 'tc-data-tables'),
                $count
            ),
        ));
    }

    /**
     * #972 v4.161.0 — Permanently delete a trashed table (Trash tab action).
     */
    public function force_delete_table(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'tc-data-tables'));
        }
        $table_id = intval($_POST['table_id'] ?? 0);
        if (!$table_id) {
            wp_send_json_error(__('Invalid table ID', 'tc-data-tables'));
        }

        $result = TC_Admin::get_instance()->force_delete_table($table_id);

        if ($result !== false) {
            do_action('gravity_tables_after_force_delete_table', $table_id);
            wp_send_json_success(__('Table permanently deleted', 'tc-data-tables'));
        } else {
            wp_send_json_error(__('Failed to permanently delete table', 'tc-data-tables'));
        }
    }

    public function get_form_fields(): void
    {
        try {
            check_ajax_referer('gt_admin_nonce', 'nonce');

            if (!current_user_can('manage_options') && !current_user_can('gt_manage_tables')) {
                wp_send_json_error(['message' => __('Forbidden', 'tc-data-tables')], 403);
            }

            $form_id = intval($_POST['form_id'] ?? 0);

            if (!$form_id) {
                wp_send_json_error(__('Invalid form ID', 'tc-data-tables'));
            }

            if (!class_exists('GFAPI')) {
                wp_send_json_error(__('Gravity Forms not available', 'tc-data-tables'));
            }

            $form = GFAPI::get_form($form_id);
            if (!$form || is_wp_error($form)) {
                wp_send_json_error(__('Form not found', 'tc-data-tables'));
            }

            $fields = array();

            // Add Entry ID as an available field option
            $fields[] = array(
                'id' => 'entry_id',
                'label' => 'Entry ID',
                'type' => 'number',
                'adminLabel' => 'Entry ID',
                'isRequired' => false
            );

            // Add Date Created as an available field option
            $fields[] = array(
                'id' => 'date_created',
                'label' => 'Date Created',
                'type' => 'date',
                'adminLabel' => 'Date Created',
                'isRequired' => false
            );

            // Add User (Created By) as an available field option
            $fields[] = array(
                'id' => 'created_by',
                'label' => 'User',
                'type' => 'user',
                'adminLabel' => 'User',
                'isRequired' => false
            );

            // Add User IP as an available field option
            $fields[] = array(
                'id' => 'ip',
                'label' => 'User IP',
                'type' => 'text',
                'adminLabel' => 'User IP',
                'isRequired' => false
            );

            foreach ($form['fields'] as $field) {
                if (!in_array($field->type, array('html', 'section', 'page'))) {
                    $fields[] = array(
                        'id' => $field->id,
                        'label' => $field->label,
                        'type' => $field->type,
                        'adminLabel' => $field->adminLabel ?: $field->label,
                        'isRequired' => $field->isRequired
                    );
                }
            }

            wp_send_json_success($fields);
        } catch (Exception $e) {
            // error_log('Get form fields error: ' . $e->getMessage());
            wp_send_json_error(__('Error loading fields: ', 'tc-data-tables') . $e->getMessage());
        }
    }

    public function preview_table(): void
    {
        try {
            check_ajax_referer('gt_admin_nonce', 'nonce');

            if (!current_user_can('manage_options') && !current_user_can('gt_manage_tables')) {
                wp_send_json_error(['message' => __('Forbidden', 'tc-data-tables')], 403);
            }

            // Validate required data
            if (empty($_POST['settings'])) {
                wp_send_json_error(__('Missing required form_id or settings', 'tc-data-tables'));
                return;
            }

            $settings_post    = (array) $_POST['settings'];
            $data_source_type = isset($settings_post['data_source_type'])
                ? sanitize_key((string) $settings_post['data_source_type'])
                : 'gravity_forms';

            // External sources don't have a Gravity Forms form_id — route them
            // before the GF-specific validation and entry-fetch path.
            if ($data_source_type === 'json') {
                wp_send_json_success(['html' => $this->preview_json_source_html($settings_post)]);
                return;
            }
            // URL-based remote sources preview inline (same as JSON).
            if (in_array($data_source_type, ['csv', 'xlsx', 'xml', 'google_sheets'], true)) {
                wp_send_json_success(['html' => $this->preview_remote_source_html($settings_post, $data_source_type)]);
                return;
            }
            // Connection/auth-based sources still preview only after saving.
            if (in_array($data_source_type, ['airtable', 'notion', 'external_db'], true)) {
                wp_send_json_success(['html' => '<p>' . esc_html__('Save the table then view it on the frontend to preview this data source.', 'tc-data-tables') . '</p>']);
                return;
            }

            // gravity_forms source requires a form_id.
            if (empty($_POST['form_id'])) {
                wp_send_json_error(__('Missing required form_id or settings', 'tc-data-tables'));
                return;
            }

            // Generate preview using shortcode with the settings from POST data
            $shortcode = TC_Shortcode::get_instance();
            $settings = $_POST['settings'];
            $settings['form_id'] = $_POST['form_id'];

            // Pre-load actual data for preview with current filter settings
            $form_id = intval($_POST['form_id']);
            $columns = $settings['columns'] ?? array();
            $lookup_fields = $settings['lookup_fields'] ?? array();

            // Debug: Check what filter settings are being passed to preview
            // error_log("GT AJAX Preview: About to call get_gravity_forms_entries with settings:");
            // error_log("GT AJAX Preview: filter_user_entries = " . var_export($settings['filter_user_entries'] ?? 'NOT SET', true));
            // error_log("GT AJAX Preview: show_deleted_entries = " . var_export($settings['show_deleted_entries'] ?? 'NOT SET', true));

            // Get filtered entries using current preview settings
            try {
                // error_log("GT AJAX Preview: Calling get_gravity_forms_entries...");
                $preview_entries = $this->get_gravity_forms_entries(
                    $form_id,
                    1, // page
                    25, // per_page  
                    '', // search
                    '', // user_filter
                    '', // date_from
                    '', // date_to
                    'date_created', // sort_field
                    'desc', // sort_order
                    $columns,
                    $lookup_fields,
                    array(), // filters
                    $settings // Use preview settings as table_config
                );

                // error_log("GT AJAX Preview: get_gravity_forms_entries returned: " . print_r($preview_entries, true));

                // Add preview data to settings so template can use it
                // @codeCoverageIgnoreStart
                $settings['preview_data'] = $preview_entries;
                // @codeCoverageIgnoreEnd
                // error_log("GT AJAX Preview: Added preview_data to settings, entries count: " . (isset($preview_entries['entries']) ? count($preview_entries['entries']) : 'NO ENTRIES'));
            } catch (Exception $e) {
                // error_log("GT AJAX Preview: Error calling get_gravity_forms_entries: " . $e->getMessage());
                // Fall back to not using preview data
            }

            $html = $shortcode->render_table($settings);

            wp_send_json_success(array('html' => $html));
        } catch (Exception $e) {
            // error_log('Preview table error: ' . $e->getMessage());
            wp_send_json_error(__('Error generating preview: ', 'tc-data-tables') . $e->getMessage());
        }
    }

    /**
     * Render a simple HTML preview table from a live JSON URL using settings
     * posted from the admin builder. Used by preview_table() for JSON sources
     * so that the admin preview works without a saved table_id.
     *
     * @param array<string,mixed> $settings Settings from $_POST['settings'].
     * @return string HTML fragment.
     */
    private function preview_json_source_html(array $settings): string
    {
        $url      = isset($settings['json_url']) ? esc_url_raw(wp_unslash((string) $settings['json_url'])) : '';
        $dot_path = isset($settings['json_dot_path']) ? sanitize_text_field((string) $settings['json_dot_path']) : '';
        $selected = isset($settings['selected_fields']) && is_array($settings['selected_fields'])
            ? array_map('strval', $settings['selected_fields'])
            : [];

        if ($url === '') {
            return '<p>' . esc_html__('Enter a JSON URL and click Test Connection to load columns.', 'tc-data-tables') . '</p>';
        }

        // Use streaming preview (Range header + early exit) to avoid 15 s timeout on large files.
        $rows = TC_JSON_Source_Service::fetch_preview_rows($url, [], $dot_path !== '' ? $dot_path : null, 5);
        if (is_wp_error($rows)) {
            return '<p class="gt-error">' . esc_html__('JSON source error:', 'tc-data-tables') . ' ' . esc_html($rows->get_error_message()) . '</p>';
        }

        $flat = [];
        foreach ($rows as $row) {
            $flat[] = TC_JSON_Source_Service::flatten_row(is_array($row) ? $row : []);
        }

        $column_keys = array_column(TC_JSON_Source_Service::infer_columns($flat), 'id');

        if (!empty($selected)) {
            $ordered = array_values(array_filter($selected, fn($k) => in_array($k, $column_keys, true)));
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }

        if (empty($column_keys)) {
            return '<p>' . esc_html__('No columns detected in JSON response.', 'tc-data-tables') . '</p>';
        }

        $preview_rows = array_slice($flat, 0, 25);
        $html = '<table class="widefat striped gt-preview-table"><thead><tr>';
        foreach ($column_keys as $key) {
            $html .= '<th>' . esc_html($key) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($preview_rows as $row) {
            $html .= '<tr>';
            foreach ($column_keys as $key) {
                $val = $row[$key] ?? '';
                if (is_array($val)) {
                    $val = wp_json_encode($val);
                }
                $html .= '<td>' . esc_html((string) $val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<p class="gt-preview-meta description">' . sprintf(
            /* translators: 1: visible rows, 2: total rows */
            esc_html__('Showing %1$d of %2$d rows from JSON source (admin preview).', 'tc-data-tables'),
            count($preview_rows),
            count($flat)
        ) . '</p>';

        return $html;
    }

    /**
     * Inline builder preview for the URL-based remote sources (CSV / XML /
     * Google Sheets), mirroring preview_json_source_html. Reuses the shared
     * fetch_remote_preview_rows() fetcher + the same table render so these
     * sources preview in the builder instead of "save then view on frontend".
     */
    private function preview_remote_source_html(array $settings, string $type): string
    {
        $url_fields = array(
            'csv'           => 'csv_url',
            'xlsx'          => 'xlsx_url',
            'xml'           => 'xml_url',
            'google_sheets' => 'google_sheets_url',
        );
        if (!isset($url_fields[$type])) {
            return '<p>' . esc_html__('Save the table then view it on the frontend to preview this data source.', 'tc-data-tables') . '</p>';
        }

        $field = $url_fields[$type];
        $url   = isset($settings[$field]) ? esc_url_raw(wp_unslash((string) $settings[$field])) : '';
        if ($url === '') {
            return '<p>' . esc_html__('Enter a URL and click Preview to load columns.', 'tc-data-tables') . '</p>';
        }

        $rows = $this->fetch_remote_preview_rows($type, $url);
        if (is_wp_error($rows)) {
            return '<p class="gt-error">' . esc_html__('Source error:', 'tc-data-tables') . ' ' . esc_html($rows->get_error_message()) . '</p>';
        }
        $rows = (array) $rows;
        if (empty($rows)) {
            return '<p>' . esc_html__('No rows returned by the source.', 'tc-data-tables') . '</p>';
        }

        // Column keys = union of associative row keys (first-seen order), then
        // narrow + order by the saved selection if present.
        $column_keys = array();
        foreach ($rows as $row) {
            foreach ((array) $row as $key => $_val) {
                $key = (string) $key;
                if (!in_array($key, $column_keys, true)) {
                    $column_keys[] = $key;
                }
            }
        }
        $selected = isset($settings['selected_fields']) && is_array($settings['selected_fields'])
            ? array_map('strval', $settings['selected_fields'])
            : array();
        if (empty($selected) && isset($settings['columns']) && is_array($settings['columns'])) {
            $selected = array_map('strval', $settings['columns']);
        }
        if (!empty($selected)) {
            $ordered = array_values(array_filter($selected, function ($k) use ($column_keys) {
                return in_array($k, $column_keys, true);
            }));
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }
        if (empty($column_keys)) {
            return '<p>' . esc_html__('No columns detected.', 'tc-data-tables') . '</p>';
        }

        $preview_rows = array_slice($rows, 0, 25);
        $html = '<table class="widefat striped gt-preview-table"><thead><tr>';
        foreach ($column_keys as $key) {
            $html .= '<th>' . esc_html($key) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($preview_rows as $row) {
            $html .= '<tr>';
            foreach ($column_keys as $key) {
                $val = (is_array($row) && isset($row[$key])) ? $row[$key] : '';
                if (is_array($val)) {
                    $val = wp_json_encode($val);
                }
                $html .= '<td>' . esc_html((string) $val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<p class="gt-preview-meta description">' . sprintf(
            /* translators: 1: visible rows, 2: total rows */
            esc_html__('Showing %1$d of %2$d rows.', 'tc-data-tables'),
            count($preview_rows),
            count($rows)
        ) . '</p>';

        return $html;
    }

    public function get_lookup_options(): void
    {
        $debug = TC_Debug::get_instance();
        $debug->log('lookup', 'get_lookup_options called');
        $debug->log('lookup', 'POST data received', $_POST);

        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $field_id = sanitize_text_field($_POST['field_id'] ?? '');
        $lookup_config = $_POST['lookup_config'] ?? array();
        $form_id = intval($_POST['form_id'] ?? 0);

        $debug->log('lookup', 'Processing parameters', array(
            'field_id' => $field_id,
            'lookup_config' => $lookup_config,
            'form_id' => $form_id
        ));

        if (empty($field_id) || empty($lookup_config)) {
            $debug->log('lookup', 'Missing required parameters', array(
                'field_id' => $field_id,
                'lookup_config' => $lookup_config
            ));
            wp_send_json_error(__('Missing required parameters - field_id: ', 'tc-data-tables') . $field_id . ', lookup_config: ' . print_r($lookup_config, true));
            return;
        }

        // #1069 slice 32 — gate lookup-option enumeration on the table's
        // role allowlist. Pre-fix this handler only checked the nonce, so
        // any public-page visitor with a valid nonce could enumerate
        // lookup options for arbitrary form_id values (info disclosure on
        // any related-table contents the lookup field references).
        // Bails inside checkTableAccessPermission via wp_send_json_error.
        if ($form_id > 0) {
            $this->checkTableAccessPermission($form_id);
        }

        $lookup_processor = TC_Lookup::get_instance();
        $options = $lookup_processor->get_lookup_options($lookup_config, $form_id);

        $debug->log('lookup', 'Lookup options result', $options);

        wp_send_json_success($options);
    }

    /**
     * Return distinct existing values for a (form_id, field_id) pair, optionally filtered by a
     * search fragment. Powers the text-filter typeahead — lets admins discover the values that
     * already exist in their data and pick one without typing it character-perfect, which doubles
     * as a manual cleanup workflow for messy free-text columns (find typos / variants → filter
     * → inline-edit to canonical).
     *
     * Request: { action, nonce, form_id, field_id, q (optional), limit (optional, default 50) }
     * Response: { success: true, data: { results: [{value: string, label: string}, ...] } }
     */
    public function get_filter_suggestions(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $form_id  = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        $field_id = isset($_POST['field_id']) ? sanitize_text_field(wp_unslash($_POST['field_id'])) : '';
        $search   = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $limit    = isset($_POST['limit']) ? absint($_POST['limit']) : 50;

        if ($form_id <= 0 || $field_id === '') {
            wp_send_json_error(array('message' => __('Missing form_id or field_id.', 'tc-data-tables')));
            return;
        }

        // #1069 slice 32 — gate the typeahead on the table's role allowlist.
        // Pre-fix this handler only checked the nonce, so any public-page
        // visitor with a valid nonce could enumerate distinct field values
        // for any GF form (including admin-only forms). Bails inside
        // checkTableAccessPermission via wp_send_json_error.
        $this->checkTableAccessPermission($form_id);

        // Reuse the same repository the rest of GT uses; no privilege escalation beyond what
        // the user already has for viewing the table.
        if (!class_exists('TC_Entry_Repository')) {
            // @codeCoverageIgnoreStart
            require_once TC_PLUGIN_PATH . 'includes/repositories/class-tc-entry-repository.php';
            // @codeCoverageIgnoreEnd
        }
        $repo = new TC_Entry_Repository();
        $values = $repo->getUniqueValuesMatching($form_id, $field_id, $search, $limit);

        $results = array();
        foreach ($values as $v) {
            $v = (string) $v;
            $results[] = array('value' => $v, 'label' => $v);
        }

        wp_send_json_success(array('results' => $results));
    }

    public function get_entries(): void
    {
        // Capture any stray WP_DEBUG notices / plugin output that has accumulated
        // before this AJAX handler runs so they cannot corrupt the JSON response (#322).
        ob_start();
        try {
            $this->safe_log('debug', 'get_entries method called');
            $debug = TC_Debug::get_instance();
            $debug->log('lookup', 'get_entries called at ' . current_time('mysql'));
            // error_log('GT AJAX: get_entries called with POST data: ' . print_r($_POST, true));

            check_ajax_referer('gravity_tables_nonce', 'nonce');

            $form_id = intval($_POST['form_id'] ?? 1);
            $table_id = intval($_POST['table_id'] ?? 0);

            // error_log('GT AJAX: Processing request for form_id: ' . $form_id . ', table_id: ' . $table_id);

            // Check table access permissions
            $debug = TC_Debug::get_instance();
            $debug->log('permissions', "Checking table access for form_id: $form_id, table_id: $table_id", array(
                'user_id' => get_current_user_id(),
                'user_roles' => wp_get_current_user()->roles,
                'form_id' => $form_id,
                'table_id' => $table_id
            ));

            try {
                $this->checkTableAccessPermission($form_id, $table_id);
                $debug->log('permissions', "Table access permission check PASSED for form_id: $form_id, table_id: $table_id");
            } catch (Exception $e) {
                $debug->log('permissions', "Table access permission check FAILED: " . $e->getMessage(), array(
                    'form_id' => $form_id,
                    'table_id' => $table_id,
                    'user_id' => get_current_user_id(),
                    'exception' => $e->getMessage()
                ));
                // The checkTableAccessPermission method already sends the JSON error response
                return;
            }

            // #2008 — graceful degrade when Gravity Forms is inactive. This
            // handler fetches Gravity Forms entries (direct gf_entry SQL +
            // GFAPI); without GF those would query a non-existent table. Return
            // a clean JSON error instead of fataling. External data-source
            // tables (JSON/Airtable/Notion) render server-side and never reach
            // this handler.
            if (!class_exists('GFAPI')) {
                $this->gt_clean_output_buffers();
                wp_send_json_error(__('Gravity Forms is required for this table.', 'tc-data-tables'));
                return;
            }

            $raw_query_args = apply_filters('gravity_tables_query_args', array(
                'page'        => intval($_POST['page'] ?? 1),
                'per_page'    => intval($_POST['per_page'] ?? 25),
                'search'      => sanitize_text_field($_POST['search'] ?? ''),
                'user_filter' => sanitize_text_field($_POST['user_filter'] ?? ''),
                'date_from'   => sanitize_text_field($_POST['date_from'] ?? ''),
                'date_to'     => sanitize_text_field($_POST['date_to'] ?? ''),
                'sort_field'  => sanitize_text_field($_POST['sort_field'] ?? 'date_created'),
                'sort_order'  => sanitize_text_field($_POST['sort_order'] ?? 'desc'),
            ), $table_id, array());

            // #565 slice 1 — multi-column sort stack from frontend shift-click.
            // Validate via TC_Multi_Sort_Service so column_id whitelist + direction
            // whitelist are enforced server-side. Slice 1 honors only the primary
            // entry (already represented by sort_field/sort_order above); slice 2
            // wires the full stack into the SQL ORDER BY block.
            $gt_sort_stack_raw = $_POST['sort_stack'] ?? '';
            $gt_sort_stack = array();
            if (is_string($gt_sort_stack_raw) && $gt_sort_stack_raw !== '' && class_exists('TC_Multi_Sort_Service')) {
                // #1073 — wp_unslash() tracks wp_magic_quotes state where
                // the legacy stripslashes idiom does not; required when
                // decoding JSON payloads off the AJAX boundary.
                // @codeCoverageIgnoreStart
                $decoded = json_decode(wp_unslash($gt_sort_stack_raw), true);
                if (is_array($decoded)) {
                    $gt_sort_stack = TC_Multi_Sort_Service::validate_sort_stack($decoded);
                // @codeCoverageIgnoreEnd
                }
            }

            $page        = max(1, intval($raw_query_args['page']));
            // #1073 — clamp per_page to [1, 200] to prevent a hostile client
            // from requesting per_page=999999 and starving the DB. Mirrors
            // the TC_Entry_Repository::find() and REST per_page idiom.
            // #1691 — resolve_per_page also maps the length selector's "All"
            // sentinel (-1) to a bounded ceiling instead of clamping it to 1.
            $per_page    = self::resolve_per_page($raw_query_args['per_page']);
            $search      = sanitize_text_field($raw_query_args['search']);
            $user_filter = sanitize_text_field($raw_query_args['user_filter']);
            $date_from   = sanitize_text_field($raw_query_args['date_from']);
            $date_to     = sanitize_text_field($raw_query_args['date_to']);
            $sort_field  = sanitize_text_field($raw_query_args['sort_field']);
            $sort_order  = sanitize_text_field($raw_query_args['sort_order']);
            $columns      = $_POST['columns'] ?? array();
            $lookup_fields = $_POST['lookup_fields'] ?? array();

            // #568 slice 4 — drilldown filters (col:val chips) for cross-page persistence.
            $drilldown_filters_raw = $_POST['drilldown_filters'] ?? '';
            $drilldown_filters = array();
            if (is_string($drilldown_filters_raw) && $drilldown_filters_raw !== '') {
                // #1073 — wp_unslash() over the legacy stripslash idiom
                // for the same wp_magic_quotes-tracking reason as the
                // sort_stack decode above.
                // @codeCoverageIgnoreStart
                $decoded_df = json_decode(wp_unslash($drilldown_filters_raw), true);
                if (is_array($decoded_df)) {
                    $drilldown_filters = $decoded_df;
                // @codeCoverageIgnoreEnd
                }
            }

            // Collect filter parameters from POST data (frontend user-applied filters)
            $debug->log('lookup', 'Starting filter collection');
            $debug->log('lookup', 'Raw POST data keys', array_keys($_POST));

            $user_filters = array();
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'filter_') === 0) {
                    // @codeCoverageIgnoreStart
                    $debug->log('lookup', "Found filter: $key", $value);
                    $field_id = str_replace('filter_', '', $key);
                    // @codeCoverageIgnoreEnd

                    // The frontend now sends structured filters for lookup fields
                    // Just pass them through as-is
                    // @codeCoverageIgnoreStart
                    $user_filters[$field_id] = $value;
                    $debug->log('lookup', "Added filter for field $field_id", $value);
                    // @codeCoverageIgnoreEnd
                }
            }

            // Load table configuration to get filter configurations for ALL fields (including hidden ones)
            global $wpdb;

            // Initialize table_config variable
            $table_config = null;

            // Check if this is a preview request with preview settings in POST data
            $is_preview = isset($_POST['is_preview']) && $_POST['is_preview'];
            if ($is_preview && isset($_POST['preview_settings'])) {
                // Use preview settings instead of database settings
                // @codeCoverageIgnoreStart
                $table_config = $_POST['preview_settings'];
                // @codeCoverageIgnoreEnd
                // error_log('GT AJAX: Using POST preview settings for filtering: ' . print_r($table_config, true));
                // error_log('GT AJAX: POST Preview filter settings - show_deleted_entries: ' . var_export($table_config['show_deleted_entries'] ?? 'NOT SET', true));
                // error_log('GT AJAX: POST Preview filter settings - filter_user_entries: ' . var_export($table_config['filter_user_entries'] ?? 'NOT SET', true));
            } else {
                // Load from database as usual - use table_id if provided, otherwise fall back to form_id
                if ($table_id > 0) {
                    $table_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active' LIMIT 1",
                        $table_id
                    ));
                    // error_log("GT AJAX: Loading table configuration by table_id: $table_id");
                } else {
                    $table_data = $wpdb->get_row($wpdb->prepare(
                        "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE form_id = %d AND status = 'active' LIMIT 1",
                        $form_id
                    ));
                    // error_log("GT AJAX: Loading table configuration by form_id: $form_id (no table_id provided)");
                }
                if ($table_data) {
                    $table_config = json_decode($table_data->settings, true);
                    // Convert string values to proper booleans for database settings
                    if (isset($table_config['filter_user_entries'])) {
                        $table_config['filter_user_entries'] = filter_var($table_config['filter_user_entries'], FILTER_VALIDATE_BOOLEAN);
                    }
                    if (isset($table_config['show_deleted_entries'])) {
                        $table_config['show_deleted_entries'] = filter_var($table_config['show_deleted_entries'], FILTER_VALIDATE_BOOLEAN);
                    }
                    // error_log('GT AJAX: Using database settings for filtering (converted to booleans): ' . print_r($table_config, true));
                } else {
                    $table_config = null;
                }
            }

            // #565 slice 2 — inject the validated sort_stack into $table_config
            // so get_gravity_forms_entries can build a multi-clause ORDER BY.
            // Only inject when the stack has 2+ entries; single-entry stacks
            // are exactly equivalent to the legacy sort_field/sort_order pair
            // and skipping the injection keeps the legacy SQL path warm for
            // backward compat / debugging.
            if (!empty($gt_sort_stack) && count($gt_sort_stack) > 1) {
                // @codeCoverageIgnoreStart
                if (!is_array($table_config)) {
                    $table_config = array();
                // @codeCoverageIgnoreEnd
                }
                // @codeCoverageIgnoreStart
                $table_config['_sort_stack'] = $gt_sort_stack;
                // @codeCoverageIgnoreEnd
            }

            // Merge user-applied filters with backend filter configurations
            $filters = $user_filters;
            if ($table_config && isset($table_config['filter_configurations'])) {
                $filter_configurations = $table_config['filter_configurations'];

                // Apply filter configurations for ALL fields (including hidden ones)
                foreach ($filter_configurations as $field_id => $filter_config) {
                    // Only apply backend filter configurations if there's no user override
                    // AND the field has a default filter configured
                    // AND the field is marked to apply as default (backend-only filtering)
                    if (
                        !isset($user_filters[$field_id]) &&
                        isset($filter_config['default_filter']) &&
                        !empty($filter_config['apply_as_default'])
                    ) {

                        // Apply default filter from Filter tab configuration
                        $default_filter = $filter_config['default_filter'];

                        // Validate and apply the default filter
                        if (is_array($default_filter) && isset($default_filter['type'])) {
                            $filters[$field_id] = $default_filter;
                            // error_log("GT AJAX: Applied backend-only filter configuration for hidden field $field_id: " . print_r($default_filter, true));
                        }
                    }
                }
            }

            // error_log('GT AJAX: User filters: ' . print_r($user_filters, true));
            // error_log('GT AJAX: Final merged filters (including hidden field configs): ' . print_r($filters, true));

            // error_log('GT AJAX: Processing sort - field: ' . $sort_field . ', order: ' . $sort_order);
            // error_log('GT AJAX: Columns: ' . print_r($columns, true));

            // #1659: do NOT invalidate caches on the read path. Flushing the
            // Gravity Forms / Gravity Tables object-cache groups on every
            // paginated read forced cold form/meta lookups plugin-wide and
            // defeated GF's own caching. Cache invalidation now happens only
            // after writes (update/delete/bulk), where it belongs.

            $entries = $this->get_gravity_forms_entries($form_id, $page, $per_page, $search, $user_filter, $date_from, $date_to, $sort_field, $sort_order, $columns, $lookup_fields, $filters, $table_config, $drilldown_filters);

            // Apply top-N limit: sort by top_n_column then slice to top_n_count rows (#347).
            $top_n_count = (int) ($table_config['top_n_count'] ?? 0);
            if ($top_n_count > 0 && !empty($entries['entries'])) {
                // @codeCoverageIgnoreStart
                $top_n_column    = sanitize_key($table_config['top_n_column'] ?? '');
                $top_n_direction = in_array($table_config['top_n_direction'] ?? 'desc', array('asc', 'desc'), true)
                    ? $table_config['top_n_direction']
                    : 'desc';
                if ($top_n_column !== '') {
                    usort($entries['entries'], function ($a, $b) use ($top_n_column, $top_n_direction) {
                        $va = $a[$top_n_column] ?? '';
                        $vb = $b[$top_n_column] ?? '';
                        $cmp = is_numeric($va) && is_numeric($vb) ? $va - $vb : strcmp((string) $va, (string) $vb);
                        return $top_n_direction === 'asc' ? $cmp : -$cmp;
                    });
                // @codeCoverageIgnoreEnd
                }
                // @codeCoverageIgnoreStart
                $entries['entries'] = array_slice($entries['entries'], 0, $top_n_count);
                $entries['total']   = min($entries['total'] ?? count($entries['entries']), $top_n_count);
                // @codeCoverageIgnoreEnd
            }

            // #796 / #798 / #806 — compose multi-input field sub-inputs into
            // the bare $entry[$N] slot so the JS frontend renders something
            // readable instead of blank. address (#796), checkbox (#798), and
            // creditcard (#806) all store their data in $entry["{N}.{sub}"]
            // keys with the bare slot empty.
            //
            // For #806 (creditcard): in addition to composing the safe summary
            // ("Visa ending in 1234") into the bare slot, also SCRUB every
            // sub-input N.1-N.5 from the response so the JS frontend / any
            // downstream consumer never receives raw card data. PCI defence
            // in depth.
            if (!empty($entries['entries']) && class_exists('GFAPI')
                && (class_exists('TC_Address_Field_Renderer')
                    || class_exists('TC_Checkbox_Field_Renderer')
                    || class_exists('TC_Creditcard_Field_Renderer')
                    || class_exists('TC_Post_Image_Field_Renderer')
                    || class_exists('TC_Product_Field_Renderer')
                    || class_exists('TC_Name_Field_Renderer')
                    || class_exists('TC_Time_Field_Renderer')
                    || class_exists('TC_Consent_Field_Renderer'))
            ) {
                $gt_form_composite = \GFAPI::get_form($form_id);
                if (is_array($gt_form_composite) && !empty($gt_form_composite['fields'])) {
                    $gt_address_field_ids    = [];
                    $gt_checkbox_field_ids   = [];
                    $gt_creditcard_field_ids = [];
                    $gt_post_image_field_ids = [];
                    $gt_product_field_ids    = [];
                    $gt_name_field_ids       = [];
                    $gt_time_field_ids       = [];
                    $gt_consent_field_ids    = [];
                    foreach ($gt_form_composite['fields'] as $gt_f) {
                        $t = isset($gt_f->type) ? (string) $gt_f->type : '';
                        if ($t === 'address')    { $gt_address_field_ids[]    = (string) $gt_f->id; }
                        if ($t === 'checkbox')   { $gt_checkbox_field_ids[]   = (string) $gt_f->id; }
                        if ($t === 'creditcard') { $gt_creditcard_field_ids[] = (string) $gt_f->id; }
                        if ($t === 'post_image') { $gt_post_image_field_ids[] = (string) $gt_f->id; }
                        if ($t === 'product')    { $gt_product_field_ids[]    = (string) $gt_f->id; }
                        if ($t === 'name')       { $gt_name_field_ids[]       = (string) $gt_f->id; }
                        if ($t === 'time')       { $gt_time_field_ids[]       = (string) $gt_f->id; }
                        if ($t === 'consent')    { $gt_consent_field_ids[]    = (string) $gt_f->id; }
                    }
                    if ($gt_address_field_ids || $gt_checkbox_field_ids || $gt_creditcard_field_ids || $gt_post_image_field_ids || $gt_product_field_ids || $gt_name_field_ids || $gt_time_field_ids || $gt_consent_field_ids) {
                        // @codeCoverageIgnoreStart
                        foreach ($entries['entries'] as &$gt_composite_entry) {
                            if (class_exists('TC_Address_Field_Renderer')) {
                                foreach ($gt_address_field_ids as $gt_fid) {
                                    $composed = TC_Address_Field_Renderer::render_text($gt_composite_entry, $gt_fid);
                                    if ($composed !== '') {
                                        $gt_composite_entry[$gt_fid] = $composed;
                        // @codeCoverageIgnoreEnd
                                    }
                                }
                            }
                            // @codeCoverageIgnoreStart
                            if (class_exists('TC_Checkbox_Field_Renderer')) {
                                foreach ($gt_checkbox_field_ids as $gt_fid) {
                                    $composed = TC_Checkbox_Field_Renderer::render_text($gt_composite_entry, $gt_fid);
                                    if ($composed !== '') {
                                        $gt_composite_entry[$gt_fid] = $composed;
                            // @codeCoverageIgnoreEnd
                                    }
                                }
                            }
                            // #806 — PCI defence: redact creditcard sub-inputs
                            // BEFORE the response ships to the JS frontend. The
                            // safe summary ends up in the bare slot; every
                            // sub-input N.1..N.5 is scrubbed.
                            // @codeCoverageIgnoreStart
                            if (class_exists('TC_Creditcard_Field_Renderer')) {
                                foreach ($gt_creditcard_field_ids as $gt_fid) {
                                    $gt_composite_entry = TC_Creditcard_Field_Renderer::redact_for_payload($gt_composite_entry, $gt_fid);
                            // @codeCoverageIgnoreEnd
                                }
                            }
                            // #808 — post_image: pre-compose the <img> HTML into
                            // the bare slot so the cell renderer's post_image
                            // branch passes it through unchanged.
                            // @codeCoverageIgnoreStart
                            if (class_exists('TC_Post_Image_Field_Renderer')) {
                                foreach ($gt_post_image_field_ids as $gt_fid) {
                                    $composed = TC_Post_Image_Field_Renderer::render_html($gt_composite_entry, $gt_fid);
                                    if ($composed !== '') {
                                        $gt_composite_entry[$gt_fid] = $composed;
                            // @codeCoverageIgnoreEnd
                                    }
                                }
                            }
                            // #807 — product: compose "Widget × 3 @ $10.00 = $30.00"
                            // into the bare slot.
                            // @codeCoverageIgnoreStart
                            if (class_exists('TC_Product_Field_Renderer')) {
                                foreach ($gt_product_field_ids as $gt_fid) {
                                    $composed = TC_Product_Field_Renderer::render_text($gt_composite_entry, $gt_fid);
                                    if ($composed !== '') {
                                        $gt_composite_entry[$gt_fid] = $composed;
                            // @codeCoverageIgnoreEnd
                                    }
                                }
                            }
                            // #817 — name: compose "Mr. John Smith Jr." into bare slot.
                            // @codeCoverageIgnoreStart
                            if (class_exists('TC_Name_Field_Renderer')) {
                                foreach ($gt_name_field_ids as $gt_fid) {
                                    $composed = TC_Name_Field_Renderer::render_text($gt_composite_entry, $gt_fid);
                                    if ($composed !== '') {
                                        $gt_composite_entry[$gt_fid] = $composed;
                            // @codeCoverageIgnoreEnd
                                    }
                                }
                            }
                            // #818 — time: compose "9:30 am" / "21:30" into bare slot.
                            // @codeCoverageIgnoreStart
                            if (class_exists('TC_Time_Field_Renderer')) {
                                foreach ($gt_time_field_ids as $gt_fid) {
                                    $composed = TC_Time_Field_Renderer::render_text($gt_composite_entry, $gt_fid);
                                    if ($composed !== '') {
                                        $gt_composite_entry[$gt_fid] = $composed;
                            // @codeCoverageIgnoreEnd
                                    }
                                }
                            }
                            // #820 — consent: compose summary into bare slot.
                            // @codeCoverageIgnoreStart
                            if (class_exists('TC_Consent_Field_Renderer')) {
                                foreach ($gt_consent_field_ids as $gt_fid) {
                                    $composed = TC_Consent_Field_Renderer::render_text($gt_composite_entry, $gt_fid);
                                    if ($composed !== '') {
                                        $gt_composite_entry[$gt_fid] = $composed;
                            // @codeCoverageIgnoreEnd
                                    }
                                }
                            }
                        }
                        // @codeCoverageIgnoreStart
                        unset($gt_composite_entry);
                        // @codeCoverageIgnoreEnd
                    }
                }
            }

            // #562 slice 3 — pivot view render. After the multi-composite
            // handlers have populated bare slots, check the per-table
            // pivot_config. When is_enabled (mode=pivot AND group_by set
            // AND >=1 valid aggregate), replace the entries set with the
            // aggregated rows and set is_pivot=true on the response so
            // the JS frontend can render the pivot column layout.
            // No-op when pivot mode is raw or the config is incomplete.
            // #1596 — pivot_view=raw is the visitor-toggle override:
            // skip aggregation for this request so the toggle can show
            // the underlying rows (read-only display choice; rows are
            // permission-filtered upstream like any raw request).
            $pivot_view_raw = isset($_POST['pivot_view'])
                && sanitize_text_field(wp_unslash($_POST['pivot_view'])) === 'raw';
            if (!$pivot_view_raw
                && class_exists('TC_Pivot_Service')
                && isset($table_config['pivot_config'])
                && is_array($table_config['pivot_config'])
                && TC_Pivot_Service::is_enabled($table_config['pivot_config'])
                && !empty($entries['entries'])
            ) {
                // @codeCoverageIgnoreStart
                $aggregated = TC_Pivot_Service::aggregate(
                    is_array($entries['entries']) ? $entries['entries'] : [],
                    $table_config['pivot_config']
                );
                $entries['entries'] = $aggregated;
                $entries['total']   = count($aggregated);
                $entries['is_pivot'] = true;
                $entries['pivot_config'] = $table_config['pivot_config'];
                // @codeCoverageIgnoreEnd
            }

            // #1598 — computed columns: inject per-row gtc_* values so
            // the frontend renders them like any other column and CSV
            // export picks them up from the entry array for free.
            if (class_exists('TC_Formula_Service')
                && empty($entries['is_pivot'])
                && !empty($table_config['computed_columns'])
                && is_array($table_config['computed_columns'])
                && !empty($entries['entries'])
            ) {
                $entries['entries'] = TC_Formula_Service::augment_rows(
                    is_array($entries['entries']) ? $entries['entries'] : [],
                    $table_config['computed_columns']
                );
            }

            // Apply do_shortcode() to cell values for columns with render_shortcodes = true (#87)
            $field_configurations = $table_config['field_configurations'] ?? [];
            if (!empty($field_configurations) && !empty($entries['entries'])) {
                // @codeCoverageIgnoreStart
                $render_shortcodes_columns = array_keys(array_filter(
                    $field_configurations,
                    fn($fc) => !empty($fc['render_shortcodes'])
                ));
                if (!empty($render_shortcodes_columns)) {
                    foreach ($entries['entries'] as &$entry) {
                        foreach ($render_shortcodes_columns as $fid) {
                            // #1672 — only invoke do_shortcode (a global regex over
                            // the whole shortcode registry) when the cell actually
                            // contains a shortcode token.
                            if (isset($entry[$fid]) && is_string($entry[$fid]) && strpos($entry[$fid], '[') !== false) {
                                $entry[$fid] = do_shortcode($entry[$fid]);
                // @codeCoverageIgnoreEnd
                            }
                        }
                    }
                    // @codeCoverageIgnoreStart
                    unset($entry);
                    // @codeCoverageIgnoreEnd
                }
            }

            // #613 phase 2 slice 19 (v4.214.0) — inject per-row push baselines
            // into the response so the JS can populate self._pushBaselines
            // (renderEntries slice 18, v4.213.0). Completes the optimistic-
            // locking flow end-to-end. The detector returns empty string for
            // rows that have never been pushed — JS treats those as "no
            // baseline yet" and the first push of that row always succeeds.
            if (class_exists('TC_Push_Conflict_Detector') && !empty($entries['entries']) && is_array($entries['entries'])) {
                // @codeCoverageIgnoreStart
                $effective_source = '';
                // @codeCoverageIgnoreEnd
                // Resolve source via table settings (already loaded earlier in
                // get_entries scope; safest to re-fetch defensively here in case
                // the local var name shifted).
                // @codeCoverageIgnoreStart
                if (class_exists('TC_Table_Persistence_Service')
                    && method_exists('TC_Table_Persistence_Service', 'get_table')) {
                    $row = TC_Table_Persistence_Service::get_table($table_id);
                    if ($row && isset($row->settings)) {
                        $decoded = is_string($row->settings) ? json_decode($row->settings, true) : $row->settings;
                        if (is_array($decoded) && isset($decoded['data_source_type'])) {
                            $effective_source = (string) $decoded['data_source_type'];
                // @codeCoverageIgnoreEnd
                        }
                    }
                }
                // @codeCoverageIgnoreStart
                $effective_source = $effective_source !== '' ? $effective_source : 'gravityforms';
                $push_baselines = array();
                foreach ($entries['entries'] as $e) {
                    $eid = isset($e['entry_id']) ? (string) $e['entry_id']
                         : (isset($e['id']) ? (string) $e['id'] : '');
                    if ($eid === '') {
                        continue;
                // @codeCoverageIgnoreEnd
                    }
                    // @codeCoverageIgnoreStart
                    $b = TC_Push_Conflict_Detector::load_baseline($effective_source, $eid);
                    if ($b !== '') {
                        $push_baselines[$eid] = $b;
                    // @codeCoverageIgnoreEnd
                    }
                }
                // @codeCoverageIgnoreStart
                if (!empty($push_baselines)) {
                    $entries['push_baselines'] = $push_baselines;
                // @codeCoverageIgnoreEnd
                }
            }

            // #1763 — per-column role visibility, ENFORCED SERVER-SIDE. The
            // client-side display:none hide (column-role-visibility.js) was a
            // confidentiality leak: restricted values still shipped in this
            // payload. Strip the values for columns this user may not see
            // BEFORE the response leaves the server. Mirrors the #806 PCI
            // sub-input scrub. No-op on the free tier and when no map is set.
            if (class_exists('TC_Column_Visibility')
                && is_array($table_config ?? null)
                && !empty($entries['entries'])
                && is_array($entries['entries'])
            ) {
                $gt_viewer_roles = function_exists('wp_get_current_user')
                    ? (array) wp_get_current_user()->roles
                    : array();
                $gt_hidden_fields = TC_Column_Visibility::hidden_field_ids($table_config, $gt_viewer_roles);
                if (!empty($gt_hidden_fields)) {
                    $entries['entries'] = TC_Column_Visibility::strip_entry_columns($entries['entries'], $gt_hidden_fields);
                }
            }

            $this->gt_clean_output_buffers();
            wp_send_json_success($entries);

        } catch (Exception $e) {
            $this->safe_log('error', 'CRITICAL ERROR in get_entries', array(
                'message' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
                'form_id' => $form_id ?? null,
                'table_id' => $table_id ?? null
            ));
            $this->gt_clean_output_buffers();
            wp_send_json_error(__('Server error: ', 'tc-data-tables') . $e->getMessage());
        } catch (Error $e) {
            $this->safe_log('error', 'CRITICAL FATAL ERROR in get_entries', array(
                'message' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
                'form_id' => $form_id ?? null,
                'table_id' => $table_id ?? null
            ));
            $this->gt_clean_output_buffers();
            wp_send_json_error(__('Fatal server error: ', 'tc-data-tables') . $e->getMessage());
        }
    }

    /**
     * Server-side processing endpoint — DataTables SSP protocol.
     *
     * Accepts: draw, start, length, search[value], order[0][column], order[0][dir], table_id, nonce.
     * Returns: { draw, recordsTotal, recordsFiltered, data }.
     */
    public function server_side_entries(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $draw     = intval($_POST['draw'] ?? 1);
        $start    = max(0, intval($_POST['start'] ?? 0));
        $length   = max(1, intval($_POST['length'] ?? 25));
        $search   = sanitize_text_field($_POST['search']['value'] ?? ($_POST['search'] ?? ''));
        $table_id = intval($_POST['table_id'] ?? 0);

        // Map DataTables order params to internal sort params.
        $order_col = intval($_POST['order'][0]['column'] ?? 0);
        $order_dir = sanitize_text_field($_POST['order'][0]['dir'] ?? 'desc');
        if (!in_array($order_dir, array('asc', 'desc'), true)) {
            $order_dir = 'desc';
        }

        // Determine page number from start/length.
        $page = max(1, (int) floor($start / $length) + 1);

        // Delegate to the standard get_entries logic by loading table config
        // and querying through the repository layer.
        global $wpdb;
        $table_settings_row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}gravity_tables WHERE id = %d", $table_id),
            ARRAY_A
        );

        if (!$table_settings_row) {
            wp_send_json(array(
                'draw'            => $draw,
                'recordsTotal'    => 0,
                'recordsFiltered' => 0,
                'data'            => array(),
            ));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $table_settings = maybe_unserialize($table_settings_row['settings'] ?? '');
        if (!is_array($table_settings)) {
            // @codeCoverageIgnoreStart
            $table_settings = array();
            // @codeCoverageIgnoreEnd
        }

        $form_id    = intval($table_settings_row['form_id'] ?? ($table_settings['form_id'] ?? 0));
        $sort_field = 'date_created';

        // Map column index to field ID if columns config is available.
        $columns = isset($table_settings['columns']) ? $table_settings['columns'] : array();
        if (!empty($columns) && isset($columns[$order_col])) {
            $col = $columns[$order_col];
            $sort_field = is_array($col) ? ($col['field_id'] ?? 'date_created') : $col;
        }

        // Build GFAPI query.
        $paging = array('offset' => $start, 'page_size' => $length);
        $sorting = array('key' => $sort_field, 'direction' => strtoupper($order_dir), 'is_numeric' => false);
        $search_criteria = array('status' => 'active');
        if ($search !== '') {
            $search_criteria['field_filters'] = array(
                array('value' => $search),
            );
        }

        $entries_total = array();
        $entries_filtered = array();
        $records_total = 0;
        $records_filtered = 0;

        if (function_exists('GFAPI::get_entries')) {
            // @codeCoverageIgnoreStart
            $records_total    = GFAPI::count_entries($form_id, array('status' => 'active'));
            $entries_filtered = GFAPI::get_entries($form_id, $search_criteria, $sorting, $paging, $records_filtered);
            // @codeCoverageIgnoreEnd
        } else {
            // Fallback for non-GF environments / tests.
            $entries_filtered = array();
        }

        $data = array();
        foreach ((array) $entries_filtered as $entry) {
            // @codeCoverageIgnoreStart
            $row = array();
            foreach ($columns as $col) {
                $field_id = is_array($col) ? ($col['field_id'] ?? '') : $col;
                $row[] = $this->gt_sanitize_cell_output(apply_filters('gravity_tables_column_value', $entry[$field_id] ?? '', $field_id, $entry, $table_settings));
            // @codeCoverageIgnoreEnd
            }
            // @codeCoverageIgnoreStart
            $data[] = $row;
            // @codeCoverageIgnoreEnd
        }

        // #1735 — Compute per-column bar maxes for SSP data bars. We use
        // TC_Data_Bars_Service::parse_numeric over all entries on the CURRENT
        // PAGE (entries_filtered) as the max for rendering bar widths. This is
        // page-scoped rather than full-dataset-scoped, matching the approach
        // used by the client-side (non-SSP) path. A full-dataset max would
        // require an additional GFAPI::get_entries call without paging, which
        // is expensive; page-scope bars are consistent within a page view.
        $bar_maxes = array();
        $bar_config = is_array($table_settings['column_data_bars'] ?? null)
            ? $table_settings['column_data_bars']
            : array();
        $col_config = is_array($table_settings['column_config'] ?? null)
            ? $table_settings['column_config']
            : array();
        if (
            !empty($bar_config)
            && !empty($entries_filtered)
            && class_exists('TC_Data_Bars_Service')
        ) {
            $numeric_types = array('number', 'quantity', 'total', 'calculation');
            foreach ($bar_config as $bar_field_id => $bar_cfg) {
                $bar_field_id = (string) $bar_field_id;
                if (!is_array($bar_cfg) || empty($bar_cfg['enabled'])) {
                    continue;
                }
                $col_type = isset($col_config[$bar_field_id]['type'])
                    ? (string) $col_config[$bar_field_id]['type']
                    : '';
                if (!in_array($col_type, $numeric_types, true)) {
                    continue;
                }
                $max = null;
                foreach ((array) $entries_filtered as $entry) {
                    // @codeCoverageIgnoreStart
                    $n = TC_Data_Bars_Service::parse_numeric($entry[$bar_field_id] ?? '');
                    if ($n !== null && ($max === null || $n > $max)) {
                        $max = $n;
                    }
                    // @codeCoverageIgnoreEnd
                }
                if ($max !== null && $max > 0) {
                    $bar_maxes[$bar_field_id] = $max;
                }
            }
        }

        wp_send_json(array(
            'draw'            => $draw,
            'recordsTotal'    => $records_total,
            'recordsFiltered' => is_int($records_filtered) ? $records_filtered : $records_total,
            'data'            => $data,
            'bar_maxes'       => $bar_maxes,
        ));
    }

    /**
     * Return WooCommerce product rows for a product-table.
     * Accepts: page, per_page, search, category, min_price, max_price, orderby, order.
     */
    public function get_wc_products(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        if (!class_exists('TC_WooCommerce')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(array('message' => __('WooCommerce integration not available.', 'tc-data-tables')));
            return;
            // @codeCoverageIgnoreEnd
        }

        $args = array(
            'page'      => max(1, intval($_POST['page'] ?? 1)),
            'per_page'  => max(1, intval($_POST['per_page'] ?? 25)),
            'search'    => sanitize_text_field($_POST['search'] ?? ''),
            'category'  => sanitize_text_field($_POST['category'] ?? ''),
            'min_price' => $_POST['min_price'] ?? '',
            'max_price' => $_POST['max_price'] ?? '',
            'orderby'   => sanitize_key($_POST['orderby'] ?? 'date'),
            'order'     => sanitize_text_field($_POST['order'] ?? 'DESC'),
        );

        $result = TC_WooCommerce::get_product_table_entries($args);
        wp_send_json_success($result);
    }

    /**
     * Admin-only AJAX: re-save all GT options with autoload = no.
     */
    public function optimize_autoload(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if (!class_exists('TC_Autoload_Manager')) {
            // @codeCoverageIgnoreStart
            require_once TC_PLUGIN_PATH . 'includes/class-tc-autoload-manager.php';
            // @codeCoverageIgnoreEnd
        }

        $updated  = TC_Autoload_Manager::optimize_autoload();
        $new_size = TC_Autoload_Manager::get_autoload_stat();

        wp_send_json_success(array(
            'updated'      => $updated,
            'autoload_size' => $new_size,
            'message'      => sprintf(
                /* translators: %d = number of options updated */
                _n('%d option updated.', '%d options updated.', $updated, 'tc-data-tables'),
                $updated
            ),
        ));
    }

    /**
     * Admin-only AJAX: migrate a single table from legacy to current format.
     */
    public function migrate_table(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $table_id = intval($_POST['table_id'] ?? 0);
        if ($table_id < 1) {
            wp_send_json_error(__('Invalid table ID.', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if (!class_exists('TC_Migration_Tool')) {
            // @codeCoverageIgnoreStart
            require_once TC_PLUGIN_PATH . 'includes/class-tc-migration-tool.php';
            // @codeCoverageIgnoreEnd
        }

        $result = TC_Migration_Tool::migrate_table($table_id);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Admin-only AJAX: return the list of table IDs still in legacy format.
     */
    public function get_legacy_tables(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if (!class_exists('TC_Migration_Tool')) {
            require_once TC_PLUGIN_PATH . 'includes/class-tc-migration-tool.php';
        }

        $ids = TC_Migration_Tool::get_legacy_tables();
        wp_send_json_success(array('legacy_table_ids' => $ids));
    }

    /**
     * #1633 — resolve the form_id that a table's "enable_frontend_editing"
     * grant applies to. Returns the table's form_id ONLY when the caller is
     * a logged-in user, the table is active, and frontend editing is on;
     * 0 otherwise.
     *
     * Callers MUST then confirm the target entry actually belongs to the
     * returned form_id. Previously the grant was decided from an
     * attacker-supplied table_id with no binding to the entry's form, so a
     * single editing-enabled table let an (even anonymous) visitor
     * edit/delete entries in ANY form.
     */
    private function frontend_editing_form_id(int $table_id): int
    {
        if ($table_id <= 0 || get_current_user_id() <= 0) {
            return 0;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT form_id, settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));
        if (!$row || !isset($row->settings)) {
            return 0;
        }
        $settings = json_decode($row->settings, true);
        if (!is_array($settings) || empty($settings['enable_frontend_editing'])) {
            return 0;
        }
        return isset($row->form_id) ? (int) $row->form_id : 0;
    }

    /**
     * #1648 — the GF field id a table uses to identify an entry's owner.
     * When set, non-admin frontend editors may only edit entries whose
     * owner field holds their user id (see TC_Entry_Owner_Guard). Empty
     * means no ownership restriction (legacy behaviour).
     */
    private function frontend_editing_owner_field_id(int $table_id): string
    {
        if ($table_id <= 0) {
            return '';
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));
        if (!$row || !isset($row->settings)) {
            return '';
        }
        $settings = json_decode($row->settings, true);
        if (!is_array($settings) || empty($settings['owner_field_id'])) {
            return '';
        }
        return (string) $settings['owner_field_id'];
    }

    /**
     * #1633 — true only when every entry id resolves to an entry that
     * belongs to $form_id. Used by bulk_action so a frontend-editing grant
     * on one table cannot be used to act on entries from another form.
     */
    private function all_entries_in_form(array $entry_ids, int $form_id): bool
    {
        if ($form_id <= 0 || empty($entry_ids)) {
            return false;
        }

        // #1668 — validate ownership with one IN query instead of one
        // GFAPI::get_entry() per id. get_entries() is scoped to $form_id, so a
        // returned count equal to the unique input count means every requested
        // id exists AND belongs to the form (an unknown or wrong-form id drops
        // the returned count below the input count).
        if (!class_exists('GFAPI')) {
            return false;
        }
        $ids = array_values(array_unique(array_map('intval', $entry_ids)));
        $criteria = array(
            'field_filters' => array(
                array('key' => 'id', 'operator' => 'in', 'value' => $ids),
            ),
        );
        $paging = array('offset' => 0, 'page_size' => count($ids));
        $found = GFAPI::get_entries($form_id, $criteria, null, $paging);
        if (is_wp_error($found) || !is_array($found)) {
            return false;
        }

        return count($found) === count($ids);
    }

    /**
     * #2143 — Re-render an inline external source for the auto-refresh poller.
     *
     * Receives the whitelisted inline shortcode atts (the same set the renderer
     * emitted in data-refresh-atts), reconstructs the [tablecrafter source=...]
     * shortcode, and returns its fresh HTML. Public (nopriv) because inline
     * tables live on the frontend; the only state it touches is the read-only
     * source fetch, which carries its own SSRF guard inside the shortcode.
     */
    public function inline_refresh(): void
    {
        check_ajax_referer('gt_inline_refresh', 'nonce');

        $raw = isset($_POST['atts']) ? wp_unslash($_POST['atts']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $atts = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        if (!is_array($atts)) {
            wp_send_json_error(array('message' => __('Invalid refresh payload.', 'tc-data-tables')));
        }

        // Only the source-render keys are honored; everything else is dropped.
        $allowed = array('source', 'root', 'include', 'exclude', 'per_page', 'search', 'export', 'filters');
        $parts   = array();
        foreach ($allowed as $key) {
            if (!isset($atts[$key]) || $atts[$key] === '') {
                continue;
            }
            // sanitize_text_field would mangle a URL's query string; esc_attr is
            // enough here since the value is re-parsed by the shortcode and the
            // fetch path re-applies its own validation (SSRF allow-list).
            $val = esc_attr((string) $atts[$key]);
            $parts[] = $key . '="' . $val . '"';
        }

        if (empty($parts) || strpos(implode(' ', $parts), 'source=') === false) {
            wp_send_json_error(array('message' => __('No inline source to refresh.', 'tc-data-tables')));
        }

        $html = do_shortcode('[tablecrafter ' . implode(' ', $parts) . ']');

        wp_send_json_success(array(
            'html'    => $html,
            'updated' => function_exists('current_time') ? current_time('timestamp') : time(),
        ));
    }

    public function update_entry(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        // Frontend editing is a premium feature
        if (gt_is_free_plan()) {
            wp_send_json_error(array(
                'message' => __('Frontend editing is a Pro feature. Upgrade to edit entries directly in tables.', 'tc-data-tables'),
                'upgrade_required' => true,
                'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
            ));
        }

        // #553 slice 2 — WAF-safe payload server-side wire. When the client posts
        // an opaque base64 envelope under $_POST['payload'] (slice-3 client-side
        // encode), decode it via TC_WAF_Safe_Payload and feed the result into
        // entry_id / updates. Falls through cleanly for legacy form-encoded
        // callers (the is_encoded check returns false and the legacy path runs).
        // Customers behind aggressive WAFs (Cloudflare / Sucuri / mod_security /
        // Wordfence) hit this when cell content like "UNION SELECT" or
        // "<script>" trips generic SQLi/XSS rules; the base64 envelope hides
        // those tokens from the WAF's pattern-matching.
        $entry_id = 0;
        $updates  = array();
        $encoded  = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';
        if (
            $encoded !== ''
            && class_exists('TC_WAF_Safe_Payload')
            && TC_WAF_Safe_Payload::is_encoded($encoded)
        ) {
            // @codeCoverageIgnoreStart
            $decoded = TC_WAF_Safe_Payload::decode($encoded);
            if (is_array($decoded)) {
                $entry_id = isset($decoded['entry_id']) ? (int) $decoded['entry_id'] : 0;
                $updates  = isset($decoded['updates']) && is_array($decoded['updates']) ? $decoded['updates'] : array();
            // @codeCoverageIgnoreEnd
            }
        }
        if ($entry_id === 0) {
            $entry_id = intval($_POST['entry_id'] ?? 0);
        }
        if (empty($updates)) {
            $updates = $_POST['updates'] ?? array();
        }

        if (!$entry_id || empty($updates)) {
            wp_send_json_error(__('Invalid entry data', 'tc-data-tables'));
        }

        // Check table access permission by getting entry's form_id
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry) || !$entry) {
            wp_send_json_error(__('Entry not found', 'tc-data-tables'));
        }
        $this->checkTableAccessPermission(intval($entry['form_id']));

        // Check if frontend editing is available (premium feature)
        if (!gt_is_premium()) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(__('Frontend editing is a premium feature. Please upgrade to Pro.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        // Check if user can edit gravity forms entries.
        // Priority 1: administrators / editors / authors are always allowed.
        // Priority 2: If the table itself has "Enable frontend editing" turned on,
        //             any logged-in user who can view the table is allowed.
        // Priority 3: Fall back to checking whether the user owns the entry (created_by).
        $current_user_id = get_current_user_id();
        // #1648 — exclude 'driver' cap (drivers lack edit_posts) so they can't bypass the ownership check below.
        $has_admin_cap = current_user_can('edit_posts') || current_user_can('publish_posts');

        if (!$has_admin_cap) {
            $can_edit = false;

            // #1633 — grant via the table's "enable_frontend_editing" flag
            // only when the table belongs to THIS entry's form and the
            // caller is logged in. Binding to the entry's form prevents
            // pointing table_id at an unrelated editing-enabled table to
            // edit arbitrary entries.
            $table_id_post = intval($_POST['table_id'] ?? 0);
            $editing_form_id = $this->frontend_editing_form_id($table_id_post);
            if ($editing_form_id > 0 && $editing_form_id === intval($entry['form_id'])
                && TC_Entry_Owner_Guard::entry_owner_matches($entry, $this->frontend_editing_owner_field_id($table_id_post), get_current_user_id())) {
                $can_edit = true;
            }

            // Final fallback: user owns this entry (checks GF created_by field)
            if (!$can_edit) {
                $can_edit = $this->can_user_edit_entry($entry_id);
            }

            if (!$can_edit) {
                // @codeCoverageIgnoreStart
                wp_send_json_error(__('Insufficient permissions to edit this entry', 'tc-data-tables'));
                // @codeCoverageIgnoreEnd
            }
        }

        // Initialize debugging
        $debug_enabled = isset($_POST['debug']) && $_POST['debug'] === 'true';
        $start_time = microtime(true);

        $this->safe_log('info', "Starting update_entry for entry {$entry_id}", array(
            'entry_id' => $entry_id,
            'user_id' => get_current_user_id(),
            'form_id' => $entry['form_id'],
            'updates_count' => count($updates),
            'wp_debug' => defined('WP_DEBUG') ? (WP_DEBUG ? 'true' : 'false') : 'undefined'
        ));

        global $wpdb;

        // Suppress $wpdb error display to prevent HTML from corrupting JSON response
        // (WP_DEBUG_DISPLAY = true causes $wpdb to output <div> errors into the body)
        $wpdb->suppress_errors(true);

        $success = true;
        $updated_fields = array();
        $entry_changes = array();

        $audit_form_id = intval($entry['form_id']);

        $gt_blocked_fields = array();

        foreach ($updates as $field_id => $value) {
            $sanitized_value = $this->sanitize_field_value($value, $field_id);

            // Check if field exists first
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                $entry_id,
                $field_id
            ));

            // #819 — guard against silently clobbering a multi-file
            // fileupload field (JSON array of URLs) with a single
            // scalar URL string. Inline edit only sees the input
            // value, so without this guard the JSON array would be
            // overwritten by the scalar and N-1 URLs would silently
            // disappear. Full multi-file modal is deferred; this
            // is the data-safety patch.
            if (class_exists('TC_Fileupload_Edit_Guard')
                && TC_Fileupload_Edit_Guard::would_clobber_multi_file($existing, $sanitized_value)
            ) {
                // @codeCoverageIgnoreStart
                $gt_blocked_fields[(string) $field_id] = 'multi_file_clobber_blocked';
                continue;
                // @codeCoverageIgnoreEnd
            }

            if ((string) $existing !== (string) $sanitized_value) {
                $entry_changes[(string) $field_id] = array(
                    'old' => $existing,
                    'new' => (string) $sanitized_value,
                );
                $this->write_audit_record($entry_id, $audit_form_id, (string) $field_id, $existing, (string) $sanitized_value);
            }

            // Handle empty values - delete the meta entry to keep database clean
            if (empty($sanitized_value) && $sanitized_value !== '0') {
                if ($existing !== null) {
                    // Delete existing empty field
                    if ($debug_enabled)
                        // @codeCoverageIgnoreStart
                        $this->safe_log('debug', "Attempting to delete empty field", array(
                            'field_id' => $field_id,
                            'entry_id' => $entry_id,
                            'original_value' => $value,
                            'sanitized_value' => $sanitized_value,
                            'existing_value' => $existing
                        ));
                        // @codeCoverageIgnoreEnd
                    $result = $wpdb->delete(
                        $wpdb->prefix . 'gf_entry_meta',
                        array(
                            'entry_id' => $entry_id,
                            'meta_key' => $field_id
                        ),
                        array('%d', '%s')
                    );
                    if ($debug_enabled)
                        // @codeCoverageIgnoreStart
                        $this->safe_log('debug', "Delete operation result", array(
                            'result' => $result,
                            'wpdb_last_error' => $wpdb->last_error,
                            'field_id' => $field_id,
                            'entry_id' => $entry_id
                        ));
                        // @codeCoverageIgnoreEnd
                } else {
                    // Field doesn't exist and value is empty - nothing to do
                    // @codeCoverageIgnoreStart
                    $result = true;
                    // @codeCoverageIgnoreEnd
                }
                $updated_fields[$field_id] = ''; // Show as empty in response
            }
            // Handle non-empty values
            else {
                if ($debug_enabled)
                    $this->safe_log('debug', "Updating field", array(
                        'field_id' => $field_id,
                        'entry_id' => $entry_id,
                        'original_value' => $value,
                        'sanitized_value' => $sanitized_value,
                        'existing_value' => $existing
                    ));
                if ($existing !== null) {
                    // Update existing field
                    $result = $wpdb->update(
                        $wpdb->prefix . 'gf_entry_meta',
                        array('meta_value' => $sanitized_value),
                        array(
                            'entry_id' => $entry_id,
                            'meta_key' => $field_id
                        ),
                        array('%s'),
                        array('%d', '%s')
                    );
                } else {
                    // Insert new field
                    $result = $wpdb->insert(
                        $wpdb->prefix . 'gf_entry_meta',
                        array(
                            'entry_id' => $entry_id,
                            'meta_key' => $field_id,
                            'meta_value' => $sanitized_value
                        ),
                        array('%d', '%s', '%s')
                    );
                }
                $updated_fields[$field_id] = $sanitized_value;
                if ($debug_enabled)
                    $this->safe_log('debug', "Update/Insert operation result", array(
                        'result' => $result,
                        'wpdb_last_error' => $wpdb->last_error,
                        'field_id' => $field_id,
                        'entry_id' => $entry_id
                    ));
            }

            if ($result === false) {
                if ($debug_enabled)
                    // @codeCoverageIgnoreStart
                    $this->safe_log('error', "Field update failed", array(
                        'field_id' => $field_id,
                        'entry_id' => $entry_id,
                        'wpdb_last_error' => $wpdb->last_error
                    ));
                    // @codeCoverageIgnoreEnd
                $success = false;
                break;
            }
        }

        // Recalculate any calculation fields that depend on the edited fields
        if ($success && !empty($updated_fields)) {
            $recalculated = $this->recalculate_dependent_fields(
                intval($entry['form_id']),
                $entry_id,
                array_keys($updated_fields)
            );
            if (!empty($recalculated)) {
                // Merge using + operator to preserve numeric string keys (field IDs)
                // array_merge() would re-index "45" => 0, "31" => 1 etc.
                // @codeCoverageIgnoreStart
                $updated_fields = $updated_fields + $recalculated;
                $this->safe_log('info', 'Recalculated calculation fields', array(
                    'entry_id' => $entry_id,
                    'recalculated_fields' => array_keys($recalculated)
                ));
                // @codeCoverageIgnoreEnd
            }
        }

        if ($success) {
            // Force database commit to ensure changes are written
            $wpdb->query('COMMIT');

            $wpdb->update(
                $wpdb->prefix . 'gf_entry',
                array('date_updated' => current_time('mysql')),
                array('id' => $entry_id),
                array('%s'),
                array('%d')
            );

            // Force another commit after updating entry
            $wpdb->query('COMMIT');

            // Fire notifications + custom action hook on a real change
            if (!empty($entry_changes)) {
                $table_id_for_notify = intval($_POST['table_id'] ?? 0);
                $this->dispatch_entry_notifications('updated', $entry_id, intval($entry['form_id']), $table_id_for_notify, $entry_changes);
            }

            // VERIFICATION: Read back the values we just wrote to confirm they were actually saved
            if ($debug_enabled) {
                $this->safe_log('debug', "Verifying saved values", array(
                    'entry_id' => $entry_id,
                    'updated_fields_count' => count($updated_fields)
                ));
                foreach ($updated_fields as $field_id => $expected_value) {
                    $actual_value = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                        $entry_id,
                        $field_id
                    ));

                    $this->safe_log('debug', "Database verification for field {$field_id}", array(
                        'field_id' => $field_id,
                        'expected_value' => $expected_value,
                        'actual_value_wpdb' => $actual_value,
                        'autocommit_status' => $wpdb->get_var("SELECT @@autocommit") ? 'ON' : 'OFF',
                        'isolation_level' => $wpdb->get_var("SELECT @@transaction_isolation")
                    ));

                    if ($actual_value !== $expected_value) {
                        $this->safe_log('warning', "Database verification failed for field {$field_id}", array(
                            'expected' => $expected_value,
                            'actual_wpdb' => $actual_value
                        ));
                    }
                }
            }

            // Additional GFAPI verification and direct DB tests - only in debug mode
            if ($debug_enabled) {
                $this->safe_log('debug', "Starting GFAPI verification");

                $gf_entry = GFAPI::get_entry($entry_id);
                foreach ($updated_fields as $field_id => $expected_value) {
                    $gf_value = isset($gf_entry[$field_id]) ? $gf_entry[$field_id] : null;

                    $this->safe_log('debug', "GFAPI verification for field {$field_id}", array(
                        'field_id' => $field_id,
                        'expected_value' => $expected_value,
                        'gfapi_value' => $gf_value
                    ));
                }

                $this->safe_log('debug', "Testing direct DB query (bypassing WordPress)");
                foreach ($updated_fields as $field_id => $expected_value) {
                    $direct_value = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                        $entry_id,
                        $field_id
                    ));
                    $this->safe_log('debug', "Field {$field_id} direct DB value", array(
                        'field_id' => $field_id,
                        'direct_value' => $direct_value,
                        'expected_value' => $expected_value
                    ));
                }
            }

            // Targeted cache invalidation for this specific entry plus the
            // shared Gravity Forms / Gravity Tables cache groups. No global
            // wp_cache_flush() — see TC_Ajax::invalidate_table_caches().
            $this->invalidate_table_caches((int) $entry_id);

            if ($debug_enabled) {
                $end_time = microtime(true);
                $duration = ($end_time - $start_time) * 1000; // Convert to milliseconds
                $this->safe_log('info', "update_entry completed successfully", array(
                    'duration_ms' => round($duration, 2),
                    'entry_id' => $entry_id,
                    'updated_fields_count' => count($updated_fields)
                ));
            }

            // #517 slice 4c — Airtable push-back. After the local GFAPI update
            // succeeds, if (a) the table is configured for push_only or two_way,
            // (b) credentials are stored, and (c) the entry has a non-empty
            // value in the per-table airtable_record_id_field, fire a PATCH at
            // Airtable. Failure is swallowed (logged) so the local update's
            // success response is unaffected — this keeps the inline-edit UX
            // consistent regardless of upstream sync state. Slice 4d+ will add
            // conflict resolution + rate limiting + audit log per #613.
            $this->maybe_pushback_to_airtable((int) $entry_id, $updates);

            $response = array(
                'message' => 'Entry updated successfully',
                'updated_fields' => $updated_fields,
            );
            // #819 — surface any fileupload fields whose edit was
            // skipped because it would have clobbered a multi-file
            // shape. The frontend can show a per-field tooltip.
            if (!empty($gt_blocked_fields)) {
                // @codeCoverageIgnoreStart
                $response['blocked_fields'] = $gt_blocked_fields;
                $response['message'] = __('Entry updated. Some multi-file fileupload columns were left untouched to prevent data loss; use the GF entry editor to manage multi-file uploads.', 'tc-data-tables');
                // @codeCoverageIgnoreEnd
            }
            // #2116 — funnel: the user saved a frontend inline edit. Guarded so
            // the option is written once.
            if (class_exists('TC_Activation_Funnel') && !TC_Activation_Funnel::has('first_inline_edit_saved')) {
                TC_Activation_Funnel::record('first_inline_edit_saved');
            }
            wp_send_json_success($response);
        } else {
            wp_send_json_error(__('Failed to update entry', 'tc-data-tables'));
        }
    }

    /**
     * #517 slice 4c — push-back helper invoked after a successful GFAPI
     * update. Returns silently when any gate fails; never throws into the
     * AJAX response. Failures get logged via safe_log when debug is on.
     */
    private function maybe_pushback_to_airtable(int $entry_id, array $updates): void
    {
        if (empty($updates)) {
            return;
        }
        if (!class_exists('TC_Airtable_Sync_Engine') || !class_exists('TC_Airtable_Credential_Service')) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
        // #517 slice 4j — push-back permission gate. Even when sync_direction
        // is push_only or two_way, only users with the explicit write-back
        // capability can trigger the upstream PATCH. Default cap is
        // manage_options; customers can lower the bar via the
        // gt_airtable_writeback_capability filter (e.g. edit_others_posts
        // for editor-role write). Gate fires BEFORE the rate-limiter and
        // conflict-detector so unauthorized users don't consume rate-limit
        // slots or trigger the conflict pre-fetch. Local GFAPI update has
        // already succeeded; only the upstream push gets denied. Denied
        // events surface in the audit log so admins can spot escalation
        // attempts or misconfigured roles.
        $writeback_cap = (string) apply_filters('gt_airtable_writeback_capability', 'manage_options');
        if (!current_user_can($writeback_cap)) {
            $entry_for_record = function_exists('GFAPI') || class_exists('GFAPI') ? GFAPI::get_entry($entry_id) : null;
            $rid_for_log = '';
            if (is_array($entry_for_record)) {
                // Best-effort record id for the audit log; may be empty if the
                // table isn't configured for write or the entry has no record id.
                $rid_for_log = '';
            }
            if (class_exists('TC_Airtable_Audit_Log_Service')) {
                TC_Airtable_Audit_Log_Service::append(array(
                    'entry_id'  => $entry_id,
                    'record_id' => $rid_for_log,
                    'http_code' => null,
                    'error'     => 'denied: user lacks ' . $writeback_cap . ' capability',
                    'direction' => 'denied',
                    'ok'        => false,
                ));
            }
            $this->safe_log('warning', 'Airtable push-back denied (capability check failed)', array(
                'entry_id' => $entry_id,
                'user_id'  => get_current_user_id(),
                'cap'      => $writeback_cap,
            ));
            return;
        }
        $table_id_post = intval($_POST['table_id'] ?? 0);
        if ($table_id_post <= 0) {
            return;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id_post
        ));
        if (!$row) {
            return;
        }
        $tbl_settings = json_decode($row->settings, true);
        if (!is_array($tbl_settings)) {
            return;
        }
        // #1011 v4.182.0 — Accept both legacy (pull_only / push_only /
        // bidirectional) and canonical (pull / push / two_way) naming so the
        // new generic sync_direction picker from #1010 / phase 1 of #613 can
        // unify Airtable into the same flow as JSON + Notion in a future iter.
        $direction = $tbl_settings['sync_direction'] ?? 'pull_only';
        $aliases = array('pull_only' => 'pull', 'push_only' => 'push', 'bidirectional' => 'two_way');
        $canonical = $aliases[$direction] ?? $direction;
        if ($canonical === 'pull') {
            return;
        }
        $rid_field = (string) ($tbl_settings['airtable_record_id_field'] ?? '');
        if ($rid_field === '') {
            return;
        }
        // Pull credentials. load() returns null when nothing is stored.
        $creds = TC_Airtable_Credential_Service::load();
        if (!is_array($creds) || $creds['base_id'] === '' || $creds['table_id'] === '' || $creds['token'] === '') {
            return;
        }
        // Resolve the record id from the entry. GFAPI::get_entry returns a
        // map keyed by GF field id (string). Composite ids like "3.2" carry
        // sub-fields; we treat the value verbatim.
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry) || !is_array($entry)) {
            return;
        }
        $record_id = (string) ($entry[$rid_field] ?? '');
        if ($record_id === '') {
            return;
        }
        // Build the fields payload: GF field labels are the default Airtable
        // column names. Customers structure their imports so labels match;
        // slice 4d can add an explicit GF-id → Airtable-column mapping if
        // needed. We resolve labels via GFAPI::get_form so the mapping is
        // always current with the live form structure.
        $form = GFAPI::get_form((int) $entry['form_id']);
        $field_labels = array();
        if (is_array($form) && !empty($form['fields'])) {
            foreach ($form['fields'] as $f) {
                if (isset($f->id, $f->label)) {
                    $field_labels[(string) $f->id] = (string) $f->label;
                }
            }
        }
        $airtable_fields = array();
        foreach ($updates as $field_id => $value) {
            $label = $field_labels[(string) $field_id] ?? '';
            if ($label === '') {
                continue; // skip fields with no label — can't map to a column name
            }
            $airtable_fields[$label] = $value;
        }
        if (empty($airtable_fields)) {
            return;
        }
        // #517 slice 4i — rate-limiter gate. Airtable caps each base at
        // ~5 req/s; without client-side throttling, a busy admin can lock
        // the base out with 429s. Consult the limiter BEFORE any HTTP call
        // (the slice-4g conflict pre-fetch + the slice-4a update_record
        // write). On throttle: append a 'direction' => 'throttled' event
        // to the audit log + safe_log warning + return; (no HTTP). The
        // local GFAPI update already succeeded; only the upstream push
        // gets deferred. Customers can re-trigger the push by editing the
        // row again once the bucket has slid forward.
        if (
            class_exists('TC_Airtable_Rate_Limiter')
            && TC_Airtable_Rate_Limiter::should_throttle($creds['base_id'])
        ) {
            if (class_exists('TC_Airtable_Audit_Log_Service')) {
                TC_Airtable_Audit_Log_Service::append(array(
                    'entry_id'  => $entry_id,
                    'record_id' => $record_id,
                    'http_code' => null,
                    'error'     => 'rate-limited: Airtable cap reached, push deferred',
                    'direction' => 'throttled',
                    'ok'        => false,
                ));
            }
            $this->safe_log('warning', 'Airtable push-back skipped (rate-limited)', array(
                'entry_id'  => $entry_id,
                'record_id' => $record_id,
                'base_id'   => $creds['base_id'],
            ));
            return;
        }

        // #517 slice 4g — conflict detection (optimistic locking).
        // Before pushing, fetch the current Airtable record and compare its
        // Last Modified Time against the locally-stored baseline. If the
        // remote is newer than what we last saw, someone else modified the
        // record since our last push — abort to avoid silent overwrite.
        // Fail-open semantics from slice 4f: missing remote lastmod or
        // missing local baseline → no conflict (don't block on noise).
        // Customers add a "Last Modified Time" field to their Airtable
        // schema for this to be effective.
        if (class_exists('TC_Airtable_Conflict_Detector')) {
            $current = TC_Airtable_Sync_Engine::fetch_single_record(
                $creds['base_id'],
                $creds['table_id'],
                $record_id,
                $creds['token']
            );
            // #517 slice 4i — record the conflict pre-fetch in the rate
            // limiter bucket so subsequent calls in the same window see
            // the running count.
            if (class_exists('TC_Airtable_Rate_Limiter')) {
                TC_Airtable_Rate_Limiter::record_request($creds['base_id']);
            }
            if (!empty($current['ok']) && is_array($current['record'])) {
                // Airtable returns the lastModifiedTime under the "fields"
                // map (when the schema includes it) — common label is
                // "Last Modified Time" but customers may use any. Probe a
                // few canonical candidates; fall through to '' if none.
                $remote_lastmod = '';
                $rec_fields = $current['record']['fields'] ?? array();
                if (is_array($rec_fields)) {
                    foreach (array('Last Modified Time', 'Last Modified', 'Modified', 'Updated', 'Updated At') as $candidate) {
                        if (!empty($rec_fields[$candidate])) {
                            $remote_lastmod = (string) $rec_fields[$candidate];
                            break;
                        }
                    }
                }
                $local_baseline = TC_Airtable_Conflict_Detector::load_baseline($entry_id);
                if (TC_Airtable_Conflict_Detector::detect_conflict($remote_lastmod, $local_baseline)) {
                    if (class_exists('TC_Airtable_Audit_Log_Service')) {
                        TC_Airtable_Audit_Log_Service::append(array(
                            'entry_id'        => $entry_id,
                            'record_id'       => $record_id,
                            'http_code'       => null,
                            'error'           => 'conflict: remote modified since baseline',
                            'direction'       => 'conflict',
                            'ok'              => false,
                            'remote_lastmod'  => $remote_lastmod,
                            'local_baseline'  => $local_baseline,
                        ));
                    }
                    $this->safe_log('warning', 'Airtable push-back skipped (conflict)', array(
                        'entry_id'        => $entry_id,
                        'record_id'       => $record_id,
                        'remote_lastmod'  => $remote_lastmod,
                        'local_baseline'  => $local_baseline,
                    ));
                    return;
                }
            }
        }
        // Fire-and-log. Failure does not affect the local-update response.
        $result = TC_Airtable_Sync_Engine::update_record(
            $creds['base_id'],
            $creds['table_id'],
            $record_id,
            $airtable_fields,
            $creds['token']
        );
        // #517 slice 4i — record the write in the rate-limiter bucket
        // (whether or not it succeeded — the HTTP call still consumed
        // a request slot per Airtable's accounting).
        if (class_exists('TC_Airtable_Rate_Limiter')) {
            TC_Airtable_Rate_Limiter::record_request($creds['base_id']);
        }
        // #517 slice 4e — record every push attempt (success + failure) in
        // the audit log so admins can inspect recent push activity from the
        // Airtable settings page without grepping debug.log.
        if (class_exists('TC_Airtable_Audit_Log_Service')) {
            TC_Airtable_Audit_Log_Service::append(array(
                'entry_id'  => $entry_id,
                'record_id' => $record_id,
                'http_code' => $result['http_code'] ?? null,
                'error'     => $result['error'] ?? null,
                'direction' => 'push',
                'ok'        => !empty($result['ok']),
            ));
        }
        // #517 slice 4g — after a successful push, snapshot the new
        // lastModifiedTime so the next push compares against the latest
        // known state. Pulled from the PATCH response's fields map (same
        // probe order as the conflict-check path above).
        if (!empty($result['ok']) && is_array($result['record']) && class_exists('TC_Airtable_Conflict_Detector')) {
            // @codeCoverageIgnoreStart
            $new_lastmod = '';
            $resp_fields = $result['record']['fields'] ?? array();
            if (is_array($resp_fields)) {
                foreach (array('Last Modified Time', 'Last Modified', 'Modified', 'Updated', 'Updated At') as $candidate) {
                    if (!empty($resp_fields[$candidate])) {
                        $new_lastmod = (string) $resp_fields[$candidate];
                        break;
            // @codeCoverageIgnoreEnd
                    }
                }
            }
            // @codeCoverageIgnoreStart
            if ($new_lastmod !== '') {
                TC_Airtable_Conflict_Detector::snapshot_baseline($entry_id, $new_lastmod);
            // @codeCoverageIgnoreEnd
            }
        }
        if (empty($result['ok'])) {
            $this->safe_log('warning', 'Airtable push-back failed', array(
                'entry_id'  => $entry_id,
                'record_id' => $record_id,
                'http_code' => $result['http_code'] ?? null,
                'error'     => $result['error'] ?? null,
            ));
        }
    }

    public function update_entry_fields(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        // Frontend editing is a premium feature
        if (gt_is_free_plan()) {
            wp_send_json_error(array(
                'message' => __('Frontend editing is a Pro feature. Upgrade to edit entries directly in tables.', 'tc-data-tables'),
                'upgrade_required' => true,
                'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
            ));
        }

        // #553 slice 2 — WAF-safe payload server-side wire (parity with
        // update_entry above). The bulk-fields handler accepts an encoded
        // envelope under $_POST['payload'] containing entry_id + fields,
        // and falls through to the legacy form-encoded path otherwise.
        $entry_id = 0;
        $fields   = array();
        $encoded  = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';
        if (
            $encoded !== ''
            && class_exists('TC_WAF_Safe_Payload')
            && TC_WAF_Safe_Payload::is_encoded($encoded)
        ) {
            // @codeCoverageIgnoreStart
            $decoded = TC_WAF_Safe_Payload::decode($encoded);
            if (is_array($decoded)) {
                $entry_id = isset($decoded['entry_id']) ? (int) $decoded['entry_id'] : 0;
                $fields   = isset($decoded['fields']) && is_array($decoded['fields']) ? $decoded['fields'] : array();
            // @codeCoverageIgnoreEnd
            }
        }
        if ($entry_id === 0) {
            $entry_id = intval($_POST['entry_id'] ?? 0);
        }
        if (empty($fields)) {
            $fields = $_POST['fields'] ?? array();
        }

        if (!$entry_id || empty($fields)) {
            wp_send_json_error(__('Invalid entry data', 'tc-data-tables'));
        }

        // #1641 — resolve the entry up-front and apply the table's form-level
        // role gate (parity with update_entry). Reused below as $fields_entry
        // so the bound frontend-editing grant doesn't re-fetch.
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry) || !$entry) {
            wp_send_json_error(__('Entry not found', 'tc-data-tables'));
        }
        $this->checkTableAccessPermission(intval($entry['form_id']));

        // Check if user can edit gravity forms entries.
        // Priority 1: administrators / editors / authors are always allowed.
        // Priority 2: If the table itself has "Enable frontend editing" turned on,
        //             a logged-in user editing an entry of that table's form.
        // Priority 3: Fall back to GF created_by ownership check.
        $current_user_id = get_current_user_id();
        // #1648 — exclude 'driver' cap (drivers lack edit_posts) so they can't bypass the ownership check below.
        $has_admin_cap = current_user_can('edit_posts') || current_user_can('publish_posts');

        if (!$has_admin_cap) {
            $can_edit = false;

            // #1633 — resolve the entry's form so the frontend-editing
            // grant can be bound to it (mirrors update_entry). Without the
            // binding an attacker could point table_id at an unrelated
            // editing-enabled table to edit arbitrary entries.
            // #1641 — entry already resolved at the top for the role gate.
            $fields_entry = $entry;
            $fields_entry_form_id = (!is_wp_error($fields_entry) && !empty($fields_entry['form_id']))
                ? intval($fields_entry['form_id'])
                : 0;
            $table_id_post = intval($_POST['table_id'] ?? 0);
            $editing_form_id = $this->frontend_editing_form_id($table_id_post);
            // #1648 — bind the grant to entry ownership when owner_field_id is set.
            if ($editing_form_id > 0 && $fields_entry_form_id > 0 && $editing_form_id === $fields_entry_form_id
                && TC_Entry_Owner_Guard::entry_owner_matches(is_array($fields_entry) ? $fields_entry : array(), $this->frontend_editing_owner_field_id($table_id_post), get_current_user_id())) {
                $can_edit = true;
            }

            if (!$can_edit) {
                $can_edit = $this->can_user_edit_entry($entry_id);
            }

            if (!$can_edit) {
                wp_send_json_error(__('Insufficient permissions to edit this entry', 'tc-data-tables'));
            }
        }

        global $wpdb;

        $success = true;
        $updated_fields = array();

        foreach ($fields as $field_id => $value) {
            // Sanitize the value based on field type
            $sanitized_value = $this->sanitize_field_value($value, $field_id);

            // For Gravity Forms, we need to check if this field already has an entry in entry_meta
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                $entry_id,
                $field_id
            ));

            if ($existing !== null) {
                // Update existing field
                $this->safe_log('debug', "UPDATING existing field", array(
                    'field_id' => $field_id,
                    'entry_id' => $entry_id,
                    'sanitized_value' => $sanitized_value
                ));
                $result = $wpdb->update(
                    $wpdb->prefix . 'gf_entry_meta',
                    array('meta_value' => $sanitized_value),
                    array(
                        'entry_id' => $entry_id,
                        'meta_key' => $field_id
                    ),
                    array('%s'),
                    array('%d', '%s')
                );
                $this->safe_log('debug', "Update result", array(
                    'success' => $result !== false,
                    'affected_rows' => $result !== false ? $result : 0,
                    'wpdb_last_error' => $result === false ? $wpdb->last_error : null,
                    'field_id' => $field_id
                ));
            } else {
                // Insert new field
                $this->safe_log('debug', "INSERTING new field", array(
                    'field_id' => $field_id,
                    'entry_id' => $entry_id,
                    'sanitized_value' => $sanitized_value
                ));
                $result = $wpdb->insert(
                    $wpdb->prefix . 'gf_entry_meta',
                    array(
                        'entry_id' => $entry_id,
                        'meta_key' => $field_id,
                        'meta_value' => $sanitized_value
                    ),
                    array('%d', '%s', '%s')
                );
                $this->safe_log('debug', "Insert result", array(
                    'success' => $result !== false,
                    'wpdb_last_error' => $result === false ? $wpdb->last_error : null,
                    'field_id' => $field_id
                ));

                // Double-check the insert worked
                if ($result !== false) {
                    $verify = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                        $entry_id,
                        $field_id
                    ));
                    $this->safe_log('debug', "Verification read after insert", array(
                        'field_id' => $field_id,
                        'verification_value' => $verify
                    ));
                }
            }

            if ($result !== false) {
                $updated_fields[$field_id] = $sanitized_value;
            } else {
                $success = false;
                break;
            }
        }

        if ($success) {
            // Update the entry's date_updated field
            $wpdb->update(
                $wpdb->prefix . 'gf_entry',
                array('date_updated' => current_time('mysql')),
                array('id' => $entry_id),
                array('%s'),
                array('%d')
            );

            // Force a fresh read to ensure we return the latest values
            // This addresses potential caching issues with the MAX(CASE WHEN...) query
            foreach ($updated_fields as $field_id => $value) {
                $fresh_value = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                    $entry_id,
                    $field_id
                ));
                if ($fresh_value !== null) {
                    $updated_fields[$field_id] = $fresh_value;
                }
            }

            wp_send_json_success(array(
                'message' => 'All fields updated successfully',
                'updated_fields' => $updated_fields
            ));
        } else {
            wp_send_json_error(__('Failed to update some fields', 'tc-data-tables'));
        }
    }

    public function bulk_action(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        // Bulk operations are a premium feature
        if (gt_is_free_plan()) {
            wp_send_json_error(array(
                'message' => __('Bulk operations are a Pro feature. Upgrade to manage multiple entries at once.', 'tc-data-tables'),
                'upgrade_required' => true,
                'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
            ));
        }

        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $entry_ids = array_map('intval', $_POST['entry_ids'] ?? array());

        if (empty($action) || empty($entry_ids)) {
            wp_send_json_error(__('Invalid bulk action data', 'tc-data-tables'));
        }

        // #635 per-table bulk-action gate. The frontend toolbar already filters
        // the dropdown options based on $table_config['bulk_actions'], but a
        // direct POST could still trigger a hidden action. Load the saved
        // settings.bulk_actions array for this table and reject when the action
        // isn't whitelisted. Tables with the setting unset (legacy) match the
        // pre-#635 behavior (all three actions allowed).
        $table_id_for_gate = intval($_POST['table_id'] ?? 0);
        if ($table_id_for_gate > 0) {
            global $wpdb;
            $gate_row = $wpdb->get_var($wpdb->prepare(
                "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
                $table_id_for_gate
            ));
            if ($gate_row) {
                $gate_settings = json_decode($gate_row, true);
                if (isset($gate_settings['settings']['bulk_actions']) && is_array($gate_settings['settings']['bulk_actions'])) {
                    $allowed_for_table = $gate_settings['settings']['bulk_actions'];
                } elseif (isset($gate_settings['bulk_actions']) && is_array($gate_settings['bulk_actions'])) {
                    $allowed_for_table = $gate_settings['bulk_actions'];
                } else {
                    $allowed_for_table = null; // legacy: leave un-gated
                }
                if (is_array($allowed_for_table) && !in_array($action, $allowed_for_table, true)) {
                    wp_send_json_error(__('That bulk action is not enabled for this table.', 'tc-data-tables'));
                }
            }
        }

        // Check if user can edit/delete gravity forms entries.
        // Allow Gravity Forms admins, WP admins/editors, OR anyone if the table has frontend editing enabled.
        $has_admin_cap = current_user_can('gform_full_access')
            || current_user_can('gravityforms_delete_entries')
            || current_user_can('edit_posts')
            || current_user_can('publish_posts');
        if (!$has_admin_cap) {
            $can_bulk = false;
            // #1633 — grant via "enable_frontend_editing" only when the
            // table belongs to the same form as EVERY targeted entry and
            // the caller is logged in. Without the all_entries_in_form
            // check, an editing-enabled table on one form could be used to
            // bulk-delete entries belonging to any other form.
            $table_id_post = intval($_POST['table_id'] ?? 0);
            $editing_form_id = $this->frontend_editing_form_id($table_id_post);
            if ($editing_form_id > 0 && $this->all_entries_in_form($entry_ids, $editing_form_id)) {
                $can_bulk = true;
            }
            if (!$can_bulk && !$this->can_user_bulk_edit_entries($entry_ids)) {
                wp_send_json_error(__('Insufficient permissions to edit these entries', 'tc-data-tables'));
            }
        }

        $result = array();

        switch ($action) {
            case 'delete':
                $result = $this->bulk_delete_entries($entry_ids);
                break;
            case 'export':
                $result = $this->bulk_export_entries($entry_ids);
                break;
            case 'edit':
                $updates = $_POST['bulk_updates'] ?? array();
                $result = $this->bulk_edit_entries($entry_ids, $updates);
                break;
            default:
                wp_send_json_error(__('Unknown bulk action', 'tc-data-tables'));
        }

        wp_send_json_success($result);
    }

    /**
     * Get full details for a single entry for popup view
     */
    public function get_entry_details(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $entry_id = intval($_POST['entry_id'] ?? 0);
        if (!$entry_id) {
            wp_send_json_error(__('Invalid entry ID', 'tc-data-tables'));
        }

        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry) || !$entry) {
            wp_send_json_error(__('Entry not found', 'tc-data-tables'));
        }

        // Permission check
        $this->checkTableAccessPermission(intval($entry['form_id']));

        $form = GFAPI::get_form($entry['form_id']);
        $details = array();

        // Standard Meta Fields
        $details[] = array('label' => 'Entry ID', 'value' => $entry['id']);
        $details[] = array('label' => 'Date Created', 'value' => GFCommon::format_date($entry['date_created'], false));
        $details[] = array('label' => 'User IP', 'value' => $entry['ip']);

        // Form Fields. The default fallback (GFCommon::get_lead_field_display) is unreliable
        // for several real-world cases that produce confusing output in the popup:
        //   1. fileupload fields with NO value return broken HTML iterating the entry tuple
        //      (entry_id, form_id, dates, ip, user_agent...) as fake `<a>` links — looks like
        //      total garbage to a non-developer viewer.
        //   2. textarea / multi-input fields whose value is structurally an array cast to
        //      the literal string "Array" once flattened.
        //   3. driver_selector / user-lookup fields render the raw user ID instead of the
        //      person's name.
        // Handle each explicitly first; only fall through to GFCommon for plain field types.
        if (!class_exists('TC_Cell_Renderer')) {
            // @codeCoverageIgnoreStart
            require_once TC_PLUGIN_PATH . 'includes/renderers/class-tc-cell-renderer.php';
            // @codeCoverageIgnoreEnd
        }

        foreach ($form['fields'] as $field) {
            if (in_array($field->type, array('html', 'section', 'page'))) {
                continue;
            }

            $field_id = (string) $field->id;
            $raw = rgar($entry, $field_id);

            // #820 — `consent` composite. Eye popup uses the dedicated
            // composer so admins see the full label + revision id, not
            // just "1" / "" from the generic scanner.
            if ($field->type === 'consent' && class_exists('TC_Consent_Field_Renderer')) {
                $c_text = TC_Consent_Field_Renderer::render_text($entry, (string) $field->id);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $c_text !== '' ? $c_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #818 — `time` composite. Eye popup uses the dedicated
            // composer for proper "9:30 am" / "21:30" formatting.
            if ($field->type === 'time' && class_exists('TC_Time_Field_Renderer')) {
                $t_text = TC_Time_Field_Renderer::render_text($entry, (string) $field->id);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $t_text !== '' ? $t_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #817 — `name` composite. Eye popup uses the dedicated
            // composer for predictable "Mr. John Smith Jr." ordering
            // (the generic scanner below joins sub-inputs in entry-
            // map order, which depends on PHP's hash insertion).
            if ($field->type === 'name' && class_exists('TC_Name_Field_Renderer')) {
                $n_text = TC_Name_Field_Renderer::render_text($entry, (string) $field->id);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $n_text !== '' ? $n_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #810 — `post_content` (and `post_excerpt`): eye popup
            // gets the full wp_kses_post HTML (allowing safe markup)
            // rather than the truncated preview the cell shows.
            if (($field->type === 'post_content' || $field->type === 'post_excerpt')
                && class_exists('TC_Post_Content_Field_Renderer')
            ) {
                $pc_html = TC_Post_Content_Field_Renderer::render_full_html($raw);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $pc_html !== '' ? $pc_html : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #807 — `product` composite (name × qty @ price = total).
            // Eye popup uses the composer for the readable summary.
            if ($field->type === 'product' && class_exists('TC_Product_Field_Renderer')) {
                $p_text = TC_Product_Field_Renderer::render_text($entry, (string) $field->id);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $p_text !== '' ? $p_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #808 — `post_image` composite. Eye popup renders the
            // labelled text shape (URL + Title + Caption + Description
            // + Alt) so each sub-input is visible. The generic
            // multi-input scanner below would also produce text but
            // without labels and in arbitrary order.
            if ($field->type === 'post_image' && class_exists('TC_Post_Image_Field_Renderer')) {
                $pi_text = TC_Post_Image_Field_Renderer::render_text($entry, (string) $field->id);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $pi_text !== '' ? $pi_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #806 — `creditcard` HIGH PRIORITY / PCI. Eye popup must
            // NEVER concatenate raw sub-inputs (the generic scanner
            // below would dump every $field_id.{1..5} value). Use the
            // safe renderer instead, which emits only the masked tail
            // + card type + holder name.
            if ($field->type === 'creditcard' && class_exists('TC_Creditcard_Field_Renderer')) {
                $cc_text = TC_Creditcard_Field_Renderer::render_safe_full($entry, (string) $field->id);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $cc_text !== '' ? $cc_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #798 — `checkbox` fields use the dedicated composer that
            // iterates every `{field_id}.{N}` sub-input. The generic
            // scanner below would mostly work but doesn't enforce the
            // ksort-by-sub-input-index ordering customers expect.
            if ($field->type === 'checkbox' && class_exists('TC_Checkbox_Field_Renderer')) {
                $cb_text = TC_Checkbox_Field_Renderer::render_text($entry, (string) $field->id);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $cb_text !== '' ? $cb_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #796 — `address` fields use the slice-1 composer for a
            // postal-layout multi-line render (street \n street2 \n
            // city, state zip \n country) instead of the generic
            // comma-joined sub-input concatenation below.
            if ($field->type === 'address' && class_exists('TC_Address_Field_Renderer')) {
                $addr_text = TC_Address_Field_Renderer::render_text($entry, (string) $field->id);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $addr_text !== '' ? $addr_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // #795 — `list` fields are a special multi-input: they store
            // serialised PHP at the BARE field-id slot (not sub-keys like
            // `name` / `address` do). Branch out before the sub-key
            // scanner so the list-field renderer handles deserialisation.
            if ($field->type === 'list' && class_exists('TC_List_Field_Renderer')) {
                $list_text = TC_List_Field_Renderer::render_as_text($raw);
                $details[] = array(
                    'label' => $field->label,
                    'value' => $list_text !== '' ? $list_text : '-',
                    'id'    => $field->id,
                );
                continue;
            }

            // Multi-input fields (name, address) store their data in sub-keys like
            // `5.1`, `5.3`, `5.6` rather than at the bare `5` slot. The bare slot is often
            // empty, which made the empty-check below emit "-" even when the sub-keys held
            // real values (e.g. an address with street/city/state filled). Gather the
            // sub-key values and emit them combined before any other handling runs.
            if (in_array($field->type, array('name', 'address', 'list'), true)) {
                // @codeCoverageIgnoreStart
                $sub_values = array();
                foreach ($entry as $k => $v) {
                    $k_str = (string) $k;
                    if (strpos($k_str, $field_id . '.') === 0 && is_scalar($v) && trim((string) $v) !== '') {
                        $sub_values[] = trim((string) $v);
                // @codeCoverageIgnoreEnd
                    }
                }
                // @codeCoverageIgnoreStart
                if (!empty($sub_values)) {
                    $details[] = array(
                        'label' => $field->label,
                        'value' => implode(', ', $sub_values),
                        'id' => $field->id,
                    );
                    continue;
                // @codeCoverageIgnoreEnd
                }
                // Genuinely empty multi-input → emit dash and move on.
                // @codeCoverageIgnoreStart
                $details[] = array('label' => $field->label, 'value' => '-', 'id' => $field->id);
                continue;
                // @codeCoverageIgnoreEnd
            }

            // Empty-out empty values BEFORE delegating to GFCommon. Skipping this is what
            // exposed bugs (1) and (2) above.
            $is_raw_empty = ($raw === '' || $raw === null);
            if (!$is_raw_empty && is_array($raw)) {
                // @codeCoverageIgnoreStart
                $non_empty = array_filter($raw, function ($v) {
                    return $v !== '' && $v !== null;
                });
                $is_raw_empty = empty($non_empty);
                // @codeCoverageIgnoreEnd
            }
            if ($is_raw_empty) {
                $details[] = array('label' => $field->label, 'value' => '-', 'id' => $field->id);
                continue;
            }

            $value = '';

            switch ($field->type) {
                case 'fileupload':
                    // Reuse the same renderer the table cells use. Pass context=detail
                    // so the image fills the popup column (up to ~360px tall) instead
                    // of rendering as the table-cell-sized 80x60 thumbnail. Either way
                    // the image is wrapped in a click-to-open-fullsize anchor.
                    $value = TC_Cell_Renderer::render($raw, 'fileupload', array(
                        'name' => $field->label,
                        'context' => 'detail',
                    ));
                    break;

                case 'driver_selector':
                    // Stored as the user ID; resolve to a display name.
                    $user = is_numeric($raw) ? get_user_by('id', (int) $raw) : false;
                    $value = $user ? $user->display_name : (string) $raw;
                    break;

                default:
                    // For simple plain-text field types, the raw entry value is the truth and
                    // GFCommon::get_lead_field_display() has been observed to return the literal
                    // string "Array" for textarea fields even when the meta row contains real
                    // text — bypass it to be safe.
                    $raw_first_types = array(
                        'text', 'textarea', 'phone', 'website', 'email', 'number',
                        'hidden', 'time', 'name', 'address',
                        // Date is in this list because GFCommon's date display has been
                        // observed to return broken "//" / "/212/" output when the form's
                        // date format doesn't match the stored value's format. Showing the
                        // raw "YYYY-MM-DD" is less pretty but always correct.
                        'date',
                    );
                    if (in_array($field->type, $raw_first_types, true) && is_scalar($raw) && (string) $raw !== '') {
                        $value = (string) $raw;
                        break;
                    }
                    // GF 2.9.29 changed the signature: get_lead_field_display($field,
                    // $value, $entry, ...). Pre-2.9.29 sites still accepted the older
                    // ($field, $entry, $currency) shape, which is why this slipped past
                    // local testing — until customers running 2.10.x reported every
                    // plain select in the eye popup collapsing to "-" (Katie 2026-05-12).
                    // Use the new signature; older GF still accepts it because $value is
                    // simply forwarded to GF_Field::get_value_entry_detail.
                    $display = GFCommon::get_lead_field_display($field, $raw, $entry);
                    if (is_scalar($display)) {
                        $value = (string) $display;
                    } elseif (is_array($display)) {
                        // Flatten array values (e.g. multi-input name/address) to a
                        // comma-separated string instead of "Array".
                        $flat = array();
                        array_walk_recursive($display, function ($v) use (&$flat) {
                            if (is_scalar($v) && trim((string) $v) !== '') {
                                $flat[] = trim((string) $v);
                            }
                        });
                        $value = implode(', ', $flat);
                    } else {
                        // @codeCoverageIgnoreStart
                        $value = '';
                        // @codeCoverageIgnoreEnd
                    }
                    // If GFCommon surfaced the literal "Array" string OR a print_r-style
                    // dump (e.g. "Array(", "Array\n(\n  [0] => foo\n)") for a scalar
                    // field type, fall back to the raw entry value or empty marker. The
                    // exact-equality check this expanded from missed those variants.
                    $looks_like_array_dump = is_string($value) && (
                        $value === 'Array'
                        || preg_match('/^\s*Array\s*[(\n]/', $value)
                    );
                    if ($looks_like_array_dump) {
                        $value = is_scalar($raw) && (string) $raw !== '' ? (string) $raw : '';
                    }
                    break;
            }

            if ($value === '' && $value !== '0') {
                // @codeCoverageIgnoreStart
                $value = '-';
                // @codeCoverageIgnoreEnd
            }

            // Final safety: the JS popup renderer concatenates `value` straight into the
            // detail-row HTML, so a non-string slipping through (e.g. a stray array or
            // object that bypassed every prior branch) would render as garbage. Coerce
            // to a clean string and never let the literal "Array" reach the response.
            if (!is_string($value)) {
                // @codeCoverageIgnoreStart
                if (is_scalar($value)) {
                    $value = (string) $value;
                } elseif (is_array($value)) {
                    $flat = array();
                    array_walk_recursive($value, function ($v) use (&$flat) {
                        if (is_scalar($v) && trim((string) $v) !== '') {
                            $flat[] = trim((string) $v);
                // @codeCoverageIgnoreEnd
                        }
                    // @codeCoverageIgnoreStart
                    });
                    $value = empty($flat) ? '-' : implode(', ', $flat);
                    // @codeCoverageIgnoreEnd
                } else {
                    // @codeCoverageIgnoreStart
                    $value = '-';
                    // @codeCoverageIgnoreEnd
                }
            }
            if ($value === 'Array') {
                // @codeCoverageIgnoreStart
                $value = '-';
                // @codeCoverageIgnoreEnd
            }

            $details[] = array(
                'label' => $field->label,
                'value' => $value,
                'id' => $field->id
            );
        }

        $form_id = (int) $entry['form_id'];
        $gf_edit_url = '';
        if (current_user_can('gform_full_access')
            || current_user_can('gravityforms_edit_entries')
            || current_user_can('gravityforms_view_entries')) {
            $gf_edit_url = admin_url('admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry_id);
        }

        wp_send_json_success(array(
            'entry_id' => $entry_id,
            'form_id' => $form_id,
            'details' => $details,
            'form_title' => $form['title'],
            'gf_entry_edit_url' => $gf_edit_url,
        ));
    }

    /**
     * #1649 — sanitize a user-sourced field value before it is serialized to
     * the client. The JS cell renderers pass values starting with '<' into
     * innerHTML raw, so a submitted '<img onerror=...>' would otherwise run.
     * Delegates to TC_Sanitization_Service::sanitize_display_html (plain text
     * is left untouched; HTML-looking values go through the wp_kses cell
     * allowlist that keeps img/a and strips script/on* handlers).
     */
    private function gt_sanitize_cell_output($value)
    {
        if (class_exists('TC_Sanitization_Service')) {
            return TC_Sanitization_Service::sanitize_display_html($value);
        }
        return $value;
    }

    /**
     * #1691 — the length selector's "All" option submits per_page = -1. The
     * #1073 DoS clamp (max(1, min(200, n))) turned that into 1, so "Show All"
     * displayed a single row. Treat -1 as the "All" sentinel and map it to a
     * high but BOUNDED ceiling (covers realistic table sizes without the
     * unbounded request #1073 guards against); every other value keeps the
     * [1, 200] clamp.
     */
    const PER_PAGE_ALL = 10000;

    public static function resolve_per_page($raw): int
    {
        $n = (int) $raw;
        if ($n === -1) {
            return self::PER_PAGE_ALL;
        }
        return max(1, min(200, $n));
    }

    /**
     * #1681 — resolve a typed display-name fragment to the set of lookup
     * IDs whose label contains it (case-insensitive substring). Pure helper
     * over an already-fetched option list (value => id, label => name), so
     * the SQL builders can constrain meta_value IN (ids). Returns [] for a
     * blank needle or no match (caller short-circuits to "no results").
     *
     * @param array<int,array{value:mixed,label:string}> $options
     */
    public static function match_lookup_options_by_name(array $options, string $needle): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return array();
        }
        $needle_lc = function_exists('mb_strtolower') ? mb_strtolower($needle) : strtolower($needle);

        $ids = array();
        foreach ($options as $opt) {
            if (!is_array($opt) || !isset($opt['label'], $opt['value'])) {
                continue;
            }
            $label = (string) $opt['label'];
            $label_lc = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
            if (strpos($label_lc, $needle_lc) !== false) {
                $ids[] = $opt['value'];
            }
        }
        return $ids;
    }

    /**
     * #1681 — build the SQL predicate for a per-column 'lookup_name' filter:
     * resolve the typed display-name fragment to the matching lookup IDs and
     * constrain meta_value IN (ids). Shared by the data + count queries.
     * Returns '' when the field isn't a lookup column (or value blank), and
     * ' AND 1=0' when the name resolves to no IDs (so the result is empty
     * rather than unfiltered). Appends to $params by reference in the same
     * order the placeholders appear (meta_key first, then the id set).
     */
    /**
     * #1663 — build a [field_id => [input_id => label]] map of the form's name
     * fields ONCE, so the per-row render loop can look up name-field components
     * instead of re-scanning $form['fields'] for every column of every row.
     * Hidden inputs are excluded; a simple name field (no inputs) maps to its
     * own label.
     *
     * @return array<string,array<string,string>>
     */
    public static function build_name_field_map($form): array
    {
        $map = array();
        if (!$form || is_wp_error($form) || !isset($form['fields'])) {
            return $map;
        }
        foreach ($form['fields'] as $field) {
            if (!isset($field->type) || $field->type !== 'name') {
                continue;
            }
            $components = array();
            if (isset($field->inputs) && is_array($field->inputs)) {
                foreach ($field->inputs as $input) {
                    if (!empty($input['id']) && !empty($input['label']) &&
                        (!isset($input['isHidden']) || !$input['isHidden'])) {
                        $components[$input['id']] = $input['label'];
                    }
                }
            } else {
                $components[strval($field->id)] = $field->label;
            }
            $map[strval($field->id)] = $components;
        }
        return $map;
    }

    private function build_lookup_name_predicate(string $field_id, $value, array $lookup_fields, ?int $form_id, array &$params): string
    {
        $lookup_config = isset($lookup_fields[$field_id]) ? $lookup_fields[$field_id] : null;
        if (!$lookup_config || $value === null || trim((string) $value) === '') {
            return '';
        }

        global $wpdb;
        $options = class_exists('TC_Lookup')
            ? TC_Lookup::get_instance()->get_lookup_options($lookup_config, $form_id)
            : array();
        $ids = self::match_lookup_options_by_name($options, (string) $value);
        if (empty($ids)) {
            return ' AND 1=0';
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $params[] = $field_id;
        foreach ($ids as $id) {
            $params[] = (string) $id;
        }
        return " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_lkn WHERE em_lkn.entry_id = e.id AND em_lkn.meta_key = %s AND em_lkn.meta_value IN ($placeholders))";
    }

    private function get_gravity_forms_entries(
        int $form_id,
        int $page = 1,
        int $per_page = 25,
        string $search = '',
        string $user_filter = '',
        string $date_from = '',
        string $date_to = '',
        string $sort_field = 'date_created',
        string $sort_order = 'desc',
        array $columns = array(),
        array $lookup_fields = array(),
        array $filters = array(),
        ?array $table_config = null,
        array $drilldown_filters = array()
    ): array {
        global $wpdb;
        $debug = TC_Debug::get_instance();

        // #1629 — $columns arrives verbatim from $_POST['columns'] and is
        // interpolated into the SELECT pivot's meta_key literal + field
        // alias below. Reduce it to the fixed special columns plus valid
        // Gravity Forms field IDs so a crafted column value cannot break
        // out of the 'meta_key = '{$field_id}'' literal. Done centrally
        // here so get_entries / preview_table / export_table are covered.
        $columns = TC_SQL_Guard::filter_columns($columns);

        // Apply free plan entry limits
        if (gt_is_free_plan()) {
            // Limit total entries per table to free plan maximum
            $per_page = min($per_page, TC_FREE_MAX_ENTRIES);

            // If this would exceed the total limit, cap it
            $max_entries_allowed = TC_FREE_MAX_ENTRIES;
            $start_entry = ($page - 1) * $per_page;

            if ($start_entry >= $max_entries_allowed) {
                // Beyond free limit, return empty with upgrade message
                return array(
                    'data' => array(),
                    'total' => 0,
                    'pages' => 0,
                    'current_page' => $page,
                    'upgrade_notice' => sprintf(
                        __('Free plan displays up to %d entries. Upgrade to Pro for unlimited entries.', 'tc-data-tables'),
                        TC_FREE_MAX_ENTRIES
                    ),
                    'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
                );
            }

            // Adjust per_page if it would exceed limit
            if ($start_entry + $per_page > $max_entries_allowed) {
                // @codeCoverageIgnoreStart
                $per_page = $max_entries_allowed - $start_entry;
                // @codeCoverageIgnoreEnd
            }
        }

        // error_log("GT AJAX: get_gravity_forms_entries called with sort_field: $sort_field, sort_order: $sort_order");
        // error_log("GT AJAX: Columns: " . print_r($columns, true));

        // Get field types and date field formats from the form for date formatting.
        // #1669 — fetch the form ONCE here and reuse it for the name-field
        // handling below (it used to be fetched a second time a few lines down).
        $field_types = array();
        $date_fields = array();
        $form = (class_exists('GFAPI')) ? GFAPI::get_form($form_id) : null;
        if ($form && !is_wp_error($form) && isset($form['fields'])) {
            $date_service = new TC_Date_Service();
            foreach ($form['fields'] as $field) {
                $field_types[strval($field->id)] = $field->type;
                if ($field->type === 'date') {
                    // @codeCoverageIgnoreStart
                    $date_fields[strval($field->id)] = $date_service->getFieldDateFormat($form_id, strval($field->id));
                    // @codeCoverageIgnoreEnd
                }
            }
        }

        $offset = ($page - 1) * $per_page;

        // Build dynamic query based on requested columns
        $select_fields = array("e.id as entry_id");

        // Add date_created if it's in the columns
        if (in_array('date_created', $columns)) {
            $select_fields[] = "e.date_created";
        }

        // Add created_by (user) if it's in the columns
        if (in_array('created_by', $columns)) {
            $select_fields[] = "e.created_by";
        }

        // Add ip (user IP) if it's in the columns
        if (in_array('ip', $columns)) {
            $select_fields[] = "e.ip";
        }

        // Get form structure to handle name fields dynamically.
        // #1669 — reuse the $form already fetched above (was re-fetched here).

        // Add column fields to select
        foreach ($columns as $field_id) {
            if ($field_id === 'date_created' || $field_id === 'entry_id' || $field_id === 'created_by' || $field_id === 'ip')
                continue;

            // Check if this is a name field that needs special handling
            $is_name_field = false;
            $name_components = array();

            if ($form && !is_wp_error($form) && isset($form['fields'])) {
                $base_field_id = strval(intval(floatval($field_id)));
                foreach ($form['fields'] as $field) {
                    if (strval($field->id) === $base_field_id && $field->type === 'name') {
                        $is_name_field = true;

                        // Get configured name field components
                        if (isset($field->inputs) && is_array($field->inputs)) {
                            foreach ($field->inputs as $input) {
                                if (
                                    !empty($input['id']) && !empty($input['label']) &&
                                    (!isset($input['isHidden']) || !$input['isHidden'])
                                ) {
                                    $name_components[$input['id']] = $input['label'];
                                }
                            }
                        } else {
                            // Simple name field - just use the main field ID
                            // @codeCoverageIgnoreStart
                            $name_components[$base_field_id] = $field->label;
                            // @codeCoverageIgnoreEnd
                        }
                        break;
                    }
                }
            }

            if ($is_name_field && !empty($name_components)) {
                // For name fields, get all configured sub-components.
                // #1629 — these component IDs come from the GF form
                // definition (not the request), but validate them anyway
                // so this interpolation can never become a sink if the
                // upstream source changes.
                foreach ($name_components as $component_id => $label) {
                    if (!TC_SQL_Guard::is_safe_field_id((string) $component_id)) {
                        continue;
                    }
                    $field_alias = "field_" . str_replace('.', '_', $component_id);
                    $select_fields[] = "MAX(CASE WHEN em.meta_key = '{$component_id}' THEN em.meta_value END) as {$field_alias}";
                }
            } else {
                // Regular field handling - ensure aliases don't have dots (invalid SQL)
                $field_alias = "field_" . str_replace('.', '_', $field_id);
                $select_fields[] = "MAX(CASE WHEN em.meta_key = '{$field_id}' THEN em.meta_value END) as {$field_alias}";
            }
        }

        // Determine status filter based on table configuration
        $status_filter = "e.status = 'active'";
        if (
            $table_config && isset($table_config['show_deleted_entries']) &&
            ($table_config['show_deleted_entries'] === true || $table_config['show_deleted_entries'] === 'true')
        ) {
            $status_filter = "e.status IN ('active', 'trash')";
            // error_log('GT AJAX: SHOW DELETED ENTRIES = TRUE - Including deleted entries in query');
        } else {
            // error_log('GT AJAX: SHOW DELETED ENTRIES = FALSE - Only showing active entries');
        }

        $query = "
            SELECT " . implode(', ', $select_fields) . "
            FROM {$wpdb->prefix}gf_entry e
            LEFT JOIN {$wpdb->prefix}gf_entry_meta em ON e.id = em.entry_id
            WHERE e.form_id = %d AND {$status_filter}
        ";

        $params = array($form_id);

        // Add user filtering based on table configuration. #2091 — admins
        // (manage_options) are EXEMPT: drivers see only their own entries, but an
        // admin who manages the table sees all of them.
        if (
            $table_config && isset($table_config['filter_user_entries']) &&
            ($table_config['filter_user_entries'] === true || $table_config['filter_user_entries'] === 'true') &&
            !current_user_can('manage_options')
        ) {
            $current_user_id = get_current_user_id();
            if ($current_user_id > 0) {
                // Filter by created_by field - the user who created the entry
                $query .= " AND e.created_by = %d";
                $params[] = $current_user_id;
                // error_log('GT AJAX: FILTER USER ENTRIES = TRUE - Filtering to user ID: ' . $current_user_id);
            } else {
                // error_log('GT AJAX: FILTER USER ENTRIES = TRUE - But no current user ID found');
            }
        } else {
            // error_log('GT AJAX: FILTER USER ENTRIES = FALSE - Showing all users entries');
            // error_log('GT AJAX: table_config exists: ' . ($table_config ? 'YES' : 'NO'));
            if ($table_config) {
                // error_log('GT AJAX: filter_user_entries isset: ' . (isset($table_config['filter_user_entries']) ? 'YES' : 'NO'));
                // error_log('GT AJAX: filter_user_entries value: ' . var_export($table_config['filter_user_entries'] ?? 'NOT SET', true));
            }
        }

        // Add search filters
        if (!empty($search)) {
            $fuzzy_enabled = is_array($table_config) && TC_Fuzzy_Search_Service::is_enabled($table_config);
            if ($fuzzy_enabled) {
                // @codeCoverageIgnoreStart
                $meta_where = TC_Fuzzy_Search_Service::build_meta_where($wpdb, $search);
                $query .= " AND (
                // @codeCoverageIgnoreEnd
                    EXISTS (
                        // @codeCoverageIgnoreStart
                        SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_search
                        WHERE em_search.entry_id = e.id AND ({$meta_where['sql']})
                        // @codeCoverageIgnoreEnd
                    ) OR
                    e.date_created LIKE %s OR
                    CAST(e.id AS CHAR) LIKE %s
                // @codeCoverageIgnoreStart
                )";
                foreach ($meta_where['params'] as $p) { $params[] = $p; }
                $params[] = '%' . $wpdb->esc_like($search) . '%';
                $params[] = '%' . $wpdb->esc_like($search) . '%';
                // @codeCoverageIgnoreEnd
            } else {
                $query .= " AND (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_search
                        WHERE em_search.entry_id = e.id AND em_search.meta_value LIKE %s
                    ) OR
                    e.date_created LIKE %s OR
                    CAST(e.id AS CHAR) LIKE %s
                )";
                $search_term = '%' . $wpdb->esc_like($search) . '%';
                $params[] = $search_term;
                $params[] = $search_term;
                $params[] = $search_term;
            }
        }

        if (!empty($user_filter)) {
            $query .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em2 
                WHERE em2.entry_id = e.id AND em2.meta_value = %s
            )";
            $params[] = $user_filter;
        }

        if (!empty($date_from)) {
            $query .= " AND e.date_created >= %s";
            $params[] = $date_from . ' 00:00:00';
        }

        if (!empty($date_to)) {
            $query .= " AND e.date_created <= %s";
            $params[] = $date_to . ' 23:59:59';
        }

        // #568 slice 4 — drilldown filters (chips) integration.
        // These are AND-combined: every active chip must match.
        if (!empty($drilldown_filters)) {
            foreach ($drilldown_filters as $df) {
                if (!is_array($df) || empty($df['col'])) { continue; }
                $val = isset($df['value']) ? (string) $df['value'] : '';
                $query .= " AND EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_dd
                    WHERE em_dd.entry_id = e.id 
                    AND em_dd.meta_key = %s 
                    AND em_dd.meta_value = %s
                )";
                $params[] = (string) $df['col'];
                $params[] = $val;
            }
        }

        // Add custom filters
        if (!empty($filters)) {
            $debug->log('lookup', 'Processing filters array', $filters);
            foreach ($filters as $field_id => $filter_data) {
                // Add debug to see exactly what we're processing
                $debug->log('lookup', "Processing filter for field $field_id", array(
                    'filter_data' => $filter_data,
                    'is_array' => is_array($filter_data),
                    'has_type' => is_array($filter_data) && isset($filter_data['type'])
                ));

                if (is_array($filter_data) && isset($filter_data['type'])) {
                    // error_log('GT AJAX: Processing filter for field ' . $field_id . ' type: ' . $filter_data['type'] . ' data: ' . print_r($filter_data, true));
                    switch ($filter_data['type']) {
                        case 'date_range':
                            $debug->log('filtering', "Processing date_range filter for field $field_id", array(
                                'from' => $filter_data['from'] ?? 'empty',
                                'to' => $filter_data['to'] ?? 'empty',
                                'full_filter_data' => $filter_data
                            ));

                            // Debug: Check what values exist for this field.
                            // #1670 — gated behind the 'filtering' debug category so
                            // this meta scan no longer runs on every date_range filter
                            // (it was unconditional; the log call below already no-ops
                            // when disabled, but the query still fired).
                            if ($debug->is_enabled('filtering')) {
                                $debug_values = $wpdb->get_results($wpdb->prepare(
                                    "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE meta_key = %s LIMIT 5",
                                    $field_id
                                ));
                                $debug->log('filtering', "Sample values for field $field_id", $debug_values);
                            }

                            if (!empty($filter_data['from'])) {
                                // Normalize filter date to match database format (add leading zeros)
                                $normalized_from = $this->normalize_date_format($filter_data['from']);
                                $debug->log('filtering', "Date FROM filter", array(
                                    'original' => $filter_data['from'],
                                    'normalized' => $normalized_from
                                ));

                                // Handle multiple date formats in database: MM/DD/YYYY, M/D/YYYY, and YYYY-MM-DD
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_date WHERE em_date.entry_id = e.id AND em_date.meta_key = %s AND 
                                    (STR_TO_DATE(em_date.meta_value, '%%m/%%d/%%Y') >= STR_TO_DATE(%s, '%%m/%%d/%%Y') OR 
                                     STR_TO_DATE(em_date.meta_value, '%%Y-%%m-%%d') >= STR_TO_DATE(%s, '%%m/%%d/%%Y')))";
                                $params[] = $field_id;
                                $params[] = $normalized_from;
                                $params[] = $normalized_from;
                            }
                            if (!empty($filter_data['to'])) {
                                // Normalize filter date to match database format (add leading zeros)
                                $normalized_to = $this->normalize_date_format($filter_data['to']);
                                $debug->log('filtering', "Date TO filter", array(
                                    'original' => $filter_data['to'],
                                    'normalized' => $normalized_to
                                ));

                                // Handle multiple date formats in database: MM/DD/YYYY, M/D/YYYY, and YYYY-MM-DD
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_date WHERE em_date.entry_id = e.id AND em_date.meta_key = %s AND 
                                    (STR_TO_DATE(em_date.meta_value, '%%m/%%d/%%Y') <= STR_TO_DATE(%s, '%%m/%%d/%%Y') OR 
                                     STR_TO_DATE(em_date.meta_value, '%%Y-%%m-%%d') <= STR_TO_DATE(%s, '%%m/%%d/%%Y')))";
                                $params[] = $field_id;
                                $params[] = $normalized_to;
                                $params[] = $normalized_to;
                            }
                            break;

                        case 'number_range':
                            if (!empty($filter_data['min']) && is_numeric($filter_data['min'])) {
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_num WHERE em_num.entry_id = e.id AND em_num.meta_key = %s AND CAST(em_num.meta_value AS DECIMAL(10,2)) >= %f)";
                                $params[] = $field_id;
                                $params[] = floatval($filter_data['min']);
                            }
                            if (!empty($filter_data['max']) && is_numeric($filter_data['max'])) {
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_num WHERE em_num.entry_id = e.id AND em_num.meta_key = %s AND CAST(em_num.meta_value AS DECIMAL(10,2)) <= %f)";
                                $params[] = $field_id;
                                $params[] = floatval($filter_data['max']);
                            }
                            break;

                        case 'text':
                            if (!empty($filter_data['value'])) {
                                // Look up the saved per-column filter config
                                // for case_sensitive / exact_match flags. The
                                // admin UI saves these in filter_configurations
                                // (admin/views/table-builder.php line 411-419)
                                // — until #650 these flags were inert; this
                                // wires them through to the SQL.
                                $cfg = $table_config['filter_configurations'][$field_id] ?? array();
                                $case_sensitive = !empty($cfg['case_sensitive']);
                                $exact_match    = !empty($cfg['exact_match']);

                                $value = is_array($filter_data['value']) ? implode(' ', $filter_data['value']) : $filter_data['value'];

                                if ($exact_match) {
                                    // Exact-match mode: meta_value = $value
                                    // (LIKE BINARY when case-sensitive; = is
                                    // already collation-bound which is
                                    // case-insensitive by default).
                                    $op = $case_sensitive ? 'LIKE BINARY' : '=';
                                    $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_text WHERE em_text.entry_id = e.id AND em_text.meta_key = %s AND em_text.meta_value {$op} %s)";
                                    $params[] = $field_id;
                                    $params[] = $value;
                                } else {
                                    // Substring mode: LIKE %value%, optionally
                                    // BINARY for case-sensitive matching.
                                    $op = $case_sensitive ? 'LIKE BINARY' : 'LIKE';
                                    $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_text WHERE em_text.entry_id = e.id AND em_text.meta_key = %s AND em_text.meta_value {$op} %s)";
                                    $params[] = $field_id;
                                    $params[] = '%' . $wpdb->esc_like($value) . '%';
                                }
                            }
                            break;

                        case 'dropdown':
                            // Handle both single and multiple dropdown selections
                            if (!empty($filter_data['value'])) {
                                // Single selection - apply filter to process value (e.g., convert driver names to IDs)
                                $processed_value = apply_filters('gt_process_filter_value', $filter_data['value'], $field_id, $filter_data);
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_dropdown WHERE em_dropdown.entry_id = e.id AND em_dropdown.meta_key = %s AND em_dropdown.meta_value = %s)";
                                $params[] = $field_id;
                                $params[] = $processed_value;
                            } elseif (!empty($filter_data['values']) && is_array($filter_data['values'])) {
                                // Multiple selections - apply filter to each value
                                $processed_values = array();
                                foreach ($filter_data['values'] as $value) {
                                    $processed_values[] = apply_filters('gt_process_filter_value', $value, $field_id, $filter_data);
                                }
                                $placeholders = implode(', ', array_fill(0, count($processed_values), '%s'));
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_dropdown WHERE em_dropdown.entry_id = e.id AND em_dropdown.meta_key = %s AND em_dropdown.meta_value IN ($placeholders))";
                                $params[] = $field_id;
                                foreach ($processed_values as $value) {
                                    $params[] = $value;
                                }
                            }
                            break;

                        case 'checkboxes':
                            // Handle multiple checkbox selections
                            if (!empty($filter_data['values']) && is_array($filter_data['values'])) {
                                $placeholders = implode(', ', array_fill(0, count($filter_data['values']), '%s'));
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_multi WHERE em_multi.entry_id = e.id AND em_multi.meta_key = %s AND em_multi.meta_value IN ($placeholders))";
                                $params[] = $field_id;
                                foreach ($filter_data['values'] as $value) {
                                    $params[] = $value;
                                }
                            }
                            break;

                        case 'lookup':
                            $debug->log('lookup', 'Processing lookup filter - SIMPLIFIED VERSION', array(
                                'field_id' => $field_id,
                                'filter_value' => $filter_data['value']
                            ));

                            if (!empty($filter_data['value'])) {
                                // Simple exact match for now to get it working
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_lookup WHERE em_lookup.entry_id = e.id AND em_lookup.meta_key = %s AND em_lookup.meta_value = %s)";
                                $params[] = $field_id;
                                $params[] = $filter_data['value'];
                                $debug->log('lookup', 'Added simple lookup filter to query', array(
                                    'field_id' => $field_id,
                                    'value' => $filter_data['value']
                                ));
                            }
                            break;

                        case 'lookup_name':
                            // #1681 — per-column free-text filter on a lookup
                            // column: match the typed display name to lookup IDs.
                            if (!empty($filter_data['value'])) {
                                $query .= $this->build_lookup_name_predicate(
                                    (string) $field_id,
                                    $filter_data['value'],
                                    $lookup_fields,
                                    $form_id,
                                    $params
                                );
                            }
                            break;

                        default:
                            if (!empty($filter_data['value'])) {
                                // Handle both string and array values for text filters
                                $value = is_array($filter_data['value']) ? implode(' ', $filter_data['value']) : $filter_data['value'];
                                $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_text WHERE em_text.entry_id = e.id AND em_text.meta_key = %s AND em_text.meta_value LIKE %s)";
                                $params[] = $field_id;
                                $params[] = '%' . $wpdb->esc_like($value) . '%';
                            }
                            break;
                    }
                }
            }
        }

        $query .= " GROUP BY e.id";

        // #565 slice 2 — multi-column ORDER BY. Build a stack of sort clauses
        // (each one shaped by the same per-field branching logic that lived
        // inline pre-v4.9.x) and implode them. Single-entry stacks produce
        // identical SQL to the legacy single-sort path; multi-entry stacks
        // (from sort_stack POST validated via TC_Multi_Sort_Service::validate_sort_stack
        // and injected into $table_config['_sort_stack']) get real
        // tiebreakers applied at the database level.
        $gt_sort_stack_for_sql = (isset($table_config['_sort_stack']) && is_array($table_config['_sort_stack']) && !empty($table_config['_sort_stack']))
            ? $table_config['_sort_stack']
            : array(array('column_id' => $sort_field, 'direction' => $sort_order));

        $gt_order_by_parts = array();
        foreach ($gt_sort_stack_for_sql as $gt_stack_entry) {
            if (!is_array($gt_stack_entry) || !isset($gt_stack_entry['column_id'], $gt_stack_entry['direction'])) {
                continue;
            }
            $gt_part = $this->build_single_sort_clause(
                (string) $gt_stack_entry['column_id'],
                (string) $gt_stack_entry['direction'],
                $columns,
                $lookup_fields,
                $date_fields
            );
            if ($gt_part !== '') {
                $gt_order_by_parts[] = $gt_part;
            }
        }
        if (empty($gt_order_by_parts)) {
            // Defensive fallback: every clause was rejected. Match the
            // pre-refactor "field not in columns" behavior.
            $gt_order_by_parts[] = "e.date_created DESC";
        }
        $query .= " ORDER BY " . implode(', ', $gt_order_by_parts);

        // error_log("GT AJAX: Final query: $query");
        // error_log("GT AJAX: Query params: " . print_r($params, true));

        // Log simpler debug info with more accurate placeholder counting
        $placeholder_count = $this->count_wp_placeholders($query);
        // error_log("GT AJAX: Query has " . $placeholder_count . " placeholders (%d/%s/%f), " . count($params) . " params");
        // error_log("GT AJAX: Raw % count: " . substr_count($query, '%') . " (includes date formats)");

        // Get total count for pagination
        $count_query = "
            SELECT COUNT(DISTINCT e.id) as total
            FROM {$wpdb->prefix}gf_entry e
            LEFT JOIN {$wpdb->prefix}gf_entry_meta em ON e.id = em.entry_id
            WHERE e.form_id = %d AND {$status_filter}
        ";

        $count_params = array($form_id);

        // Add user filtering to count query. #2091 — admins are exempt (see all).
        if (
            $table_config && isset($table_config['filter_user_entries']) &&
            ($table_config['filter_user_entries'] === true || $table_config['filter_user_entries'] === 'true') &&
            !current_user_can('manage_options')
        ) {
            $current_user_id = get_current_user_id();
            if ($current_user_id > 0) {
                // Filter by created_by field - the user who created the entry
                $count_query .= " AND e.created_by = %d";
                $count_params[] = $current_user_id;
                // error_log('GT AJAX: COUNT QUERY - FILTER USER ENTRIES = TRUE - Filtering to user ID: ' . $current_user_id);
            }
        } else {
            // error_log('GT AJAX: COUNT QUERY - FILTER USER ENTRIES = FALSE - Counting all users entries');
        }

        // Apply same filters for count
        if (!empty($search)) {
            $fuzzy_enabled = is_array($table_config) && TC_Fuzzy_Search_Service::is_enabled($table_config);
            if ($fuzzy_enabled) {
                // @codeCoverageIgnoreStart
                $meta_where = TC_Fuzzy_Search_Service::build_meta_where($wpdb, $search);
                $count_query .= " AND (
                // @codeCoverageIgnoreEnd
                    EXISTS (
                        // @codeCoverageIgnoreStart
                        SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_search
                        WHERE em_search.entry_id = e.id AND ({$meta_where['sql']})
                        // @codeCoverageIgnoreEnd
                    ) OR
                    e.date_created LIKE %s OR
                    CAST(e.id AS CHAR) LIKE %s
                // @codeCoverageIgnoreStart
                )";
                foreach ($meta_where['params'] as $p) { $count_params[] = $p; }
                $count_params[] = '%' . $wpdb->esc_like($search) . '%';
                $count_params[] = '%' . $wpdb->esc_like($search) . '%';
                // @codeCoverageIgnoreEnd
            } else {
                $count_query .= " AND (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_search
                        WHERE em_search.entry_id = e.id AND em_search.meta_value LIKE %s
                    ) OR
                    e.date_created LIKE %s OR
                    CAST(e.id AS CHAR) LIKE %s
                )";
                $search_term = '%' . $wpdb->esc_like($search) . '%';
                $count_params[] = $search_term;
                $count_params[] = $search_term;
                $count_params[] = $search_term;
            }
        }

        if (!empty($user_filter)) {
            $count_query .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em2 
                WHERE em2.entry_id = e.id AND em2.meta_value = %s
            )";
            $count_params[] = $user_filter;
        }

        if (!empty($date_from)) {
            $count_query .= " AND e.date_created >= %s";
            $count_params[] = $date_from . ' 00:00:00';
        }

        if (!empty($date_to)) {
            $count_query .= " AND e.date_created <= %s";
            $count_params[] = $date_to . ' 23:59:59';
        }

        // #568 slice 4 — drilldown filters (chips) integration (count parity).
        if (!empty($drilldown_filters)) {
            foreach ($drilldown_filters as $df) {
                if (!is_array($df) || empty($df['col'])) { continue; }
                $val = isset($df['value']) ? (string) $df['value'] : '';
                $count_query .= " AND EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_dd
                    WHERE em_dd.entry_id = e.id 
                    AND em_dd.meta_key = %s 
                    AND em_dd.meta_value = %s
                )";
                $count_params[] = (string) $df['col'];
                $count_params[] = $val;
            }
        }

        // Add custom filters to count query
        if (!empty($filters)) {
            foreach ($filters as $field_id => $filter_data) {
                if (is_array($filter_data) && isset($filter_data['type'])) {
                    switch ($filter_data['type']) {
                        case 'date_range':
                            if (!empty($filter_data['from'])) {
                                // Normalize filter date to match database format (add leading zeros)
                                $normalized_from = $this->normalize_date_format($filter_data['from']);
                                $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_date WHERE em_date.entry_id = e.id AND em_date.meta_key = %s AND 
                                    (STR_TO_DATE(em_date.meta_value, '%%m/%%d/%%Y') >= STR_TO_DATE(%s, '%%m/%%d/%%Y') OR 
                                     STR_TO_DATE(em_date.meta_value, '%%Y-%%m-%%d') >= STR_TO_DATE(%s, '%%m/%%d/%%Y')))";
                                $count_params[] = $field_id;
                                $count_params[] = $normalized_from;
                                $count_params[] = $normalized_from;
                            }
                            if (!empty($filter_data['to'])) {
                                // Normalize filter date to match database format (add leading zeros)
                                $normalized_to = $this->normalize_date_format($filter_data['to']);
                                $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_date WHERE em_date.entry_id = e.id AND em_date.meta_key = %s AND 
                                    (STR_TO_DATE(em_date.meta_value, '%%m/%%d/%%Y') <= STR_TO_DATE(%s, '%%m/%%d/%%Y') OR 
                                     STR_TO_DATE(em_date.meta_value, '%%Y-%%m-%%d') <= STR_TO_DATE(%s, '%%m/%%d/%%Y')))";
                                $count_params[] = $field_id;
                                $count_params[] = $normalized_to;
                                $count_params[] = $normalized_to;
                            }
                            break;

                        case 'number_range':
                            if (!empty($filter_data['min']) && is_numeric($filter_data['min'])) {
                                $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_num WHERE em_num.entry_id = e.id AND em_num.meta_key = %s AND CAST(em_num.meta_value AS DECIMAL(10,2)) >= %f)";
                                $count_params[] = $field_id;
                                $count_params[] = floatval($filter_data['min']);
                            }
                            if (!empty($filter_data['max']) && is_numeric($filter_data['max'])) {
                                $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_num WHERE em_num.entry_id = e.id AND em_num.meta_key = %s AND CAST(em_num.meta_value AS DECIMAL(10,2)) <= %f)";
                                $count_params[] = $field_id;
                                $count_params[] = floatval($filter_data['max']);
                            }
                            break;

                        case 'text':
                            if (!empty($filter_data['value'])) {
                                // For text filters, use LIKE comparison
                                $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_text WHERE em_text.entry_id = e.id AND em_text.meta_key = %s AND em_text.meta_value LIKE %s)";
                                $count_params[] = $field_id;
                                $count_params[] = '%' . $wpdb->esc_like($filter_data['value']) . '%';
                            }
                            break;

                        case 'dropdown':
                        case 'checkboxes':
                            if (!empty($filter_data['values']) && is_array($filter_data['values'])) {
                                // For dropdown/checkboxes, use IN clause
                                $placeholders = implode(', ', array_fill(0, count($filter_data['values']), '%s'));
                                $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_multi WHERE em_multi.entry_id = e.id AND em_multi.meta_key = %s AND em_multi.meta_value IN ($placeholders))";
                                $count_params[] = $field_id;
                                foreach ($filter_data['values'] as $value) {
                                    $count_params[] = $value;
                                }
                            }
                            break;

                        case 'lookup':
                            $debug->log('lookup', 'Processing lookup filter', array(
                                'field_id' => $field_id,
                                'filter_value' => $filter_data['value']
                            ));
                            if (!empty($filter_data['value'])) {
                                // Check if this is a user lookup field and get the lookup config
                                $lookup_config = isset($lookup_fields[$field_id]) ? $lookup_fields[$field_id] : null;
                                $debug->log('lookup', 'Lookup config for field ' . $field_id, $lookup_config);

                                if ($lookup_config && $lookup_config['type'] === 'user') {
                                    // For user lookup fields, handle both user IDs and display names
                                    // Get the user's display name to match against stored values
                                    $user = get_user_by('ID', $filter_data['value']);
                                    if ($user) {
                                        // @codeCoverageIgnoreStart
                                        $user_display_name = $user->display_name;
                                        // @codeCoverageIgnoreEnd
                                        // Try to match either the user ID (if stored) or the display name (if stored)
                                        // @codeCoverageIgnoreStart
                                        $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_lookup WHERE em_lookup.entry_id = e.id AND em_lookup.meta_key = %s AND (em_lookup.meta_value = %s OR em_lookup.meta_value = %s))";
                                        $count_params[] = $field_id;
                                        $count_params[] = $filter_data['value']; // User ID
                                        $count_params[] = $user_display_name; // Display name
                                        // @codeCoverageIgnoreEnd
                                    } else {
                                        // User not found, try exact match
                                        $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_lookup WHERE em_lookup.entry_id = e.id AND em_lookup.meta_key = %s AND em_lookup.meta_value = %s)";
                                        $count_params[] = $field_id;
                                        $count_params[] = $filter_data['value'];
                                    }
                                } else {
                                    // For non-user lookup fields, filter by the exact stored value (ID)
                                    $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_lookup WHERE em_lookup.entry_id = e.id AND em_lookup.meta_key = %s AND em_lookup.meta_value = %s)";
                                    $count_params[] = $field_id;
                                    $count_params[] = $filter_data['value'];
                                }
                            }
                            break;

                        case 'lookup_name':
                            // #1681 — count-query mirror of the per-column
                            // lookup-name filter (must match the data query so
                            // pagination totals stay correct).
                            if (!empty($filter_data['value'])) {
                                $count_query .= $this->build_lookup_name_predicate(
                                    (string) $field_id,
                                    $filter_data['value'],
                                    $lookup_fields,
                                    $form_id,
                                    $count_params
                                );
                            }
                            break;

                        default:
                            if (!empty($filter_data['value'])) {
                                // Handle both string and array values for text filters
                                $value = is_array($filter_data['value']) ? implode(' ', $filter_data['value']) : $filter_data['value'];
                                $count_query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_text WHERE em_text.entry_id = e.id AND em_text.meta_key = %s AND em_text.meta_value LIKE %s)";
                                $count_params[] = $field_id;
                                $count_params[] = '%' . $wpdb->esc_like($value) . '%';
                            }
                            break;
                    }
                }
            }
        }

        // Debug count query parameters
        $count_placeholder_count = $this->count_wp_placeholders($count_query);
        // error_log("GT AJAX: Count query has $count_placeholder_count placeholders, " . count($count_params) . " params");

        if ($count_placeholder_count !== count($count_params)) {
            // error_log("GT AJAX: Count query parameter mismatch!");
            // error_log("GT AJAX: Count query: " . $count_query);
            // error_log("GT AJAX: Count params: " . print_r($count_params, true));
        }

        $total_count = $wpdb->get_var($wpdb->prepare($count_query, $count_params));

        if ($wpdb->last_error) {
            // error_log("GT AJAX: Count query SQL Error: " . $wpdb->last_error);
        }

        // #1733 — filtered-set server-supplied column max for Data Bars.
        // Pro-gated; runs only when column_data_bars is configured. We
        // extract the WHERE body from the already-built $count_query and
        // reuse its $count_params so the per-column MAX respects every
        // active filter (search, date range, drilldowns, custom filters,
        // user-entry gate) — the same scope the total count uses.
        $bar_column_maxes = array();
        if (
            gt_is_premium()
            && is_array($table_config)
            && !empty($table_config['column_data_bars'])
            && class_exists('TC_Data_Bars_Service')
        ) {
            // The count query has the shape:
            //   SELECT COUNT(...) FROM ... WHERE <body>
            // Extract <body> by trimming everything up to and including WHERE.
            $bar_where_sql = '';
            $where_pos = stripos($count_query, 'WHERE');
            if ($where_pos !== false) {
                $bar_where_sql = trim(substr($count_query, $where_pos + 5));
            }
            if ($bar_where_sql !== '') {
                $col_config = is_array($table_config['column_config'] ?? null)
                    ? $table_config['column_config']
                    : array();
                $bar_column_maxes = TC_Data_Bars_Service::compute_filtered_maxes(
                    (array) $table_config['column_data_bars'],
                    $col_config,
                    $bar_where_sql,
                    $count_params,
                    $wpdb
                );
            }
        }

        // Add pagination to main query
        $query .= " LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        // Debug parameter counting with accurate placeholder detection
        $final_placeholder_count = $this->count_wp_placeholders($query);
        if ($final_placeholder_count !== count($params)) {
            // error_log("GT AJAX: Query has $final_placeholder_count placeholders, " . count($params) . " params");
        }

        // Execute query with proper error handling
        try {
            $results = $wpdb->get_results($wpdb->prepare($query, $params));
            if ($wpdb->last_error) {
                // error_log("GT AJAX: SQL Error: " . $wpdb->last_error);
                $results = array();
            }
        // @codeCoverageIgnoreStart
        } catch (Exception $e) {
        // @codeCoverageIgnoreEnd
            // error_log("GT AJAX: Query execution error: " . $e->getMessage());
            // @codeCoverageIgnoreStart
            $results = array();
            // @codeCoverageIgnoreEnd
        }

        if (!is_array($results)) {
            // @codeCoverageIgnoreStart
            $results = array();
            // @codeCoverageIgnoreEnd
        }

        // error_log("GT AJAX: Main query returned " . count($results) . " results");

        if (empty($results) && $wpdb->last_error) {
            // error_log("GT AJAX: SQL Error: " . $wpdb->last_error);
        }

        // Process results
        $lookup_processor = TC_Lookup::get_instance();
        $entries = array();
        // TC_Star_Rating_Service / TC_Badge_Service per-column cell type map.
        $gt_cell_types = (is_array($table_config) && isset($table_config['column_cell_types']) && is_array($table_config['column_cell_types']))
            ? $table_config['column_cell_types']
            : array();
        // #1741 — TC_Badge_Service per-column badge maps.
        $gt_badge_maps = (is_array($table_config) && isset($table_config['column_badge_maps']) && is_array($table_config['column_badge_maps']))
            ? $table_config['column_badge_maps']
            : array();

        // --- BATCH FETCHING START ---
        // 1. Collect IDs
        $batch_user_ids = array();
        $batch_lookup_values = array(); // [field_id => [values]]

        foreach ($results as $row) {
            // Collect User IDs
            if (in_array('created_by', $columns) && !empty($row->created_by)) {
                $batch_user_ids[] = $row->created_by;
            }

            // Collect Lookup Values
            foreach ($lookup_fields as $field_id => $config) {
                // Check if row has this field. The query pivots meta to column with field_id name.
                // However, field_id might be "1.3", but column name might be "1.3" or "1_3" depending on pivot?
                // The pivot query uses: MAX(CASE WHEN em.meta_key = '{$field_id}' ... ) as `{$field_id}`
                // So row property name matches field_id.
                if (isset($row->$field_id)) {
                    $batch_lookup_values[$field_id][] = $row->$field_id;
                // @codeCoverageIgnoreStart
                } elseif ($field_id === 'created_by' && isset($row->created_by)) {
                    $batch_lookup_values['created_by'][] = $row->created_by;
                // @codeCoverageIgnoreEnd
                }
            }
        }

        // 2. Fetch Users
        $user_cache = array();
        if (!empty($batch_user_ids)) {
            $batch_user_ids = array_unique($batch_user_ids);
            // Verify get_users is not empty array to avoid getting all users
            if (count($batch_user_ids) > 0) {
                $fetched_users = get_users(['include' => $batch_user_ids]);
                foreach ($fetched_users as $u) {
                    $user_cache[$u->ID] = $u;
                }
            }
        }

        // 3. Fetch Lookups
        $lookup_cache = array();
        foreach ($batch_lookup_values as $field_id => $values) {
            if (empty($values))
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            $config = $lookup_fields[$field_id];
            $lookup_cache[$field_id] = $lookup_processor->process_lookup_values_batch(array_unique($values), $config);
        }
        // --- BATCH FETCHING END ---
        $entries = array();

        // #1663 — build the name-field component map ONCE so the per-cell
        // render below doesn't re-scan $form['fields'] for every column of
        // every row.
        $gt_name_field_map = self::build_name_field_map($form);

        foreach ($results as $row) {
            $entry = array(
                'entry_id' => $row->entry_id
            );

            // Add entry_id if it's in the columns
            if (in_array('entry_id', $columns)) {
                $entry['entry_id'] = $row->entry_id;
            }

            // Add date_created if it's in the columns
            if (in_array('date_created', $columns)) {
                $entry['date_created'] = $this->format_date_display($row->date_created);
            }

            // Add created_by (user) if it's in the columns
            if (in_array('created_by', $columns)) {
                // Check if this field has lookup configuration
                if (!empty($lookup_fields['created_by'])) {
                    // @codeCoverageIgnoreStart
                    $lookup_config = $lookup_fields['created_by'];
                    $val = $row->created_by;
                    // @codeCoverageIgnoreEnd
                    // Use cached lookup value if available
                    // @codeCoverageIgnoreStart
                    if (isset($lookup_cache['created_by']) && isset($lookup_cache['created_by'][$val])) {
                        $entry['created_by'] = $lookup_cache['created_by'][$val];
                    // @codeCoverageIgnoreEnd
                    } else {
                        // @codeCoverageIgnoreStart
                        $entry['created_by'] = $lookup_processor->process_lookup_value($val, $lookup_config);
                        // @codeCoverageIgnoreEnd
                    }
                } else {
                    // Optimized User Display using Batch Cache
                    $user_id = $row->created_by;
                    if (empty($user_id) || $user_id == '0') {
                        $entry['created_by'] = 'Guest';
                    } elseif (isset($user_cache[$user_id])) {
                        $u = $user_cache[$user_id];
                        $entry['created_by'] = !empty($u->display_name) ? $u->display_name : $u->user_login;
                    } else {
                        // Fallback in case user not found in batch or cache missing
                        $entry['created_by'] = $this->format_user_display($user_id);
                    }
                }
            }

            // Add ip (user IP) if it's in the columns
            if (in_array('ip', $columns)) {
                $entry['ip'] = $row->ip ?? '';
            }

            // Add dynamic field values
            foreach ($columns as $field_id) {
                if ($field_id === 'date_created' || $field_id === 'entry_id' || $field_id === 'created_by' || $field_id === 'ip')
                    continue;

                // #1663 — look up name-field components from the precomputed
                // map instead of re-scanning $form['fields'] per cell.
                $base_field_id = strval(intval(floatval($field_id)));
                $is_name_field = array_key_exists($base_field_id, $gt_name_field_map);
                $name_components = $is_name_field ? $gt_name_field_map[$base_field_id] : array();

                if ($is_name_field && !empty($name_components)) {
                    // Handle name field components
                    // @codeCoverageIgnoreStart
                    if (count($name_components) === 1) {
                    // @codeCoverageIgnoreEnd
                        // Single component
                        // @codeCoverageIgnoreStart
                        $component_id = array_keys($name_components)[0];
                        $field_key = str_replace('.', '_', $component_id);
                        $field_name = "field_{$field_key}";
                        $value = isset($row->$field_name) ? $row->$field_name : '';
                        // @codeCoverageIgnoreEnd
                    } else {
                        // Multiple components - combine them intelligently
                        // @codeCoverageIgnoreStart
                        $values = array();
                        foreach ($name_components as $component_id => $label) {
                            $field_key = str_replace('.', '_', $component_id);
                            $field_name = "field_{$field_key}";
                            $component_value = isset($row->$field_name) ? $row->$field_name : '';
                            if (!empty($component_value)) {
                                $values[] = $component_value;
                        // @codeCoverageIgnoreEnd
                            }
                        }
                        // @codeCoverageIgnoreStart
                        $value = implode(' ', $values);
                        // @codeCoverageIgnoreEnd
                    }
                } else {
                    // Option 1: Use JOIN query result (potentially cached)
                    // Handle field IDs with dots (convert to underscores for SQL aliases)
                    $field_key = str_replace('.', '_', $field_id);
                    $field_name = "field_{$field_key}";
                    $value = isset($row->$field_name) ? $row->$field_name : '';
                }

                // Option 2: Fresh direct query (bypass all caching) when debug is enabled
                $debug_enabled = isset($_GET['gt_debug']) && $_GET['gt_debug'] === '1';
                if ($debug_enabled) {
                    // @codeCoverageIgnoreStart
                    $fresh_value = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                        $row->entry_id,
                        $field_id
                    ));
                    // @codeCoverageIgnoreEnd

                    // @codeCoverageIgnoreStart
                    if ($fresh_value !== $value) {
                        $this->safe_log('warning', "Data inconsistency detected for field {$field_id}", array(
                            'entry_id' => $row->entry_id,
                            'field_id' => $field_id,
                            'join_query_value' => $value,
                            'fresh_db_value' => $fresh_value,
                            'using_fresh_value' => true
                        ));
                        $value = $fresh_value; // Use the fresh value when there's a discrepancy
                    // @codeCoverageIgnoreEnd
                    }
                }

                // TC_Star_Rating_Service: intercept before lookup / date /
                // plain-text. Numeric values render as filled / half / empty
                // SVG stars with role="img" and aria-label. Non-numeric
                // values render as 0 stars (the service clamps floatval).
                if (
                    isset($gt_cell_types[$field_id])
                    && $gt_cell_types[$field_id] === 'star_rating'
                    && class_exists('TC_Star_Rating_Service')
                ) {
                    $entry[$field_id] = apply_filters(
                        'gravity_tables_column_value',
                        TC_Star_Rating_Service::render($value),
                        $field_id, $row, $table_config
                    );
                } elseif (
                    isset($gt_cell_types[$field_id])
                    && $gt_cell_types[$field_id] === 'badge'
                    && isset($gt_badge_maps[$field_id])
                    && class_exists('TC_Badge_Service')
                ) {
                    // #1741 — status badge cell type.
                    $entry[$field_id] = apply_filters(
                        'gravity_tables_column_value',
                        TC_Badge_Service::render((string)$value, $gt_badge_maps[$field_id]),
                        $field_id, $row, $table_config
                    );
                } elseif (isset($lookup_fields[$field_id])) {
                    // @codeCoverageIgnoreStart
                    $lookup_config = $lookup_fields[$field_id];
                    // @codeCoverageIgnoreEnd
                    // Use cached lookup value if available
                    // @codeCoverageIgnoreStart
                    if (isset($lookup_cache[$field_id]) && isset($lookup_cache[$field_id][$value])) {
                        $resolved = $lookup_cache[$field_id][$value];
                    // @codeCoverageIgnoreEnd
                    } else {
                        // Fallback to individual lookup (should be rare)
                        // @codeCoverageIgnoreStart
                        $resolved = $lookup_processor->process_lookup_value($value, $lookup_config);
                        // @codeCoverageIgnoreEnd
                    }
                    // @codeCoverageIgnoreStart
                    $entry[$field_id] = $this->gt_sanitize_cell_output(apply_filters('gravity_tables_column_value', $resolved, $field_id, $row, $table_config));
                    // @codeCoverageIgnoreEnd
                } else {
                    // Format date fields according to settings
                    if (!empty($value) && isset($field_types[$field_id]) && $field_types[$field_id] === 'date') {
                        // @codeCoverageIgnoreStart
                        $value = $this->format_gravity_forms_date($value, $form_id, $field_id);
                        // @codeCoverageIgnoreEnd
                    }
                    $entry[$field_id] = $this->gt_sanitize_cell_output(apply_filters('gravity_tables_column_value', $value, $field_id, $row, $table_config));
                }
            }

            $entries[] = $entry;
        }

        // #518 slice 4: pre-compute per-column rowspan-merge directives for any
        // column flagged in column_auto_merge. The JS renderer in templates/
        // table.php consumes this map to skip <td> on render=false rows and
        // emit <td rowspan="N"> on the first row of each run. Runs are scoped
        // to the current page (pagination breaks runs naturally — that's fine).
        $directives = array();
        if (
            class_exists('TC_Rowspan_Merge_Service')
            && is_array($table_config)
            && !empty($table_config['column_auto_merge'])
            && is_array($table_config['column_auto_merge'])
        ) {
            // @codeCoverageIgnoreStart
            foreach ($table_config['column_auto_merge'] as $merge_field_id => $enabled) {
                if (!$enabled) {
                    continue;
            // @codeCoverageIgnoreEnd
                }
                // @codeCoverageIgnoreStart
                $col_values = array();
                foreach ($entries as $row) {
                    $col_values[] = isset($row[$merge_field_id]) ? $row[$merge_field_id] : null;
                // @codeCoverageIgnoreEnd
                }
                // @codeCoverageIgnoreStart
                $directives[$merge_field_id] = TC_Rowspan_Merge_Service::directives($col_values);
                // @codeCoverageIgnoreEnd
            }
        }

        $result = array(
            'entries' => $entries,
            'total' => intval($total_count),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total_count / $per_page),
            'columns' => $columns,
            'directives' => $directives,
        );

        // #1733 — attach filtered-set column maxes for Data Bars when computed.
        if (!empty($bar_column_maxes)) {
            $result['bar_column_maxes'] = $bar_column_maxes;
        }

        return $result;
    }

    /**
     * #565 slice 2 — build a single ORDER BY clause (without the leading
     * "ORDER BY" keyword) for a given (sort_field, sort_order) pair. Pulls
     * the same six branches that lived inline in get_gravity_forms_entries
     * pre-v4.9.x: entry meta-columns (id / date_created / created_by / ip),
     * lookup fields (delegated to build_lookup_sort_clause), date fields
     * with STR_TO_DATE format coercion, regular text fields with
     * COLLATE utf8mb4_unicode_ci. Returns empty string when the field
     * isn't sortable so the caller can either skip the entry (multi-sort
     * loop) or fall back (single-sort caller defaults to date_created).
     */
    private function build_single_sort_clause(
        string $sort_field,
        string $sort_order,
        array $columns,
        array $lookup_fields,
        array $date_fields
    ): string {
        $direction_sql = ($sort_order === 'asc') ? 'ASC' : 'DESC';
        if ($sort_field === 'entry_id')    { return "e.id $direction_sql"; }
        if ($sort_field === 'date_created') { return "e.date_created $direction_sql"; }
        if ($sort_field === 'created_by')  { return "e.created_by $direction_sql"; }
        if ($sort_field === 'ip')          { return "e.ip $direction_sql"; }

        if (in_array($sort_field, $columns, true)) {
            if (!empty($lookup_fields[$sort_field])) {
                return $this->build_lookup_sort_clause($sort_field, $lookup_fields[$sort_field], $sort_order);
            }
            if (isset($date_fields[$sort_field])) {
                $mysql_format = $date_fields[$sort_field]['mysql_format'];
                $field_alias = "field_" . str_replace('.', '_', $sort_field);
                $escaped_mysql_format = str_replace('%', '%%', $mysql_format);
                $null_date = ($direction_sql === 'ASC' ? '1900-01-01' : '2099-12-31');
                return "CASE
						WHEN {$field_alias} = '' OR {$field_alias} IS NULL THEN '{$null_date}'
						WHEN STR_TO_DATE({$field_alias}, '{$escaped_mysql_format}') IS NOT NULL THEN STR_TO_DATE({$field_alias}, '{$escaped_mysql_format}')
						WHEN STR_TO_DATE({$field_alias}, '%%m/%%d/%%Y') IS NOT NULL THEN STR_TO_DATE({$field_alias}, '%%m/%%d/%%Y')
						WHEN STR_TO_DATE({$field_alias}, '%%d/%%m/%%Y') IS NOT NULL THEN STR_TO_DATE({$field_alias}, '%%d/%%m/%%Y')
						WHEN STR_TO_DATE({$field_alias}, '%%Y-%%m-%%d') IS NOT NULL THEN STR_TO_DATE({$field_alias}, '%%Y-%%m-%%d')
						WHEN STR_TO_DATE({$field_alias}, '%%m-%%d-%%Y') IS NOT NULL THEN STR_TO_DATE({$field_alias}, '%%m-%%d-%%Y')
						WHEN STR_TO_DATE({$field_alias}, '%%d-%%m-%%Y') IS NOT NULL THEN STR_TO_DATE({$field_alias}, '%%d-%%m-%%Y')
						ELSE '{$null_date}'
					END {$direction_sql}";
            }
            $sort_field_safe = str_replace('.', '_', $sort_field);
            return "field_{$sort_field_safe} COLLATE utf8mb4_unicode_ci $direction_sql";
        }
        // Field not in columns — caller decides whether to skip or fall back.
        return '';
    }

    /**
     * Build sorting clause for lookup fields to sort by visible values instead of stored IDs
     */
    private function build_lookup_sort_clause(string $field_id, array $lookup_config, string $sort_order): string
    {
        global $wpdb;

        // #1630 — $field_id is interpolated into the meta_key literal of
        // every branch below. It must be a valid GF field ID; if not, omit
        // this sort component entirely (caller treats '' as "skip").
        if (!TC_SQL_Guard::is_safe_field_id($field_id)) {
            return '';
        }

        $order = ($sort_order === 'asc') ? 'ASC' : 'DESC';

        switch ($lookup_config['type']) {
            case 'user':
                $user_field = isset($lookup_config['user_field']) ? $lookup_config['user_field'] : 'display_name';

                switch ($user_field) {
                    case 'first_name':
                    case 'last_name':
                        return "(SELECT um.meta_value FROM {$wpdb->usermeta} um 
                                JOIN {$wpdb->prefix}gf_entry_meta em_sort ON um.user_id = em_sort.meta_value 
                                WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' AND um.meta_key = '{$user_field}' LIMIT 1) {$order}";
                    case 'user_email':
                        return "(SELECT u.user_email FROM {$wpdb->users} u 
                                JOIN {$wpdb->prefix}gf_entry_meta em_sort ON u.ID = em_sort.meta_value 
                                WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' LIMIT 1) {$order}";
                    case 'user_login':
                        return "(SELECT u.user_login FROM {$wpdb->users} u 
                                JOIN {$wpdb->prefix}gf_entry_meta em_sort ON u.ID = em_sort.meta_value 
                                WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' LIMIT 1) {$order}";
                    case 'display_name':
                    default:
                        // Handle both user IDs and display names stored in the field
                        // First try to sort by user lookup (if field contains user IDs)
                        // Fall back to direct field value sorting (if field contains display names)
                        return "(COALESCE(
                                    (SELECT u.display_name FROM {$wpdb->users} u 
                                     JOIN {$wpdb->prefix}gf_entry_meta em_sort ON u.ID = em_sort.meta_value 
                                     WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' 
                                     AND em_sort.meta_value REGEXP '^[0-9]+$' LIMIT 1),
                                    (SELECT em_sort.meta_value FROM {$wpdb->prefix}gf_entry_meta em_sort 
                                     WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' LIMIT 1)
                                )) {$order}";
                }

            case 'post':
                $post_field = isset($lookup_config['post_field']) ? $lookup_config['post_field'] : 'post_title';

                switch ($post_field) {
                    case 'post_content':
                        return "(SELECT p.post_content FROM {$wpdb->posts} p 
                                JOIN {$wpdb->prefix}gf_entry_meta em_sort ON p.ID = em_sort.meta_value 
                                WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' LIMIT 1) {$order}";
                    case 'post_date':
                        return "(SELECT p.post_date FROM {$wpdb->posts} p 
                                JOIN {$wpdb->prefix}gf_entry_meta em_sort ON p.ID = em_sort.meta_value 
                                WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' LIMIT 1) {$order}";
                    case 'post_title':
                    default:
                        return "(SELECT p.post_title FROM {$wpdb->posts} p 
                                JOIN {$wpdb->prefix}gf_entry_meta em_sort ON p.ID = em_sort.meta_value 
                                WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' LIMIT 1) {$order}";
                }

            case 'custom':
                if (
                    empty($lookup_config['table']) ||
                    empty($lookup_config['id_column']) ||
                    empty($lookup_config['display_column'])
                ) {
                    // Fallback to original field value if config is incomplete
                    return "MAX(CASE WHEN em.meta_key = '{$field_id}' THEN em.meta_value END) {$order}";
                }

                $table = sanitize_text_field($lookup_config['table']);
                $id_column = sanitize_text_field($lookup_config['id_column']);
                $display_column = sanitize_text_field($lookup_config['display_column']);

                // Ensure table name has proper prefix if it's a WordPress table
                if (strpos($table, $wpdb->prefix) !== 0 && in_array($table, ['posts', 'users', 'usermeta', 'postmeta', 'terms', 'term_taxonomy'])) {
                    $table = $wpdb->prefix . $table;
                }

                // #1630 — sanitize_text_field() permits SQL metacharacters,
                // so it is NOT an identifier guard. These three values are
                // interpolated into identifier positions (table/column) that
                // $wpdb->prepare() cannot parameterise. Require a strict
                // identifier allowlist; on any violation fall back to the
                // safe default (sort by the raw stored field value).
                if (!TC_SQL_Guard::is_safe_identifier($table)
                    || !TC_SQL_Guard::is_safe_identifier($id_column)
                    || !TC_SQL_Guard::is_safe_identifier($display_column)
                ) {
                    return "MAX(CASE WHEN em.meta_key = '{$field_id}' THEN em.meta_value END) {$order}";
                }

                return "(SELECT ct.{$display_column} FROM {$table} ct
                        JOIN {$wpdb->prefix}gf_entry_meta em_sort ON ct.{$id_column} = em_sort.meta_value 
                        WHERE em_sort.entry_id = e.id AND em_sort.meta_key = '{$field_id}' LIMIT 1) {$order}";

            default:
                // Fallback to original field value
                return "MAX(CASE WHEN em.meta_key = '{$field_id}' THEN em.meta_value END) {$order}";
        }
    }

    /**
     * #1671 — request-memoized gt_settings read. The per-cell date formatters
     * called get_option('gt_settings') on every row; cache it on the instance
     * so it is read at most once per request (get_option is WP-cached, but the
     * repeated call + array copy is avoidable per-row overhead).
     */
    private $gt_settings_memo = null;

    private function get_gt_settings(): array
    {
        if ($this->gt_settings_memo === null) {
            $this->gt_settings_memo = get_option('gt_settings', array());
        }
        return $this->gt_settings_memo;
    }

    private function format_date_display(string $date_value): string
    {
        if (empty($date_value)) {
            return '';
        }

        // Get user configured date format
        $gt_settings = $this->get_gt_settings();
        $date_format = isset($gt_settings['date_format']) ? $gt_settings['date_format'] : 'm/d/Y';
        $time_format = isset($gt_settings['time_format']) ? $gt_settings['time_format'] : 'g:i A';

        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date_value);
        if ($date) {
            // Check if the time is significant (not midnight)
            if ($date->format('H:i:s') !== '00:00:00') {
                return $date->format($date_format . ' ' . $time_format);
            } else {
                // Date-only field, show just the date
                return $date->format($date_format);
            }
        }

        return $date_value;
    }

    /**
     * #1662 — resolve a date field's input format once per (form, field) and
     * cache it on the instance, so the per-cell date formatter no longer
     * fetches the form and scans every field on each row.
     */
    private $date_input_format_memo = array();

    private function get_date_input_format(int $form_id, string $field_id): string
    {
        $key = $form_id . ':' . $field_id;
        if (isset($this->date_input_format_memo[$key])) {
            return $this->date_input_format_memo[$key];
        }

        $input_format = 'm/d/Y'; // Default GF format
        if (class_exists('GFAPI')) {
            $form = GFAPI::get_form($form_id);
            if ($form && !is_wp_error($form) && isset($form['fields'])) {
                foreach ($form['fields'] as $field) {
                    if (strval($field->id) === strval($field_id) && $field->type === 'date') {
                        if (isset($field->dateFormat)) {
                            switch ($field->dateFormat) {
                                case 'mdy':       $input_format = 'm/d/Y'; break;
                                case 'dmy':       $input_format = 'd/m/Y'; break;
                                case 'ymd_slash': $input_format = 'Y/m/d'; break;
                                case 'ymd_dash':  $input_format = 'Y-m-d'; break;
                                case 'ymd_dot':   $input_format = 'Y.m.d'; break;
                                case 'dmy_dash':  $input_format = 'd-m-Y'; break;
                                case 'dmy_dot':   $input_format = 'd.m.Y'; break;
                                default:          $input_format = 'm/d/Y';
                            }
                        }
                        break;
                    }
                }
            }
        }

        $this->date_input_format_memo[$key] = $input_format;
        return $input_format;
    }

    private function format_gravity_forms_date(string $date_value, int $form_id, string $field_id): string
    {
        if (empty($date_value)) {
            return '';
        }

        // Get user configured date format
        $gt_settings = $this->get_gt_settings();
        $date_format = isset($gt_settings['date_format']) ? $gt_settings['date_format'] : 'm/d/Y';


        // #1662 — resolve the field's input date format once per (form, field)
        // via a request-memoized helper instead of fetching the form and
        // scanning its fields on every date cell.
        $input_format = $this->get_date_input_format($form_id, $field_id);

        // Try to parse the date with the determined input format
        $date = DateTime::createFromFormat($input_format, $date_value);
        if ($date) {
            return $date->format($date_format);
        }

        // If that fails, try common formats as fallback
        $fallback_formats = ['m/d/Y', 'Y-m-d', 'd/m/Y', 'Y/m/d'];
        foreach ($fallback_formats as $format) {
            $date = DateTime::createFromFormat($format, $date_value);
            if ($date) {
                return $date->format($date_format);
            }
        }

        // If all else fails, return the original value
        return $date_value;
    }

    private function get_gravity_form_date_format(int $form_id, string $field_id): string
    {
        // Get the specific field configuration from Gravity Forms
        if (class_exists('GFAPI')) {
            $form = GFAPI::get_form($form_id);
            if ($form && !is_wp_error($form) && isset($form['fields'])) {
                foreach ($form['fields'] as $field) {
                    if (strval($field->id) === strval($field_id) && $field->type === 'date') {
                        // Return the date format if configured, or the default
                        return isset($field->dateFormat) ? $field->dateFormat : 'mdy';
                    }
                }
            }
        }
        return 'mdy'; // Default Gravity Forms date format
    }

    private function convert_gf_date_format_to_mysql(string $gf_format): string
    {
        // Convert Gravity Forms date format to MySQL STR_TO_DATE format
        switch ($gf_format) {
            case 'mdy':
                return '%m/%d/%Y';
            case 'dmy':
                return '%d/%m/%Y';
            case 'dmy_dash':
                return '%d-%m-%Y';
            case 'dmy_dot':
                return '%d.%m.%Y';
            case 'ymd_slash':
                return '%Y/%m/%d';
            case 'ymd_dash':
                return '%Y-%m-%d';
            case 'ymd_dot':
                return '%Y.%m.%d';
            default:
                return '%m/%d/%Y'; // Default to US format
        }
    }

    private function bulk_delete_entries(array $entry_ids): array
    {
        $deleted = 0;
        $errors = array();

        foreach ($entry_ids as $entry_id) {
            // Use proper Gravity Forms API to delete entry (not just move to trash)
            $result = GFAPI::delete_entry($entry_id);

            if (is_wp_error($result)) {
                $errors[] = sprintf('Entry %d: %s', $entry_id, $result->get_error_message());
            } else {
                $deleted++;
            }
        }

        $message = sprintf('%d entries deleted successfully', $deleted);
        if (!empty($errors)) {
            $message .= '. Errors: ' . implode(', ', $errors);
        }

        return array(
            'message' => $message,
            'deleted_count' => $deleted,
            'errors' => $errors
        );
    }

    public function delete_entry(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        // #1765 — deleting entries from the frontend is part of the premium
        // frontend-editing suite (consistent with inline edit, bulk fill, and
        // duplicate). Gate before any Gravity Forms write so a free-tier site
        // with frontend editing enabled cannot delete real entries.
        if (!gt_is_premium()) {
            wp_send_json_error(__('Frontend entry deletion is a premium feature. Please upgrade to Pro.', 'tc-data-tables'));
            return;
        }

        $entry_id = intval($_POST['entry_id'] ?? 0);

        if (!$entry_id) {
            wp_send_json_error(__('Invalid entry ID', 'tc-data-tables'));
        }

        // Check if entry exists
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry) || !$entry) {
            wp_send_json_error(__('Entry not found', 'tc-data-tables'));
        }

        // Check table access permission by getting entry's form_id
        $this->checkTableAccessPermission(intval($entry['form_id']));

        // Check if user can delete entries.
        // Allow admins/editors, OR allow anyone if the table has frontend editing enabled.
        $can_delete = current_user_can('edit_posts') || current_user_can('publish_posts') || current_user_can('delete_others_posts');
        if (!$can_delete) {
            // #1633 — bound to this entry's form + login floor. Previously
            // an unbound table_id with enable_frontend_editing let an
            // anonymous visitor delete entries from any form.
            $table_id_post = intval($_POST['table_id'] ?? 0);
            $editing_form_id = $this->frontend_editing_form_id($table_id_post);
            if ($editing_form_id > 0 && $editing_form_id === intval($entry['form_id'])) {
                $can_delete = true;
            }
        }
        if (!$can_delete) {
            wp_send_json_error(__('Insufficient permissions to delete this entry', 'tc-data-tables'));
        }

        // Use proper Gravity Forms API to delete entry (not just move to trash)
        $result = GFAPI::delete_entry($entry_id);

        if (is_wp_error($result)) {
            wp_send_json_error(__('Failed to delete entry: ', 'tc-data-tables') . $result->get_error_message());
        }

        do_action('gravity_tables_entry_deleted', $entry_id, intval($entry['form_id']));

        wp_send_json_success(array(
            'message' => 'Entry deleted successfully',
            'entry_id' => $entry_id
        ));
    }

    /**
     * Handle inline-edit file uploads for fileupload fields.
     * Validates size, MIME type (via WordPress + GF allowlist), and sanitizes filename.
     */
    public function upload_file(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        // #1069 slice 32 — capability check. Pre-fix this handler only
        // verified the nonce, so a logged-out visitor with a public-page
        // nonce could POST a file and have it written to the WP uploads
        // directory (subject to wp_check_filetype). upload_files is the
        // standard WP cap for "may write to the media library" — every
        // legitimate frontend-edit user already has it via the gforms
        // / driver / publish_posts grant in submit_new_entry. Bails
        // BEFORE any $_FILES handling.
        if (!current_user_can('upload_files')) {
            wp_send_json_error(
                array(
                    'code'    => 'gt_access_denied',
                    'message' => __('You do not have permission to upload files.', 'tc-data-tables'),
                ),
                403
            );
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(array('message' => __('No file received.', 'tc-data-tables')));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $file  = $_FILES['file'];
        $error = $file['error'] ?? UPLOAD_ERR_OK;

        // Check PHP-level upload errors (covers upload_max_filesize / post_max_size limits)
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $max = ini_get('upload_max_filesize');
            wp_send_json_error(array(
                'message' => sprintf(
                    __('The file is too large. Maximum allowed size is %s (upload_max_filesize / post_max_size).', 'tc-data-tables'),
                    $max
                ),
                'code' => 'file_too_large',
            ));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if ($error !== UPLOAD_ERR_OK) {
            wp_send_json_error(array(
                'message' => __('Upload failed with an unknown error.', 'tc-data-tables'),
                'code'    => 'upload_error_' . $error,
            ));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        // Validate MIME type via WordPress
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        if (!$check['ext'] || !$check['type']) {
            wp_send_json_error(array(
                'message' => __('File type not allowed. Please upload a permitted file type.', 'tc-data-tables'),
                'code'    => 'mime_not_allowed',
            ));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
        $mime_type = $check['type'];

        // Cross-check against GF field's allowed extensions if form_id / field_id supplied
        $form_id  = intval($_POST['form_id'] ?? 0);
        $field_id = sanitize_text_field($_POST['field_id'] ?? '');
        if ($form_id && $field_id && class_exists('GFAPI')) {
            $form  = GFAPI::get_form($form_id);
            $field = $form ? \GFFormsModel::get_field($form, $field_id) : null;
            if ($field && !empty($field->allowedExtensions)) {
                $allowed_extensions = array_map('trim', explode(',', strtolower($field->allowedExtensions)));
                if (!in_array(strtolower($check['ext']), $allowed_extensions, true)) {
                    wp_send_json_error(array(
                        'message' => sprintf(
                            __('File type not allowed by this field. Allowed types: %s.', 'tc-data-tables'),
                            implode(', ', $allowed_extensions)
                        ),
                        'code' => 'mime_not_allowed',
                    ));
                    // @codeCoverageIgnoreStart
                    return;
                    // @codeCoverageIgnoreEnd
                }
            }
        }

        // Sanitize filename (handles spaces, unicode, parentheses, hashes)
        $safe_name = sanitize_file_name($file['name']);

        // Move to WP uploads directory
        $upload_overrides = array('test_form' => false);
        $_FILES['file']['name'] = $safe_name;
        $result = wp_handle_upload($file, $upload_overrides);

        if (isset($result['error'])) {
            wp_send_json_error(array(
                'message' => $result['error'],
                'code'    => 'wp_upload_error',
            ));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        wp_send_json_success(array(
            'url'       => $result['url'],
            'file'      => $result['file'],
            'mime_type' => $mime_type,
            'name'      => $safe_name,
        ));
    }

    private function bulk_export_entries(array $entry_ids): array
    {
        // Implementation for CSV export
        $form_id = intval($_POST['form_id'] ?? 1);

        // Use Gravity Forms API to get entries
        $entries = GFAPI::get_entries($form_id, array('field_filters' => array()));

        $csv_data = array();
        $headers = array('Entry ID', 'Date Created');

        // Get form to determine fields
        $form = GFAPI::get_form($form_id);
        foreach ($form['fields'] as $field) {
            if (!in_array($field->type, array('html', 'section', 'page'))) {
                $headers[] = $field->label;
            }
        }

        $csv_data[] = $headers;

        foreach ($entries as $entry) {
            if (in_array($entry['id'], $entry_ids)) {
                $row = array($entry['id'], $entry['date_created']);

                foreach ($form['fields'] as $field) {
                    if (!in_array($field->type, array('html', 'section', 'page'))) {
                        $row[] = isset($entry[$field->id]) ? $entry[$field->id] : '';
                    }
                }

                $csv_data[] = $row;
            }
        }

        $filename = 'gravity_tables_export_' . date('Y-m-d_H-i-s') . '.csv';

        return array(
            'message' => sprintf('%d entries exported', count($entry_ids)),
            'csv_data' => $csv_data,
            'filename' => $filename
        );
    }

    private function bulk_edit_entries(array $entry_ids, array $updates): array
    {
        global $wpdb;

        $updated = 0;
        foreach ($entry_ids as $entry_id) {
            $entry_updated = false;

            foreach ($updates as $field_id => $value) {
                $sanitized_value = $this->sanitize_field_value($value, $field_id);

                $result = $wpdb->update(
                    $wpdb->prefix . 'gf_entry_meta',
                    array('meta_value' => $sanitized_value),
                    array(
                        'entry_id' => $entry_id,
                        'meta_key' => $field_id
                    ),
                    array('%s'),
                    array('%d', '%s')
                );

                if ($result !== false) {
                    $entry_updated = true;
                }
            }

            if ($entry_updated) {
                $wpdb->update(
                    $wpdb->prefix . 'gf_entry',
                    array('date_updated' => current_time('mysql')),
                    array('id' => $entry_id),
                    array('%s'),
                    array('%d')
                );
                $updated++;
            }
        }

        return array(
            'message' => sprintf('%d entries updated successfully', $updated),
            'updated_count' => $updated
        );
    }

    /**
     * Check if current user can edit a specific entry
     * Allow editing if user created the entry or has admin privileges
     */
    private function can_user_edit_entry(int $entry_id): bool
    {
        $current_user_id = get_current_user_id();
        if (!$current_user_id) {
            return false;
        }

        // Use GF's own created_by field — do NOT match arbitrary meta values
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry) || !$entry) {
            return false;
        }

        return isset($entry['created_by']) && intval($entry['created_by']) === $current_user_id;
    }

    /**
     * Check if current user can bulk edit entries
     * Allow if user has edit_posts or if all entries belong to them
     */
    private function can_user_bulk_edit_entries(array $entry_ids): bool
    {
        if (empty($entry_ids)) {
            return false;
        }

        // Check each entry individually
        foreach ($entry_ids as $entry_id) {
            if (!$this->can_user_edit_entry($entry_id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize date format to match database storage (add leading zeros)
     * Converts 9/19/2025 to 09/19/2025
     */
    private function normalize_date_format(string $date_string): string
    {
        if (empty($date_string)) {
            return $date_string;
        }

        // Split the date by /
        $parts = explode('/', $date_string);
        if (count($parts) === 3) {
            $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $year = $parts[2];

            return "{$month}/{$day}/{$year}";
        }

        return $date_string;
    }

    /**
     * Sanitize field value based on field type and content
     * Provides proper sanitization for different field types instead of just text
     */
    private function sanitize_field_value($value, string $field_id): string|array
    {
        // Only enable debugging if debug parameter is set in the current request
        $debug_enabled = isset($_POST['debug']) && $_POST['debug'] === 'true';

        if ($debug_enabled)
            $this->safe_log('debug', "Sanitizing field", array(
                'field_id' => $field_id,
                'input_value' => $value,
                'input_type' => gettype($value)
            ));
        // Handle empty values
        if ($value === '' || $value === null) {
            if ($debug_enabled)
                // @codeCoverageIgnoreStart
                $this->safe_log('debug', "Value is empty/null, returning empty string");
                // @codeCoverageIgnoreEnd
            return '';
        }

        // Handle arrays (multi-select dropdowns, multi-checkbox payloads).
        // #507: route through TC_Inline_Edit_Sanitizer so multi-select values
        // round-trip via JSON instead of being PHP-coerced to the literal "Array".
        if (is_array($value)) {
            if (class_exists('TC_Inline_Edit_Sanitizer')) {
                return TC_Inline_Edit_Sanitizer::prepare_for_storage($value);
            }
            // Defensive fallback (used only when the service file failed to load):
            // JSON-encode rather than returning the raw array, so wpdb never
            // writes the string "Array" into meta_value.
            // @codeCoverageIgnoreStart
            $clean = array_values(array_filter(array_map('sanitize_text_field', $value), 'strlen'));
            return $clean ? wp_json_encode($clean) : '';
            // @codeCoverageIgnoreEnd
        }

        // Convert to string for processing
        $value = strval($value);

        // Detect field type based on value patterns and format

        // Boolean-like values (Yes/No, True/False, 1/0)
        if (preg_match('/^(yes|no|true|false|1|0)$/i', $value)) {
            return sanitize_text_field($value);
        }

        // Email fields
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return sanitize_email($value);
        }

        // URL fields
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return esc_url_raw($value);
        }

        // Phone number (allow common phone formats)
        if (preg_match('/^[\+]?[\d\s\-\(\)\.]+$/', $value)) {
            return sanitize_text_field($value);
        }

        // Date fields (various formats)
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ||
            preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $value) ||
            preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $value)
        ) {
            return sanitize_text_field($value);
        }

        // Numeric values (integers and decimals)
        if (is_numeric($value)) {
            // @codeCoverageIgnoreStart
            return sanitize_text_field($value);
            // @codeCoverageIgnoreEnd
        }

        // If the value contains HTML, delegate to TC_Sanitization_Service::sanitize_cell_html()
        // which uses wp_kses() with an allowlist that includes:
        //   'a' => ['href' => true, 'rel' => true, 'target' => true, 'title' => true],
        //   ul, ol, li, strong, em, br, p, span, s, u, … (#132, #147, #171)
        if (strpos($value, '<') !== false) {
            if (class_exists('TC_Sanitization_Service')) {
                return (new TC_Sanitization_Service())->sanitize_cell_html($value);
            }
            // Fallback when service class is not yet loaded (e.g. during unit tests)
            // @codeCoverageIgnoreStart
            $allowed_html = [
                'a'      => ['href' => true, 'rel' => true, 'target' => true, 'title' => true],
                'strong' => [],
                'b'      => [],
                'em'     => [],
                'i'      => [],
                'u'      => [],
                's'      => [],
                'br'     => [],
                'p'      => ['style' => true],
                'span'   => ['style' => true, 'class' => true],
                'ul'     => [],
                'ol'     => [],
                'li'     => [],
                'img'    => ['src' => true, 'alt' => true, 'class' => true, 'style' => true, 'width' => true, 'height' => true, 'loading' => true],
            ];
            return wp_kses($value, $allowed_html);
            // @codeCoverageIgnoreEnd
        }

        // For multi-line plain text (no HTML tags), preserve line breaks
        if (strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
            return sanitize_textarea_field($value);
        }

        // Default to text field sanitization
        $sanitized = sanitize_text_field($value);
        if ($debug_enabled)
            $this->safe_log('debug', "Sanitized result", array(
                'field_id' => $field_id,
                'sanitized_value' => $sanitized,
                'sanitized_type' => gettype($sanitized)
            ));
        return $sanitized;
    }

    /**
     * Get form HTML for add new entry modal
     */
    public function get_form_html(): void
    {
        // @codeCoverageIgnoreStart
        try {
            check_ajax_referer('gravity_tables_nonce', 'nonce');
        } catch (Exception $e) {
            wp_send_json_error(__('Invalid nonce: ', 'tc-data-tables') . $e->getMessage());
        }

        $form_id = intval($_REQUEST['form_id'] ?? 0);

        if (!$form_id) {
            wp_send_json_error(__('Invalid form ID', 'tc-data-tables'));
        }

        // Check if user can create entries.
        // Allow admins/editors, OR allow anyone if the table has frontend editing enabled.
        $can_edit_posts = current_user_can('edit_posts') || current_user_can('publish_posts');
        $can_driver = current_user_can('driver');
        if (!$can_edit_posts && !$can_driver) {
            $table_id_get = intval($_REQUEST['table_id'] ?? 0);
            $allowed_by_table = false;
            if ($table_id_get > 0) {
                global $wpdb;
                $form_table_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
                    $table_id_get
                ));
                if ($form_table_row) {
                    $form_tbl_settings = json_decode($form_table_row->settings, true);
                    if (!empty($form_tbl_settings['enable_frontend_editing'])) {
                        $allowed_by_table = true;
                    }
                }
            }
            if (!$allowed_by_table) {
                wp_send_json_error(__('Insufficient permissions to create entries', 'tc-data-tables'));
            }
        }

        if (!class_exists('GFAPI')) {
            wp_send_json_error(__('Gravity Forms is not available', 'tc-data-tables'));
        }

        $form = GFAPI::get_form($form_id);

        if (!$form || is_wp_error($form)) {
            wp_send_json_error(__('Form not found or invalid - Form ID: ', 'tc-data-tables') . $form_id);
        }

        // Double-check form is active
        if (!$form['is_active']) {
            wp_send_json_error(__('Form is not active', 'tc-data-tables'));
        }

        // Use TC_Form_Renderer to generate HTML
        $renderer = TC_Form_Renderer::get_instance();
        $form_html = $renderer->render_form_html($form_id);
        error_log('GT Add New: Form HTML length: ' . strlen($form_html));
        error_log('GT Add New: Form HTML preview: ' . substr($form_html, 0, 500));

        if (empty($form_html) || strlen($form_html) < 50) {
            error_log('GT Add New: Form HTML appears empty or too short');
            wp_send_json_error(__('Form HTML generation failed - no content generated. Length: ', 'tc-data-tables') . strlen($form_html));
        }

        if (isset($_REQUEST['raw']) && $_REQUEST['raw'] === '1') {
            // Output raw HTML for iframe usage.
            // Note: wp_add_inline_script() is not available in this early-exit path,
            // so we build the script block as a variable to keep it CSP-friendly
            // (no eval, no unsafe patterns — just a static event listener).
            $postmessage_js = 'jQuery(document).on("gform_confirmation_loaded", function(event, formId) {'
                . ' window.parent.postMessage({ gtEvent: "form_submitted", formId: formId }, "*"); });';
            $iframe_style = 'body { padding: 20px; background: #fff; } .gt-form-container { margin: 0 auto; max-width: 100%; }';
            $script_block = '<script type="text/javascript">' . $postmessage_js . '</script>';
            echo '<!DOCTYPE html><html><head>';
            do_action('wp_head');
            echo '<style>' . $iframe_style . '</style>';
            echo '</head><body>';
            echo $form_html;
            do_action('wp_footer');
            echo $script_block;
            echo '</body></html>';
            exit;
        }

        wp_send_json_success(array(
            'form_html' => $form_html,
            'form_id' => $form_id
        ));
        // @codeCoverageIgnoreEnd
    }

    /**
     * Submit new entry from frontend form
     */
    public function submit_new_entry(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        // #1765 — creating entries from the frontend is part of the premium
        // frontend-editing suite (consistent with inline edit, bulk fill, and
        // duplicate). Gate before any Gravity Forms write so a free-tier site
        // with frontend editing enabled cannot create real entries.
        if (!gt_is_premium()) {
            wp_send_json_error(__('Frontend entry creation is a premium feature. Please upgrade to Pro.', 'tc-data-tables'));
            return;
        }

        // Check if user can create entries.
        // Allow admins/editors, OR allow anyone if the table has frontend editing enabled.
        if (!current_user_can('edit_posts') && !current_user_can('publish_posts') && !current_user_can('driver')) {
            $table_id_sub = intval($_POST['table_id'] ?? 0);
            $allowed_sub = false;
            if ($table_id_sub > 0) {
                global $wpdb;
                $sub_table_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
                    $table_id_sub
                ));
                if ($sub_table_row) {
                    $sub_settings = json_decode($sub_table_row->settings, true);
                    if (!empty($sub_settings['enable_frontend_editing'])) {
                        $allowed_sub = true;
                    }
                }
            }
            if (!$allowed_sub) {
                $table_id_err = intval($_POST['table_id'] ?? 0);
                wp_send_json_error([
                    'code'     => 'permission_denied',
                    'message'  => __('You do not have permission to add rows to this table.', 'tc-data-tables'),
                    'table_id' => $table_id_err,
                ]);
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
        }

        if (!class_exists('GFAPI')) {
            wp_send_json_error(__('Gravity Forms is not available', 'tc-data-tables'));
        }

        // Get form ID from POST data
        $form_id = 0;
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'gform_submit_') === 0) {
                $form_id = intval(str_replace('gform_submit_', '', $key));
                break;
            }
        }

        if (!$form_id && isset($_POST['gform_submit'])) {
            $form_id = intval($_POST['gform_submit']);
        }

        $table_id = intval($_POST['table_id'] ?? 0);

        if (!$form_id) {
            wp_send_json_error([
                'code'     => 'missing_form_id',
                'message'  => __('Could not determine form ID from request.', 'tc-data-tables'),
                'table_id' => $table_id,
            ]);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $form = GFAPI::get_form($form_id);
        if (!$form || is_wp_error($form)) {
            wp_send_json_error([
                'code'     => 'form_not_found',
                'message'  => sprintf(__('Gravity Form #%d was not found. It may have been deleted.', 'tc-data-tables'), $form_id),
                'table_id' => $table_id,
                'form_id'  => $form_id,
            ]);
            return;
        }

        // Create entry array from POST data
        $entry = array();
        $entry['form_id'] = $form_id;
        $entry['date_created'] = current_time('mysql');
        $entry['is_starred'] = 0;
        $entry['is_read'] = 0;
        // #1073 — route $_SERVER reads through gt_request_server_text() so
        // missing keys (proxy environments) degrade to '' without notices,
        // and any slashes / tags injected by a hostile client are stripped
        // at the AJAX boundary before they reach the GF entry array.
        $entry['ip'] = gt_request_server_text('REMOTE_ADDR');
        $entry['source_url'] = gt_request_server_text('HTTP_REFERER');
        $entry['user_agent'] = gt_request_server_text('HTTP_USER_AGENT');
        $entry['currency'] = 'USD';
        $entry['payment_status'] = null;
        $entry['payment_date'] = null;
        $entry['payment_amount'] = null;
        $entry['payment_method'] = null;
        $entry['transaction_id'] = null;
        $entry['is_fulfilled'] = null;
        $entry['created_by'] = get_current_user_id();
        $entry['transaction_type'] = null;
        $entry['status'] = 'active';

        // Add field values
        foreach (($form['fields'] ?? []) as $field) {
            $field_id   = strval($field->id);
            $input_name = 'input_' . str_replace('.', '_', $field_id);

            if (isset($_POST[$input_name])) {
                // #1073 — wp_unslash() before any sanitisation so
                // wp_magic_quotes-added backslashes are stripped (a literal
                // O'Brien round-trips as O'Brien, not O\'Brien).
                $value = wp_unslash($_POST[$input_name]);

                // Handle different field types
                if (is_array($value)) {
                    $value = implode(',', array_map('sanitize_text_field', $value));
                } else {
                    $value = $this->sanitize_field_value($value, $field_id);
                }

                $entry[$field_id] = $value;
            }
        }

        // Add the entry to Gravity Forms
        $result = GFAPI::add_entry($entry);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'code'     => 'gf_error',
                'message'  => $result->get_error_message(),
                'table_id' => $table_id,
                'form_id'  => $form_id,
                'user_id'  => get_current_user_id(),
            ]);
        } elseif ($result === false) {
            wp_send_json_error([
                'code'     => 'insert_failed',
                'message'  => __('The entry could not be saved. Please try again or contact support.', 'tc-data-tables'),
                'table_id' => $table_id,
                'form_id'  => $form_id,
                'user_id'  => get_current_user_id(),
            ]);
        } else {
            do_action('gravity_tables_entry_created', $result, $form_id);
            wp_send_json_success(array(
                'message'  => __('Entry created successfully.', 'tc-data-tables'),
                'entry_id' => $result,
            ));
        }
    }

    /**
     * Check if current user has permission to access table for given form
     *
     * @param int $form_id Form ID
     * @param int $table_id Table ID (optional, will use form_id if not provided)
     * @throws TC_Permission_Exception When access is denied
     */
    private function checkTableAccessPermission(int $form_id, int $table_id = 0): void
    {
        global $wpdb;

        // Get table configuration from database
        if ($table_id > 0) {
            // Use specific table_id if provided
            $table_data = $wpdb->get_row($wpdb->prepare(
                "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND form_id = %d AND status = 'active'",
                $table_id,
                $form_id
            ));
        } else {
            // Fallback to form_id only (for backward compatibility)
            // When multiple configurations exist, try to find one the current user can access
            $debug = TC_Debug::get_instance();
            $debug->log('permissions', "No table_id provided, checking all configurations for form_id: $form_id");

            $all_tables = $wpdb->get_results($wpdb->prepare(
                "SELECT id, settings FROM {$wpdb->prefix}gravity_tables WHERE form_id = %d AND status = 'active' ORDER BY id",
                $form_id
            ));

            $debug->log('permissions', "Found " . count($all_tables) . " active configurations for form_id: $form_id");

            $table_data = null;
            $current_user = wp_get_current_user();

            // Try to find a configuration the current user can access
            foreach ($all_tables as $table_config_data) {
                $settings = json_decode($table_config_data->settings, true);
                if ($settings) {
                    $table_config = new TC_Table_Configuration($settings);
                    if ($table_config->canCurrentUserViewTable()) {
                        $table_data = $table_config_data;
                        $debug->log('permissions', "Found accessible configuration: table_id " . $table_config_data->id);
                        break;
                    }
                }
            }

            // If no accessible configuration found, use the first one (original behavior)
            if (!$table_data && !empty($all_tables)) {
                $table_data = $all_tables[0];
                $debug->log('permissions', "No accessible configuration found, using first available: table_id " . $table_data->id);
            }
        }

        if (!$table_data) {
            // #1069 slice 32 — fail-closed on missing config. Pre-fix this
            // branch silently `return;`d ("backward compatibility"), so
            // orphan form_ids (table config row deleted but a shortcode
            // still references the form) bypassed the role gate entirely.
            // An attacker who discovers such an orphan would have got
            // unguarded read access to every entry. Customer-visible
            // behaviour change: orphan form_ids now 403 instead of
            // silently allowing access. Documented in the slice 32 PR.
            wp_send_json_error(
                [
                    'code'    => 'gt_access_denied',
                    'message' => 'Access denied. No table configuration found for this form.',
                ],
                403
            );
            return;
        }

        $table_config_array = json_decode($table_data->settings, true);
        if (!$table_config_array) {
            // #1069 slice 32 — fail-closed on malformed JSON. Pre-fix this
            // branch silently `return;`d to "prevent breaking existing
            // tables", but a corrupted settings row should bail rather
            // than skip the gate. If a legitimate table's settings get
            // corrupted, admins can repair via the table-builder UI;
            // skipping the gate to "work around" corruption is the wrong
            // trade-off.
            wp_send_json_error(
                [
                    'code'    => 'gt_access_denied',
                    'message' => 'Access denied. Table configuration is corrupted.',
                ],
                403
            );
            return;
        }

        $table_config = new TC_Table_Configuration($table_config_array);

        if (!$table_config->canCurrentUserViewTable()) {
            $allowedRoles = $table_config->getAllowedUserRoles();
            $currentUser = wp_get_current_user();

            // error_log('GT AJAX: Access denied for user ' . $currentUser->ID .
            //           ' (roles: ' . implode(', ', $currentUser->roles) . ') to table with form ID ' . $form_id .
            //           '. Required roles: ' . implode(', ', $allowedRoles));

            wp_send_json_error([
                'code'           => 'gt_access_denied',
                'message'        => 'Access denied. You do not have permission to view this table.',
                'required_roles' => $allowedRoles,
                'user_roles'     => $currentUser->roles
            ], 403);
        }
    }

    /**
     * Count WordPress placeholders (%d, %s, %f) in a query string
     * Ignores MySQL date format strings like '%m/%d/%Y'
     */
    private function count_wp_placeholders(string $query): int
    {
        // Remove quoted strings to avoid counting % in MySQL date formats like '%m/%d/%Y'
        $query_without_quotes = preg_replace("/'[^']*'/", '', $query);

        // Debug logging for placeholder counting
        $original_percent_count = substr_count($query, '%');
        $filtered_percent_count = substr_count($query_without_quotes, '%');

        // error_log("GT AJAX: Original query % count: $original_percent_count, after removing quotes: $filtered_percent_count");

        // WordPress wpdb::prepare recognizes these placeholder patterns:
        // %d (integer), %s (string), %f (float), %i (identifier - table/column names)
        // According to WordPress source, it uses this regex: '/(?<!%)%[sdif]/'
        // But let's count exactly what WordPress counts using a similar approach
        $count = 0;
        $count += substr_count($query_without_quotes, '%d');
        $count += substr_count($query_without_quotes, '%s');
        $count += substr_count($query_without_quotes, '%f');
        $count += substr_count($query_without_quotes, '%i'); // Add %i for identifiers

        // Also check if there are other % patterns that might be causing the mismatch
        $all_percent_patterns = array();
        preg_match_all('/(?<!%)%[a-zA-Z]/', $query_without_quotes, $all_percent_patterns);
        $all_patterns = $all_percent_patterns[0] ?? array();

        // error_log("GT AJAX: WP placeholders found: %d=" . substr_count($query_without_quotes, '%d') . 
        //           ", %s=" . substr_count($query_without_quotes, '%s') . 
        //           ", %f=" . substr_count($query_without_quotes, '%f') . 
        //           ", %i=" . substr_count($query_without_quotes, '%i') . 
        //           " (total=$count)");
        // error_log("GT AJAX: All % patterns found: " . implode(', ', $all_patterns));

        return $count;
    }

    /**
     * Format user ID for display (convert to username/display name)
     */
    private function format_user_display($user_id): string
    {
        if (empty($user_id) || $user_id == '0') {
            return 'Guest';
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return "User #{$user_id}";
        }

        // Return display name if available, otherwise username
        return !empty($user->display_name) ? $user->display_name : $user->user_login;
    }

    /**
     * Export table data with current filters applied
     */
    public function export_table(): void
    {
        // @codeCoverageIgnoreStart
        // Verify nonce
        if (!check_ajax_referer('gravity_tables_nonce', 'nonce', false)) {
            wp_die('Security check failed');
        }

        // #1069 slice 32 — dedicated export capability instead of the
        // pre-fix `read` cap. `read` is granted to subscribers by
        // default, so the pre-fix gate effectively meant "any logged-in
        // user can export any form". export_gravity_tables is registered
        // in TC_Capabilities_Service and auto-granted to administrators
        // on activation; admins can grant to other roles through the
        // existing capability admin UI.
        if (!current_user_can('export_gravity_tables')) {
            wp_die('Insufficient permissions');
        }

        // Get parameters
        $table_id = intval($_POST['table_id'] ?? 0);
        $form_id = intval($_POST['form_id'] ?? 0);

        // #1069 slice 32 — per-table access check. Even with the export
        // capability, the caller must hold the table's configured role
        // gate so an export-capable user cannot enumerate rows from a
        // table they do not have explicit access to. Mirrors the
        // get_entries / update_entry / delete_entry gating pattern.
        // Bails via wp_send_json_error inside checkTableAccessPermission
        // when access is denied; the side-effect code below never runs.
        if ($form_id > 0) {
            $this->checkTableAccessPermission($form_id, $table_id);
        }
        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $sort_field = sanitize_text_field($_POST['sort_field'] ?? 'date_created');
        $sort_order = sanitize_text_field($_POST['sort_order'] ?? 'desc');
        // #1073 — wp_unslash() over the legacy stripslash idiom:
        // wp_magic_quotes-aware and the WP-idiomatic AJAX-boundary choice.
        $filters_raw = json_decode(wp_unslash($_POST['filters'] ?? '{}'), true) ?: array();
        $columns = json_decode(wp_unslash($_POST['columns'] ?? '[]'), true) ?: array();

        // Transform filters from frontend format to backend format
        // Frontend sends: {"filter_20": {"type": "date_range", "from": "...", "to": "..."}}
        // Backend expects: {"20": {"type": "date_range", "from": "...", "to": "..."}}
        $filters = array();
        foreach ($filters_raw as $key => $value) {
            if (strpos($key, 'filter_') === 0) {
                $field_id = str_replace('filter_', '', $key);
                $filters[$field_id] = $value;
            }
        }

        if (!$form_id) {
            wp_die('Invalid form ID');
        }

        // Get form
        $form = GFAPI::get_form($form_id);
        if (!$form) {
            wp_die('Form not found');
        }

        // #2116 — funnel: a valid export is proceeding. Guarded so the option
        // is written once.
        if (class_exists('TC_Activation_Funnel') && !TC_Activation_Funnel::has('first_export')) {
            TC_Activation_Funnel::record('first_export');
        }

        // Get table configuration for column settings
        $table_config = array();
        $export_meta = array('table_id' => $table_id, 'table_name' => '');
        if ($table_id > 0) {
            global $wpdb;
            $table_data = $wpdb->get_row($wpdb->prepare(
                "SELECT name, settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active' LIMIT 1",
                $table_id
            ));
            if ($table_data) {
                $table_config = json_decode($table_data->settings, true) ?: array();
                $export_meta['table_name'] = (string) $table_data->name;
            }
        }
        // #634 — pass id/name through $table_config so the export functions can
        // resolve {table_name} / {table_id} tokens via TC_Export_Filename_Service
        // without changing their signatures.
        $table_config['_export_meta'] = $export_meta;

        // Make sure we include essential columns for export
        $export_columns = array_unique(array_merge($columns, array('entry_id', 'date_created')));

        // For CSV we stream the response in chunks of 500 entries so large
        // datasets (10k+ rows) don't OOM the request. Excel still uses the
        // single-batch path for now — PhpSpreadsheet doesn't have a clean
        // streaming writer.
        if ($format === 'excel') {
            $entries_result = $this->get_gravity_forms_entries(
                $form_id, 1, 10000, $search, '', '', '', $sort_field, $sort_order,
                $export_columns, array(), $filters, $table_config
            );
            if (!$entries_result || !isset($entries_result['entries'])) {
                wp_die('No data to export');
            }
            $this->export_excel($entries_result['entries'], $form, $export_columns, $table_config);
            return;
        }

        $this->export_csv_streamed(
            $form, $form_id, $export_columns, $table_config,
            $search, $sort_field, $sort_order, $filters
        );
        // @codeCoverageIgnoreEnd
    }

    /**
     * #634 — resolve the export filename for a given table_config + extension.
     * If the table has a saved `export_filename_pattern` setting, run it through
     * TC_Export_Filename_Service::expand() with the table_name / table_id
     * context. Otherwise fall back to the legacy hardcoded pattern so
     * existing customers see no behavior change.
     */
    private function resolve_export_filename(array $table_config, string $ext): string
    {
        $pattern = isset($table_config['export_filename_pattern'])
            ? trim((string) $table_config['export_filename_pattern'])
            : '';
        if ($pattern === '') {
            return 'gravity_tables_export_' . date('Y-m-d_H-i-s') . '.' . $ext;
        }
        $context = isset($table_config['_export_meta']) && is_array($table_config['_export_meta'])
            ? $table_config['_export_meta']
            : array();
        $resolved = TC_Export_Filename_Service::expand($pattern, $context);
        // Customer-supplied patterns may not include the right extension.
        // Append it when the resolved name doesn't already end with `.{ext}`
        // (case-insensitive). Empty resolved → safe fallback.
        if ($resolved === '') {
            return 'gravity_tables_export_' . date('Y-m-d_H-i-s') . '.' . $ext;
        }
        if (!preg_match('/\.' . preg_quote($ext, '/') . '$/i', $resolved)) {
            $resolved .= '.' . $ext;
        }
        return $resolved;
    }

    /**
     * Stream a CSV export by paging through GFAPI in fixed-size chunks so
     * memory stays bounded regardless of total entry count. Each chunk is
     * fetched, written to php://output, and dropped before the next page
     * is queried.
     */
    private function export_csv_streamed(
        $form,
        int $form_id,
        array $export_columns,
        array $table_config,
        string $search,
        string $sort_field,
        string $sort_order,
        array $filters
    ): void {
        $filename = $this->resolve_export_filename($table_config, 'csv');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM so Excel renders accented characters correctly
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = $this->get_export_headers($form, $export_columns, $table_config);
        // @codeCoverageIgnoreStart
        fputcsv($output, TC_CSV_Formula_Detector::neutralize_row($headers)); // #1636
        // @codeCoverageIgnoreEnd

        // @codeCoverageIgnoreStart
        $chunk_size = 500;
        $page = 1;
        $total_written = 0;
        // @codeCoverageIgnoreEnd

        // @codeCoverageIgnoreStart
        while (true) {
            $result = $this->get_gravity_forms_entries(
                $form_id, $page, $chunk_size, $search,
                '', '', '', $sort_field, $sort_order,
                $export_columns, array(), $filters, $table_config
            );
        // @codeCoverageIgnoreEnd

            // @codeCoverageIgnoreStart
            $entries = (is_array($result) && isset($result['entries'])) ? $result['entries'] : array();
            if (empty($entries)) break;
            // @codeCoverageIgnoreEnd

            // @codeCoverageIgnoreStart
            foreach ($entries as $entry) {
                fputcsv($output, TC_CSV_Formula_Detector::neutralize_row($this->get_export_row($entry, $form, $export_columns, $table_config))); // #1636
                $total_written++;
            // @codeCoverageIgnoreEnd
            }

            // If we got fewer rows than the chunk size, we're done.
            // @codeCoverageIgnoreStart
            if (count($entries) < $chunk_size) break;
            // @codeCoverageIgnoreEnd

            // Free this chunk before the next query so memory stays flat.
            // @codeCoverageIgnoreStart
            unset($entries, $result);
            if (function_exists('gc_collect_cycles')) gc_collect_cycles();
            // @codeCoverageIgnoreEnd

            // @codeCoverageIgnoreStart
            $page++;
            // @codeCoverageIgnoreEnd
            // Hard ceiling at 200 chunks (100k rows) to bound runaway requests.
            // @codeCoverageIgnoreStart
            if ($page > 200) break;
            // @codeCoverageIgnoreEnd
        }

        // @codeCoverageIgnoreStart
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->safe_log('info', 'CSV export streamed', array(
                'form_id' => $form_id,
                'rows_written' => $total_written,
                'pages' => $page,
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ));
        // @codeCoverageIgnoreEnd
        }

        // @codeCoverageIgnoreStart
        fclose($output);
        exit;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Export data as CSV
     */
    private function export_csv($entries, $form, $columns, $table_config): void
    {
        // @codeCoverageIgnoreStart
        $filename = $this->resolve_export_filename($table_config, 'csv');

        // Set headers for download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Generate headers
        $headers = $this->get_export_headers($form, $columns, $table_config);
        fputcsv($output, TC_CSV_Formula_Detector::neutralize_row($headers)); // #1636

        // Add data rows
        foreach ($entries as $entry) {
            $row = $this->get_export_row($entry, $form, $columns, $table_config);
            fputcsv($output, TC_CSV_Formula_Detector::neutralize_row($row)); // #1636
        }

        fclose($output);
        exit;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Export data as Excel
     */
    private function export_excel($entries, $form, $columns, $table_config): void
    {
        // @codeCoverageIgnoreStart
        // Check if PhpSpreadsheet is available
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            // Fallback to CSV if PhpSpreadsheet is not available
            $this->export_csv($entries, $form, $columns, $table_config);
            return;
        }

        $filename = $this->resolve_export_filename($table_config, 'xlsx');

        // Create new spreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Table Export');

        // Generate headers
        $headers = $this->get_export_headers($form, $columns, $table_config);
        $sheet->fromArray($headers, null, 'A1');

        // Style headers
        $headerRange = 'A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E9ECEF']
            ]
        ]);

        // Add data rows — use TC_Excel_Float_Service to write numeric cells with
        // DataType::TYPE_NUMERIC so floats are not corrupted or mis-formatted as dates.
        $row = 2;
        foreach ($entries as $entry) {
            $exportRow = $this->get_export_row($entry, $form, $columns, $table_config);
            TC_Excel_Float_Service::write_data_row($sheet, $exportRow, $row);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Set headers for download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        // Save to output
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get export headers based on visible columns
     */
    private function get_export_headers($form, $columns, $table_config): array
    {
        $headers = array();

        // Only include columns that are actually configured in the table
        if (!empty($columns)) {
            foreach ($columns as $field_id) {
                // Handle special columns
                if ($field_id === 'entry_id') {
                    $headers[] = 'Entry ID';
                    continue;
                }
                if ($field_id === 'date_created') {
                    $headers[] = 'Date Created';
                    continue;
                }

                // Find the field in the form to get its label
                $field_label = 'Field ' . $field_id; // fallback
                foreach ($form['fields'] as $field) {
                    if ($field->id == $field_id) {
                        $field_label = $field->label;
                        break;
                    }
                }
                $headers[] = $field_label;
            }
        }

        return $headers;
    }

    /**
     * Get export row data for an entry
     */
    private function get_export_row($entry, $form, $columns, $table_config): array
    {
        $row = array();

        // Only include values for configured table columns (in the same order as headers)
        if (!empty($columns)) {
            foreach ($columns as $field_id) {
                // Handle special columns
                if ($field_id === 'entry_id') {
                    $row[] = $entry['entry_id'] ?? 'N/A';
                    continue;
                }
                if ($field_id === 'date_created') {
                    $row[] = $entry['date_created'] ?? 'N/A';
                    continue;
                }

                // Get value for this field
                $value = isset($entry[$field_id]) ? $entry[$field_id] : '';

                // Ensure value is a string for processing
                if (is_array($value)) {
                    $value = implode(', ', array_filter($value));
                }

                // Find the field in form to get its type for formatting
                foreach ($form['fields'] as $field) {
                    if ($field->id == $field_id) {
                        // Format value based on field type
                        switch ($field->type) {
                            case 'date':
                                if ($value && is_string($value) && !is_array($value)) {
                                    // Convert date to readable format
                                    try {
                                        $date = date_create($value);
                                        $value = $date ? $date->format('m/d/Y') : $value;
                                    // @codeCoverageIgnoreStart
                                    } catch (Exception $e) {
                                    // @codeCoverageIgnoreEnd
                                        // If date parsing fails, keep original value
                                        // @codeCoverageIgnoreStart
                                        $this->safe_log('warning', "Date parsing failed during export", array(
                                            'field_id' => $field_id,
                                            'raw_value' => $value,
                                            'entry_id' => $result->id ?? 'unknown'
                                        ));
                                        // @codeCoverageIgnoreEnd
                                    }
                                }
                                break;
                            case 'select':
                            case 'radio':
                                // Get choice text instead of value
                                if ($value && !empty($field->choices)) {
                                    foreach ($field->choices as $choice) {
                                        if ($choice['value'] === $value) {
                                            $value = $choice['text'];
                                            break;
                                        }
                                    }
                                }
                                break;
                            case 'checkbox':
                                // Handle checkbox serialized values
                                if ($value) {
                                    $checkbox_values = maybe_unserialize($value);
                                    if (is_array($checkbox_values)) {
                                        $value = implode(', ', array_filter($checkbox_values));
                                    }
                                }
                                break;
                        }
                        break;
                    }
                }

                $row[] = $value;
            }
        }

        return $row;
    }

    /**
     * Validate that free plan users aren't using premium features
     * 
     * @param array $data Form data to validate
     */
    private function validate_free_plan_features($data)
    {
        // Check if advanced filters are enabled (premium feature)
        if (isset($data['show_advanced_filters']) && $data['show_advanced_filters'] === 'true') {
            wp_send_json_error(array(
                'message' => __('Advanced filters are a Pro feature. Please upgrade to use this functionality.', 'tc-data-tables'),
                'upgrade_required' => true,
                'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
            ));
        }

        // Check if bulk actions are enabled (premium feature)
        if (isset($data['show_bulk_actions']) && $data['show_bulk_actions'] === 'true') {
            wp_send_json_error(array(
                'message' => __('Bulk actions are a Pro feature. Please upgrade to use this functionality.', 'tc-data-tables'),
                'upgrade_required' => true,
                'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
            ));
        }

        // Check if frontend editing is enabled (premium feature)
        if (isset($data['enable_frontend_editing']) && $data['enable_frontend_editing'] === 'true') {
            wp_send_json_error(array(
                'message' => __('Frontend editing is a Pro feature. Please upgrade to use this functionality.', 'tc-data-tables'),
                'upgrade_required' => true,
                'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
            ));
        }

        // Check column configurations for premium features
        if (isset($data['columns']) && is_array($data['columns'])) {
            foreach ($data['columns'] as $column) {
                // Check for advanced column features that might be premium
                if (isset($column['advanced_features']) && !empty($column['advanced_features'])) {
                    wp_send_json_error(array(
                        'message' => __('Advanced column features are only available in Pro. Please upgrade to use this functionality.', 'tc-data-tables'),
                        'upgrade_required' => true,
                        'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : ''
                    ));
                }
            }
        }
    }

    /**
     * Recalculate calculation fields that depend on the given updated fields.
     *
     * Detects GF fields with enableCalculation, parses their formulas to find
     * dependencies, and recalculates values when a dependency was edited.
     *
     * @param int   $form_id          The Gravity Form ID
     * @param int   $entry_id         The entry being edited
     * @param array $updated_field_ids Field IDs that were just edited
     * @return array Associative array of [calc_field_id => new_value]
     */
    private function recalculate_dependent_fields(int $form_id, int $entry_id, array $updated_field_ids): array
    {
        $recalculated = array();

        $form = GFAPI::get_form($form_id);
        if (!$form || is_wp_error($form)) {
            return $recalculated;
        }

        // Find all calculation fields and their dependency field IDs
        $calc_fields = array();
        foreach ($form['fields'] as $field) {
            if (!empty($field->enableCalculation) && !empty($field->calculationFormula)) {
                // Extract field IDs from merge tags: {Label:ID} or {:ID}
                $dep_ids = array();
                preg_match_all('/{[^}]*:(\d+(?:\.\d+)?)}/', $field->calculationFormula, $matches);
                if (!empty($matches[1])) {
                    $dep_ids = $matches[1];
                }

                $calc_fields[] = array(
                    'field' => $field,
                    'formula' => $field->calculationFormula,
                    'dep_ids' => $dep_ids,
                    'rounding' => $field->calculationRounding,
                );
            }
        }

        // Even if no calc fields, continue — we may still need to recalculate Total fields

        // Normalize updated field IDs to strings for comparison
        $updated_str = array_map('strval', $updated_field_ids);

        // Load current entry values for all fields we might need
        global $wpdb;
        $entry_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d",
            $entry_id
        ), OBJECT);
        $field_values = array();
        if ($entry_meta) {
            foreach ($entry_meta as $meta) {
                $field_values[$meta->meta_key] = $meta->meta_value;
            }
        }

        // Process calculation fields — may need multiple passes for nested calcs
        $max_passes = 3;
        for ($pass = 0; $pass < $max_passes; $pass++) {
            $changed_this_pass = false;

            foreach ($calc_fields as $calc) {
                $field_id = (string) $calc['field']->id;

                // Skip if already recalculated in a previous pass
                if (isset($recalculated[$field_id])) {
                    continue;
                }

                // Check if any dependency was updated (directly or via earlier recalculation)
                $affected = false;
                foreach ($calc['dep_ids'] as $dep_id) {
                    if (in_array($dep_id, $updated_str, true) || isset($recalculated[$dep_id])) {
                        $affected = true;
                        break;
                    }
                }

                if (!$affected) {
                    continue;
                }

                // Evaluate the formula
                $new_value = $this->evaluate_calculation_formula($calc['formula'], $field_values);

                // Apply rounding
                if ($new_value !== null && $calc['rounding'] !== '' && $calc['rounding'] !== null) {
                    $rounding = intval($calc['rounding']);
                    if ($rounding >= 0) {
                        $new_value = round($new_value, $rounding);
                    }
                }

                if ($new_value !== null) {
                    $new_value_str = (string) $new_value;

                    // Save to database
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                        $entry_id,
                        $field_id
                    ));

                    if ($existing !== null) {
                        $wpdb->update(
                            $wpdb->prefix . 'gf_entry_meta',
                            array('meta_value' => $new_value_str),
                            array('entry_id' => $entry_id, 'meta_key' => $field_id),
                            array('%s'),
                            array('%d', '%s')
                        );
                    } else {
                        $wpdb->insert(
                            $wpdb->prefix . 'gf_entry_meta',
                            array(
                                'entry_id' => $entry_id,
                                'meta_key' => $field_id,
                                'meta_value' => $new_value_str,
                                'form_id' => $form_id,
                            ),
                            array('%d', '%s', '%s', '%d')
                        );
                    }

                    // Update local values for potential nested calculations
                    $field_values[$field_id] = $new_value_str;
                    $recalculated[$field_id] = $new_value_str;
                    $changed_this_pass = true;
                }
            }

            if (!$changed_this_pass) {
                break; // No more changes, no need for further passes
            }
        }

        // --- GF Total field recalculation (Product × Quantity sums) ---
        // Total fields (type='total') auto-sum all product subtotals in a form
        $has_total_field = false;
        $total_field = null;
        $product_fields = array();
        $quantity_fields = array();

        foreach ($form['fields'] as $field) {
            if ($field->type === 'total') {
                $has_total_field = true;
                $total_field = $field;
            } elseif ($field->type === 'product') {
                $product_fields[(string) $field->id] = $field;
            } elseif ($field->type === 'quantity') {
                // Quantity fields link to their product via productField property
                $quantity_fields[(string) $field->id] = $field;
            }
        }

        if ($has_total_field && !empty($product_fields)) {
            // Check if any updated field is a quantity field, or a product field, or a calc field
            // that might affect the total
            $should_recalc_total = false;
            // Cast all ID arrays to strings for consistent strict comparison
            $all_qty_ids = array_map('strval', array_keys($quantity_fields));
            $all_product_ids = array_map('strval', array_keys($product_fields));
            $all_affected = array_map('strval', array_merge($updated_str, array_keys($recalculated)));

            foreach ($all_affected as $affected_id) {
                if (
                    in_array($affected_id, $all_qty_ids, true) ||
                    in_array($affected_id, $all_product_ids, true)
                ) {
                    $should_recalc_total = true;
                    break;
                }
            }

            if ($should_recalc_total) {
                // GF caches product info as entry meta (gform_product_info_*).
                // Delete these caches so get_order_total() recalculates from actual field values.
                gform_delete_meta($entry_id, 'gform_product_info__');
                gform_delete_meta($entry_id, 'gform_product_info_1_');
                gform_delete_meta($entry_id, 'gform_product_info__1');
                gform_delete_meta($entry_id, 'gform_product_info_1_1');

                // Drop only the GF entry cache for this entry so GFAPI reads
                // fresh data — no global wp_cache_flush().
                $this->invalidate_table_caches((int) $entry_id);
                $fresh_entry = GFAPI::get_entry($entry_id);

                if (!is_wp_error($fresh_entry)) {
                    $total_value = GFCommon::get_order_total($form, $fresh_entry);
                } else {
                    $total_value = 0.0;
                }

                $total_field_id = (string) $total_field->id;
                $total_value_str = number_format($total_value, 2, '.', '');

                // Save the recalculated total
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d AND meta_key = %s",
                    $entry_id,
                    $total_field_id
                ));

                if ($existing !== null) {
                    // @codeCoverageIgnoreStart
                    $wpdb->update(
                        $wpdb->prefix . 'gf_entry_meta',
                        array('meta_value' => $total_value_str),
                        array('entry_id' => $entry_id, 'meta_key' => $total_field_id),
                        array('%s'),
                        array('%d', '%s')
                    );
                    // @codeCoverageIgnoreEnd
                } else {
                    $wpdb->insert(
                        $wpdb->prefix . 'gf_entry_meta',
                        array(
                            'entry_id' => $entry_id,
                            'meta_key' => $total_field_id,
                            'meta_value' => $total_value_str,
                            'form_id' => $form_id,
                        ),
                        array('%d', '%s', '%s', '%d')
                    );
                }

                $recalculated[$total_field_id] = $total_value_str;
            }
        }

        return $recalculated;
    }

    /**
     * Safely evaluate a GF calculation formula.
     *
     * Replaces merge tags with field values and evaluates the resulting
     * arithmetic expression. Only supports +, -, *, /, parentheses, and numbers.
     *
     * @param string $formula      The GF calculation formula with merge tags
     * @param array  $field_values Current field values keyed by field ID
     * @return float|null The calculated result, or null on error
     */
    private function evaluate_calculation_formula(string $formula, array $field_values): ?float
    {
        // Replace merge tags {Label:ID} with actual values
        $expression = preg_replace_callback(
            '/{[^}]*:(\d+(?:\.\d+)?)}/',
            function ($matches) use ($field_values) {
                $field_id = $matches[1];
                $value = isset($field_values[$field_id]) ? $field_values[$field_id] : '0';
                // Ensure it's a valid number, default to 0
                return is_numeric($value) ? $value : '0';
            },
            $formula
        );

        // Clean up: remove any remaining non-math characters for safety
        // Allow: digits, decimal point, +, -, *, /, (, ), spaces
        $expression = trim($expression);
        if (!preg_match('/^[\d\s\.\+\-\*\/\(\)]+$/', $expression)) {
            $this->safe_log('warning', 'Calculation formula contains invalid characters after substitution', array(
                'formula' => $formula,
                'expression' => $expression
            ));
            return null;
        }

        // Handle empty expression
        if (empty($expression) || trim($expression) === '') {
            // @codeCoverageIgnoreStart
            return 0.0;
            // @codeCoverageIgnoreEnd
        }

        // Evaluate using a safe tokenizer approach
        try {
            $result = $this->safe_math_eval($expression);
            return is_finite($result) ? $result : null;
        } catch (\Throwable $e) {
            $this->safe_log('error', 'Calculation formula evaluation failed', array(
                'formula' => $formula,
                'expression' => $expression,
                'error' => $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Safely evaluate a mathematical expression without using eval().
     *
     * Implements a simple recursive descent parser supporting:
     * +, -, *, /, parentheses, unary minus, and decimal numbers.
     *
     * @param string $expr The mathematical expression to evaluate
     * @return float The result
     * @throws \RuntimeException On invalid expressions or division by zero
     */
    private function safe_math_eval(string $expr): float
    {
        $expr = str_replace(' ', '', $expr);
        $pos = 0;
        $len = strlen($expr);

        $result = $this->parse_expression($expr, $pos, $len);

        if ($pos < $len) {
            throw new \RuntimeException('Unexpected character at position ' . $pos . ': ' . $expr[$pos]);
        }

        return $result;
    }

    private function parse_expression(string $expr, int &$pos, int $len): float
    {
        $result = $this->parse_term($expr, $pos, $len);

        while ($pos < $len && ($expr[$pos] === '+' || $expr[$pos] === '-')) {
            $op = $expr[$pos];
            $pos++;
            $right = $this->parse_term($expr, $pos, $len);
            if ($op === '+') {
                $result += $right;
            } else {
                $result -= $right;
            }
        }

        return $result;
    }

    private function parse_term(string $expr, int &$pos, int $len): float
    {
        $result = $this->parse_factor($expr, $pos, $len);

        while ($pos < $len && ($expr[$pos] === '*' || $expr[$pos] === '/')) {
            $op = $expr[$pos];
            $pos++;
            $right = $this->parse_factor($expr, $pos, $len);
            if ($op === '*') {
                $result *= $right;
            } else {
                if ($right == 0) {
                    return 0.0; // Division by zero returns 0 (GF behavior)
                }
                $result /= $right;
            }
        }

        return $result;
    }

    private function parse_factor(string $expr, int &$pos, int $len): float
    {
        // Handle unary minus
        if ($pos < $len && $expr[$pos] === '-') {
            $pos++;
            return -$this->parse_factor($expr, $pos, $len);
        }

        // Handle unary plus
        if ($pos < $len && $expr[$pos] === '+') {
            $pos++;
            return $this->parse_factor($expr, $pos, $len);
        }

        // Handle parentheses
        if ($pos < $len && $expr[$pos] === '(') {
            $pos++; // skip '('
            $result = $this->parse_expression($expr, $pos, $len);
            if ($pos < $len && $expr[$pos] === ')') {
                $pos++; // skip ')'
            }
            return $result;
        }

        // Parse number
        $start = $pos;
        while ($pos < $len && (ctype_digit($expr[$pos]) || $expr[$pos] === '.')) {
            $pos++;
        }

        if ($start === $pos) {
            throw new \RuntimeException('Expected number at position ' . $pos);
        }

        return (float) substr($expr, $start, $pos - $start);
    }

    /**
     * Transpose a manual table: rows become columns and vice-versa (#346).
     */
    public function transpose_table(): void
    {
        check_ajax_referer('gt-transpose', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $table_id = (int) ($_POST['table_id'] ?? 0);
        if ($table_id <= 0) {
            wp_send_json_error(__('Invalid table ID', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        global $wpdb;
        $table = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gravity_tables WHERE id = %d", $table_id));
        if (!$table) {
            wp_send_json_error(__('Table not found', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $settings = json_decode($table->settings, true) ?: array();

        if (empty($settings['manual_data']) || !is_array($settings['manual_data'])) {
            wp_send_json_error(__('Transpose is only available for manually-entered tables.', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $settings['manual_data'] = $this->transpose_matrix($settings['manual_data']);
        $settings['transposed']   = !empty($settings['transposed']) ? false : true;

        $wpdb->update(
            $wpdb->prefix . 'gravity_tables',
            array('settings' => wp_json_encode($settings), 'updated_at' => current_time('mysql')),
            array('id' => $table_id),
            array('%s', '%s'),
            array('%d')
        );

        wp_send_json_success(array(
            'message'    => __('Table transposed successfully.', 'tc-data-tables'),
            'transposed' => $settings['transposed'],
        ));
    }

    /**
     * Flip a 2-D array so rows become columns (#346).
     */
    private function transpose_matrix(array $matrix): array
    {
        if (empty($matrix)) {
            return $matrix;
        }
        return array_map(null, ...$matrix);
    }

    /**
     * Persist a manually reordered row sequence for a table (#440).
     *
     * Expects POST: table_id (int), row_order (array of int entry IDs), nonce.
     */
    public function save_row_order(): void
    {
        check_ajax_referer('gt_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;
        if ($table_id <= 0) {
            wp_send_json_error(__('Invalid table ID', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $order = isset($_POST['row_order']) && is_array($_POST['row_order'])
            ? $_POST['row_order']
            : [];

        $admin = TC_Admin::get_instance();
        $result = $admin->save_row_order($table_id, $order);

        if ($result) {
            wp_send_json_success(['message' => __('Row order saved.', 'tc-data-tables')]);
        } else {
            wp_send_json_error(__('Failed to save row order.', 'tc-data-tables'));
        }
    }

    /**
     * #613 phase 2 (v4.197.0) — push a single row update back to the
     * configured external data source (currently JSON; Airtable + Notion
     * follow in later slices).
     *
     * Expects:
     *   $_POST['nonce']    — gt_nonce.
     *   $_POST['table_id'] — int.
     *   $_POST['row_id']   — string identifier for the row (appended to
     *                        the source URL).
     *   $_POST['payload']  — assoc array of field => value updates.
     *
     * Delegates to TC_JSON_Push_Engine::push_row and surfaces success /
     * error via wp_send_json_*.
     */
    public function push_row(): void
    {
        check_ajax_referer('gt_nonce', 'nonce');

        // #613 — writes require an authenticated user with write capability.
        // Three-tier permission gate:
        //   1. push_rows_to_source — dedicated capability added in slice 12
        //      (v4.207.0). Auto-granted to administrators; admins can grant
        //      to other roles for fine-grained access.
        //   2. gravityforms_edit_entries — legacy fallback so existing users
        //      don't lose access on upgrade.
        //   3. manage_options — administrator override.
        if (!current_user_can('push_rows_to_source')
            && !current_user_can('gravityforms_edit_entries')
            && !current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions to push row updates.', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        // #2026 (D1) — two-way sync (writing changes back to the external
        // source) is a Pro feature. Reads stay free; writes require premium.
        if (!gt_is_premium()) {
            wp_send_json_error(__('Two-way sync (writing changes back to the source) is a Pro feature.', 'tc-data-tables'));
            return;
        }

        $table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;
        if ($table_id <= 0) {
            wp_send_json_error(__('Invalid table ID', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $row_id = isset($_POST['row_id']) ? sanitize_text_field((string) wp_unslash($_POST['row_id'])) : '';
        if ($row_id === '') {
            wp_send_json_error(__('row_id is required', 'tc-data-tables'));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $payload_raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : array();
        if (is_string($payload_raw)) {
            $payload_raw = json_decode($payload_raw, true);
        }
        $payload = is_array($payload_raw) ? $payload_raw : array();
        // Shallow sanitize: keep keys, scrub values via sanitize_text_field.
        $payload = array_map(function ($v) {
            return is_scalar($v) ? sanitize_text_field((string) $v) : $v;
        }, $payload);

        // #613 phase 2 slice 15 (v4.210.0) — optimistic conflict check.
        // The caller (bulk-action JS or custom code) may pass a
        // baseline_lastmod token reflecting what they last saw. If our
        // stored baseline differs, refuse the push so the user can
        // re-pull and reconcile rather than blindly overwriting.
        $client_baseline = isset($_POST['baseline_lastmod']) ? sanitize_text_field((string) wp_unslash($_POST['baseline_lastmod'])) : '';
        if ($client_baseline !== '' && class_exists('TC_Push_Conflict_Detector')) {
            // We don't know the source yet — read it below first.
        }

        // #613 phase 2 slice 8 (v4.203.0) — dispatch by data_source_type.
        // Look up the table's settings, route to the matching push engine.
        $settings = array();
        if (class_exists('TC_Table_Persistence_Service')
            && method_exists('TC_Table_Persistence_Service', 'get_table')) {
            $row = TC_Table_Persistence_Service::get_table($table_id);
            if ($row && isset($row->settings)) {
                $decoded = is_string($row->settings) ? json_decode($row->settings, true) : $row->settings;
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }
        }
        $source_type = isset($settings['data_source_type']) ? (string) $settings['data_source_type'] : '';

        // #613 phase 2 slice 15 (v4.210.0) — finish the optimistic conflict
        // check now that we know the source. If the stored baseline differs
        // from what the caller expected, refuse the push with a typed
        // 'conflict' error code so the JS can surface it via the per-row
        // gt-push-failed tooltip from v4.202.0.
        if ($client_baseline !== '' && class_exists('TC_Push_Conflict_Detector')) {
            $effective_source = $source_type !== '' ? $source_type : 'json';
            $stored_baseline = TC_Push_Conflict_Detector::load_baseline($effective_source, $row_id);
            if ($stored_baseline !== '' && $stored_baseline !== $client_baseline) {
                wp_send_json_error(array(
                    'code'    => 'conflict',
                    'message' => __('Row was modified since you loaded it. Reload the table and try again.', 'tc-data-tables'),
                    'data'    => array(
                        'source'           => $effective_source,
                        'stored_baseline'  => $stored_baseline,
                        'client_baseline'  => $client_baseline,
                    ),
                ));
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
        }

        // #613 phase 2 slice 11 (v4.206.0) — per-source rate-limit gate.
        // Avoids tripping Airtable's 5 req/sec or Notion's 3 req/sec caps
        // when the user pushes a large batch in one click.
        if (class_exists('TC_Push_Rate_Limiter')
            && TC_Push_Rate_Limiter::should_throttle($source_type !== '' ? $source_type : 'json')) {
            wp_send_json_error(array(
                'code'    => 'rate_limited',
                'message' => __('Push rate limit reached. Wait a moment and try again.', 'tc-data-tables'),
                'data'    => array(
                    'source'         => $source_type,
                    'window_seconds' => TC_Push_Rate_Limiter::window_seconds(),
                ),
            ));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if ($source_type === 'airtable') {
            if (!class_exists('TC_Airtable_Push_Engine')) {
                wp_send_json_error(__('Airtable push engine not available.', 'tc-data-tables'));
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
            $result = TC_Airtable_Push_Engine::push_row($table_id, $row_id, $payload);
        } elseif ($source_type === 'notion') {
            if (!class_exists('TC_Notion_Push_Engine')) {
                wp_send_json_error(__('Notion push engine not available.', 'tc-data-tables'));
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
            $result = TC_Notion_Push_Engine::push_row($table_id, $row_id, $payload);
        } else {
            if (!class_exists('TC_JSON_Push_Engine')) {
                // @codeCoverageIgnoreStart
                wp_send_json_error(__('Push engine not available.', 'tc-data-tables'));
                return;
                // @codeCoverageIgnoreEnd
            }
            $result = TC_JSON_Push_Engine::push_row($table_id, $row_id, $payload);
        }

        // #613 phase 2 slice 11 (v4.206.0) — record the request for
        // rate-limit accounting now that the engine has finished.
        if (class_exists('TC_Push_Rate_Limiter')) {
            TC_Push_Rate_Limiter::record_request($source_type !== '' ? $source_type : 'json');
        }

        // #613 phase 2 slice 10 (v4.205.0) — audit-log every push attempt,
        // success or failure, across all 3 source engines. Operators can
        // inspect the most-recent N events via TC_Push_Audit_Log_Service::load.
        if (class_exists('TC_Push_Audit_Log_Service')) {
            $event = array(
                'source'   => $source_type !== '' ? $source_type : 'json',
                'table_id' => $table_id,
                'row_id'   => $row_id,
                'success'  => !is_wp_error($result),
            );
            if (is_wp_error($result)) {
                $event['error_code'] = $result->get_error_code();
                $event['error_message'] = $result->get_error_message();
                $data = $result->get_error_data();
                if (is_array($data) && isset($data['status'])) {
                    // @codeCoverageIgnoreStart
                    $event['http_code'] = (int) $data['status'];
                    // @codeCoverageIgnoreEnd
                }
            }
            TC_Push_Audit_Log_Service::append($event);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'data'    => $result->get_error_data(),
            ));
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        // #613 phase 2 slice 15 (v4.210.0) — snapshot a fresh baseline so
        // subsequent pushes from a stale client get rejected with a
        // 'conflict' code. Uses the current time as a per-push token;
        // future slice can swap this for the engine's returned lastmod.
        // #613 phase 2 slice 16 (v4.211.0) — return the new baseline to the
        // client so it can update self._pushBaselines without a re-pull.
        $new_baseline = (string) microtime(true);
        if (class_exists('TC_Push_Conflict_Detector')) {
            // @codeCoverageIgnoreStart
            $effective_source = $source_type !== '' ? $source_type : 'json';
            TC_Push_Conflict_Detector::snapshot_baseline($effective_source, $row_id, $new_baseline);
            // @codeCoverageIgnoreEnd
        }

        wp_send_json_success(array(
            'message'      => __('Row pushed successfully.', 'tc-data-tables'),
            'response'     => $result,
            'new_baseline' => $new_baseline,
        ));
    }

    /**
     * #1745 — Bulk Column Fill for Selected Rows (Pro).
     *
     * Writes a single value to one field across multiple GF entries.
     * Pro-gated; authenticated users with edit_entries capability only.
     *
     * Expects:
     *   $_POST['nonce']      — gt_nonce.
     *   $_POST['table_id']   — int.
     *   $_POST['entry_ids']  — array of int entry IDs.
     *   $_POST['field_id']   — int field ID.
     *   $_POST['value']      — string value to write.
     */
    public function bulk_fill_column(): void
    {
        check_ajax_referer('gt_nonce', 'nonce');

        if ( ! gt_is_premium() ) {
            wp_send_json_error( array( 'message' => __( 'Pro feature.', 'tc-data-tables' ) ), 403 );
        }

        if ( ! current_user_can( 'edit_gravity_table_entries' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'tc-data-tables' ) ), 403 );
        }

        $raw_ids  = isset( $_POST['entry_ids'] ) && is_array( $_POST['entry_ids'] )
            ? $_POST['entry_ids']
            : array();
        $entry_ids = array_filter( array_map( 'absint', $raw_ids ) );

        if ( empty( $entry_ids ) ) {
            wp_send_json_error( array( 'message' => __( 'No entries specified.', 'tc-data-tables' ) ) );
        }

        $field_id = isset( $_POST['field_id'] ) ? absint( $_POST['field_id'] ) : 0;
        if ( $field_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid field.', 'tc-data-tables' ) ) );
        }

        $value = isset( $_POST['value'] ) ? sanitize_text_field( (string) $_POST['value'] ) : '';

        // Load alert rules for this table (#1748).
        $table_id   = isset( $_POST['table_id'] ) ? absint( $_POST['table_id'] ) : 0;
        $alert_rules = [];
        if ( $table_id > 0 ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d", $table_id ) );
            if ( $row ) {
                $s           = json_decode( $row->settings, true );
                $alert_rules = ( isset( $s['email_alert_rules'] ) && is_array( $s['email_alert_rules'] ) ) ? $s['email_alert_rules'] : [];
            }
        }
        $field_label = (string) $field_id;

        $updated = 0;
        $failed  = 0;
        foreach ( $entry_ids as $entry_id ) {
            // Capture old value for alert comparison.
            $old_value = '';
            if ( ! empty( $alert_rules ) ) {
                $old_entry = GFAPI::get_entry( $entry_id );
                if ( ! is_wp_error( $old_entry ) && isset( $old_entry[ $field_id ] ) ) {
                    $old_value = (string) $old_entry[ $field_id ];
                }
            }
            $result = GFAPI::update_entry_field( $entry_id, $field_id, $value );
            if ( is_wp_error( $result ) || false === $result ) {
                $failed++;
            } else {
                $updated++;
                // Fire threshold alerts (#1748).
                if ( ! empty( $alert_rules ) ) {
                    TC_Email_Alerts::fire_alerts( $alert_rules, (string) $field_id, $old_value, $value, $table_id, $field_label );
                }
            }
        }

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: 1: updated count, 2: total count */
                __( 'Updated %1$d of %2$d entries.', 'tc-data-tables' ),
                $updated,
                count( $entry_ids )
            ),
            'updated' => $updated,
            'failed'  => $failed,
        ) );
    }

    /**
     * #1747 — One-Click Entry Duplicate (Pro).
     *
     * Reads an existing GF entry and creates a copy with all field values
     * preserved. Date created is set to now; the new entry is marked active.
     * Pro-gated; authenticated users with edit_entries capability only.
     *
     * Expects:
     *   $_POST['nonce']    — gt_nonce.
     *   $_POST['entry_id'] — int ID of the source entry.
     *   $_POST['table_id'] — int table config ID (for capability gate).
     */
    public function duplicate_entry(): void
    {
        check_ajax_referer( 'gt_nonce', 'nonce' );

        if ( ! gt_is_premium() ) {
            wp_send_json_error( array( 'message' => __( 'Pro feature.', 'tc-data-tables' ) ), 403 );
        }

        if ( ! current_user_can( 'edit_gravity_table_entries' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'tc-data-tables' ) ), 403 );
        }

        $entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
        if ( $entry_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid entry.', 'tc-data-tables' ) ) );
        }

        $source = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $source ) ) {
            wp_send_json_error( array( 'message' => __( 'Entry not found.', 'tc-data-tables' ) ) );
        }

        // Strip meta fields that must be fresh on the new entry.
        unset( $source['id'], $source['date_created'], $source['date_updated'] );
        $source['status']       = 'active';
        $source['date_created'] = gmdate( 'Y-m-d H:i:s' );
        // Preserve the form_id so GFAPI can locate the form schema.
        $form_id = isset( $source['form_id'] ) ? absint( $source['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Source entry has no form_id.', 'tc-data-tables' ) ) );
        }

        $new_id = GFAPI::add_entry( $source );
        if ( is_wp_error( $new_id ) ) {
            wp_send_json_error( array( 'message' => $new_id->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'      => __( 'Entry duplicated.', 'tc-data-tables' ),
            'new_entry_id' => $new_id,
        ) );
    }

}