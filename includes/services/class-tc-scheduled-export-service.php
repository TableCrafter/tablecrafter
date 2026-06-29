<?php
/**
 * TC_Scheduled_Export_Service
 *
 * Issue #519 — slice 2 of 3. Outbound counterpart to TC_Auto_Import.
 * On a configurable WP-Cron cadence (hourly / 6h / daily / weekly),
 * renders a table's current entry set to a CSV (or XLSX when
 * PhpSpreadsheet is loaded) and writes it under
 * `wp-content/uploads/gravity-tables-exports/`.
 *
 * Slice 1 (v4.7.37): pure `TC_Export_Filename_Service` token expander.
 * Slice 2 (this slice): the runner — WP-Cron registration + file
 *   writer + failure logging.
 * Slice 3 (deferred): admin UI ("Scheduled export" panel), email
 *   destination + recipient list, "Run export now" button, and the
 *   "honors-current-filters" opt-in.
 *
 * Settings shape consumed from the table config (saved later by
 * slice 3's admin UI; for slice 2 the runner just reads what's
 * there with safe defaults):
 *
 *   - scheduled_export_format            string  'csv' | 'xlsx'   (default 'csv')
 *   - scheduled_export_filename_pattern  string  passed to expand() (default '{table_name}-{YYYY-MM-DD}.csv')
 *   - scheduled_export_recurrence        string  'hourly' | 'gt_every_6h' | 'daily' | 'weekly' (default 'daily')
 *
 * XLSX is opt-in via the table config and degrades gracefully to
 * CSV when PhpSpreadsheet isn't loaded (matches the existing
 * pattern in `TC_Ajax::export_excel()` at class-tc-ajax.php:5067).
 *
 * @since 4.64.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd

// #1636 — export writers neutralize formula injection via
// TC_CSV_Formula_Detector, which is loaded on-demand (not in the main
// bootstrap list), so guarantee it is available here.
if (!class_exists('TC_CSV_Formula_Detector')) {
    require_once __DIR__ . '/class-tc-csv-formula-detector.php';
}

class TC_Scheduled_Export_Service {

    /** Canonical WP-Cron hook the runner is registered against. */
    const HOOK = 'gt_run_scheduled_export';

    /** Subdirectory under `wp-content/uploads/` where files land. */
    const UPLOAD_SUBDIR = 'gravity-tables-exports';

    /** Allowed schedule recurrences. Mirrors TC_Auto_Import. */
    const ALLOWED_RECURRENCES = ['hourly', 'gt_every_6h', 'daily', 'weekly'];

    /**
     * Bootstrap. Hooks:
     *   - `cron_schedules` filter → register the gt_every_6h interval
     *   - The cron hook itself → `run_scheduled_export`
     *   - `wp_ajax_gt_run_scheduled_export` → the admin "Run export now"
     *     button handler (slice 3, v4.65.0)
     *
     * Idempotent — safe to call from tablecrafter.php's `init` path.
     */
    public static function boot(): void {
        if (function_exists('add_filter')) {
            add_filter('cron_schedules', [self::class, 'register_cron_schedules']);
        }
        if (function_exists('add_action')) {
            add_action(self::HOOK, [self::class, 'run_scheduled_export']);
            // Slice 3 — Run-now AJAX handler.
            add_action('wp_ajax_gt_run_scheduled_export', [self::class, 'ajax_run_now']);
        }
    }

    /**
     * AJAX handler for the table-builder "Run export now" button.
     * Slice 3 (v4.65.0). Capability: manage_options. Proxies to
     * run_scheduled_export() and returns the result envelope as JSON.
     */
    public static function ajax_run_now(): void {
        check_ajax_referer('gt_run_scheduled_export', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'tc-data-tables')], 403);
        }
        $table_id = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
        if ($table_id <= 0) {
            wp_send_json_error(['message' => __('Invalid table id.', 'tc-data-tables')], 400);
        }
        $result = self::run_scheduled_export($table_id);
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            wp_send_json_error([
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 500);
        }
        wp_send_json_success($result);
    }

    /**
     * Register the 6h cron interval. Same key as TC_Auto_Import
     * (`gt_every_6h`) so we only register it once per site even when
     * both inbound and outbound auto-features are active.
     */
    public static function register_cron_schedules(array $schedules): array {
        if (!isset($schedules['gt_every_6h'])) {
            $schedules['gt_every_6h'] = [
                'interval' => 21600,
                'display'  => function_exists('__') ? __('Every 6 Hours', 'tc-data-tables') : 'Every 6 Hours',
            ];
        }
        return $schedules;
    }

    /**
     * Schedule the recurring export for a table. Whitelists the
     * recurrence; invalid values fall back to 'daily' (safest default).
     * Idempotent — bails when an event is already queued for this
     * table_id.
     */
    public static function schedule_for_table(int $table_id, string $recurrence = 'daily'): void {
        $rec = in_array($recurrence, self::ALLOWED_RECURRENCES, true) ? $recurrence : 'daily';
        $args = [$table_id];
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::HOOK, $args)) {
            return;
        }
        if (function_exists('wp_schedule_event')) {
            wp_schedule_event(time(), $rec, self::HOOK, $args);
        }
    }

    /**
     * Clear the recurring export for a table. No-op when nothing is
     * scheduled.
     */
    public static function clear_schedule_for_table(int $table_id): void {
        $args = [$table_id];
        if (!function_exists('wp_next_scheduled')) {
            return;
        }
        $ts = wp_next_scheduled(self::HOOK, $args);
        if ($ts && function_exists('wp_unschedule_event')) {
            wp_unschedule_event($ts, self::HOOK, $args);
        }
    }

    /**
     * Cron callback. Renders the table to a file and returns a
     * status array on success or a WP_Error on failure. Errors are
     * also logged via error_log so admins can find them in
     * debug.log without needing to wire the admin notice (slice 3).
     *
     * @return array{status:string,file:string,rows:int,format:string}|\WP_Error
     */
    public static function run_scheduled_export(int $table_id) {
        if ($table_id <= 0) {
            return self::fail('gt_export_invalid_args', 'table_id must be > 0', $table_id);
        }
        $admin = self::admin_instance();
        if ($admin === null) {
            // @codeCoverageIgnoreStart
            return self::fail('gt_export_unavailable', 'TC_Admin not loaded', $table_id);
            // @codeCoverageIgnoreEnd
        }
        $row = $admin->get_table($table_id);
        if (!$row) {
            return self::fail('gt_export_not_found', sprintf('Table %d not found', $table_id), $table_id);
        }
        if (!class_exists('GFAPI')) {
            return self::fail('gt_export_unavailable', 'Gravity Forms not loaded', $table_id);
        }

        $config = $admin->get_table_config($table_id);
        $columns       = (isset($config['columns']) && is_array($config['columns'])) ? array_values($config['columns']) : [];
        $column_labels = (isset($config['column_labels']) && is_array($config['column_labels'])) ? $config['column_labels'] : [];

        $requested_format = strtolower((string) ($config['scheduled_export_format'] ?? 'csv'));
        $format           = ($requested_format === 'xlsx' && class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet'))
            ? 'xlsx'
            : 'csv';

        $ext     = '.' . $format;
        $pattern = (string) ($config['scheduled_export_filename_pattern'] ?? ('{table_name}-{YYYY-MM-DD}' . $ext));

        $table_name = (string) ($row->name ?? $row->title ?? ('table-' . $table_id));
        $filename   = class_exists('TC_Export_Filename_Service')
            ? TC_Export_Filename_Service::expand($pattern, [
                'table_name' => $table_name,
                'table_id'   => $table_id,
            ])
            // @codeCoverageIgnoreStart
            : (preg_replace('/[^A-Za-z0-9._-]+/', '-', $table_name) . '-' . date('Y-m-d') . $ext);
            // @codeCoverageIgnoreEnd

        // Make sure the resolved filename actually carries our chosen
        // extension. After token substitution + sanitization a custom
        // pattern can drop it (e.g. user-supplied '{table_name}.txt'
        // with our format=csv); we enforce the format here so the
        // downstream writer + return shape stay consistent.
        if (!str_ends_with($filename, $ext)) {
            $filename .= $ext;
        }

        $form_id = (int) ($row->form_id ?? 0);

        // Resolve uploads target. wp_upload_dir() returns false-ish
        // on misconfigured installs — bail with a write failure.
        if (!function_exists('wp_upload_dir')) {
            // @codeCoverageIgnoreStart
            return self::fail('gt_export_unavailable', 'wp_upload_dir not available', $table_id);
            // @codeCoverageIgnoreEnd
        }
        $uploads = wp_upload_dir();
        if (empty($uploads['basedir'])) {
            return self::fail('gt_export_write_failed', 'no uploads basedir', $table_id);
        }
        $dir = rtrim((string) $uploads['basedir'], '/\\') . '/' . self::UPLOAD_SUBDIR;
        if (!self::ensure_dir($dir)) {
            return self::fail('gt_export_write_failed', "could not create $dir", $table_id);
        }

        $filepath = $dir . '/' . $filename;

        // Slice 3 — `scheduled_export_honor_filters` opt-in. The runner
        // exposes the search criteria via the `gt_scheduled_export_
        // search_criteria` filter so theme / snippet code can inject
        // GF field_filters. Default search is the full active-entries
        // set (slice 2 invariant preserved when the toggle is off).
        $search_criteria = ['status' => 'active'];
        if (!empty($config['scheduled_export_honor_filters']) && function_exists('apply_filters')) {
            $search_criteria = apply_filters(
                'gt_scheduled_export_search_criteria',
                $search_criteria,
                $table_id,
                $config
            );
            if (!is_array($search_criteria)) {
                $search_criteria = ['status' => 'active'];
            }
        }

        $entries = \GFAPI::get_entries($form_id, $search_criteria, [], ['offset' => 0, 'page_size' => 10000]);
        if (function_exists('is_wp_error') && is_wp_error($entries)) {
            return self::fail('gt_export_query_failed', $entries->get_error_message(), $table_id);
        }
        if (!is_array($entries)) {
            $entries = [];
        }

        $write_ok = ($format === 'xlsx')
            ? self::write_xlsx($filepath, $columns, $column_labels, $entries)
            : self::write_csv($filepath, $columns, $column_labels, $entries);

        if (!$write_ok) {
            // @codeCoverageIgnoreStart
            return self::fail('gt_export_write_failed', "could not write $filepath", $table_id);
            // @codeCoverageIgnoreEnd
        }

        // Slice 3 — email destination. When the per-table
        // `scheduled_export_email_recipients` setting carries one or
        // more comma-separated addresses, attach the file and ship it
        // via wp_mail. Mail failures are logged but do NOT fail the
        // run — the file already landed on disk and that's the
        // primary destination per the AC.
        $email_sent = false;
        $recipients_raw = (string) ($config['scheduled_export_email_recipients'] ?? '');
        if ($recipients_raw !== '' && function_exists('wp_mail')) {
            $recipients = self::parse_recipients($recipients_raw);
            if ($recipients) {
                $subject = sprintf(
                    /* translators: 1: table name, 2: ISO date */
                    __('Scheduled export: %1$s (%2$s)', 'tc-data-tables'),
                    $table_name,
                    function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s')
                );
                $body = sprintf(
                    __('Attached: %1$s. %2$d rows. Source: TableCrafter #%3$d.', 'tc-data-tables'),
                    basename($filepath),
                    count($entries),
                    $table_id
                );
                $ok = wp_mail($recipients, $subject, $body, [], [$filepath]);
                $email_sent = (bool) $ok;
                if (!$ok) {
                    error_log(sprintf('TC_Scheduled_Export_Service: wp_mail failed for table %d', $table_id));
                }
            }
        }

        return [
            'status'     => 'ok',
            'file'       => $filepath,
            'rows'       => count($entries),
            'format'     => $format,
            'email_sent' => $email_sent,
        ];
    }

    /**
     * Parse a comma- or whitespace-separated address list. Drops
     * empties and runs each through `sanitize_email` when WP is
     * available; otherwise a permissive regex.
     */
    private static function parse_recipients(string $raw): array {
        $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            if (function_exists('sanitize_email')) {
                $part = sanitize_email($part);
            }
            if ($part && (function_exists('is_email') ? is_email($part) : preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $part))) {
                $clean[] = $part;
            }
        }
        return array_values(array_unique($clean));
    }

    // ---- internals --------------------------------------------------------

    private static function admin_instance(): ?object {
        if (!class_exists('TC_Admin') || !method_exists('TC_Admin', 'get_instance')) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }
        return TC_Admin::get_instance();
    }

    /**
     * Idempotent dir-create. wp_mkdir_p falls back to PHP's mkdir
     * with parents when WordPress isn't loaded (tests).
     */
    private static function ensure_dir(string $dir): bool {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        if (function_exists('wp_mkdir_p')) {
            return (bool) wp_mkdir_p($dir);
        }
        // @codeCoverageIgnoreStart
        return @mkdir($dir, 0755, true);
        // @codeCoverageIgnoreEnd
    }

    private static function write_csv(string $filepath, array $columns, array $labels, array $entries): bool {
        $fh = @fopen($filepath, 'w');
        if (!$fh) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        // Header row from labels (falls back to field_id if label is missing).
        $header = [];
        foreach ($columns as $field_id) {
            $fid = (string) $field_id;
            $header[] = $labels[$fid] ?? $fid;
        }
        // PHP 8.5+ requires the $escape arg explicitly. Pass ''
        // (no escape char) so backslashes in field values aren't
        // interpreted — matches RFC 4180 and PhpSpreadsheet default.
        fputcsv($fh, TC_CSV_Formula_Detector::neutralize_row($header), ',', '"', ''); // #1636

        foreach ($entries as $entry) {
            $row = [];
            foreach ($columns as $field_id) {
                $fid = (string) $field_id;
                $val = $entry[$fid] ?? '';
                $row[] = is_scalar($val) ? (string) $val : '';
            }
            fputcsv($fh, TC_CSV_Formula_Detector::neutralize_row($row), ',', '"', ''); // #1636
        }
        fclose($fh);
        return file_exists($filepath);
    }

    private static function write_xlsx(string $filepath, array $columns, array $labels, array $entries): bool {
        // Caller has already gated this on class_exists; the
        // double-check is a safety net for future call sites.
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            // @codeCoverageIgnoreStart
            return self::write_csv(preg_replace('/\\.xlsx$/', '.csv', $filepath), $columns, $labels, $entries);
            // @codeCoverageIgnoreEnd
        }
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();

            // #1568 / PhpSpreadsheet 2.x: setCellValueByColumnAndRow was
            // removed in the 2.0 release. Use the [col, row] coordinate
            // form of setCellValue which works on both 1.x and 2.x.
            // #1636 — force every cell to an explicit STRING type so Excel
            // never evaluates a leading "=" / "+" / "-" / "@" as a formula.
            // (For the typed XLSX format this is the correct defence; the
            // CSV path uses the single-quote prefix via neutralize_row.)
            $col_idx = 1;
            foreach ($columns as $field_id) {
                $fid   = (string) $field_id;
                $label = $labels[$fid] ?? $fid;
                $sheet->setCellValueExplicit([$col_idx, 1], (string) $label, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $col_idx++;
            }
            $row_idx = 2;
            foreach ($entries as $entry) {
                $col_idx = 1;
                foreach ($columns as $field_id) {
                    $fid = (string) $field_id;
                    $val = $entry[$fid] ?? '';
                    $sheet->setCellValueExplicit([$col_idx, $row_idx], is_scalar($val) ? (string) $val : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $col_idx++;
                }
                $row_idx++;
            }
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);
            return file_exists($filepath);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            error_log('TC_Scheduled_Export_Service: xlsx write failed — ' . $e->getMessage());
            return false;
        // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Build a WP_Error + log to error_log so the failure surfaces in
     * the WordPress debug.log without needing the slice-3 admin notice.
     */
    private static function fail(string $code, string $message, int $table_id): \WP_Error {
        error_log(sprintf('TC_Scheduled_Export_Service: table %d export failed [%s] — %s', $table_id, $code, $message));
        return new \WP_Error($code, $message, ['status' => 500, 'table_id' => $table_id]);
    }
}
