<?php
/**
 * TC_JSON_Push_Engine — phase 2 of #613 (two-way sync).
 *
 * HTTP PUTs a row update back to a JSON data source URL. Counterpart
 * to TC_JSON_Source_Service (which handles the pull side).
 *
 * @since 4.196.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_JSON_Push_Engine {

    /**
     * Push a row update back to the JSON source URL.
     *
     * @param int    $table_id Gravity Tables table id.
     * @param string $row_id   Identifier for the row to update (appended to the source URL).
     * @param array  $payload  Field => value map for the update body.
     * @return array|WP_Error  Decoded response body on 2xx, WP_Error otherwise.
     */
    public static function push_row(int $table_id, string $row_id, array $payload) {
        $settings = self::resolve_table_settings($table_id);

        // Guard 1: data_source_type must be 'json'.
        $type = isset($settings['data_source_type']) ? (string) $settings['data_source_type'] : '';
        if ($type !== 'json') {
            return new WP_Error('not_a_json_source', 'Table is not configured as a JSON data source (got: ' . $type . ')');
        }

        // Guard 2: sync_direction must allow pushing. Accept both legacy
        // (push_only / bidirectional) and canonical (push / two_way) naming
        // to match the alias-aware consumer pattern from #1011.
        $direction = isset($settings['sync_direction']) ? (string) $settings['sync_direction'] : 'pull';
        $canonical = self::canonicalize_direction($direction);
        if ($canonical === 'pull') {
            return new WP_Error('pull_only', 'sync_direction is pull — pushes are not allowed for this table');
        }

        // Guard 3: json_url must be set.
        $url = isset($settings['json_url']) ? trim((string) $settings['json_url']) : '';
        if ($url === '') {
            return new WP_Error('missing_config', 'json_url is empty');
        }

        // Guard 4: payload must be non-empty.
        if (empty($payload)) {
            return new WP_Error('invalid_payload', 'payload is empty — nothing to push');
        }

        // Guard 5: SSRF safety on the constructed URL.
        // #1075 — promoted to the shared gt_validate_outbound_url() helper.
        if (function_exists('gt_validate_outbound_url') && !gt_validate_outbound_url($url)) {
            return new WP_Error('unsafe_url', 'json_url failed the SSRF safety check');
        }

        $target = self::build_push_url($url, $row_id);
        $headers = self::resolve_headers($settings);

        $response = wp_remote_request($target, array(
            'method'  => 'PUT',
            'headers' => array_merge($headers, array('Content-Type' => 'application/json')),
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        // @codeCoverageIgnoreStart
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('push_failed', 'HTTP ' . $code . ' on push', array(
                'status' => $code,
                'body'   => wp_remote_retrieve_body($response),
            ));
        // @codeCoverageIgnoreEnd
        }

        // @codeCoverageIgnoreStart
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : array('raw' => $body);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Build the RESTful push target URL.
     *
     * Strips trailing slashes from the base, then appends '/' + row_id.
     */
    public static function build_push_url(string $base, string $row_id): string {
        $base = rtrim($base, '/');
        return $base . '/' . ltrim($row_id, '/');
    }

    /**
     * Map legacy sync_direction values to canonical naming.
     * Matches the alias map used by the consumer in class-tc-ajax.php (#1011).
     */
    private static function canonicalize_direction(string $direction): string {
        $aliases = array(
            'pull_only'     => 'pull',
            'push_only'     => 'push',
            'bidirectional' => 'two_way',
        );
        return $aliases[$direction] ?? $direction;
    }

    /**
     * Resolve headers for the push request, parsing the optional
     * json_headers setting (newline-separated "Key: value" pairs).
     */
    private static function resolve_headers(array $settings): array {
        $out = array();
        $raw = isset($settings['json_headers']) ? (string) $settings['json_headers'] : '';
        if ($raw === '') {
            return $out;
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            list($k, $v) = array_map('trim', explode(':', $line, 2));
            if ($k !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Resolve table settings. Test seam: when $gt_test_table_settings_override
     * is set, use it directly. Otherwise fetch from the persistence layer.
     */
    private static function resolve_table_settings(int $table_id): array {
        global $gt_test_table_settings_override;
        if (is_array($gt_test_table_settings_override)) {
            return $gt_test_table_settings_override;
        }
        if (class_exists('TC_Table_Persistence_Service')) {
            $row = method_exists('TC_Table_Persistence_Service', 'get_table')
                ? TC_Table_Persistence_Service::get_table($table_id)
                : null;
            if ($row && isset($row->settings)) {
                $decoded = is_string($row->settings) ? json_decode($row->settings, true) : $row->settings;
                return is_array($decoded) ? $decoded : array();
            }
        }
        return array();
    }
}
