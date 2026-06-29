<?php
/**
 * TC_JSON_Source_Service
 *
 * Issue #512 — slice 1 of 3. Pure JSON parser + column inference for
 * the new "JSON file / REST API URL" data source type. No HTTP, no
 * encryption, no admin UI in this slice — those land in slices 2/3.
 *
 * Three primitives:
 *
 *   parse($json, ?$dot_path)
 *     - Parses the JSON document.
 *     - Auto-detects the row array when the top-level is itself an
 *       array OR when it's a single-array-property object.
 *     - Walks a dot-path (with optional `[*]` sugar) when the caller
 *       provides one.
 *     - Returns [] for malformed / unresolved / non-array values
 *       so the caller's render path stays uniform.
 *
 *   infer_columns($rows, $sample_size = 25)
 *     - Looks at the first $sample_size rows.
 *     - Returns column definitions in first-seen order.
 *     - Heuristic types: number / date / email / url / text.
 *
 *   flatten_row($row, $separator = '.')
 *     - Recursively flattens a row's nested objects to dot-keyed
 *       scalars. Arrays of scalars become JSON-encoded strings.
 *     - Lets the caller keep one column per leaf instead of one
 *       column per top-level key with embedded objects.
 *
 * Slice 2 (HTTP fetcher with custom-header auth + encrypted-at-rest
 * storage + error surfacing) and slice 3 (admin UI + scheduled
 * refresh integration) build on this contract.
 *
 * @since 4.7.33
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd

use JsonMachine\Items as JsonMachineItems;
// #1075 — is_safe_url() delegates to gt_validate_outbound_url(). Pull
// the helper file in directly when running under unit-test harnesses
// that include this class without going through tablecrafter.php
// (e.g. tests/test-issue-512-*.php, tests/test-issue-984-*.php).
// @codeCoverageIgnoreStart
if (!function_exists('gt_validate_outbound_url')) {
    require_once __DIR__ . '/../helpers-url.php';
// @codeCoverageIgnoreEnd
}

class TC_JSON_Source_Service {

    /**
     * Parse a JSON document, optionally walking a dot-path, and
     * return the row array. Returns [] for malformed input,
     * unresolved paths, or non-array values.
     */
    public static function parse(string $json, ?string $dot_path = null): array {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        if ($dot_path !== null && $dot_path !== '') {
            $path = self::strip_array_sugar($dot_path);
            $value = self::walk_path($decoded, $path);
            return self::ensure_row_array($value);
        }

        // Auto-detect:
        //   - top-level array → return directly
        //   - single-array-property object → walk into it
        if (self::is_list($decoded)) {
            return $decoded;
        }
        if (count($decoded) === 1) {
            $only = reset($decoded);
            if (self::is_list($only)) {
                return $only;
            }
        }
        return [];
    }

    /**
     * Infer columns from the first $sample_size rows. Returns
     * `[{ id, label, type }]` in first-seen order.
     */
    public static function infer_columns(array $rows, int $sample_size = 25): array {
        $sample = array_slice($rows, 0, max(1, $sample_size));
        $seen_order = [];          // value-list of keys in first-seen order
        $values_per_key = [];       // key => array of sample values

        foreach ($sample as $row) {
            if (!is_array($row)) continue;
            foreach ($row as $k => $v) {
                $key = (string) $k;
                if (!in_array($key, $seen_order, true)) {
                    $seen_order[] = $key;
                    $values_per_key[$key] = [];
                }
                $values_per_key[$key][] = $v;
            }
        }

        $cols = [];
        foreach ($seen_order as $key) {
            $cols[] = [
                'id'    => $key,
                'label' => self::humanize($key),
                'type'  => self::infer_type($values_per_key[$key]),
            ];
        }
        return $cols;
    }

    /**
     * Flatten a row's nested objects to dot-keyed scalars.
     * Arrays of scalars become JSON-encoded; arrays of objects
     * pass through unchanged so the caller can decide.
     */
    public static function flatten_row(array $row, string $separator = '.'): array {
        $out = [];
        self::flatten_into($row, '', $separator, $out);
        return $out;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    private static function strip_array_sugar(string $path): string {
        // Trailing `[*]` is sugar for "treat the resolved value as an array".
        if (substr($path, -3) === '[*]') {
            return substr($path, 0, -3);
        }
        return $path;
    }

    private static function walk_path(array $root, string $path) {
        $segments = explode('.', $path);
        $cursor = $root;
        foreach ($segments as $seg) {
            if (!is_array($cursor) || !array_key_exists($seg, $cursor)) {
                return null;
            }
            $cursor = $cursor[$seg];
        }
        return $cursor;
    }

    private static function ensure_row_array($value): array {
        if (!is_array($value)) {
            return [];
        }
        if (!self::is_list($value)) {
            return [];
        }
        return $value;
    }

    /**
     * Local "is a list (sequential 0-indexed array)?" predicate.
     * PHP 8.1+ has `array_is_list` natively but we polyfill for
     * 8.0 compat.
     */
    private static function is_list(array $a): bool {
        if (function_exists('array_is_list')) {
            return array_is_list($a);
        }
        // @codeCoverageIgnoreStart
        if ($a === []) return true;
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) return false;
        // @codeCoverageIgnoreEnd
        }
        // @codeCoverageIgnoreStart
        return true;
        // @codeCoverageIgnoreEnd
    }

    private static function infer_type(array $values): string {
        $non_empty = array_filter($values, function ($v) {
            return $v !== null && $v !== '';
        });
        if (empty($non_empty)) {
            return 'text';
        }
        $first = reset($non_empty);
        // All-numeric → number.
        $all_numeric = true;
        foreach ($non_empty as $v) {
            if (!is_numeric($v)) { $all_numeric = false; break; }
        }
        if ($all_numeric) return 'number';

        // ISO-8601 date (YYYY-MM-DD optionally with time).
        $all_date = true;
        foreach ($non_empty as $v) {
            if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}/', $v) !== 1) {
                $all_date = false;
                break;
            }
        }
        if ($all_date) return 'date';

        // Email.
        $all_email = true;
        foreach ($non_empty as $v) {
            if (!is_string($v) || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                $all_email = false;
                break;
            }
        }
        if ($all_email) return 'email';

        // URL.
        $all_url = true;
        foreach ($non_empty as $v) {
            if (!is_string($v) || filter_var($v, FILTER_VALIDATE_URL) === false) {
                $all_url = false;
                break;
            }
        }
        if ($all_url) return 'url';

        return 'text';
    }

    private static function humanize(string $key): string {
        $h = preg_replace('/[_-]+/', ' ', $key);
        return ucfirst(trim($h));
    }

    private static function flatten_into($value, string $prefix, string $separator, array &$out): void {
        if (is_array($value) && !self::is_list($value)) {
            // Associative — recurse.
            foreach ($value as $k => $v) {
                $key = $prefix === '' ? (string) $k : $prefix . $separator . $k;
                self::flatten_into($v, $key, $separator, $out);
            }
            return;
        }
        if (is_array($value) && self::is_list($value)) {
            // Decide: scalar list vs object list.
            $all_scalar = true;
            foreach ($value as $item) {
                if (is_array($item)) { $all_scalar = false; break; }
            }
            if ($all_scalar) {
                $out[$prefix] = json_encode(array_values($value));
            } else {
                // Pass through so the caller can decide what to do
                // with an array of objects (a sub-table, perhaps).
                $out[$prefix] = $value;
            }
            return;
        }
        // Scalar.
        $out[$prefix] = $value;
    }

    /**
     * #980 v4.165.0 — slice 2 of #512.
     *
     * Fetch a JSON document from a remote URL with optional auth headers,
     * SSRF guard, and structured error surfacing. Returns the parsed row
     * array on success or a WP_Error on any failure path.
     *
     * @param string             $url      Public HTTP(S) URL. Localhost / loopback / private subnets are rejected.
     * @param array<string,string> $headers Optional headers (e.g. ['Authorization' => 'Bearer ...']).
     * @param int                $timeout  Hard timeout in seconds. Defaults to 15.
     * @param string|null        $dot_path Optional JSON dot-path forwarded to parse().
     * @return array|\WP_Error
     */
    public static function fetch_from_url(string $url, array $headers = [], int $timeout = 15, ?string $dot_path = null) {
        // Bundled demo files are trusted local assets — read from disk so the
        // demos work on private/local hosts (the SSRF guard blocks loopback URLs).
        if (class_exists('TC_Demo_Data')) {
            $local = \TC_Demo_Data::read_local_body($url);
            if ($local !== null) {
                return self::parse($local, $dot_path);
            }
        }

        if (!self::is_safe_url($url)) {
            return new \WP_Error(
                'gt_json_source_unsafe_url',
                __('URL must be a public HTTP(S) endpoint. Loopback and private-network hosts are blocked.', 'tc-data-tables')
            );
        }

        $tmp = wp_tempnam('gt-json-');

        $args = array(
            'timeout'    => max(1, $timeout),
            'headers'    => $headers,
            'stream'     => true,
            'filename'   => $tmp,
            // Identifies our request so server admins can rate-limit us specifically.
            'user-agent' => 'GravityTables/' . TC_VERSION . ' (+https://github.com/TableCrafter/gravity-tables)',
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            @unlink($tmp); // @codeCoverageIgnore
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            // Read a short excerpt from the streamed file for the error message.
            $body = '';
            $fh   = @fopen($tmp, 'r');
            if ($fh) {
                $body = (string) fread($fh, 200);
                fclose($fh);
            }
            @unlink($tmp);
            return new \WP_Error(
                'gt_json_source_http_error',
                sprintf(
                    /* translators: 1: HTTP status code, 2: short body excerpt. */
                    __('Remote endpoint returned HTTP %1$d. Response excerpt: %2$s', 'tc-data-tables'),
                    $status,
                    function_exists('mb_substr') ? mb_substr($body, 0, 200) : substr($body, 0, 200)
                ),
                array('status' => $status)
            );
        }

        if (!file_exists($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            return new \WP_Error(
                'gt_json_source_empty_body',
                __('Remote endpoint returned an empty body.', 'tc-data-tables')
            );
        }

        try {
            $rows = self::stream_parse_rows($tmp, $dot_path);
        } catch (\Throwable $e) {
            @unlink($tmp);
            return new \WP_Error(
                'gt_json_source_malformed',
                sprintf(
                    /* translators: %s is the JSON parse error message. */
                    __('Remote endpoint returned malformed JSON: %s', 'tc-data-tables'),
                    $e->getMessage()
                )
            );
        }

        @unlink($tmp);
        return $rows;
    }

    /**
     * True streaming preview fetch: opens an HTTP connection, parses JSON
     * incrementally with halaxa/json-machine, and aborts as soon as $limit
     * rows are collected. The TCP connection is dropped at that point — the
     * server stops sending. For a 5-row preview of a 5MB file this downloads
     * ~5–20KB instead of the full payload.
     *
     * Strategy:
     *   1. Send `Range: bytes=0-131071` — servers that honour it (GitHub, S3,
     *      CDNs, most REST APIs) return only the first 128KB via HTTP 206.
     *   2. Parse incrementally via Items::fromStream(); break after $limit rows.
     *      This aborts the connection even when Range is not supported.
     *   3. json-machine throws on truncated JSON (206 cutoff or early break).
     *      Catch \Throwable and return whatever rows were parsed — that is the
     *      correct behaviour for a "give me 5 sample rows" preview.
     *   4. Falls back to fetch_from_url() when allow_url_fopen is off.
     *
     * @param string      $url      Public HTTPS URL (SSRF-checked before call).
     * @param array<string,string> $headers Caller-supplied auth headers.
     * @param string|null $dot_path Optional JSON dot-path into the document.
     * @param int         $limit    Stop after this many rows (default 5).
     * @return array|\WP_Error
     */
    public static function fetch_preview_rows(
        string $url,
        array $headers = [],
        ?string $dot_path = null,
        int $limit = 5
    ) {
        // Bundled demo files are trusted local assets — read from disk (see
        // fetch_from_url) so "Test connection" works on private/local hosts.
        if (class_exists('TC_Demo_Data')) {
            $local = \TC_Demo_Data::read_local_body($url);
            if ($local !== null) {
                return array_slice(self::parse($local, $dot_path), 0, max(1, $limit));
            }
        }

        if (!self::is_safe_url($url)) {
            return new \WP_Error(
                'gt_json_source_unsafe_url',
                __('URL must be a public HTTP(S) endpoint. Loopback and private-network hosts are blocked.', 'tc-data-tables')
            );
        }

        // Fall back to the full-download path when allow_url_fopen is disabled.
        if (!ini_get('allow_url_fopen')) {
            return self::fetch_from_url($url, $headers, 60, $dot_path);
        }

        // Build the request headers. Range asks the server to send only the
        // first 128KB; most CDNs and REST APIs honour this with HTTP 206.
        $req_headers = array_merge(
            [
                'Range'       => 'bytes=0-131071',
                'User-Agent'  => 'GravityTables/' . TC_VERSION . ' (+https://github.com/TableCrafter/gravity-tables)',
                'Accept'      => 'application/json',
            ],
            $headers
        );
        $header_string = implode("\r\n", array_map(
            static fn(string $k, string $v): string => "$k: $v",
            array_keys($req_headers),
            array_values($req_headers)
        ));

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => $header_string,
                'timeout'       => 30,
                'ignore_errors' => true, // Don't throw on 4xx/5xx — we check the status ourselves.
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            return new \WP_Error(
                'gt_json_source_connection_failed',
                __('Could not open a streaming connection to the URL.', 'tc-data-tables')
            );
        }

        // Check HTTP status from response headers.
        $meta   = stream_get_meta_data($stream);
        $status = 0;
        if (!empty($meta['wrapper_data'])) {
            foreach ($meta['wrapper_data'] as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string) $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }
        // 200 = full response (Range ignored or not supported — still works).
        // 206 = partial content (Range honoured — fastest path).
        // Anything else is an error.
        if ($status !== 0 && ($status < 200 || $status >= 300) && $status !== 206) {
            fclose($stream);
            return new \WP_Error(
                'gt_json_source_http_error',
                sprintf(
                    __('Remote endpoint returned HTTP %d.', 'tc-data-tables'),
                    $status
                ),
                ['status' => $status]
            );
        }

        // Build JSON Pointer from dot_path.
        $pointer = '';
        if ($dot_path !== null && $dot_path !== '') {
            $clean   = self::strip_array_sugar($dot_path);
            $pointer = '/' . str_replace('.', '/', $clean);
        }

        $rows = [];
        try {
            $opts  = $pointer !== '' ? ['pointer' => $pointer] : [];
            foreach (JsonMachineItems::fromStream($stream, $opts) as $item) {
                $rows[] = is_array($item) ? $item : (array) $item;
                if (count($rows) >= $limit) {
                    break; // Drop the TCP connection — server stops sending.
                }
            }
        } catch (\Throwable $e) {
            // json-machine throws SyntaxError on truncated JSON, which is
            // expected when Range cutoff or early break interrupts the stream.
            // Return whatever rows were parsed before the cutoff.
            if (empty($rows)) {
                fclose($stream);
                return new \WP_Error(
                    'gt_json_source_malformed',
                    sprintf(__('Remote endpoint returned malformed JSON: %s', 'tc-data-tables'), $e->getMessage())
                );
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }

    /**
     * Stream-parse a downloaded JSON temp file using halaxa/json-machine.
     * Avoids loading the full decoded structure into memory simultaneously
     * with the raw body string — critical for 5MB+ responses.
     *
     * @param string      $path     Absolute path to the temp file.
     * @param string|null $dot_path Optional dot-path into the JSON tree.
     * @return array|\WP_Error
     */
    private static function stream_parse_rows(string $path, ?string $dot_path) {
        // Explicit dot_path: convert to JSON Pointer and stream directly.
        if ($dot_path !== null && $dot_path !== '') {
            $clean   = self::strip_array_sugar($dot_path);
            $pointer = '/' . str_replace('.', '/', $clean);
            $rows    = [];
            foreach (JsonMachineItems::fromFile($path, ['pointer' => $pointer]) as $item) {
                $rows[] = is_array($item) ? $item : (array) $item;
            }
            return $rows;
        }

        // Auto-detect top-level structure by peeking at the first non-whitespace byte.
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return new \WP_Error('gt_json_source_read_error',
                __('Could not open downloaded JSON temp file.', 'tc-data-tables'));
        }
        $first = '';
        while (!feof($fh) && $first === '') {
            $ch = fgetc($fh);
            if ($ch !== false && trim($ch) !== '') {
                $first = $ch;
            }
        }
        fclose($fh);

        if ($first === '[') {
            // Top-level array — stream each item without loading all into memory at once.
            $rows = [];
            foreach (JsonMachineItems::fromFile($path) as $item) {
                $rows[] = is_array($item) ? $item : (array) $item;
            }
            return $rows;
        }

        if ($first === '{') {
            // Object — mirror parse()'s single-array-key auto-detect.
            $pairs = [];
            foreach (JsonMachineItems::fromFile($path) as $key => $value) {
                $pairs[(string) $key] = $value;
            }
            if (count($pairs) === 1) {
                $only = reset($pairs);
                if (is_array($only) && self::is_list($only)) {
                    return $only;
                }
            }
            return [];
        }

        return new \WP_Error(
            'gt_json_source_malformed',
            sprintf(
                __('Remote endpoint returned malformed JSON: %s', 'tc-data-tables'),
                json_last_error_msg() ?: 'unexpected start of document'
            )
        );
    }

    /**
     * #1919 — Paginated REST API fetch.
     *
     * Follows a paginated REST API endpoint across multiple pages, merging
     * the row arrays from every page into a single flat result. Stops when:
     *   - No next-page URL is detectable (end of dataset), OR
     *   - $max_pages pages have been fetched (safety ceiling, default 50), OR
     *   - The next-page URL is identical to the current URL (infinite-loop guard).
     *
     * Next-page detection (two strategies, in order):
     *   1. RFC 5988 Link response header — Link: <URL>; rel="next"
     *   2. Response-body JSON field — {"next":"URL"} or {"next_url":"URL"}
     *      at the top level or inside a "links"/"pagination" envelope.
     *
     * All HTTP requests share the same $headers and $timeout as fetch_from_url
     * and every URL is passed through the SSRF guard before fetching.
     *
     * @param string               $url        Starting URL.
     * @param array<string,string> $headers    Optional request headers.
     * @param int                  $timeout    Per-request timeout in seconds.
     * @param string|null          $dot_path   Dot-path to the row array within each page's body.
     * @param int                  $max_pages  Maximum pages to follow (default 50).
     * @return array|\WP_Error     Merged row array on success, WP_Error on first-page failure.
     */
    public static function fetch_paginated(
        string $url,
        array $headers = [],
        int $timeout = 15,
        ?string $dot_path = null,
        int $max_pages = 50
    ) {
        $all_rows    = [];
        $current_url = $url;
        $page_count  = 0;

        while ($current_url !== '' && $page_count < $max_pages) {
            if (!self::is_safe_url($current_url)) {
                $err = new \WP_Error(
                    'gt_json_source_unsafe_url',
                    __('URL must be a public HTTP(S) endpoint. Loopback and private-network hosts are blocked.', 'tc-data-tables')
                );
                // Return error on first page; on subsequent pages stop silently.
                return ($page_count === 0) ? $err : $all_rows;
            }

            $args = array(
                'timeout'    => max(1, $timeout),
                'headers'    => $headers,
                'user-agent' => 'GravityTables/' . TC_VERSION . ' (+https://github.com/TableCrafter/gravity-tables)',
            );

            $response = wp_remote_get($current_url, $args);

            if (is_wp_error($response)) {
                return ($page_count === 0) ? $response : $all_rows;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body   = (string) wp_remote_retrieve_body($response);

            if ($status < 200 || $status >= 300) {
                $err = new \WP_Error(
                    'gt_json_source_http_error',
                    sprintf(
                        /* translators: 1: HTTP status code, 2: short body excerpt. */
                        __('Remote endpoint returned HTTP %1$d. Response excerpt: %2$s', 'tc-data-tables'),
                        $status,
                        function_exists('mb_substr') ? mb_substr($body, 0, 200) : substr($body, 0, 200)
                    ),
                    array('status' => $status, 'body' => $body)
                );
                return ($page_count === 0) ? $err : $all_rows;
            }

            $page_rows = self::parse($body, $dot_path);
            $all_rows  = array_merge($all_rows, $page_rows);
            $page_count++;

            // ── Detect next-page URL ────────────────────────────────────────
            $next_url = '';

            // Strategy 1: RFC 5988 Link header.
            $link_header = wp_remote_retrieve_header($response, 'link');
            if ($link_header) {
                // Match: <URL>; rel="next"  (double or single quotes, or unquoted)
                if (preg_match('/<([^>]+)>\s*;\s*rel=["\']?next["\']?/i', $link_header, $m)) {
                    $next_url = $m[1];
                }
            }

            // Strategy 2: Body JSON field (next / next_url / links.next / pagination.next).
            if ($next_url === '' && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    foreach (['next', 'next_url', 'nextUrl', 'next_page_url'] as $key) {
                        if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                            $next_url = $decoded[$key];
                            break;
                        }
                    }
                    // Envelope: {"links":{"next":"..."}} or {"pagination":{"next":"..."}}
                    if ($next_url === '') {
                        foreach (['links', 'pagination', 'meta'] as $env) {
                            if (isset($decoded[$env]) && is_array($decoded[$env])) {
                                foreach (['next', 'next_url'] as $key) {
                                    if (isset($decoded[$env][$key]) && is_string($decoded[$env][$key]) && $decoded[$env][$key] !== '') {
                                        $next_url = $decoded[$env][$key];
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Infinite-loop guard: stop if next URL is the same as current.
            if ($next_url === $current_url) {
                break;
            }

            $current_url = $next_url;
        }

        return $all_rows;
    }

    /**
     * #980 v4.165.0 — SSRF guard.
     * #1075 v5.2.0 — delegates to gt_validate_outbound_url() so every
     * outbound HTTP call site in the plugin shares one well-tested gate.
     * The new helper also adds a DNS-rebinding re-check that this
     * inlined version explicitly skipped (see prior docblock).
     *
     * Kept as a public method so call sites that already use
     * TC_JSON_Source_Service::is_safe_url() (json-push-engine,
     * sanitization-service) need no change.
     */
    public static function is_safe_url(string $url): bool {
        // #1075
        return function_exists('gt_validate_outbound_url')
            ? gt_validate_outbound_url($url)
            : false; // fail closed if the helper was somehow not loaded
    }

    /**
     * #987 v4.169.0 — slice 3b-3a of #512.
     *
     * Per-table transient-cached wrapper around fetch_from_url. Reads the
     * table's stored settings (json_url, json_headers, json_dot_path,
     * json_refresh_minutes), looks up the cached rows, fetches on miss,
     * stores back to the transient.
     *
     * Slice 3b-3b will call this from the frontend render path. This slice
     * is the primitive — also useful for admin debug, REST endpoints, etc.
     *
     * @param int  $table_id      The wp_gravity_tables row id.
     * @param bool $force_refresh If true, bypass cache and re-fetch.
     * @return array|\WP_Error    Parsed rows on success; WP_Error on any failure path.
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
        if ($source_type !== 'json') {
            return new \WP_Error('gt_not_json_source', __('Table is not a JSON-source table', 'tc-data-tables'));
        }

        $url = isset($settings['json_url']) ? (string) $settings['json_url'] : '';
        if ($url === '') {
            return new \WP_Error('gt_missing_json_url', __('JSON URL is not configured for this table', 'tc-data-tables'));
        }

        $headers  = isset($settings['json_headers']) && is_array($settings['json_headers'])
            ? array_map('strval', $settings['json_headers'])
            : array();
        $dot_path = !empty($settings['json_dot_path']) ? (string) $settings['json_dot_path'] : null;

        // Refresh window: in minutes per the storage contract (#984), clamped to
        // [5, 1440]. Convert to seconds for the transient. Apply a hard ceiling
        // of DAY_IN_SECONDS (24h) and floor of 5*MINUTE_IN_SECONDS (300s) so
        // even a misconfigured filter cannot leave a stale cache for weeks.
        $refresh_minutes = isset($settings['json_refresh_minutes']) ? (int) $settings['json_refresh_minutes'] : 30;
        $ttl = max(5 * MINUTE_IN_SECONDS, min(DAY_IN_SECONDS, $refresh_minutes * MINUTE_IN_SECONDS));

        $transient_key = 'gt_json_table_' . $table_id;

        if (!$force_refresh) {
            $cached = get_transient($transient_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $rows = self::fetch_from_url($url, $headers, 15, $dot_path);
        if (is_wp_error($rows)) {
            // Don't cache errors — next page view should retry. Surfaced upstream.
            return $rows;
        }

        // Cache only on success.
        set_transient($transient_key, $rows, $ttl);

        return $rows;
    }
}
