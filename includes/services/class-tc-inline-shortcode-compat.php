<?php
/**
 * Legacy inline-shortcode compatibility (#2139).
 *
 * The 3.5.x free plugin ("TableCrafter - Data to Beautiful Tables") defined
 * tables inline and stored nothing in the database:
 *
 *   [tablecrafter source="https://api.example.com/data.json" root="data.items"
 *                 include="name,price" per_page="10" search="true"]
 *
 * v8 is id-based ([tablecrafter id="123"]). Without this shim, every legacy
 * inline shortcode renders "Error: Table ID is required" after an upgrade.
 *
 * This class holds the pure decision/mapping logic; TC_Shortcode wires it into
 * render_table() and fetches live via the existing source services
 * (TC_JSON_Source_Service::fetch_from_url, etc.), reusing their SSRF guards.
 *
 * @since 8.0.7
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Inline_Shortcode_Compat {

    /** True when the shortcode is a legacy inline source (source set, no id). */
    public static function has_inline_source(array $atts): bool {
        $source = isset($atts['source']) ? trim((string) $atts['source']) : '';
        $id     = isset($atts['id']) ? (int) $atts['id'] : 0;
        return $source !== '' && $id === 0;
    }

    /**
     * Classify an inline source URL the way 3.5.x did: a single `source=` could
     * be JSON, a CSV file, or a public Google Sheet.
     */
    public static function detect_source_type(string $url): string {
        $url = trim($url);
        if (stripos($url, 'docs.google.com/spreadsheets') !== false) {
            return 'google_sheets';
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path !== '' && preg_match('/\.csv$/i', $path)) {
            return 'csv';
        }
        return 'json';
    }

    /**
     * Apply the legacy include / exclude column curation against the columns
     * inferred from the fetched data.
     *
     * - `include` is an ordered allow-list (unknown keys dropped).
     * - `exclude` removes keys from the full set, preserving order.
     * - include wins when both are present.
     */
    public static function resolve_columns(array $available, array $atts): array {
        $include = isset($atts['include']) ? self::split_list($atts['include']) : array();
        if (!empty($include)) {
            $ordered = array();
            foreach ($include as $key) {
                if (in_array($key, $available, true)) {
                    $ordered[] = $key;
                }
            }
            return $ordered;
        }

        $exclude = isset($atts['exclude']) ? self::split_list($atts['exclude']) : array();
        if (!empty($exclude)) {
            return array_values(array_filter(
                $available,
                static function ($key) use ($exclude) {
                    return !in_array($key, $exclude, true);
                }
            ));
        }

        return $available;
    }

    /**
     * Normalize the legacy view toggles into options the renderer emits as
     * data-attributes for gt-external-interactive.js.
     *
     * Defaults preserve current v8 behavior (search ON) while restoring the
     * 3.5.x opt-ins: `export`, `filters`, a custom `per_page`, and the ability
     * to turn search OFF.
     *
     * @return array{per_page:?int,search:bool,export:bool,filters:bool}
     */
    public static function build_view_opts(array $atts): array {
        $per_page = null;
        if (isset($atts['per_page']) && (int) $atts['per_page'] > 0) {
            $per_page = (int) $atts['per_page'];
        }

        return array(
            'per_page' => $per_page,
            // Search defaults ON; only an explicit falsey value turns it off.
            'search'   => !self::is_falsey($atts['search'] ?? ''),
            'export'   => self::is_truthy($atts['export'] ?? ''),
            'filters'  => self::is_truthy($atts['filters'] ?? ''),
        );
    }

    /**
     * #2145 — Map a legacy Elementor widget's settings to an inline
     * `[tablecrafter source=...]` shortcode. Returns '' when there is no inline
     * data source (so the widget can fall back to its table-id path).
     *
     * Elementor's URL control stores `data_source` as `['url' => '...']`.
     * Reads both the 3.5.x toggle keys (enable_*) and v8's (show_*).
     */
    public static function elementor_inline_shortcode(array $settings): string {
        $ds  = $settings['data_source'] ?? '';
        $url = is_array($ds) ? (string) ($ds['url'] ?? '') : (string) $ds;
        $url = trim(str_replace(array('"', ']', '['), '', $url));
        if ($url === '') {
            return '';
        }

        $parts = array('source="' . $url . '"');

        $map = array('root' => 'root_path', 'include' => 'include_columns', 'exclude' => 'exclude_columns');
        foreach ($map as $att => $key) {
            $val = trim(str_replace(array('"', ']', '['), '', (string) ($settings[$key] ?? '')));
            if ($val !== '') {
                $parts[] = $att . '="' . $val . '"';
            }
        }

        $per_page = isset($settings['per_page']) ? (int) $settings['per_page'] : 0;
        if ($per_page > 0) {
            $parts[] = 'per_page="' . $per_page . '"';
        }

        // Toggles: honour either the 3.5.x (enable_*) or v8 (show_*) keys.
        $toggles = array(
            'search'  => array('show_search', 'enable_search'),
            'export'  => array('show_export', 'enable_export'),
            'filters' => array('show_filters', 'enable_filters'),
        );
        foreach ($toggles as $att => $keys) {
            $present = false;
            $on      = false;
            foreach ($keys as $k) {
                if (array_key_exists($k, $settings)) {
                    $present = true;
                    if (self::is_truthy($settings[$k])) {
                        $on = true;
                    }
                }
            }
            if ($present) {
                $parts[] = $att . '="' . ($on ? 'true' : 'false') . '"';
            }
        }

        return '[tablecrafter ' . implode(' ', $parts) . ']';
    }

    private static function is_truthy($v): bool {
        return in_array(strtolower(trim((string) $v)), array('true', '1', 'yes', 'on'), true);
    }

    private static function is_falsey($v): bool {
        return in_array(strtolower(trim((string) $v)), array('false', '0', 'no', 'off'), true);
    }

    /** Split a comma-separated attribute into a trimmed, non-empty list. */
    private static function split_list($raw): array {
        $parts = array_map('trim', explode(',', (string) $raw));
        return array_values(array_filter($parts, static function ($p) {
            return $p !== '';
        }));
    }
}
