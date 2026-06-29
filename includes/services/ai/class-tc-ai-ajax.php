<?php
/**
 * TC_AI_Ajax
 *
 * AJAX surface for AI features. Currently exposes:
 *   - wp_ajax_gt_ai_detect_column_types → handle_detect_column_types()
 *
 * @package GravityTables
 * @since   4.7.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_AI_Ajax
{
    public static function register(): void
    {
        if (function_exists('add_action')) {
            add_action('wp_ajax_gt_ai_detect_column_types', [__CLASS__, 'handle_detect_column_types']);
            // #1601 slice A — frontend summary line. nopriv too: public
            // tables may render the digest for logged-out visitors; the
            // handler enforces the per-table opt-in + role gate itself.
            add_action('wp_ajax_gt_ai_table_summary', [__CLASS__, 'handle_table_summary']);
            add_action('wp_ajax_nopriv_gt_ai_table_summary', [__CLASS__, 'handle_table_summary']);
            // #1601 slice B — builder Data Quality scan. Admin tool:
            // no nopriv variant, manage_options gated.
            add_action('wp_ajax_gt_ai_cleanup_suggest', [__CLASS__, 'handle_cleanup_suggest']);
        }
    }

    /**
     * #1601 — run TC_AI_Cleanup_Suggester over one column of a form's
     * active entries and map each suggestion's value_index back to the
     * source entry_id so the builder panel can write accepted values
     * through the normal gt_update_entry path.
     */
    public static function handle_cleanup_suggest(): void
    {
        $nonce = isset($_REQUEST['_ajax_nonce']) ? (string) $_REQUEST['_ajax_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'gt_ai_cleanup_suggest')) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'tc-data-tables')], 403);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'tc-data-tables')], 403);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $form_id  = isset($_REQUEST['form_id']) ? absint($_REQUEST['form_id']) : 0;
        $field_id = isset($_REQUEST['field_id']) ? sanitize_text_field((string) $_REQUEST['field_id']) : '';
        if ($form_id <= 0 || $field_id === '') {
            wp_send_json_error(['message' => __('Missing form_id or field_id.', 'tc-data-tables')], 400);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if (!class_exists('GFAPI')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(['message' => __('Gravity Forms not loaded.', 'tc-data-tables')], 503);
            return;
            // @codeCoverageIgnoreEnd
        }

        $sample = isset($_REQUEST['sample_size']) ? absint($_REQUEST['sample_size']) : 200;
        $sample = max(1, min(500, $sample > 0 ? $sample : 200));
        $rows   = \GFAPI::get_entries(
            $form_id,
            ['status' => 'active'],
            [],
            ['offset' => 0, 'page_size' => $sample]
        );
        if (is_wp_error($rows)) {
            wp_send_json_error(['message' => $rows->get_error_message()], 400);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
        $rows = is_array($rows) ? $rows : [];

        // Column values aligned with their source entry ids — the
        // suggester reports value_index positions into this array.
        $values    = [];
        $entry_ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            $values[]    = isset($row[$field_id]) ? $row[$field_id] : '';
            $entry_ids[] = isset($row['id']) ? (int) $row['id'] : 0;
        }

        $suggestions = \TC_AI_Cleanup_Suggester::suggest_for_column($values, 'text');
        $out = [];
        foreach ($suggestions as $s) {
            $idx = isset($s['value_index']) ? (int) $s['value_index'] : -1;
            $out[] = [
                'entry_id'        => ($idx >= 0 && isset($entry_ids[$idx])) ? $entry_ids[$idx] : 0,
                'current_value'   => (string) ($s['current_value'] ?? ''),
                'suggested_value' => (string) ($s['suggested_value'] ?? ''),
                'reason'          => (string) ($s['reason'] ?? ''),
                'confidence'      => (float) ($s['confidence'] ?? 0),
            ];
        }

        wp_send_json_success([
            'suggestions' => $out,
            'scanned'     => count($values),
        ]);
    }

    /**
     * #1601 — feed TC_AI_Table_Summarizer (rule-based, key-free) with
     * the table's active entries and return its bullets envelope.
     * Server-side gates: table nonce, per-table `show_table_summary`
     * opt-in, and the table's role allowlist when one is configured.
     */
    public static function handle_table_summary(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        $table_id = isset($_POST['table_id']) ? absint($_POST['table_id']) : 0;
        if ($table_id <= 0) {
            wp_send_json_error(['message' => __('Missing table_id.', 'tc-data-tables')], 400);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if (!class_exists('TC_Admin') || !method_exists('TC_Admin', 'get_instance')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(['message' => __('Tables unavailable.', 'tc-data-tables')], 503);
            return;
            // @codeCoverageIgnoreEnd
        }
        $admin = \TC_Admin::get_instance();
        $table = $admin->get_table($table_id);
        if (!$table) {
            wp_send_json_error(['message' => __('Table not found.', 'tc-data-tables')], 404);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
        $config = $admin->get_table_config($table_id);
        if (!is_array($config) || empty($config['show_table_summary'])) {
            wp_send_json_error(['message' => __('Summary is not enabled for this table.', 'tc-data-tables')], 403);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        // Respect the table's role allowlist when configured.
        if (class_exists('TC_Table_Configuration')) {
            $table_config = new \TC_Table_Configuration($config);
            if (method_exists($table_config, 'canCurrentUserViewTable') && !$table_config->canCurrentUserViewTable()) {
                wp_send_json_error(['message' => __('Access denied.', 'tc-data-tables')], 403);
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
        }

        if (!class_exists('GFAPI')) {
            // @codeCoverageIgnoreStart
            wp_send_json_error(['message' => __('Gravity Forms not loaded.', 'tc-data-tables')], 503);
            return;
            // @codeCoverageIgnoreEnd
        }

        $form_id = (int) ($table->form_id ?? 0);
        $cap     = 1000;
        $rows    = \GFAPI::get_entries(
            $form_id,
            ['status' => 'active'],
            [],
            ['offset' => 0, 'page_size' => $cap]
        );
        if (is_wp_error($rows)) {
            wp_send_json_error(['message' => $rows->get_error_message()], 400);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
        $rows  = is_array($rows) ? $rows : [];
        $total = (int) \GFAPI::count_entries($form_id, ['status' => 'active']);

        // Column defs for the summarizer: configured columns + labels,
        // types mapped from the GF form (number→numeric, date→date).
        $types = [];
        $form  = \GFAPI::get_form($form_id);
        if (is_array($form) && !empty($form['fields'])) {
            foreach ($form['fields'] as $f) {
                if (!isset($f->id, $f->type)) {
                    continue;
                }
                if ($f->type === 'number') {
                    $types[(string) $f->id] = 'numeric';
                } elseif ($f->type === 'date') {
                    $types[(string) $f->id] = 'date';
                }
            }
        }
        $labels  = (isset($config['column_labels']) && is_array($config['column_labels'])) ? $config['column_labels'] : [];
        $columns = [];
        foreach ((array) ($config['columns'] ?? []) as $field_id) {
            $fid = (string) $field_id;
            $columns[] = [
                'id'    => $fid,
                'label' => isset($labels[$fid]) && $labels[$fid] !== '' ? (string) $labels[$fid] : $fid,
                'type'  => $types[$fid] ?? 'text',
            ];
        }

        $result = \TC_AI_Table_Summarizer::summarize_data($rows, $columns);
        $result['truncated'] = $result['truncated'] || $total > $cap;

        wp_send_json_success($result);
    }

    public static function handle_detect_column_types(): void
    {
        $nonce = isset($_REQUEST['_ajax_nonce']) ? (string) $_REQUEST['_ajax_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'gt_ai_detect_column_types')) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'tc-data-tables')], 403);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'tc-data-tables')], 403);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $form_id     = isset($_REQUEST['form_id'])     ? absint($_REQUEST['form_id'])     : 0;
        $sample_size = isset($_REQUEST['sample_size']) ? absint($_REQUEST['sample_size']) : 25;
        if ($form_id <= 0) {
            wp_send_json_error(['message' => __('Missing form_id.', 'tc-data-tables')], 400);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $result = TC_AI_Column_Type_Detector::detect($form_id, $sample_size > 0 ? $sample_size : 25);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        // @codeCoverageIgnoreStart
        wp_send_json_success($result);
        // @codeCoverageIgnoreEnd
    }
}

// @codeCoverageIgnoreStart
if (function_exists('add_action')) {
    TC_AI_Ajax::register();
// @codeCoverageIgnoreEnd
}
