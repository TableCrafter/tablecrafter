<?php
/**
 * TC_Clipboard_Paste_Handler
 *
 * Issue #516 — slice 2 of 3. Admin-side AJAX wire-up that takes a
 * pasted Excel / Sheets / Numbers clipboard payload (TSV), parses it
 * via the slice-1 TC_TSV_Parser_Service, and either previews the
 * detected shape or applies the rows as new Gravity Forms entries
 * on the table's bound form.
 *
 * Slice 1 (v4.7.34): pure parser.
 * Slice 2 (this slice): preview / apply AJAX + table-builder UI.
 * Slice 3: column-type preservation when headers match + #496
 *   detection integration + 5000×50 stress invariant.
 *
 * @since 4.66.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Clipboard_Paste_Handler {

    /**
     * Bootstrap. Idempotent — invoked from tablecrafter.php on init.
     */
    public static function boot(): void {
        if (function_exists('add_action')) {
            add_action('wp_ajax_gt_clipboard_paste_preview', [self::class, 'ajax_preview']);
            add_action('wp_ajax_gt_clipboard_paste_apply',   [self::class, 'ajax_apply']);
        }
    }

    /**
     * AJAX: parse the pasted payload and return a row-count +
     * detected dialect summary. No DB writes. Capability gate
     * `manage_options`, nonce `gt_clipboard_paste`.
     */
    public static function ajax_preview(): void {
        check_ajax_referer('gt_clipboard_paste', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'tc-data-tables')], 403);
        }
        $tsv = isset($_POST['tsv']) ? (string) wp_unslash($_POST['tsv']) : '';
        if ($tsv === '') {
            wp_send_json_error(['message' => __('Empty paste payload.', 'tc-data-tables')], 400);
        }
        if (!class_exists('TC_TSV_Parser_Service')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(['message' => __('TSV parser not loaded.', 'tc-data-tables')], 500);
            // @codeCoverageIgnoreEnd
        }
        $dialect = TC_TSV_Parser_Service::detect_dialect($tsv);
        $rows    = TC_TSV_Parser_Service::parse($tsv);
        $cols    = isset($rows[0]) ? count($rows[0]) : 0;
        wp_send_json_success([
            'dialect' => $dialect,
            'rows'    => count($rows),
            'cols'    => $cols,
            'preview' => array_slice($rows, 0, 5),
        ]);
    }

    /**
     * AJAX: apply the parsed payload as new Gravity Forms entries on
     * the table's bound form. `mode` selects:
     *   - 'append' (default, safest) — adds parsed rows alongside
     *     existing entries.
     *   - 'replace' — deletes all existing active entries for the
     *     form first, then adds the parsed rows.
     *
     * When `has_headers` is true the first parsed row is treated as
     * header labels and is consumed by TC_TSV_Parser_Service::
     * to_rows so cells are mapped to the table's saved column ids
     * by position.
     *
     * Capability gate `manage_options`, nonce `gt_clipboard_paste`.
     */
    public static function ajax_apply(): void {
        check_ajax_referer('gt_clipboard_paste', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'tc-data-tables')], 403);
        }
        $table_id    = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
        $tsv         = isset($_POST['tsv']) ? (string) wp_unslash($_POST['tsv']) : '';
        $mode        = isset($_POST['mode']) ? (string) $_POST['mode'] : 'append';
        $has_headers = !empty($_POST['has_headers']);

        if ($table_id <= 0 || $tsv === '') {
            wp_send_json_error(['message' => __('table_id and tsv are required.', 'tc-data-tables')], 400);
        }
        if (!in_array($mode, ['append', 'replace'], true)) {
            $mode = 'append';
        }
        if (!class_exists('TC_TSV_Parser_Service') || !class_exists('GFAPI') || !class_exists('TC_Admin')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(['message' => __('Dependencies not loaded.', 'tc-data-tables')], 500);
            // @codeCoverageIgnoreEnd
        }

        $admin = TC_Admin::get_instance();
        $row   = $admin->get_table($table_id);
        if (!$row) {
            wp_send_json_error(['message' => __('Table not found.', 'tc-data-tables')], 404);
        }
        $form_id = (int) ($row->form_id ?? 0);
        if ($form_id <= 0) {
            wp_send_json_error(['message' => __('Table is not bound to a Gravity Form.', 'tc-data-tables')], 400);
        }

        $config  = $admin->get_table_config($table_id);
        $columns = (isset($config['columns']) && is_array($config['columns'])) ? array_values($config['columns']) : [];

        // Slice 3 — header-match column-type preservation. When
        // $has_headers is true AND every parsed header matches one
        // of the table's saved column labels, rewire the column-id
        // order to follow the pasted header order so column moves /
        // reorders in the source land in the correct GF fields.
        // Partial matches return null → fall through to positional
        // (the slice-2 safety contract).
        $effective_columns = $columns;
        if ($has_headers) {
            $column_labels = (isset($config['column_labels']) && is_array($config['column_labels'])) ? $config['column_labels'] : [];
            $parsed_raw    = TC_TSV_Parser_Service::parse($tsv);
            $header_row    = isset($parsed_raw[0]) && is_array($parsed_raw[0]) ? $parsed_raw[0] : [];
            $rewired       = self::rewire_columns_by_header($header_row, $columns, $column_labels);
            if (is_array($rewired)) {
                $effective_columns = $rewired;
            }
        }

        // Parse via slice-1 service. to_rows() maps cells to column
        // ids positionally against $effective_columns. When the
        // header rewire produced a reordered id list, the same
        // positional mapping now lands in the header-matched
        // fields.
        $mapped = TC_TSV_Parser_Service::to_rows($tsv, $effective_columns ?: null, $has_headers);

        $deleted = 0;
        if ($mode === 'replace') {
            $existing = \GFAPI::get_entries($form_id, ['status' => 'active'], [], ['offset' => 0, 'page_size' => 5000]);
            if (is_array($existing)) {
                foreach ($existing as $entry) {
                    if (!empty($entry['id'])) {
                        \GFAPI::update_entry(['status' => 'trash'], (int) $entry['id']);
                        $deleted++;
                    }
                }
            }
        }

        $added  = 0;
        $errors = 0;
        foreach ($mapped as $row_map) {
            $entry = ['form_id' => $form_id];
            foreach ($row_map as $fid => $val) {
                $entry[(string) $fid] = is_scalar($val) ? (string) $val : '';
            }
            $result = \GFAPI::add_entry($entry);
            if (function_exists('is_wp_error') && is_wp_error($result)) {
                $errors++;
            } else {
                $added++;
            }
        }

        wp_send_json_success([
            'mode'    => $mode,
            'added'   => $added,
            'deleted' => $deleted,
            'errors'  => $errors,
        ]);
    }

    /**
     * Slice 3 — rewire column ids by header match.
     *
     * Given a row of pasted header labels and the table's saved
     * (columns, column_labels), return a reordered list of column
     * ids matching the header order. Returns null when the headers
     * don't fully match the saved labels (partial matches are
     * deliberately rejected — silently dropping unmatched cells
     * into wrong columns is a worse failure mode than falling back
     * to positional mapping).
     *
     * Matching is case-insensitive on the labels (Excel users
     * casually retype headers); whitespace is trimmed at both ends.
     *
     * @param array $headers       Parsed first-row cells from the TSV.
     * @param array $saved_columns Table's saved column-id list (string ids).
     * @param array $labels        Saved column_labels map id => label.
     * @return array|null          Reordered column-id list, or null on no-match.
     *
     * @since 4.68.0
     */
    public static function rewire_columns_by_header(array $headers, array $saved_columns, array $labels): ?array {
        if (count($headers) === 0) { return null; }
        // Build lowercase label → column-id index, restricted to the
        // table's saved columns (so labels belonging to columns the
        // table doesn't expose don't accidentally match).
        $label_to_id = [];
        foreach ($saved_columns as $cid) {
            $key = isset($labels[(string) $cid]) ? strtolower(trim((string) $labels[(string) $cid])) : '';
            if ($key !== '') {
                $label_to_id[$key] = (string) $cid;
            }
        }
        $rewired = [];
        foreach ($headers as $h) {
            $k = strtolower(trim((string) $h));
            if ($k === '' || !isset($label_to_id[$k])) {
                // Any unmatched header aborts the rewire — caller
                // falls back to positional mapping (slice-2 contract).
                return null;
            }
            $rewired[] = $label_to_id[$k];
        }
        return $rewired;
    }
}
