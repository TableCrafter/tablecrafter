<?php
/**
 * TC_Drilldown_Filter_Service
 *
 * Issue #568 - slice 1 of 3. Pure filter-state helper for the future
 * click-to-filter cell drill-down feature. Manages the list of
 * active drill-down filters, applies them to a rows array with AND
 * semantics, toggles individual filters idempotently, and
 * serializes / deserializes for URL persistence.
 *
 * Slice 2 ships the per-column "Enable click-to-filter" admin
 * toggle + AJAX save sanitization via `normalize_settings()`. Slice 3
 * ships the frontend (cell hover affordance + click delegation +
 * removable chips above the table + URL persistence via the
 * serializers + conflict handling with #567 row-level click links).
 *
 * Distinct from #373 per-column dropdown filters; drill-down is a
 * one-click "filter by example" UX on the cell value the user is
 * already looking at.
 *
 * @since 4.7.53
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Drilldown_Filter_Service {

    public static function defaults(): array {
        return ['drilldown_columns' => []];
    }

    public static function normalize_settings(array $settings): array {
        $out = self::defaults();
        if (!array_key_exists('drilldown_columns', $settings)) {
            return $out;
        }
        $raw = $settings['drilldown_columns'];
        if (!is_array($raw)) {
            return $out;
        }
        $clean = [];
        $seen = [];
        foreach ($raw as $col) {
            if (!is_string($col) || $col === '') { continue; }
            if (isset($seen[$col])) { continue; }
            $seen[$col] = true;
            $clean[] = $col;
        }
        $out['drilldown_columns'] = $clean;
        return $out;
    }

    public static function is_column_enabled(string $col, array $settings): bool {
        if ($col === '') { return false; }
        $cols = $settings['drilldown_columns'] ?? [];
        if (!is_array($cols)) { return false; }
        return in_array($col, $cols, true);
    }

    /**
     * Apply active filters to $rows with AND semantics. Comparison
     * is `(string) $row[col] === (string) $value`. Invalid filter
     * entries (missing keys, non-string col, non-scalar value) are
     * silently dropped before applying.
     */
    public static function apply(array $rows, array $filters): array {
        $clean = self::clean_filters($filters);
        if (empty($clean)) {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            if (self::row_matches($row, $clean)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private static function clean_filters(array $filters): array {
        $out = [];
        foreach ($filters as $f) {
            if (!is_array($f)) { continue; }
            if (!array_key_exists('col', $f) || !array_key_exists('value', $f)) { continue; }
            if (!is_string($f['col']) || $f['col'] === '') { continue; }
            $v = $f['value'];
            if (!is_scalar($v)) { continue; }
            $out[] = ['col' => $f['col'], 'value' => (string) $v];
        }
        return $out;
    }

    private static function row_matches(array $row, array $cleanFilters): bool {
        foreach ($cleanFilters as $f) {
            $col = $f['col'];
            $val = $f['value'];
            if (!array_key_exists($col, $row)) { return false; }
            if ((string) $row[$col] !== $val) { return false; }
        }
        return true;
    }

    /**
     * Toggle a {col, value} membership. Adds when not present;
     * removes when present (exact string equality on both keys).
     * Idempotent under double-call.
     */
    public static function toggle_filter(array $filters, string $col, $value): array {
        $valStr = is_scalar($value) ? (string) $value : '';
        $found = false;
        $out = [];
        foreach ($filters as $f) {
            if (!is_array($f)) { continue; }
            $fcol = isset($f['col']) && is_string($f['col']) ? $f['col'] : '';
            $fval = isset($f['value']) ? (is_scalar($f['value']) ? (string) $f['value'] : '') : '';
            if ($fcol === $col && $fval === $valStr) {
                $found = true;
                continue; // drop on remove
            }
            $out[] = ['col' => $fcol, 'value' => $fval];
        }
        if (!$found) {
            $out[] = ['col' => $col, 'value' => $valStr];
        }
        return $out;
    }

    /**
     * Serialize filters as `col:value,col:value` with rawurlencoded
     * values so commas / colons / spaces inside values are safe.
     * Empty input → ''.
     */
    public static function to_query_string(array $filters): string {
        $parts = [];
        foreach ($filters as $f) {
            if (!is_array($f)) { continue; }
            $col = isset($f['col']) && is_string($f['col']) ? $f['col'] : '';
            if ($col === '') { continue; }
            $val = isset($f['value']) ? (is_scalar($f['value']) ? (string) $f['value'] : '') : '';
            $parts[] = $col . ':' . rawurlencode($val);
        }
        return implode(',', $parts);
    }

    public static function from_query_string($q): array {
        if (!is_string($q) || $q === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $q) as $token) {
            $token = trim($token);
            if ($token === '') { continue; }
            $sep = strpos($token, ':');
            if ($sep === false) { continue; }   // bare token without ':' - drop
            $col = substr($token, 0, $sep);
            $val = substr($token, $sep + 1);
            if ($col === '') { continue; }
            $out[] = ['col' => $col, 'value' => rawurldecode($val)];
        }
        return $out;
    }
}
