<?php
/**
 * TC_CSV_Source — live remote-CSV data source.
 *
 * Issue #2010 (convergence epic #2006, Phase 2). Ported from the free plugin's
 * TC_CSV_Source. Distinct from the one-shot TC_Import: this fetches a CSV URL
 * on a TTL and re-reads it like the JSON/XML sources. Returns header-keyed
 * associative rows (or WP_Error) so the shortcode renderer is trivial.
 */

class TC_CSV_Source
{
    /** Default cache TTL in seconds. */
    const DEFAULT_TTL = 3600;

    /**
     * Fetch + cache a remote CSV URL as associative rows.
     *
     * @return array<int,array<string,string>>|WP_Error
     */
    public static function get_cached(string $url, int $ttl = self::DEFAULT_TTL)
    {
        $cache_key = 'gt_csv_' . md5($url);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Bundled demo files are trusted local assets — read from disk so the
        // demos work on private/local hosts (the SSRF gate blocks loopback URLs).
        if (class_exists('TC_Demo_Data')) {
            $local = \TC_Demo_Data::read_local_body($url);
            if ($local !== null) {
                $rows = self::parse($local);
                set_transient($cache_key, $rows, $ttl);
                return $rows;
            }
        }

        // SSRF gate — block loopback / private subnets (shared validator, #1075).
        if (function_exists('gt_validate_outbound_url') && !gt_validate_outbound_url($url)) {
            return new WP_Error(
                'gt_outbound_url_rejected',
                __('CSV URL was rejected by the outbound-URL SSRF gate.', 'tc-data-tables')
            );
        }

        $response = wp_remote_get($url, array(
            'timeout'    => 20,
            'user-agent' => 'GravityTables/' . (defined('TC_VERSION') ? TC_VERSION : 'dev'),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('gt_csv_fetch_failed', sprintf(
                /* translators: %s: error message */
                __('CSV fetch failed: %s', 'tc-data-tables'),
                $response->get_error_message()
            ));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('gt_csv_http_error', sprintf(
                /* translators: %d: HTTP status code */
                __('CSV source returned HTTP %d.', 'tc-data-tables'),
                $code
            ));
        }

        $rows = self::parse((string) wp_remote_retrieve_body($response));
        set_transient($cache_key, $rows, $ttl);

        return $rows;
    }

    /**
     * Parse CSV text into header-keyed associative rows.
     *
     * RFC-4180-ish: first non-blank line is the header; UTF-8 BOM stripped;
     * CR/CRLF normalised; blank lines skipped. Short rows pad with '';
     * extra cells beyond the header are dropped.
     *
     * @return array<int,array<string,string>>
     */
    public static function parse(string $csv): array
    {
        if (strncmp($csv, "\xef\xbb\xbf", 3) === 0) {
            $csv = substr($csv, 3);
        }

        $csv   = str_replace(array("\r\n", "\r"), "\n", $csv);
        $lines = explode("\n", trim($csv));

        $headers = array();
        $rows    = array();
        $have_headers = false;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if (!$have_headers) {
                $headers = array_map(static function ($h) {
                    return trim((string) $h);
                }, $cells);
                $have_headers = true;
                continue;
            }
            $assoc = array();
            foreach ($headers as $i => $key) {
                $assoc[(string) $key] = isset($cells[$i]) ? (string) $cells[$i] : '';
            }
            $rows[] = $assoc;
        }

        return $rows;
    }
}
