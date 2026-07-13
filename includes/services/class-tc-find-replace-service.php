<?php
/**
 * TC_Find_Replace_Service
 *
 * Issue #538 - slice 1 of 3. Pure plain-text find / count / replace
 * over an in-memory rows array. Substrate the future admin dialog
 * (slice 2) and undo-aware preview-and-apply UX (slice 3) bind to.
 *
 * Options:
 *   case_sensitive  bool      default false
 *   whole_cell      bool      default false
 *   columns         string[]  default [] (empty = all columns)
 *
 * Empty needle is a no-op everywhere. Non-string cell values pass
 * through unchanged. Regex mode is deferred to slice 3.
 *
 * @since 4.7.44
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Find_Replace_Service {

    /**
     * Replace occurrences within a single value. Returns
     * `{ new_value, count }`.
     */
    public static function replace_in_value(string $haystack, string $needle, string $replacement, array $options = []): array {
        if ($needle === '') {
            // @codeCoverageIgnoreStart
            return ['new_value' => $haystack, 'count' => 0];
            // @codeCoverageIgnoreEnd
        }
        $case_sensitive = !empty($options['case_sensitive']);
        $whole_cell     = !empty($options['whole_cell']);

        if ($whole_cell) {
            $matches = $case_sensitive
                ? ($haystack === $needle)
                : (strcasecmp($haystack, $needle) === 0);
            return $matches
                ? ['new_value' => $replacement, 'count' => 1]
                : ['new_value' => $haystack,    'count' => 0];
        }

        $count = 0;
        $new = $case_sensitive
            ? str_replace($needle, $replacement, $haystack, $count)
            : str_ireplace($needle, $replacement, $haystack, $count);
        return ['new_value' => $new, 'count' => $count];
    }

    /**
     * Walk the rows and return the list of cells matching the needle.
     * Each entry: `{ row_index, col_id, value, count }`.
     */
    public static function find_matches(array $rows, string $needle, array $options = []): array {
        if ($needle === '') {
            return [];
        }
        $columns = isset($options['columns']) && is_array($options['columns']) ? $options['columns'] : [];
        $case_sensitive = !empty($options['case_sensitive']);
        $whole_cell     = !empty($options['whole_cell']);
        $out = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) continue;
            foreach ($row as $col_id => $value) {
                if (!empty($columns) && !in_array((string) $col_id, $columns, true)) continue;
                if (!is_string($value)) continue;
                if ($whole_cell) {
                    $hit = $case_sensitive
                        // @codeCoverageIgnoreStart
                        ? ($value === $needle)
                        // @codeCoverageIgnoreEnd
                        : (strcasecmp($value, $needle) === 0);
                    if (!$hit) continue;
                    $out[] = ['row_index' => $i, 'col_id' => (string) $col_id, 'value' => $value, 'count' => 1];
                    continue;
                }
                $found = $case_sensitive
                    ? substr_count($value, $needle)
                    : substr_count(strtolower($value), strtolower($needle));
                if ($found > 0) {
                    $out[] = ['row_index' => $i, 'col_id' => (string) $col_id, 'value' => $value, 'count' => $found];
                }
            }
        }
        return $out;
    }

    /**
     * Apply replacements across the full rows array. Returns
     * `{ rows: updated, count: total replacements }`.
     */
    public static function apply(array $rows, string $needle, string $replacement, array $options = []): array {
        if ($needle === '') {
            return ['rows' => $rows, 'count' => 0];
        }
        $columns = isset($options['columns']) && is_array($options['columns']) ? $options['columns'] : [];
        $total = 0;
        foreach ($rows as $i => $row) {
            if (!is_array($row)) continue;
            foreach ($row as $col_id => $value) {
                if (!empty($columns) && !in_array((string) $col_id, $columns, true)) continue;
                if (!is_string($value)) continue;
                $r = self::replace_in_value($value, $needle, $replacement, $options);
                if ($r['count'] > 0) {
                    $rows[$i][$col_id] = $r['new_value'];
                    $total += $r['count'];
                }
            }
        }
        return ['rows' => $rows, 'count' => $total];
    }
}
