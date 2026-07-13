<?php
/**
 * TC_Data_Integrity_Guard
 *
 * Issue #557 - defensive runtime guard against silent / catastrophic
 * loss of all tables. Mirrors a recurring competitor failure mode
 * (Supsystic 1-star reviews: "Lost all my 12 tables data,"
 * "Spontaneous Loss of Data") where a malformed save POST body, a
 * partial write, or a typo in an error-handling fallback wipes every
 * row in the `gravity_tables` custom table.
 *
 * Two layers of defense ship in this codebase:
 *
 *   1. Runtime guard (this service) - `assert_safe_table_save()` is
 *      invoked from the AJAX save handler BEFORE any UPDATE statement
 *      runs. It compares the incoming payload against the existing-
 *      table snapshot and refuses the save when the shape suggests
 *      silent data loss.
 *
 *   2. Build-time audit (sibling test) - `tests/test-issue-557-data-
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
 * The guard is paranoid by design - false positives are recoverable
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
     * with HTTP 409 / wp_send_json_error - the user sees a clear
     * "save aborted to protect your data" message instead of silent
     * loss.
     */
    public static function assert_safe_table_save(array $payload, ?array $existing_table = null): ?array {
        // Fresh-create with no existing snapshot - the integrity guard
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
     * (future slice) and the dashboard "Active" stat. Returns the count of
     * currently-active tables. #2257 - also requires deleted_at IS NULL so a
     * row carrying both an active status and a trash timestamp (should not
     * exist, but real data has held inconsistent soft-delete signals before)
     * can never inflate Active.
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
            "SELECT COUNT(*) FROM {$db->prefix}gravity_tables WHERE status = 'active' AND deleted_at IS NULL"
        );
        return (int) $count;
    }

    /**
     * #2257 - count of trashed tables for the dashboard "Trash" stat. The
     * WHERE clause mirrors the Trash tab list query (get_trashed_tables in
     * class-tc-admin.php) exactly, so the card and the tab always agree.
     */
    public static function count_trashed_tables($db = null): int {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        if (!$db || !is_object($db)) {
            return 0;
        }
        $count = $db->get_var(
            "SELECT COUNT(*) FROM {$db->prefix}gravity_tables WHERE status = 'deleted' AND deleted_at IS NOT NULL"
        );
        return (int) $count;
    }

    /**
     * #2257 - active-table counts grouped by data_source_type (stored inside
     * each row's settings JSON; absent/empty means gravity_forms, the same
     * default the builder and preview paths use). Feeds both the stats cards
     * and the Data Sources widget on the dashboard.
     *
     * @return array<string,int> e.g. ['gravity_forms' => 2, 'json' => 2]
     */
    public static function count_active_by_source($db = null): array {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        if (!$db || !is_object($db)) {
            return array();
        }
        $rows = $db->get_results(
            "SELECT settings FROM {$db->prefix}gravity_tables WHERE status = 'active' AND deleted_at IS NULL"
        );
        $counts = array();
        foreach ((array) $rows as $row) {
            $settings = json_decode((string) ($row->settings ?? ''), true);
            $source   = (is_array($settings) && !empty($settings['data_source_type']))
                ? (string) $settings['data_source_type']
                : 'gravity_forms';
            $counts[$source] = ($counts[$source] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * #2257 - count of live tables actually embedded somewhere (shortcode or
     * block in a post/page), via TC_Where_Used_Service (#542). A table count
     * alone reads meaningless when demo tables pile up - "In use" is what
     * admins actually want to know. The where-used candidate query is cached
     * per request, so this costs one posts query + a regex per live table.
     */
    public static function count_tables_in_use($db = null): int {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        if (!$db || !is_object($db)) {
            return 0;
        }
        // The where-used service is lazily required by the tables-list view
        // only - on the dashboard it isn't loaded yet, and a bare
        // class_exists() bail made this count silently read 0.
        if (!class_exists('TC_Where_Used_Service')) {
            $svc_path = __DIR__ . '/class-tc-where-used-service.php';
            if (is_readable($svc_path)) {
                require_once $svc_path;
            }
        }
        if (!class_exists('TC_Where_Used_Service')) {
            return 0; // @codeCoverageIgnore
        }
        $ids = $db->get_col(
            "SELECT id FROM {$db->prefix}gravity_tables WHERE status = 'active' AND deleted_at IS NULL"
        );
        $in_use = 0;
        foreach ((array) $ids as $id) {
            if (!empty(TC_Where_Used_Service::find_usages((int) $id))) {
                $in_use++;
            }
        }
        return $in_use;
    }

    /**
     * #2257 - friendly display labels for every supported data_source_type.
     * Unknown/future types should fall back to ucwords at the call site.
     *
     * @return array<string,string>
     */
    public static function source_labels(): array {
        return array(
            'manual'               => __('Manual', 'tc-data-tables'), // #2366
            'gravity_forms'        => __('Gravity Forms', 'tc-data-tables'),
            'json'                 => __('JSON / REST', 'tc-data-tables'),
            'csv'                  => __('CSV', 'tc-data-tables'),
            'xlsx'                 => __('Excel (XLSX)', 'tc-data-tables'),
            'xml'                  => __('XML', 'tc-data-tables'),
            'google_sheets'        => __('Google Sheets', 'tc-data-tables'),
            'airtable'             => __('Airtable', 'tc-data-tables'),
            'notion'               => __('Notion', 'tc-data-tables'),
            'external_db'          => __('External Database', 'tc-data-tables'),
            'woocommerce_products' => __('WooCommerce', 'tc-data-tables'),
        );
    }

    /**
     * #2257 - one-time self-heal for legacy "ghost" rows: soft-deletes from
     * before the #593 deleted_at trash system carry status='deleted' with
     * deleted_at NULL, which matches NEITHER the tables-list query NOR the
     * Trash tab query - the rows are invisible everywhere and counted
     * nowhere. Backfilling deleted_at folds them into the Trash tab
     * (restorable / purgeable). NOW() rather than updated_at so they get a
     * full retention window before the auto-purge cron may remove them.
     *
     * Idempotent: matches zero rows once every deleted row is timestamped.
     *
     * @return int rows updated
     */
    public static function backfill_legacy_trash($db = null): int {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        if (!$db || !is_object($db)) {
            return 0;
        }
        $updated = $db->query(
            "UPDATE {$db->prefix}gravity_tables SET deleted_at = NOW() WHERE status = 'deleted' AND deleted_at IS NULL" // DATA_INTEGRITY_OK: repairs orphaned soft-deletes into the Trash tab, see method docblock
        );
        return (int) $updated;
    }

    /**
     * #2229 - count of all tables the user actually has: everything except
     * soft-deleted (Trash) rows. This is the dashboard's "Total". A bare
     * COUNT(*) counted trashed tables too, so Total ballooned past Active.
     *
     * Excludes BOTH soft-delete signals: `status = 'deleted'` and a set
     * `deleted_at`. Real data can carry one without the other (a partial
     * delete leaves status='deleted' with deleted_at NULL), so filtering on
     * deleted_at alone would still count those trashed rows.
     */
    public static function count_all_tables($db = null): int {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        if (!$db || !is_object($db)) {
            return 0;
        }
        $count = $db->get_var(
            "SELECT COUNT(*) FROM {$db->prefix}gravity_tables WHERE status <> 'deleted' AND deleted_at IS NULL"
        );
        return (int) $count;
    }
}
