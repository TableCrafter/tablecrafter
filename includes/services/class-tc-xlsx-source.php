<?php
/**
 * TC_XLSX_Source — read a remote Excel (.xlsx) file as table rows (#1998).
 *
 * Mirrors TC_CSV_Source (transient cache + demo-disk bypass + SSRF gate + remote
 * fetch) but parses the workbook via PhpOffice\PhpSpreadsheet. The first row is
 * treated as headers; each subsequent non-empty row becomes an associative row.
 */

// @codeCoverageIgnoreStart -- ABSPATH guard; condition is always false under the test shim and runs pre-instrumentation.
if (!defined('ABSPATH') && !defined('TC_PHPUNIT_SHIM')) {
    exit;
}
// @codeCoverageIgnoreEnd

class TC_XLSX_Source
{
    const DEFAULT_TTL = 3600;

    /**
     * @return array<int,array<string,string>>|WP_Error
     */
    public static function get_cached(string $url, int $ttl = self::DEFAULT_TTL)
    {
        $cache_key = 'gt_xlsx_' . md5($url);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Bundled demo files are trusted local assets (disk bypass; #2076).
        if (class_exists('TC_Demo_Data')) {
            $local = \TC_Demo_Data::read_local_body($url);
            if ($local !== null) {
                $rows = self::parse_binary($local);
                if (!is_wp_error($rows)) {
                    set_transient($cache_key, $rows, $ttl);
                }
                return $rows;
            }
        }

        // SSRF gate — block loopback / private subnets (shared validator, #1075).
        if (function_exists('gt_validate_outbound_url') && !gt_validate_outbound_url($url)) {
            return new WP_Error(
                'gt_outbound_url_rejected',
                __('XLSX URL was rejected by the outbound-URL SSRF gate.', 'tc-data-tables')
            );
        }

        $response = wp_remote_get($url, array(
            'timeout'    => 20,
            'user-agent' => 'GravityTables/' . (defined('TC_VERSION') ? TC_VERSION : 'dev'),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('gt_xlsx_fetch_failed', sprintf(
                /* translators: %s: error message */
                __('XLSX fetch failed: %s', 'tc-data-tables'),
                $response->get_error_message()
            ));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('gt_xlsx_http_error', sprintf(
                /* translators: %d: HTTP status code */
                __('XLSX source returned HTTP %d.', 'tc-data-tables'),
                $code
            ));
        }

        $rows = self::parse_binary((string) wp_remote_retrieve_body($response));
        if (!is_wp_error($rows)) {
            set_transient($cache_key, $rows, $ttl);
        }
        return $rows;
    }

    /**
     * Parse raw .xlsx bytes into assoc rows.
     *
     * @return array<int,array<string,string>>|WP_Error
     */
    public static function parse_binary(string $bytes)
    {
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            // @codeCoverageIgnoreStart -- free-build-only fallback; PhpSpreadsheet is present in the dev/converged build.
            return new WP_Error('gt_xlsx_lib_missing', __('Spreadsheet library is not available.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $tmp = function_exists('wp_tempnam') ? wp_tempnam('gt-xlsx-') : tempnam(sys_get_temp_dir(), 'gt-xlsx-');
        file_put_contents($tmp, $bytes);
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmp);
            $matrix      = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            @unlink($tmp);
            return new WP_Error('gt_xlsx_parse_failed', sprintf(
                /* translators: %s: error message */
                __('Could not read the XLSX file: %s', 'tc-data-tables'),
                $e->getMessage()
            ));
        }
        @unlink($tmp);

        return self::rows_from_matrix($matrix);
    }

    /**
     * Convert a 2-D cell matrix (first row = headers) into associative rows.
     * Pure + side-effect-free for unit testing.
     *
     * @param array<int,array<int,mixed>> $matrix
     * @return array<int,array<string,string>>
     */
    public static function rows_from_matrix(array $matrix): array
    {
        $rows    = array();
        $headers = array();
        $have    = false;

        foreach ($matrix as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (!$have) {
                $headers = array_map(static function ($h) {
                    return trim((string) $h);
                }, $line);
                $have = true;
                continue;
            }
            // Skip fully-empty rows.
            $non_empty = array_filter($line, static function ($v) {
                return trim((string) $v) !== '';
            });
            if (empty($non_empty)) {
                continue;
            }
            $assoc = array();
            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[(string) $key] = isset($line[$i]) ? (string) $line[$i] : '';
            }
            $rows[] = $assoc;
        }

        return $rows;
    }
}
