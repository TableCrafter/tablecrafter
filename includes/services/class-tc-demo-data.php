<?php
/**
 * TC_Demo_Data - bundled sample datasets for one-click demo tables (#2062).
 *
 * Onboarding-port epic #2061. Ports the standalone free plugin's demo-data
 * fixtures so a new user can spin up a working table instantly. The loader
 * (#2063) reads these definitions; the welcome screen (#2064) surfaces them.
 */

// @codeCoverageIgnoreStart -- ABSPATH guard; condition is always false under the test shim and runs pre-instrumentation.
if (!defined('ABSPATH') && !defined('TC_PHPUNIT_SHIM')) {
    exit;
}
// @codeCoverageIgnoreEnd

class TC_Demo_Data
{
    /** Subdirectory (relative to the plugin root) holding the fixtures. */
    const DIR = 'demo-data/';

    /**
     * #2106 - Google Sheets one-click demo. Points at Google's long-stable
     * public sample spreadsheet (the "Class Data" sheet used across Google's
     * own Sheets API docs and TableCrafter's guides), so there is no fragile
     * self-hosted sheet to keep published. Requires outbound internet; the
     * file-based demos still work offline.
     */
    const DEMO_SHEET_URL = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit#gid=0';

    /**
     * The bundled demo datasets.
     *
     * @return array<string,array{label:string,file:string,type:string}>
     */
    public static function datasets(): array
    {
        $t = static function (string $s): string {
            return function_exists('__') ? __($s, 'tc-data-tables') : $s;
        };

        return array(
            'users' => array(
                'label' => $t('Users (JSON)'),
                'file'  => 'users.json',
                'type'  => 'json',
            ),
            'products' => array(
                'label' => $t('Products (JSON)'),
                'file'  => 'products.json',
                'type'  => 'json',
            ),
            'metrics' => array(
                'label' => $t('Metrics (JSON)'),
                'file'  => 'metrics.json',
                'type'  => 'json',
            ),
            'employees' => array(
                'label' => $t('Employee List (CSV)'),
                'file'  => 'employees.csv',
                'type'  => 'csv',
            ),
            'students' => array(
                'label' => $t('Students (Google Sheets)'),
                'url'   => self::DEMO_SHEET_URL,
                'type'  => 'google_sheets',
            ),
        );
    }

    /**
     * #2063 - Build the table settings for a demo dataset (a JSON or CSV remote
     * source pointing at the bundled fixture). Null for an unknown key.
     *
     * @return array<string,mixed>|null
     */
    public static function table_settings(string $key): ?array
    {
        $datasets = self::datasets();
        if (!isset($datasets[$key])) {
            return null;
        }
        $def = $datasets[$key];
        $settings = array(
            'data_source_type' => $def['type'],
            'table_title'      => 'Demo: ' . $def['label'],
        );
        if ($def['type'] === 'google_sheets') {
            // External source - a full sheet URL, not a bundled file.
            $settings['google_sheets_url'] = (string) ($def['url'] ?? '');
        } elseif ($def['type'] === 'csv') {
            $settings['csv_url'] = self::url($def['file']);
        } else {
            $settings['json_url'] = self::url($def['file']);
        }
        return $settings;
    }

    /**
     * #2063 follow-up - infer the column keys for a demo so the loader can
     * pre-select all of them (selected_fields). Without this a demo table is
     * created with a source but no columns, so it renders empty and the builder
     * preview stays blank until the user manually drags columns in. Returns the
     * ordered column keys (JSON property names / CSV headers), or [] on failure.
     *
     * @return array<int,string>
     */
    public static function columns_for(string $key): array
    {
        $datasets = self::datasets();
        if (!isset($datasets[$key])) {
            return array();
        }
        $def = $datasets[$key];

        if ($def['type'] === 'google_sheets') {
            // Network fetch of the public sheet; returns its header row as the
            // column keys. Empty (no pre-selected columns) when offline or the
            // sheet is unreachable - the table still renders, just unconfigured.
            if (!class_exists('TC_Google_Sheets')) {
                // @codeCoverageIgnoreStart -- free-build-only fallback; TC_Google_Sheets is autoloaded in this build.
                return array();
                // @codeCoverageIgnoreEnd
            }
            $gs  = \TC_Google_Sheets::get_instance();
            $csv = $gs->fetch_public_csv((string) ($def['url'] ?? ''));
            if ((function_exists('is_wp_error') && is_wp_error($csv)) || !is_string($csv) || $csv === '') {
                return array();
            }
            $parsed = $gs->parse_csv_to_rows($csv);
            return isset($parsed['headers']) && is_array($parsed['headers'])
                ? array_map('strval', $parsed['headers'])
                : array();
        }

        $url = self::url($def['file']);

        if ($def['type'] === 'csv') {
            if (!class_exists('TC_CSV_Source')) {
                // @codeCoverageIgnoreStart -- free-build-only fallback; TC_CSV_Source is autoloaded in this build.
                return array();
                // @codeCoverageIgnoreEnd
            }
            $rows = \TC_CSV_Source::get_cached($url);
            if (!is_array($rows) || empty($rows) || !is_array($rows[0])) {
                return array();
            }
            return array_map('strval', array_keys($rows[0]));
        }

        if (!class_exists('TC_JSON_Source_Service')) {
            // @codeCoverageIgnoreStart -- free-build-only fallback; TC_JSON_Source_Service is autoloaded in this build.
            return array();
            // @codeCoverageIgnoreEnd
        }
        $rows = \TC_JSON_Source_Service::fetch_from_url($url);
        if (!is_array($rows) || empty($rows)) {
            return array();
        }
        $flat = array();
        foreach ($rows as $row) {
            $flat[] = is_array($row) ? \TC_JSON_Source_Service::flatten_row($row) : array();
        }
        $keys = array();
        foreach (\TC_JSON_Source_Service::infer_columns($flat) as $col) {
            if (is_array($col) && isset($col['id'])) {
                $keys[] = (string) $col['id'];
            }
        }
        return $keys;
    }

    /**
     * If $url points at one of our OWN bundled demo files, return its contents
     * read straight from disk; otherwise null. Demo files are trusted local
     * assets, so callers can serve them without an HTTP fetch - which also makes
     * the demos work on private/local hosts where the SSRF guard (correctly)
     * blocks loopback URLs. Path-traversal-safe (basename only).
     */
    public static function read_local_body(string $url): ?string
    {
        $base = self::url(''); // e.g. https://site/wp-content/plugins/tablecrafter/demo-data/
        if (strpos($base, '://') === false || strpos($url, $base) !== 0) {
            return null;
        }
        $path = function_exists('wp_parse_url') ? wp_parse_url($url, PHP_URL_PATH) : parse_url($url, PHP_URL_PATH);
        $file = self::path(basename((string) $path));
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** Public URL of a bundled demo file. */
    public static function url(string $file): string
    {
        $base = defined('TC_PLUGIN_URL') ? TC_PLUGIN_URL : (defined('GT_PLUGIN_URL') ? GT_PLUGIN_URL : '');
        return $base . self::DIR . $file;
    }

    /** Filesystem path of a bundled demo file. */
    public static function path(string $file): string
    {
        $base = defined('TC_PLUGIN_PATH') ? TC_PLUGIN_PATH : (defined('GT_PLUGIN_PATH') ? GT_PLUGIN_PATH : '');
        return $base . self::DIR . $file;
    }
}
