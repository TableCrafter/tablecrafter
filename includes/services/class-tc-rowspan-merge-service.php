<?php
/**
 * TC_Rowspan_Merge_Service
 *
 * Issue #518 — slice 1 of 3. Pure helper that walks a column's
 * values in row order and identifies runs of consecutive duplicates.
 * The future per-column "Auto-merge consecutive duplicate values"
 * toggle will use the directives output to render rowspan'd cells:
 * the first row of a run gets `<td rowspan="N">`; subsequent rows in
 * the run skip rendering the cell entirely.
 *
 * Strict equality (`===`) — `'10'` and `10` are NOT merged together,
 * `'A'` and `'a'` are NOT merged. Consecutive nulls ARE merged into
 * one run; consecutive empty strings ARE merged; but null and empty
 * string are NOT merged together (strict equality again).
 *
 * Slices 2 and 3 wire this into the template render path and the
 * JS post-sort/filter rebinding.
 *
 * @since 4.7.36
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Rowspan_Merge_Service {

    /**
     * Walk $values in order and return an ordered list of runs:
     * `[{ start, length, value }]`. Runs are bounded by a strict
     * inequality between consecutive values.
     */
    public static function compute_runs(array $values): array {
        $runs = [];
        $count = count($values);
        if ($count === 0) {
            return $runs;
        }
        // Re-key as 0-indexed so $values[$i] is unambiguous regardless
        // of the input being keyed.
        $values = array_values($values);
        $start = 0;
        for ($i = 1; $i < $count; $i++) {
            if ($values[$i] !== $values[$start]) {
                $runs[] = [
                    'start'  => $start,
                    'length' => $i - $start,
                    'value'  => $values[$start],
                ];
                $start = $i;
            }
        }
        // Flush the final run.
        $runs[] = [
            'start'  => $start,
            'length' => $count - $start,
            'value'  => $values[$start],
        ];
        return $runs;
    }

    /**
     * Produce a per-row directive: { render: bool, rowspan: int }.
     *
     *   - First row of a run: render=true, rowspan=length-of-run.
     *   - Subsequent rows of the run: render=false, rowspan=0
     *     (the caller skips emitting the cell).
     *   - Length-1 runs: render=true, rowspan=1.
     */
    public static function directives(array $values): array {
        $runs = self::compute_runs($values);
        $count = count($values);
        if ($count === 0) {
            return [];
        }
        $out = array_fill(0, $count, ['render' => false, 'rowspan' => 0]);
        foreach ($runs as $run) {
            $out[$run['start']] = [
                'render'  => true,
                'rowspan' => $run['length'],
            ];
        }
        return $out;
    }
}
