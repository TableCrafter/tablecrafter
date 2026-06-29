<?php
/**
 * TC_Pagination_REST
 *
 * Issue #560 — slice 2 of 3. Registers the REST endpoint that the
 * future DataTables `serverSide: true` binding (slice 3) consumes.
 *
 *   GET /wp-json/gt/v1/tables/(?P<id>\d+)/rows
 *     query params (parsed via TC_Pagination_Service::parse_request):
 *       page       — 1-indexed, default 1
 *       page_size  — clamped 1..500, default 50
 *       sort_col   — string (validated against the table's saved columns)
 *       sort_dir   — asc | desc, default asc
 *       search     — trimmed string for substring match
 *     response (built via TC_Pagination_Service::build_response):
 *       { rows, total, page, page_size, total_pages }
 *
 * Permission: the same per-table check used by `gt_get_entries`
 * AJAX (`TC_Ajax::checkTableAccessPermission`). Mirrors the #545
 * audit pattern — the REST endpoint must not be more permissive
 * than the AJAX equivalent.
 *
 * Opt-in: the per-table `server_side_pagination` setting (slice 2
 * also ships in the table builder UI). When off, this endpoint
 * still works (handy for external integrations) but the table
 * builder won't bind DataTables to it — that's slice 3.
 *
 * @since 4.87.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd

// #1636 — the CSV export handler neutralizes formula injection via
// TC_CSV_Formula_Detector, which is loaded on-demand (not in the main
// bootstrap list), so guarantee it is available here.
if (!class_exists('TC_CSV_Formula_Detector')) {
    require_once __DIR__ . '/services/class-tc-csv-formula-detector.php';
}

class TC_Pagination_REST {

    const NAMESPACE_V1 = 'gt/v1';
    const ROUTE_ROWS   = '/tables/(?P<id>\d+)/rows';
    const ROUTE_EXPORT = '/tables/(?P<id>\d+)/rows.csv';

    /**
     * Bootstrap. Idempotent — safe to call from tablecrafter.php
     * `init` path. Hooks `rest_api_init` → register_routes.
     */
    public static function boot(): void {
        if (function_exists('add_action')) {
            add_action('rest_api_init', [self::class, 'register_routes']);
        }
    }

    public static function register_routes(): void {
        if (!function_exists('register_rest_route')) { return; }
        register_rest_route(self::NAMESPACE_V1, self::ROUTE_ROWS, [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_rows'],
            'permission_callback' => [self::class, 'permission_callback'],
            'args'                => [
                'id'        => ['type' => 'integer', 'required' => true],
                'page'      => ['type' => 'integer', 'required' => false],
                'page_size' => ['type' => 'integer', 'required' => false],
                'sort_col'  => ['type' => 'string',  'required' => false],
                'sort_dir'  => ['type' => 'string',  'required' => false],
                'search'    => ['type' => 'string',  'required' => false],
            ],
        ]);
        // #560 slice 3 — streaming CSV companion endpoint.
        register_rest_route(self::NAMESPACE_V1, self::ROUTE_EXPORT, [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle_export_csv'],
            'permission_callback' => [self::class, 'permission_callback'],
            'args'                => [
                'id'       => ['type' => 'integer', 'required' => true],
                'sort_col' => ['type' => 'string',  'required' => false],
                'sort_dir' => ['type' => 'string',  'required' => false],
                'search'   => ['type' => 'string',  'required' => false],
            ],
        ]);
    }

    /**
     * Permission check. Mirrors the AJAX path: load the table, find
     * its `allowed_user_roles`, and verify the current user qualifies.
     * Empty allowed_user_roles = open to all logged-in users (matches
     * the existing AJAX default).
     */
    public static function permission_callback($request) {
        if (!class_exists('TC_Admin')) {
            // @codeCoverageIgnoreStart
            return new \WP_Error('gt_unavailable', 'TC_Admin not loaded', ['status' => 503]);
            // @codeCoverageIgnoreEnd
        }
        $table_id = (int) $request['id'];
        if ($table_id <= 0) {
            return new \WP_Error('gt_invalid_args', 'Invalid table id', ['status' => 400]);
        }
        // #1631 — require an authenticated user before any data access.
        // Without this floor these endpoints (JSON rows + CSV export)
        // were readable by unauthenticated callers whenever a table had
        // no explicit allowed_user_roles, which is the default.
        if (function_exists('is_user_logged_in') && !is_user_logged_in()) {
            return new \WP_Error('gt_forbidden', 'Authentication required', ['status' => 401]);
        }
        $admin = TC_Admin::get_instance();
        $row = $admin->get_table($table_id);
        if (!$row) {
            return new \WP_Error('gt_not_found', sprintf('Table %d not found', $table_id), ['status' => 404]);
        }
        $config = $admin->get_table_config($table_id);
        // #1632 — enforce the per-table password gate (parity with the
        // shortcode + the main REST endpoints).
        $pw_hash = (is_array($config) && !empty($config['table_password_hash']))
            ? (string) $config['table_password_hash']
            : '';
        if ($pw_hash !== '' && class_exists('TC_Table_Password_Service')
            && !TC_Table_Password_Service::request_is_unlocked($table_id, $pw_hash)) {
            return new \WP_Error('gt_forbidden', 'This table is password protected', ['status' => 401]);
        }
        $allowed = (isset($config['allowed_user_roles']) && is_array($config['allowed_user_roles']))
            ? $config['allowed_user_roles']
            : [];
        if (empty($allowed)) {
            return true; // open to all logged-in users (the login floor above applies)
        }
        // @codeCoverageIgnoreStart
        if (!function_exists('wp_get_current_user')) {
            return true; // defensive — bail out of permission gate in test env
        }
        // @codeCoverageIgnoreEnd
        $user = wp_get_current_user();
        if (!$user || empty($user->roles)) {
            return new \WP_Error('gt_forbidden', 'Forbidden', ['status' => 403]);
        }
        foreach ($user->roles as $role) {
            if (in_array($role, $allowed, true)) {
                return true;
            }
        }
        return new \WP_Error('gt_forbidden', 'Forbidden', ['status' => 403]);
    }

    /**
     * Main handler. Resolves the table, calls slice-1 parse_request
     * on the query params, queries entries via GFAPI, and returns
     * the slice-1 response envelope.
     */
    public static function handle_rows($request) {
        if (!class_exists('TC_Pagination_Service') || !class_exists('TC_Admin') || !class_exists('GFAPI')) {
            return new \WP_Error('gt_unavailable', 'Dependencies not loaded', ['status' => 503]);
        }
        $table_id = (int) $request['id'];
        $admin = TC_Admin::get_instance();
        $row = $admin->get_table($table_id);
        if (!$row) {
            return new \WP_Error('gt_not_found', sprintf('Table %d not found', $table_id), ['status' => 404]);
        }
        $form_id = (int) ($row->form_id ?? 0);
        if ($form_id <= 0) {
            return new \WP_Error('gt_invalid_args', 'Table not bound to a Gravity Form', ['status' => 400]);
        }

        $config = $admin->get_table_config($table_id);
        $columns = (isset($config['columns']) && is_array($config['columns']))
            ? array_values($config['columns'])
            : [];

        $params = method_exists($request, 'get_params') ? $request->get_params() : (array) $request;
        $req = TC_Pagination_Service::parse_request($params);

        // Apply per-table default_page_size when client didn't specify.
        $settings = TC_Pagination_Service::normalize_settings(is_array($config) ? $config : []);
        if (!isset($params['page_size']) && !empty($settings['default_page_size'])) {
            $req['page_size'] = (int) $settings['default_page_size'];
            $req['offset']    = ($req['page'] - 1) * $req['page_size'];
        }

        // Validate sort_col against the table's saved columns to
        // avoid injecting arbitrary GF entry-meta keys.
        $sort_col = $req['sort_col'];
        if ($sort_col !== null && $columns && !in_array($sort_col, array_map('strval', $columns), true)) {
            $sort_col = null;
        }

        $search_criteria = ['status' => 'active'];
        if ($req['search'] !== '') {
            // Use GF's built-in search; runs across the form's
            // searchable fields. Slice 3 can add per-column search.
            $search_criteria['field_filters'] = [
                ['key' => 'any', 'operator' => 'contains', 'value' => $req['search']],
            ];
        }

        $sorting = [];
        if ($sort_col !== null) {
            $sorting = [
                'key'       => $sort_col,
                'direction' => strtoupper($req['sort_dir']),
            ];
        }
        $paging = [
            'offset'    => $req['offset'],
            'page_size' => $req['page_size'],
        ];

        $entries = \GFAPI::get_entries($form_id, $search_criteria, $sorting, $paging);
        if (function_exists('is_wp_error') && is_wp_error($entries)) {
            return $entries;
        }
        $total = \GFAPI::count_entries($form_id, $search_criteria);
        if (function_exists('is_wp_error') && is_wp_error($total)) {
            $total = is_array($entries) ? count($entries) : 0;
        }

        return TC_Pagination_Service::build_response(
            is_array($entries) ? array_values($entries) : [],
            (int) $total,
            $req['page'],
            $req['page_size']
        );
    }

    /**
     * Streaming CSV export. Pulls entries from the same query path
     * as `handle_rows` but in chunks (page_size 500) so a 100k-row
     * table doesn't OOM the request. Emits `text/csv` with a
     * Content-Disposition: attachment header.
     *
     * Slice 3 of #560. Companion to the JSON `/rows` endpoint for
     * external integrations that need to pull a full table without
     * the typical "load everything then download" pattern.
     *
     * Note: this endpoint terminates with `exit` after streaming —
     * WP's REST response machinery is bypassed because chunked
     * `text/csv` is fundamentally not a JSON envelope.
     *
     * @since 4.90.0
     */
    public static function handle_export_csv($request) {
        if (!class_exists('TC_Admin') || !class_exists('GFAPI')) {
            return new \WP_Error('gt_unavailable', 'Dependencies not loaded', ['status' => 503]);
        }
        $table_id = (int) $request['id'];
        $admin = TC_Admin::get_instance();
        $row = $admin->get_table($table_id);
        if (!$row) {
            return new \WP_Error('gt_not_found', sprintf('Table %d not found', $table_id), ['status' => 404]);
        }
        $form_id = (int) ($row->form_id ?? 0);
        if ($form_id <= 0) {
            return new \WP_Error('gt_invalid_args', 'Table not bound to a Gravity Form', ['status' => 400]);
        }
        $config  = $admin->get_table_config($table_id);
        $columns = (isset($config['columns']) && is_array($config['columns']))
            ? array_values($config['columns']) : [];
        $labels  = (isset($config['column_labels']) && is_array($config['column_labels']))
            ? $config['column_labels'] : [];

        $params = method_exists($request, 'get_params') ? $request->get_params() : (array) $request;

        $search_criteria = ['status' => 'active'];
        if (isset($params['search']) && is_string($params['search']) && trim($params['search']) !== '') {
            $search_criteria['field_filters'] = [
                ['key' => 'any', 'operator' => 'contains', 'value' => trim($params['search'])],
            ];
        }
        $sorting = [];
        if (isset($params['sort_col']) && is_string($params['sort_col']) && $params['sort_col'] !== ''
            && $columns && in_array($params['sort_col'], array_map('strval', $columns), true)
        ) {
            $dir = (isset($params['sort_dir']) && strtolower($params['sort_dir']) === 'desc') ? 'DESC' : 'ASC';
            $sorting = ['key' => $params['sort_col'], 'direction' => $dir];
        }

        // Stream: HTTP headers + open stdout + chunked GFAPI fetch.
        // Test envs ($_GT_TEST_CSV_BUFFER) collect output into a
        // global instead of emitting + exit-ing so the handler is
        // testable without killing the runner.
        $test_mode = !empty($GLOBALS['_gt_test_csv_buffer_mode']);
        $fp = $test_mode ? fopen('php://memory', 'w+') : self::open_csv_stream($row);

        // Header row from column labels.
        $header = [];
        foreach ($columns as $cid) {
            $cid_str = (string) $cid;
            $header[] = isset($labels[$cid_str]) ? (string) $labels[$cid_str] : $cid_str;
        }
        fputcsv($fp, TC_CSV_Formula_Detector::neutralize_row($header), ',', '"', ''); // #1636

        // Chunked fetch — 500 entries per page.
        $chunk = 500;
        $page  = 0;
        do {
            $entries = \GFAPI::get_entries(
                $form_id,
                $search_criteria,
                $sorting,
                ['offset' => $page * $chunk, 'page_size' => $chunk]
            );
            if (!is_array($entries) || empty($entries)) { break; }
            foreach ($entries as $entry) {
                $row_out = [];
                foreach ($columns as $cid) {
                    $cid_str = (string) $cid;
                    $val = $entry[$cid_str] ?? '';
                    $row_out[] = is_scalar($val) ? (string) $val : '';
                }
                fputcsv($fp, TC_CSV_Formula_Detector::neutralize_row($row_out), ',', '"', ''); // #1636
            }
            $page++;
            // Defensive cap — refuse to spin past 200 pages (100k
            // rows). Customers needing bigger exports should use
            // the scheduled-export pipeline (#519).
            if ($page >= 200) { break; }
        } while (count($entries) === $chunk);

        if ($test_mode) {
            rewind($fp);
            $GLOBALS['_gt_test_csv_buffer'] = stream_get_contents($fp);
            fclose($fp);
            return null;
        }
        // @codeCoverageIgnoreStart
        fclose($fp);
        if (function_exists('exit')) { exit; }
        return null;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Open the HTTP response stream for the streaming CSV. Wrapped
     * so tests can stub the side-effecting headers + exit.
     */
    private static function open_csv_stream($row) {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($row->name ?? $row->title ?? 'export'))
                  . '-' . date('Y-m-d') . '.csv';
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        return fopen('php://output', 'w');
    }
}
