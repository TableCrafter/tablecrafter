<?php
/**
 * TC_Airtable_Request_Builder
 *
 * Issue #517 — slice 1 of N. Pure-function builder for Airtable REST
 * API requests. No HTTP — slice 2 (sync engine) layers on top of this.
 *
 * Pairs with the existing TC_Airtable_Field_Mapper (schema mapping).
 *
 * Reference: https://airtable.com/developers/web/api/list-records
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Airtable_Request_Builder {

    const API_BASE = 'https://api.airtable.com/v0';
    const PAGE_SIZE_MAX = 100; // Airtable spec.
    const PAGE_SIZE_MIN = 1;

    /**
     * Build the URL for a list-records request.
     *
     * @param string $base_id   e.g. "appAbc123"
     * @param string $table_id  table name or id (URL-encoded inside this method)
     * @param array  $params    optional: ['offset' => string, 'page_size' => int]
     */
    public static function build_list_url(string $base_id, string $table_id, array $params = []): string {
        $url = self::API_BASE . '/' . self::sanitize_id($base_id) . '/' . rawurlencode($table_id);
        $query = [];
        if (isset($params['offset']) && $params['offset'] !== '') {
            $query['offset'] = (string) $params['offset'];
        }
        if (isset($params['page_size'])) {
            $size = (int) $params['page_size'];
            if ($size < self::PAGE_SIZE_MIN) $size = self::PAGE_SIZE_MIN;
            if ($size > self::PAGE_SIZE_MAX) $size = self::PAGE_SIZE_MAX;
            $query['pageSize'] = $size;
        }
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    /**
     * Build the URL for a single-record GET / PATCH / DELETE request.
     * Returns empty string when the record id is invalid (empty or
     * contains characters Airtable record ids never have).
     */
    public static function build_record_url(string $base_id, string $table_id, string $record_id): string {
        $rid = trim($record_id);
        if ($rid === '' || preg_match('/^[A-Za-z0-9_-]+$/', $rid) !== 1) {
            return '';
        }
        return self::API_BASE . '/' . self::sanitize_id($base_id) . '/' . rawurlencode($table_id) . '/' . rawurlencode($rid);
    }

    /**
     * Build the HTTP headers array for a request. Returns empty array
     * for empty token (fail-closed; callers shouldn't fire requests
     * without auth).
     */
    public static function build_headers(string $token): array {
        if ($token === '') {
            return [];
        }
        return [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Extract the pagination offset from a list-records response. Empty
     * string means no more pages.
     */
    public static function parse_pagination_offset(array $response): string {
        return isset($response['offset']) ? (string) $response['offset'] : '';
    }

    /**
     * Sanitize a base id. Airtable ids are alphanumeric (with the
     * occasional underscore); strip anything else to defend against
     * accidental URL injection from misconfigured admin input.
     */
    public static function sanitize_id(string $id): string {
        return preg_replace('/[^A-Za-z0-9_]+/', '', $id);
    }
}
