<?php
/**
 * TC_Data_Integrity_Guard
 *
 * Issue #557 — defensive runtime guard against silent / catastrophic
 * loss of all tables. Mirrors a recurring competitor failure mode
 * (Supsystic 1-star reviews: "Lost all my 12 tables data,"
 * "Spontaneous Loss of Data") where a malformed save POST body, a
 * partial write, or a typo in an error-handling fallback wipes every
 * row in the `gravity_tables` custom table.
 *
 * Two layers of defense ship in this codebase:
 *
 *   1. Runtime guard (this service) — `assert_safe_table_save()` is
 *      invoked from the AJAX save handler BEFORE any UPDATE statement
 *      runs. It compares the incoming payload against the existing-
 *      table snapshot and refuses the save when the shape suggests
 *      silent data loss.
 *
 *   2. Build-time audit (sibling test) — `tests/test-issue-557-data-
 *      integrity-guard.php` static-scans `includes/` and fails the
 *      build if any code path adds a destructive pattern:
 *
 *        update_option('gt_…', false)
 *        update_option('gt_…', array())
 *        update_option('gt_…', [])
 *        delete_option('gt_…')             [outside uninstall.php]
 *        DELETE FROM …gravity_tables       [outside DATA_INTEGRITY_OK]
 *
 *      A future contributor adding any of those by accident triggers a
 *      red CI build. Legitimate destructive code paths (the uninstall
 *      hook, an explicit per-table delete handler) tag the line with
 *      `// DATA_INTEGRITY_OK: <reason>` to opt out of the scan. The
 *      marker forces a deliberate code review of every destructive op.
 *
 * Heuristics flagged by `assert_safe_table_save()`:
 *
 *   - Empty / whitespace-only title attempting to overwrite an
 *     existing non-empty title (typical signature of a corrupted POST
 *     body wiping a populated row).
 *   - Empty / null / missing `columns` array when the existing table
 *     had >0 columns (silent loss of all column config).
 *
 * The guard is paranoid by design — false positives are recoverable
 * (the user retries the save with a complete payload) but false
 * negatives are catastrophic (silent data loss).
 *
 * @since 4.7.24
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Data_Integrity_Guard {

    /**
     * Validate a save payload against the existing-table snapshot.
     * Returns null when the save is safe to proceed; returns an error
     * envelope (`code`, `message`, `details`) when the payload looks
     * destructive.
     *
     * The caller (AJAX handler) returns the envelope to the client
     * with HTTP 409 / wp_send_json_error — the user sees a clear
     * "save aborted to protect your data" message instead of silent
     * loss.
     */
    public static function assert_safe_table_save(array $payload, ?array $existing_table = null): ?array {
        // Fresh-create with no existing snapshot — the integrity guard
        // doesn't second-guess anything here. Validation belongs in the
        // per-form layer, not the integrity guard.
        if ($existing_table === null) {
            return null;
        }

        // Heuristic 1: empty title overwriting populated.
        $existing_title = isset($existing_table['title']) ? trim((string) $existing_table['title']) : '';
        $incoming_title = isset($payload['title']) ? trim((string) $payload['title']) : '';
        if ($existing_title !== '' && $incoming_title === '') {
            return [
                'code'    => 'integrity_empty_title_overwrites_populated',
                'message' => __(
                    'Save aborted to protect your data: the incoming payload has an empty title but the existing table is populated. Reload the editor and try again. If this persists, the request body may have been truncated by post_max_size or max_input_vars (see #530 diagnostics).',
                    'tc-data-tables'
                ),
                'details' => [
                    'existing_title' => $existing_table['title'] ?? '',
                ],
            ];
        }

        // Heuristic 2: empty / null / missing columns overwriting populated.
        $existing_cols = isset($existing_table['columns']) && is_array($existing_table['columns'])
            ? $existing_table['columns']
            // @codeCoverageIgnoreStart
            : [];
            // @codeCoverageIgnoreEnd
        $existing_col_count = count($existing_cols);

        $has_columns_key = array_key_exists('columns', $payload);
        $incoming_cols   = $has_columns_key && is_array($payload['columns'])
            ? $payload['columns']
            : null;
        $incoming_col_count = is_array($incoming_cols) ? count($incoming_cols) : -1;
        // -1 marks "missing or wrong-shape" so we can distinguish from
        // the legitimate empty-columns case.

        if ($existing_col_count > 0 && $incoming_col_count <= 0) {
            return [
                'code'    => 'integrity_empty_columns_overwrites_populated',
                'message' => __(
                    'Save aborted to protect your data: the incoming payload has no columns but the existing table is populated. Reload the editor and try again. If this persists, the request body may have been truncated by post_max_size or max_input_vars (see #530 diagnostics).',
                    'tc-data-tables'
                ),
                'details' => [
                    'existing_column_count' => $existing_col_count,
                    'incoming_shape'        => $has_columns_key ? gettype($payload['columns']) : 'missing',
                ],
            ];
        }

        return null;
    }

    /**
     * Tally helper used by the autobackup-before-mutate machinery
     * (future slice). Returns the count of currently-active tables.
     */
    public static function count_active_tables($db = null): int {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        if (!$db || !is_object($db)) {
            return 0;
        }
        $count = $db->get_var(
            "SELECT COUNT(*) FROM {$db->prefix}gravity_tables WHERE status = 'active'"
        );
        return (int) $count;
    }
}
