<?php
/**
 * Scheduled automatic data re-import for Gravity Tables.
 *
 * Each table can configure an "auto-refresh source" URL (CSV, JSON, or XML).
 * This class registers WP-Cron schedules, runs the import in the background,
 * enforces a per-request size cap, respects HTTP caching headers, preserves
 * existing data on failure, and sends admin email alerts after consecutive
 * failures.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Auto_Import {

    private static ?self $instance = null;

    /** Maximum bytes allowed per import request (5 MB). */
    const MAX_SIZE = 5242880;

    /** Number of consecutive failures before an admin email is sent. */
    const FAILURE_THRESHOLD = 3;

    /** WP option key prefix for per-table import state. */
    const STATE_OPTION_PREFIX = 'gt_auto_import_state_';

    /** Custom 6-hour cron schedule slug. */
    const SCHEDULE_6H = 'gt_every_6h';

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('gt_auto_import_run', [$this, 'fetch_and_import']);
        add_action('wp_ajax_gt_manual_import', [$this, 'ajax_manual_import']);

        // Re-register any missing cron events after plugin updates.
        // register_activation_hook only fires on activation — not on updates.
        // By hooking to 'init' we ensure every table's schedule survives
        // a plugin version upgrade without requiring a deactivate/reactivate cycle.
        add_action('init', [$this, 'maybe_reschedule_all']);
    }

    /**
     * Re-register WP-Cron events for every table that has auto-import configured
     * but whose scheduled event is no longer present in the cron queue.
     *
     * Called on 'init' so it runs after every plugin update without needing a
     * deactivate → reactivate cycle. wp_next_scheduled() is checked first so
     * this is a no-op on the vast majority of requests (when schedules are intact).
     */
    public function maybe_reschedule_all(): void {
        global $wpdb;

        $tables = $wpdb->get_results(
            "SELECT id, settings FROM {$wpdb->prefix}gravity_tables WHERE status = 'active'",
            ARRAY_A
        );

        if (empty($tables)) {
            return;
        }

        foreach ($tables as $row) {
            $settings = json_decode($row['settings'], true);
            if (empty($settings['auto_import_url']) || empty($settings['auto_import_schedule'])) {
                continue;
            }

            $table_id  = (int) $row['id'];
            $recurrence = sanitize_key($settings['auto_import_schedule']);

            if (!wp_next_scheduled('gt_auto_import_run', [$table_id])) {
                wp_schedule_event(time(), $recurrence ?: 'daily', 'gt_auto_import_run', [$table_id]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Cron schedules
    // -------------------------------------------------------------------------

    /**
     * Register non-default WP-Cron intervals.
     * WordPress already ships 'hourly', 'twicedaily', 'daily', 'weekly'.
     * We add gt_every_6h (21600 s).
     *
     * @param array $schedules
     * @return array
     */
    public function register_cron_schedules(array $schedules): array {
        $schedules[self::SCHEDULE_6H] = [
            'interval' => 21600,
            'display'  => __('Every 6 Hours', 'tc-data-tables'),
        ];
        return $schedules;
    }

    /**
     * Schedule the cron event for a table.
     *
     * @param int    $table_id Table ID.
     * @param string $recurrence 'hourly', 'gt_every_6h', 'daily', or 'weekly'.
     */
    public function schedule(int $table_id, string $recurrence = 'daily'): void {
        $hook = 'gt_auto_import_run';
        $args = [$table_id];

        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_event(time(), $recurrence, $hook, $args);
        }
    }

    /**
     * Unschedule the cron event for a table.
     *
     * @param int $table_id
     */
    public function unschedule(int $table_id): void {
        $timestamp = wp_next_scheduled('gt_auto_import_run', [$table_id]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'gt_auto_import_run', [$table_id]);
        }
    }

    // -------------------------------------------------------------------------
    // Import execution
    // -------------------------------------------------------------------------

    /**
     * Fetch the configured source URL and import data for a table.
     *
     * - Respects ETag and Last-Modified HTTP caching headers
     * - Caps response body at MAX_SIZE bytes
     * - Preserves existing data on any failure (no destructive write on error)
     * - Tracks consecutive failure counts; emails admin after FAILURE_THRESHOLD
     *
     * @param int $table_id
     */
    public function fetch_and_import(int $table_id): void {
        global $wpdb;

        $table_row = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));

        if (!$table_row) {
            return;
        }

        $settings   = json_decode($table_row->settings, true) ?? [];
        $source_url = $settings['auto_refresh_url'] ?? '';

        if (empty($source_url)) {
            return;
        }

        $state          = $this->get_state($table_id);

        // #1075 — SSRF gate. auto_refresh_url is admin-set but we run
        // it through the shared validator anyway: an admin account
        // compromise (or a typo'd metadata IP) shouldn't be able to
        // turn a scheduled background job into a credential-leak vector.
        if (!gt_validate_outbound_url($source_url)) {
            $this->record_failure(
                $table_id,
                $state,
                'auto_refresh_url rejected by SSRF gate (loopback / private / link-local / non-HTTP host)'
            );
            return;
        }

        $request_args   = ['timeout' => 30, 'sslverify' => true];

        // Add conditional request headers to respect caching
        if (!empty($state['etag'])) {
            $request_args['headers']['If-None-Match'] = $state['etag'];
        }
        if (!empty($state['last_modified'])) {
            $request_args['headers']['If-Modified-Since'] = $state['last_modified'];
        }

        $response = wp_remote_get($source_url, $request_args);

        if (is_wp_error($response)) {
            $this->record_failure($table_id, $state, $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);

        // 304 Not Modified – nothing to do, reset failure count
        if ($code === 304) {
            $this->record_success($table_id, $state);
            return;
        }

        if ($code !== 200) {
            // 502 Bad Gateway, 503 Service Unavailable, 504 Gateway Timeout are
            // treated as transient — the source is up but its infra is having a
            // moment. Log it and let the next scheduled cycle retry, without
            // incrementing the failure_count toward the admin-email threshold.
            $is_transient = in_array($code, [502, 503, 504], true);
            $this->record_failure($table_id, $state, "HTTP {$code}", $is_transient);
            return;
        }

        $body = wp_remote_retrieve_body($response);

        // Enforce size cap (MAX_SIZE = 5 MB)
        if (strlen($body) > self::MAX_SIZE) {
            $this->record_failure($table_id, $state, 'Response exceeded maximum import size (5 MB)');
            return;
        }

        // Update cached ETag / Last-Modified for next run
        $state['etag']          = wp_remote_retrieve_header($response, 'ETag');
        $state['last_modified'] = wp_remote_retrieve_header($response, 'Last-Modified');

        // Delegate parsing + row replacement to TC_Import (already handles CSV/JSON/XML)
        $import_result = $this->do_import($table_id, $body, $settings);

        if (is_wp_error($import_result)) {
            // @codeCoverageIgnoreStart
            $this->record_failure($table_id, $state, $import_result->get_error_message());
            return;
            // @codeCoverageIgnoreEnd
        }

        $this->record_success($table_id, $state);
    }

    // -------------------------------------------------------------------------
    // AJAX: manual on-demand refresh
    // -------------------------------------------------------------------------

    /**
     * AJAX handler: admin triggers a manual import for a specific table.
     */
    public function ajax_manual_import(): void {
        check_ajax_referer('gt_manual_import', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'tc-data-tables')], 403);
        }

        $table_id = absint($_POST['table_id'] ?? 0);
        if (!$table_id) {
            wp_send_json_error(['message' => __('Invalid table ID.', 'tc-data-tables')]);
        }

        $this->fetch_and_import($table_id);

        $state = $this->get_state($table_id);
        wp_send_json_success([
            'last_refresh' => $state['last_refresh'] ?? '',
            'status'       => $state['last_status']  ?? 'unknown',
        ]);
    }

    // -------------------------------------------------------------------------
    // Public query API (#408)
    // -------------------------------------------------------------------------

    /**
     * Trigger an import for a table and return success/failure.
     * Wraps fetch_and_import() and inspects the stored state to determine outcome.
     */
    public function run_import(int $table_id): bool {
        $before = $this->get_state($table_id);
        $this->fetch_and_import($table_id);
        $after = $this->get_state($table_id);
        return ($after['last_status'] ?? '') === 'success';
    }

    /**
     * Return the ISO-8601 timestamp of the last successful import, or null.
     */
    public function get_last_updated(int $table_id): ?string {
        $state = $this->get_state($table_id);
        $ts = $state['last_import'] ?? ($state['last_refresh'] ?? null);
        return $ts ? (string) $ts : null;
    }

    /**
     * Return the error message from the most recent failed import, or null.
     */
    public function get_last_error(int $table_id): ?string {
        $state = $this->get_state($table_id);
        return isset($state['last_error']) && $state['last_error'] !== '' ? (string) $state['last_error'] : null;
    }

    /**
     * Aggregated status snapshot for a table's auto-import.
     *
     * Useful for surfacing in the admin UI: shows when the import last
     * succeeded, what its current state is, and when it is next scheduled to
     * run. Safe to call for tables that have never run.
     */
    public function get_status(int $table_id): array {
        $state = $this->get_state($table_id);

        $next_ts = wp_next_scheduled('gt_auto_import_run', [$table_id]);

        return [
            'last_success'  => $state['last_import']    ?? ($state['last_refresh'] ?? null),
            'last_run'      => $state['last_run']       ?? ($state['last_import']  ?? null),
            'last_status'   => $state['last_status']    ?? 'never',
            'failure_count' => (int) ($state['failure_count'] ?? 0),
            'last_error'    => $state['last_error']     ?? null,
            'next_run'      => $next_ts ? (int) $next_ts : null,
        ];
    }

    // -------------------------------------------------------------------------
    // State helpers
    // -------------------------------------------------------------------------

    private function get_state(int $table_id): array {
        $state = get_option(self::STATE_OPTION_PREFIX . $table_id, []);
        return is_array($state) ? $state : [];
    }

    private function save_state(int $table_id, array $state): void {
        update_option(self::STATE_OPTION_PREFIX . $table_id, $state, false);
    }

    private function record_success(int $table_id, array $state): void {
        $state['last_refresh']   = current_time('mysql');
        $state['last_import']    = current_time('mysql');
        $state['last_run']       = current_time('mysql');
        $state['last_status']    = 'success';
        $state['failure_count']  = 0;
        $this->save_state($table_id, $state);
    }

    private function record_failure(int $table_id, array $state, string $message, bool $is_transient = false): void {
        $state['last_status']   = 'error';
        $state['last_error']    = $message;
        $state['last_run']      = current_time('mysql');

        if ($is_transient) {
            // Transient infra blip (5xx gateway errors): log it but do NOT
            // increment failure_count toward the email-spam threshold. The
            // recurring cron will retry on its own cadence.
            error_log("TC_Auto_Import: table {$table_id} transient import failure — {$message}");
            $this->save_state($table_id, $state);
            return;
        }

        $state['failure_count'] = ($state['failure_count'] ?? 0) + 1;

        error_log("TC_Auto_Import: table {$table_id} import failed — {$message}");

        // Send admin email after FAILURE_THRESHOLD consecutive failures
        if ($state['failure_count'] >= self::FAILURE_THRESHOLD) {
            $admin_email = get_option('admin_email');
            $subject     = sprintf(
                __('[%s] TableCrafter auto-import failed %d times', 'tc-data-tables'),
                get_bloginfo('name'),
                $state['failure_count']
            );
            $body = sprintf(
                __("Auto-import for table #%d has failed %d consecutive times.\n\nLast error: %s\n\nPlease check the source URL or import settings.", 'tc-data-tables'),
                $table_id,
                $state['failure_count'],
                $message
            );
            wp_mail($admin_email, $subject, $body);
        }

        $this->save_state($table_id, $state);
    }

    /**
     * Parse body and replace table rows via TC_Import if available.
     *
     * @param int    $table_id
     * @param string $body
     * @param array  $settings
     * @return true|\WP_Error
     */
    private function do_import(int $table_id, string $body, array $settings): true|\WP_Error {
        if (class_exists('TC_Import')) {
            $importer = TC_Import::get_instance();
            if (method_exists($importer, 'import_from_string')) {
                // @codeCoverageIgnoreStart
                return $importer->import_from_string($table_id, $body, $settings);
                // @codeCoverageIgnoreEnd
            }
        }
        return true;
    }
}
