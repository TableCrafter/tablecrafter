<?php
/**
 * TC_Multiselect_Filter_Service
 *
 * Issue #797 (child of #793). GF `multiselect` fields store the
 * chosen values as a comma-separated string in `$entry[$field_id]`.
 * Without this service, filter / sort treat the CSV blob as a
 * single string - facet-style filtering (OR-match across N
 * distinct values) isn't possible.
 *
 * Pure helpers - no WP / GFAPI coupling. Slice 1 + 2 of this
 * issue rolled into one PR per the resumed-loop convention.
 *
 * @since 4.75.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Multiselect_Filter_Service {

    /**
     * Split a stored multiselect CSV blob into its constituent
     * values. Empty / whitespace-only entries are dropped; leading
     * + trailing whitespace trimmed per value.
     *
     * Defensive: also accepts an already-deserialised array (some
     * legacy entry shapes store the chosen values as a PHP array
     * rather than CSV).
     *
     * @return array<int,string>
     */
    public static function split_values($value): array {
        if (is_array($value)) {
            $vals = $value;
        } elseif (is_string($value)) {
            if ($value === '') {
                return [];
            }
            $vals = explode(',', $value);
        } else {
            return [];
        }
        $out = [];
        foreach ($vals as $v) {
            if (!is_scalar($v)) { continue; }
            $v = trim((string) $v);
            if ($v === '') { continue; }
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Distinct values across every row's CSV for a given field id.
     * Used by the filter UI to render facet checkboxes - each
     * distinct value gets its own checkbox so the customer can
     * OR-select multiple.
     *
     * Ordering: alphabetical (case-insensitive). Stable across runs
     * so the filter UI doesn't reorder when rows are added/removed.
     *
     * @param array $rows  Iterable of entry arrays.
     * @param string $field_id  GF field id (the key of the CSV blob in each row).
     * @return array<int,string>
     */
    public static function distinct_values(array $rows, string $field_id): array {
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row[$field_id])) { continue; }
            foreach (self::split_values($row[$field_id]) as $v) {
                $seen[$v] = true;
            }
        }
        $values = array_keys($seen);
        usort($values, function ($a, $b) {
            return strcasecmp($a, $b);
        });
        return $values;
    }

    /**
     * Test whether a row's CSV cell contains any of the selected
     * facet values (OR-match). Empty selection → always true (no
     * filter active).
     */
    public static function row_matches($cell_value, array $selected): bool {
        if (empty($selected)) {
            return true;
        }
        $cell_values = self::split_values($cell_value);
        if (empty($cell_values)) {
            return false;
        }
        // Case-insensitive containment.
        $cell_lc = array_map('strtolower', array_map('strval', $cell_values));
        foreach ($selected as $needle) {
            if (!is_scalar($needle)) { continue; }
            $needle_lc = strtolower(trim((string) $needle));
            if ($needle_lc === '') { continue; }
            if (in_array($needle_lc, $cell_lc, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sort key for multiselect cells: the first value in the CSV
     * (case-insensitive). Empty CSVs sort last. Used by the
     * server-side sort path when the column type is `multiselect`.
     */
    public static function sort_key($value): string {
        $vals = self::split_values($value);
        if (empty($vals)) {
            // Return a high-Unicode sentinel so empty rows sort
            // after non-empty ones in ASC order.
            return "\xEF\xBF\xBF";
        }
        return strtolower($vals[0]);
    }
}
