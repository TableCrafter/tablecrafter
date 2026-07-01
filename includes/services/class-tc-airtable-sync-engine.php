<?php
/**
 * TC_Airtable_Sync_Engine
 *
 * Issue #517 — slice 2 of N. HTTP layer over the slice-1 request
 * builder. Calls wp_remote_get under the hood (injectable so unit
 * tests don't require WP), auto-paginates, and surfaces errors as
 * a structured return shape.
 *
 * Return shape (success):
 *   ['ok' => true, 'records' => array<assoc>, 'http_code' => int, 'error' => null]
 *
 * Return shape (failure):
 *   ['ok' => false, 'records' => [], 'http_code' => int|null, 'error' => string]
 *
 * Slice 3 will wire admin UI for credential storage. Slice 4+
 * tackles two-way sync (#613 territory).
 *
 * Pairs with: TC_Airtable_Request_Builder (URL + headers; slice 1)
 *             TC_Airtable_Field_Mapper    (schema mapping; orphan)
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Airtable_Sync_Engine {

    const MAX_PAGES = 100; // safety cap — 100 pages * 100 records = 10k rows per sync.

    /**
     * Fetch all records for a base+table, auto-paginating until the
     * Airtable response stops returning an `offset`.
     *
     * @param string        $base_id   e.g. "appAbc123"
     * @param string        $table_id  table name or id
     * @param string        $token     Airtable API key / PAT
     * @param array         $opts      ['page_size' => int]
     * @param callable|null $http      injectable transport with the
     *                                 wp_remote_get(url, args) shape;
     *                                 defaults to wp_remote_get when WP loaded
     */
    public static function fetch_records(string $base_id, string $table_id, string $token, array $opts = [], ?callable $http = null): array {
        if ($token === '') {
            return self::fail('missing token / auth', null);
        }
        $http = $http ?: self::default_http();
        $headers = TC_Airtable_Request_Builder::build_headers($token);

        $records = [];
        $offset = '';
        $http_code = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $params = [];
            if (!empty($opts['page_size'])) {
                $params['page_size'] = (int) $opts['page_size'];
            }
            if ($offset !== '') {
                $params['offset'] = $offset;
            }
            $url = TC_Airtable_Request_Builder::build_list_url($base_id, $table_id, $params);
            $response = $http($url, ['headers' => $headers, 'timeout' => 30]);

            $check = self::check_response($response);
            if (!$check['ok']) {
                return self::fail($check['error'], $check['http_code']);
            }
            $http_code = $check['http_code'];
            $body = $check['body'];

            if (isset($body['records']) && is_array($body['records'])) {
                foreach ($body['records'] as $r) {
                    $records[] = $r;
                }
            }
            $offset = TC_Airtable_Request_Builder::parse_pagination_offset($body);
            if ($offset === '') {
                break;
            }
        }

        return [
            'ok'        => true,
            'records'   => $records,
            'http_code' => $http_code,
            'error'     => null,
        ];
    }

    /**
     * Fetch a single record by id.
     *
     * @return array ['ok' => bool, 'record' => array|null, 'http_code' => int|null, 'error' => string|null]
     */
    public static function fetch_single_record(string $base_id, string $table_id, string $record_id, string $token, ?callable $http = null): array {
        if ($token === '') {
            return ['ok' => false, 'record' => null, 'http_code' => null, 'error' => 'missing token / auth'];
        }
        $url = TC_Airtable_Request_Builder::build_record_url($base_id, $table_id, $record_id);
        if ($url === '') {
            return ['ok' => false, 'record' => null, 'http_code' => null, 'error' => 'invalid record id'];
        }
        $http = $http ?: self::default_http();
        $headers = TC_Airtable_Request_Builder::build_headers($token);
        $response = $http($url, ['headers' => $headers, 'timeout' => 30]);

        $check = self::check_response($response);
        if (!$check['ok']) {
            return ['ok' => false, 'record' => null, 'http_code' => $check['http_code'], 'error' => $check['error']];
        }
        return [
            'ok'        => true,
            'record'    => $check['body'],
            'http_code' => $check['http_code'],
            'error'     => null,
        ];
    }

    /**
     * PATCH a single record's fields. The write half of two-way sync
     * (#517 slice 4 / #613 broader). Pure addition — no admin caller
     * yet (slice 4b owes the AJAX wire).
     *
     * @param string        $base_id    Airtable base id (e.g. "appAbc")
     * @param string        $table_id   table name or id
     * @param string        $record_id  e.g. "recXyZ"
     * @param array         $fields     map of Airtable column name => new value
     * @param string        $token      Airtable PAT with data.records:write scope
     * @param callable|null $http       transport (wp_remote_request shape) — injected for tests
     *
     * @return array ['ok' => bool, 'record' => array|null, 'http_code' => int|null, 'error' => string|null]
     */
    public static function update_record(string $base_id, string $table_id, string $record_id, array $fields, string $token, ?callable $http = null): array {
        if ($token === '') {
            return ['ok' => false, 'record' => null, 'http_code' => null, 'error' => 'missing token / auth'];
        }
        if (empty($fields)) {
            return ['ok' => false, 'record' => null, 'http_code' => null, 'error' => 'empty fields — nothing to update'];
        }
        $url = TC_Airtable_Request_Builder::build_record_url($base_id, $table_id, $record_id);
        if ($url === '') {
            return ['ok' => false, 'record' => null, 'http_code' => null, 'error' => 'invalid record id'];
        }
        $http = $http ?: self::default_request_http();
        $headers = TC_Airtable_Request_Builder::build_headers($token);
        $body = function_exists('wp_json_encode')
            ? wp_json_encode(['fields' => $fields])
            // @codeCoverageIgnoreStart
            : json_encode(['fields' => $fields]);
            // @codeCoverageIgnoreEnd

        $response = $http($url, [
            'method'  => 'PATCH',
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 30,
        ]);

        $check = self::check_response($response);
        if (!$check['ok']) {
            return ['ok' => false, 'record' => null, 'http_code' => $check['http_code'], 'error' => $check['error']];
        }
        return [
            'ok'        => true,
            'record'    => $check['body'],
            'http_code' => $check['http_code'],
            'error'     => null,
        ];
    }

    /**
     * Whether an HTTP status code is worth retrying. 429 (rate limit)
     * and 5xx (server-side) are retryable; 2xx + 4xx (except 429) are
     * terminal — caller shouldn't keep hammering on them.
     */
    public static function is_retryable_status(int $http_code): bool {
        if ($http_code === 429) {
            return true;
        }
        return $http_code >= 500 && $http_code < 600;
    }

    /**
     * #994 v4.173.0 — Phase C of #517. Per-table cached rows helper.
     *
     * Mirrors TC_JSON_Source_Service::get_cached_rows_for_table from #987.
     * Loads the table's stored Airtable settings, decrypts the PAT, fetches
     * via fetch_records(), normalizes the Airtable record shape to flat rows,
     * caches via transient.
     *
     * Returns array of flat row dicts (each row has 'airtable_id',
     * 'airtable_created_time', plus every field from the record's 'fields'
     * sub-array) OR a WP_Error.
     *
     * @param int  $table_id
     * @param bool $force_refresh
     * @return array|\WP_Error
     */
    public static function get_cached_rows_for_table(int $table_id, bool $force_refresh = false) {
        global $wpdb;

        if ($table_id <= 0) {
            return new \WP_Error('gt_invalid_table_id', __('Invalid table id', 'tc-data-tables'));
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d",
            $table_id
        ));
        if (!$row) {
            return new \WP_Error('gt_table_not_found', __('Table not found', 'tc-data-tables'));
        }

        $settings = json_decode((string) $row->settings, true);
        if (!is_array($settings)) {
            return new \WP_Error('gt_invalid_settings', __('Table settings are malformed', 'tc-data-tables'));
        }

        $source_type = isset($settings['data_source_type']) ? (string) $settings['data_source_type'] : 'gravity_forms';
        if ($source_type !== 'airtable') {
            return new \WP_Error('gt_not_airtable_source', __('Table is not an Airtable-source table', 'tc-data-tables'));
        }

        $base_id  = isset($settings['airtable_base_id'])  ? (string) $settings['airtable_base_id']  : '';
        $table_at = isset($settings['airtable_table_id']) ? (string) $settings['airtable_table_id'] : '';
        $pat_enc  = isset($settings['airtable_pat'])      ? (string) $settings['airtable_pat']      : '';

        if ($base_id === '' || $table_at === '' || $pat_enc === '') {
            return new \WP_Error('gt_airtable_missing_credentials', __('Airtable Base ID, Table ID, and PAT are all required', 'tc-data-tables'));
        }

        $pat = '';
        if (class_exists('TC_Airtable_Credential_Service')) {
            $pat = TC_Airtable_Credential_Service::decrypt($pat_enc);
        }
        if ($pat === '') {
            return new \WP_Error('gt_airtable_decrypt_failed', __('Could not decrypt stored Airtable PAT', 'tc-data-tables'));
        }

        // Reuse the json_refresh_minutes shape — same semantics, mirrored across the two
        // data-source families. Default 30, clamped to [5*MINUTE, DAY_IN_SECONDS].
        $refresh_minutes = isset($settings['json_refresh_minutes']) ? (int) $settings['json_refresh_minutes'] : 30;
        $ttl = max(5 * MINUTE_IN_SECONDS, min(DAY_IN_SECONDS, $refresh_minutes * MINUTE_IN_SECONDS));

        $transient_key = 'gt_airtable_table_' . $table_id;

        if (!$force_refresh) {
            $cached = get_transient($transient_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = self::fetch_records($base_id, $table_at, $pat);
        if (empty($result['ok'])) {
            return new \WP_Error(
                'gt_airtable_fetch_failed',
                sprintf(
                    /* translators: 1: HTTP code, 2: error message. */
                    __('Airtable fetch failed (HTTP %1$s): %2$s', 'tc-data-tables'),
                    isset($result['http_code']) ? (string) $result['http_code'] : '?',
                    isset($result['error']) ? (string) $result['error'] : 'unknown'
                ),
                $result
            );
        }

        // Normalize Airtable record shape -> flat rows.
        // Airtable record: {id: 'recXXX', createdTime: '...', fields: {Name: 'Foo', Email: 'a@b'}}
        // Flat row: {airtable_id: 'recXXX', airtable_created_time: '...', Name: 'Foo', Email: 'a@b'}
        $rows = array();
        foreach ($result['records'] as $rec) {
            $flat = array();
            if (isset($rec['id'])) {
                $flat['airtable_id'] = (string) $rec['id'];
            }
            if (isset($rec['createdTime'])) {
                $flat['airtable_created_time'] = (string) $rec['createdTime'];
            }
            if (isset($rec['fields']) && is_array($rec['fields'])) {
                foreach ($rec['fields'] as $k => $v) {
                    $flat[$k] = $v;
                }
            }
            $rows[] = $flat;
        }

        set_transient($transient_key, $rows, $ttl);

        return $rows;
    }

    // ---- internals ----

    /**
     * Normalize a transport response into a uniform shape so the
     * paginator + single-record paths can share validation.
     *
     * @param mixed $response  raw return from the http callable;
     *                         WP_Error or array with 'response'/'body'
     *                         or pseudo-WP_Error array with 'errors'.
     */
    private static function check_response($response): array {
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return ['ok' => false, 'http_code' => null, 'error' => 'transport: ' . $response->get_error_message(), 'body' => null];
        }
        // Pseudo-WP_Error shape used in unit tests: ['errors' => [...]].
        if (is_array($response) && isset($response['errors']) && !isset($response['response'])) {
            $first = '';
            foreach ($response['errors'] as $msg) {
                $first = is_array($msg) ? (string) reset($msg) : (string) $msg;
                break;
            }
            return ['ok' => false, 'http_code' => null, 'error' => 'transport: ' . $first, 'body' => null];
        }
        if (!is_array($response) || !isset($response['response']['code'])) {
            return ['ok' => false, 'http_code' => null, 'error' => 'transport: unexpected response shape', 'body' => null];
        }
        $code = (int) $response['response']['code'];
        $raw_body = isset($response['body']) ? (string) $response['body'] : '';
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'http_code' => $code, 'error' => "non-2xx response: HTTP $code", 'body' => null];
        }
        $decoded = json_decode($raw_body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'http_code' => $code, 'error' => 'JSON parse error', 'body' => null];
        }
        return ['ok' => true, 'http_code' => $code, 'error' => null, 'body' => $decoded];
    }

    private static function fail(string $error, ?int $http_code): array {
        return [
            'ok'        => false,
            'records'   => [],
            'http_code' => $http_code,
            'error'     => $error,
        ];
    }

    /**
     * Production transport. Returns a closure that invokes
     * wp_remote_get with the given args. Outside WP, returns a stub
     * that fails closed so test runs without ABSPATH don't blow up.
     */
    private static function default_http(): callable {
        if (function_exists('wp_remote_get')) {
            return function (string $url, array $args) {
                return wp_remote_get($url, $args);
            };
        }
        // @codeCoverageIgnoreStart -- wp_remote_get is always defined under the test shim, so this no-WP fallback is unreachable here.
        return function (string $url, array $args) {
            return ['errors' => ['no-wp' => 'wp_remote_get unavailable']];
        };
        // @codeCoverageIgnoreEnd
    }

    /**
     * #1920 — List Airtable bases accessible with the given PAT.
     *
     * Calls GET https://api.airtable.com/v0/meta/bases (requires
     * `schema.bases:read` scope on the PAT).
     *
     * Returns an array of base descriptors:
     *   [['id' => 'appXXX', 'name' => 'My Base', 'permissionLevel' => 'create'], ...]
     * or a WP_Error on HTTP/API failure.
     *
     * @param string $pat  Personal Access Token.
     * @return array|\WP_Error
     */
    public static function list_bases(string $pat) {
        $url  = 'https://api.airtable.com/v0/meta/bases';
        $args = [
            'timeout' => 15,
            'headers' => class_exists('TC_Airtable_Request_Builder')
                ? TC_Airtable_Request_Builder::build_headers($pat)
                : ['Authorization' => 'Bearer ' . $pat, 'Content-Type' => 'application/json'],
        ];

        $http     = self::default_http();
        $response = $http($url, $args);

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return $response;
        }

        $checked = self::check_response($response);
        if (!$checked['ok']) {
            return new \WP_Error(
                'gt_airtable_list_bases_failed',
                sprintf(
                    /* translators: 1: HTTP code, 2: error message */
                    __('Airtable list-bases failed (HTTP %1$d): %2$s', 'tc-data-tables'),
                    (int) ($checked['http_code'] ?? 0),
                    (string) ($checked['error'] ?? 'Unknown error')
                )
            );
        }

        $body   = function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : ($response['body'] ?? '');
        $parsed = json_decode((string) $body, true);
        $bases  = $parsed['bases'] ?? [];

        return is_array($bases) ? $bases : [];
    }

    /**
     * #1920 — List tables in a specific Airtable base.
     *
     * Calls GET https://api.airtable.com/v0/meta/bases/{baseId}/tables
     * (requires `schema.bases:read` scope on the PAT).
     *
     * Returns an array of table descriptors:
     *   [['id' => 'tblXXX', 'name' => 'My Table', 'fields' => [...]], ...]
     * or a WP_Error on HTTP/API failure.
     *
     * @param string $pat      Personal Access Token.
     * @param string $base_id  Airtable base ID (appXXX…).
     * @return array|\WP_Error
     */
    public static function list_tables(string $pat, string $base_id) {
        $base_id = class_exists('TC_Airtable_Request_Builder')
            ? TC_Airtable_Request_Builder::sanitize_id($base_id)
            // @codeCoverageIgnoreStart -- TC_Airtable_Request_Builder is unconditionally required at bootstrap, so this fallback is unreachable.
            : preg_replace('/[^A-Za-z0-9_-]/', '', $base_id);
            // @codeCoverageIgnoreEnd

        if ($base_id === '') {
            return new \WP_Error('gt_airtable_invalid_base_id', __('Invalid Airtable base ID.', 'tc-data-tables'));
        }

        $url  = 'https://api.airtable.com/v0/meta/bases/' . rawurlencode($base_id) . '/tables';
        $args = [
            'timeout' => 15,
            'headers' => class_exists('TC_Airtable_Request_Builder')
                ? TC_Airtable_Request_Builder::build_headers($pat)
                : ['Authorization' => 'Bearer ' . $pat, 'Content-Type' => 'application/json'],
        ];

        $http     = self::default_http();
        $response = $http($url, $args);

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return $response;
        }

        $checked = self::check_response($response);
        if (!$checked['ok']) {
            return new \WP_Error(
                'gt_airtable_list_tables_failed',
                sprintf(
                    /* translators: 1: HTTP code, 2: error message */
                    __('Airtable list-tables failed (HTTP %1$d): %2$s', 'tc-data-tables'),
                    (int) ($checked['http_code'] ?? 0),
                    (string) ($checked['error'] ?? 'Unknown error')
                )
            );
        }

        $body   = function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : ($response['body'] ?? '');
        $parsed = json_decode((string) $body, true);
        $tables = $parsed['tables'] ?? [];

        return is_array($tables) ? $tables : [];
    }

    /**
     * Production transport for arbitrary-method requests (PATCH/POST/DELETE).
     * `wp_remote_request` honors the `method` arg in the args bag, unlike
     * `wp_remote_get` which is GET-only.
     */
    private static function default_request_http(): callable {
        if (function_exists('wp_remote_request')) {
            return function (string $url, array $args) {
                return wp_remote_request($url, $args);
            };
        }
        // @codeCoverageIgnoreStart
        return function (string $url, array $args) {
            return ['errors' => ['no-wp' => 'wp_remote_request unavailable']];
        };
        // @codeCoverageIgnoreEnd
    }
}
