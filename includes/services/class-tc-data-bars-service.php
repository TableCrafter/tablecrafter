<?php
/**
 * TC_Data_Bars_Service
 *
 * Issue #1731 - Data Bars (Pro): per-column, CSS-only in-cell horizontal
 * value bars for numeric columns. Pure helpers shared by the save
 * sanitizer (TC_Admin::save_table) and the server-side template preview
 * (templates/table.php). The live frontend render is handled in JS
 * (assets/js/frontend/data-bars.js); this PHP mirror keeps the admin
 * builder preview and the SSR/non-JS path in parity.
 *
 * Design: a bar is emitted ONLY as a `data-gt-bar-pct` attribute + the
 * `--gt-bar-pct` / `--gt-bar-color` CSS custom properties on the <td>,
 * driving a low-opacity `::after` underlay. NO markup is injected inside
 * the cell, so totals / export / conditional-format / inline-edit keep
 * reading the bare number.
 *
 * @since 6.3.5
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Data_Bars_Service {

    const DEFAULT_COLOR = '#3b82f6';

    /**
     * Sanitize the raw column_data_bars map. Pro-gated: returns [] on the
     * free tier (persist-strip), so a free user can never persist the key
     * even via a hand-edited save payload.
     *
     * @param array $raw        field_id => { enabled, color } map.
     * @param bool  $is_premium gt_is_premium() result.
     * @return array Sanitized map, or [] when not premium.
     */
    public static function sanitize(array $raw, bool $is_premium): array {
        if (!$is_premium) {
            return array();
        }
        $out = array();
        foreach ($raw as $field_id => $v) {
            if (!is_array($v)) {
                continue;
            }
            $out[(string) $field_id] = array(
                'enabled'    => !empty($v['enabled']),
                'color'      => self::sanitize_color(isset($v['color']) ? $v['color'] : ''),
                // #1738 - visual sub-options (Pro-only)
                'show_label' => !empty($v['show_label']),
                'gradient'   => !empty($v['gradient']),
                'bipolar'    => !empty($v['bipolar']),
                'sparkline'  => !empty($v['sparkline']),
            );
        }
        return $out;
    }

    /**
     * Numeric coercion mirroring the JS gtParseNumeric (util.js): strip
     * currency symbols / thousands separators, then parse. Returns the
     * float or null when not finite-numeric.
     *
     * #1736 - locale-aware two-pass normalizer. Heuristic: the last
     * separator is the decimal separator ONLY when followed by 1 or 2
     * digits (not 3). Three digits after a separator => thousands grouping.
     *
     * Examples:
     *   "1.234,56"  -> last sep=comma, 2 digits after  => decimal comma  -> 1234.56
     *   "$1,240.00" -> last sep=period, 2 digits after => decimal period  -> 1240.0
     *   "1,899"     -> last sep=comma, 3 digits after  => thousands comma -> 1899
     *   "1,00"      -> last sep=comma, 2 digits after  => decimal comma   -> 1.0
     *
     * @param mixed $s
     * @return float|null
     */
    public static function parse_numeric($s): ?float {
        // Strip everything except digits, commas, periods, minus sign.
        $stripped = preg_replace('/[^0-9,.\-]/', '', (string) $s);
        if ($stripped === '' || $stripped === '-' || !preg_match('/\d/', $stripped)) {
            return null;
        }

        // Find the last separator and count digits after it.
        if (preg_match('/([,.])(\d+)$/', $stripped, $m)) {
            $after_last   = strlen($m[2]);
            $last_sep     = $m[1];
            $is_thousands = ($after_last === 3);

            if ($is_thousands) {
                // Last separator is a thousands char - strip all commas/periods.
                $normalized = str_replace([',', '.'], '', $stripped);
            } elseif ($last_sep === ',') {
                // Comma is the decimal separator (EU format).
                $normalized = str_replace(['.', ','], ['', '.'], $stripped);
            } else {
                // Period is the decimal separator (US format).
                $normalized = str_replace(',', '', $stripped);
            }
        } else {
            // No separator at all - plain integer.
            $normalized = $stripped;
        }

        if (!is_numeric($normalized)) {
            return null;
        }
        return (float) $normalized;
    }

    /**
     * Page-scoped per-column max over the given rows. Returns null when
     * there is no positive domain (a non-positive max yields no bar).
     *
     * @param array  $rows     List of row assoc-arrays.
     * @param string $field_id Column key.
     * @return float|null
     */
    public static function column_max(array $rows, string $field_id): ?float {
        $max = null;
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($field_id, $row)) {
                continue;
            }
            $n = self::parse_numeric($row[$field_id]);
            if ($n !== null && ($max === null || $n > $max)) {
                $max = $n;
            }
        }
        return ($max !== null && $max > 0) ? $max : null;
    }

    /**
     * Page-scoped per-column MIN over the given rows. Returns the minimum
     * numeric value found (including negatives), or null when no numeric
     * values exist.
     *
     * @since 7.5.0 (#1738)
     * @param array  $rows     List of row assoc-arrays.
     * @param string $field_id Column key.
     * @return float|null
     */
    public static function column_min(array $rows, string $field_id): ?float {
        $min = null;
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($field_id, $row)) {
                continue;
            }
            $n = self::parse_numeric($row[$field_id]);
            if ($n !== null && ($min === null || $n < $min)) {
                $min = $n;
            }
        }
        return $min;
    }

    /**
     * Build the <td> bar attribute string for a server-side cell render.
     * Returns ' data-gt-bar-pct="N" style="--gt-bar-pct:N;--gt-bar-color:
     * COLOR;"' (clamped 0-100) or '' when no bar applies (no positive max,
     * empty / non-numeric value). The returned color is already sanitized
     * to a safe hex, so the string is attribute-safe.
     *
     * @since 6.3.5
     * @since 7.5.0 (#1738) Added optional $options param for show_label support.
     *
     * @param mixed      $value   Raw cell value.
     * @param float|null $max     Page-scoped column max.
     * @param string     $color   Configured bar color.
     * @param array      $options Visual sub-options: show_label, gradient, bipolar, sparkline.
     * @return string
     */
    public static function cell_attr($value, ?float $max, string $color, array $options = []): string {
        if (!($max !== null && $max > 0)) {
            return '';
        }
        $v = self::parse_numeric($value);
        if ($v === null) {
            return '';
        }
        $pct = (int) round(($v / $max) * 100);
        if ($pct < 0) { $pct = 0; }
        if ($pct > 100) { $pct = 100; }
        $color = self::sanitize_color($color);
        $attr  = ' data-gt-bar-pct="' . $pct . '" style="--gt-bar-pct:' . $pct . ';--gt-bar-color:' . $color . ';"';

        // #1738 - show_label: render the formatted cell value as a visible
        // <span class="gt-bar-label"> alongside the ::after underlay.
        if (!empty($options['show_label'])) {
            $attr .= '<span class="gt-bar-label">' . esc_html((string) $value) . '</span>';
        }

        return $attr;
    }

    /**
     * Build the <td> bar attribute string for a bipolar (centered-axis) bar.
     * Maps the value into the signed range [-100, +100]: positive → right of
     * axis, negative → left of axis.
     *
     * Returns a string with `data-gt-bar-signed-pct` + CSS vars, or '' when
     * the value is non-numeric or the min/max range is zero.
     *
     * @since 7.5.0 (#1738)
     *
     * @param mixed  $value Raw cell value.
     * @param float  $min   Page-scoped column min (may be negative).
     * @param float  $max   Page-scoped column max.
     * @param string $color Configured bar color.
     * @return string
     */
    public static function cell_attr_bipolar($value, float $min, float $max, string $color): string {
        $range = $max - $min;
        if ($range == 0) {
            return '';
        }
        $v = self::parse_numeric($value);
        if ($v === null) {
            return '';
        }
        // Map value into [-100, +100] centered at 0 (axis).
        // Signed pct = (value / max) * 100 when positive,
        //              (value / abs(min)) * 100 when negative.
        if ($v >= 0) {
            $signed_pct = ($max > 0) ? (int) round(($v / $max) * 100) : 0;
        } else {
            $signed_pct = ($min < 0) ? (int) round(($v / abs($min)) * 100) : 0;
        }
        if ($signed_pct > 100)  { $signed_pct = 100; }
        if ($signed_pct < -100) { $signed_pct = -100; }
        $color = self::sanitize_color($color);
        return ' data-gt-bar-signed-pct="' . $signed_pct . '" style="--gt-bar-signed-pct:' . $signed_pct . ';--gt-bar-color:' . $color . ';"';
    }

    /**
     * Numeric column types that qualify for data bars. Mirrors the JS
     * NUMERIC_TYPES constant in data-bars.js so PHP and JS agree.
     */
    private const NUMERIC_TYPES = ['number', 'quantity', 'total', 'calculation'];

    /**
     * Compute the full-filtered-set per-column MAX for every enabled,
     * numeric-type bar column. Runs one
     *   SELECT MAX(CAST(em.meta_value AS DECIMAL(20,6)))
     * query per qualifying column against the SAME WHERE clause used by
     * the pagination count query, so the returned max spans the entire
     * filtered result set - not just the current page.
     *
     * Returns a flat map { field_id (string) => float } for fields whose
     * MAX is a positive finite number. Fields with NULL or non-positive
     * MAX are omitted.
     *
     * @since 7.2.0 (#1733)
     *
     * @param array  $bar_config      column_data_bars map (field_id => {enabled, color}).
     * @param array  $column_config   column_config map (field_id => {type, ...}).
     * @param string $base_where_sql  The WHERE clause (without "WHERE"), already containing
     *                                %d/%s placeholders, matching the count query.
     * @param array  $where_params    Ordered parameter array for $base_where_sql.
     * @param object $wpdb            wpdb instance (injected for testability).
     * @return array<string, float>
     */
    public static function compute_filtered_maxes(
        array  $bar_config,
        array  $column_config,
        string $base_where_sql,
        array  $where_params,
        object $wpdb
    ): array {
        if (empty($bar_config)) {
            return array();
        }

        $out = array();

        foreach ($bar_config as $field_id => $cfg) {
            $field_id = (string) $field_id;

            // Skip disabled bars.
            if (!is_array($cfg) || empty($cfg['enabled'])) {
                continue;
            }

            // Skip non-numeric column types.
            $col_type = isset($column_config[$field_id]['type'])
                ? (string) $column_config[$field_id]['type']
                : '';
            if (!in_array($col_type, self::NUMERIC_TYPES, true)) {
                continue;
            }

            // One MAX query per qualifying column, reusing the same WHERE
            // clause as the count query so filters are respected.
            $sql = $wpdb->prepare(
                "SELECT MAX(CAST(em.meta_value AS DECIMAL(20,6)))
                 FROM {$wpdb->prefix}gf_entry e
                 LEFT JOIN {$wpdb->prefix}gf_entry_meta em
                       ON e.id = em.entry_id AND em.meta_key = %s
                 WHERE {$base_where_sql}",
                array_merge(array($field_id), $where_params)
            );

            $raw = $wpdb->get_var($sql);

            if ($raw === null || !is_numeric($raw)) {
                continue;
            }

            $max = (float) $raw;
            if ($max <= 0) {
                continue;
            }

            $out[$field_id] = $max;
        }

        return $out;
    }

    /**
     * Sanitize a hex color, falling back to the brand default. Uses the
     * WP sanitize_hex_color() when available; a self-contained regex
     * fallback keeps the service unit-testable without a WP bootstrap.
     *
     * @param mixed $color
     * @return string A safe hex color.
     */
    private static function sanitize_color($color): string {
        $color = (string) $color;
        if (function_exists('sanitize_hex_color')) {
            $c = sanitize_hex_color($color);
            return !empty($c) ? $c : self::DEFAULT_COLOR;
        }
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $color;
        }
        return self::DEFAULT_COLOR;
    }
}
