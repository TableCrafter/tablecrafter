<?php
/**
 * TC_Cascading_Filter_Service
 *
 * Issue #599 — slice 1 of 3. Pure service for cascading filter
 * dropdowns (Country → State → City pattern).
 *
 * Slice 1 (this release): registry + valid-options compute + data-*
 * attribute renderer. No production caller yet.
 *
 * Slice 2 (future): wire frontend.js to listen for parent change +
 * re-populate child dropdown via AJAX.
 *
 * Slice 3 (future): admin UI to configure chain field-id pairs
 * per-table.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Cascading_Filter_Service {

    /**
     * Validate a single chain candidate. Returns
     * `['parent' => ..., 'child' => ...]` or null.
     */
    public static function normalize_chain(array $candidate): ?array {
        $p = isset($candidate['parent']) ? (string) $candidate['parent'] : '';
        $c = isset($candidate['child']) ? (string) $candidate['child'] : '';
        if ($p === '' || $c === '' || $p === $c) {
            return null;
        }
        return ['parent' => $p, 'child' => $c];
    }

    /**
     * Filter a list of candidates through normalize_chain, dropping
     * invalid entries.
     *
     * @param array $candidates
     * @return array<int, array{parent: string, child: string}>
     */
    public static function normalize_chains(array $candidates): array {
        $out = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $n = self::normalize_chain($candidate);
            if ($n !== null) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /**
     * Compute the distinct child-field values for rows where the
     * parent field matches `$parent_value`. Skips rows missing
     * either field.
     *
     * @param array  $rows         per-row associative arrays.
     * @param string $parent_field field id used as the filter parent.
     * @param string $parent_value chosen parent dropdown value.
     * @param string $child_field  field id whose values become the child dropdown options.
     * @return array<int, string>  distinct, original-order values.
     */
    public static function get_valid_options(array $rows, string $parent_field, string $parent_value, string $child_field): array {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (!isset($row[$parent_field], $row[$child_field])) continue;
            if ((string) $row[$parent_field] !== $parent_value) continue;
            $cv = (string) $row[$child_field];
            if (isset($seen[$cv])) continue;
            $seen[$cv] = true;
            $out[] = $cv;
        }
        return $out;
    }

    /**
     * Render the data-* attributes the frontend needs to detect a
     * cascading dependency on a child <select>. Returns an empty
     * string for invalid chain shapes.
     */
    public static function render_dependency_attributes(string $parent_field, string $child_field): string {
        if ($parent_field === '' || $child_field === '' || $parent_field === $child_field) {
            return '';
        }
        return sprintf(
            'data-gt-cascade-parent="%s" data-gt-cascade-child="%s"',
            self::escape_attr($parent_field),
            self::escape_attr($child_field)
        );
    }

    private static function escape_attr(string $s): string {
        if (function_exists('esc_attr')) {
            return esc_attr($s);
        }
        // @codeCoverageIgnoreStart
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // @codeCoverageIgnoreEnd
    }
}
