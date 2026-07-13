<?php
/**
 * TC_Pivot_Service
 *
 * Issue #562 - slice 1 of 3. Pure aggregation engine for the future
 * pivot-table feature. Defines the pivot config schema, validates /
 * sanitizes it, and computes aggregated rows from a raw rows array.
 * No UI, no SQL - just the in-memory aggregation engine slices 2 + 3
 * bind to.
 *
 * Slice 2 ships the per-table view-mode toggle (Raw / Pivot) and
 * the pivot config editor (group-by + aggregate repeater) plus AJAX
 * save sanitization via `normalize()`. Slice 3 ships the server-side
 * compute path (pairs with #560 server-side pagination), the
 * optional second-level group-by for 2D cross-tab, and CSV / Excel
 * export of the pivot result via existing #519 / #522 plumbing.
 *
 * Aggregate output keys are `<col>_<op>` (e.g. `price_sum`,
 * `qty_count`) to support multiple aggregates on the same column.
 *
 * @since 4.7.51
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Pivot_Service {

    private const OPS = ['sum', 'count', 'avg', 'min', 'max'];
    private const MODES = ['raw', 'pivot'];

    public static function operators(): array {
        return self::OPS;
    }

    public static function defaults(): array {
        return [
            'mode'       => 'raw',
            'group_by'   => null,
            'aggregates' => [],
        ];
    }

    public static function normalize(array $config): array {
        $out = self::defaults();

        if (array_key_exists('mode', $config)) {
            $m = $config['mode'];
            if (is_string($m) && in_array($m, self::MODES, true)) {
                $out['mode'] = $m;
            }
        }

        if (array_key_exists('group_by', $config)) {
            $g = $config['group_by'];
            if (is_string($g) && $g !== '') {
                $out['group_by'] = $g;
            }
        }

        if (array_key_exists('aggregates', $config) && is_array($config['aggregates'])) {
            $clean = [];
            foreach ($config['aggregates'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!isset($entry['col'], $entry['op'])) {
                    continue;
                }
                if (!is_string($entry['col']) || $entry['col'] === '') {
                    continue;
                }
                if (!is_string($entry['op']) || !in_array($entry['op'], self::OPS, true)) {
                    continue;
                }
                $clean[] = ['col' => $entry['col'], 'op' => $entry['op']];
            }
            $out['aggregates'] = $clean;
        }

        return $out;
    }

    /**
     * #1617 - parse the builder repeater's aggregates payload (JSON
     * string or array of {col, op}) into the raw list normalize()
     * expects. Garbage in, [] out; entries missing col/op dropped;
     * values coerced to strings. Validation (op whitelist, empty
     * col) stays in normalize() - single source of truth.
     *
     * @param mixed $raw
     * @return array<int,array{col:string,op:string}>
     */
    public static function parse_aggregates_input($raw): array {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['col'], $entry['op'])) {
                continue;
            }
            if (!is_scalar($entry['col']) || !is_scalar($entry['op'])) {
                continue;
            }
            $out[] = ['col' => (string) $entry['col'], 'op' => (string) $entry['op']];
        }
        return $out;
    }

    public static function is_enabled(array $config): bool {
        if (($config['mode'] ?? 'raw') !== 'pivot') {
            return false;
        }
        if (!isset($config['group_by']) || !is_string($config['group_by']) || $config['group_by'] === '') {
            return false;
        }
        if (empty($config['aggregates']) || !is_array($config['aggregates'])) {
            return false;
        }
        return true;
    }

    /**
     * Aggregate $rows according to $config. Returns one output row
     * per distinct group value, with keys `<col>_<op>` for each
     * aggregate. Returns [] when the config is not enabled.
     */
    public static function aggregate(array $rows, array $config): array {
        if (!self::is_enabled($config)) {
            return [];
        }
        $group_col = $config['group_by'];
        $aggs = $config['aggregates'];

        // First pass: bucket rows by group value (preserving first-seen order)
        $buckets = [];
        $order = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            $key = array_key_exists($group_col, $row) ? $row[$group_col] : null;
            $hash = is_scalar($key) || $key === null ? (string) $key : serialize($key);
            if (!array_key_exists($hash, $buckets)) {
                $buckets[$hash] = [];
                $order[] = $hash;
            }
            $buckets[$hash][] = $row;
        }

        $out = [];
        foreach ($order as $hash) {
            $groupRows = $buckets[$hash];
            $groupValue = array_key_exists($group_col, $groupRows[0]) ? $groupRows[0][$group_col] : null;
            $resultRow = [$group_col => $groupValue];

            foreach ($aggs as $agg) {
                $col = $agg['col'];
                $op  = $agg['op'];
                $key = $col . '_' . $op;
                $resultRow[$key] = self::compute_op($groupRows, $col, $op);
            }
            $out[] = $resultRow;
        }

        return $out;
    }

    private static function compute_op(array $groupRows, string $col, string $op) {
        $values = [];
        foreach ($groupRows as $row) {
            if (array_key_exists($col, $row)) {
                $values[] = $row[$col];
            } else {
                // @codeCoverageIgnoreStart
                $values[] = null;
                // @codeCoverageIgnoreEnd
            }
        }

        if ($op === 'count') {
            $n = 0;
            foreach ($values as $v) {
                if ($v !== null) {
                    $n++;
                }
            }
            return $n;
        }

        $numeric = [];
        foreach ($values as $v) {
            if (is_int($v) || is_float($v)) {
                $numeric[] = $v + 0;
            } elseif (is_string($v) && is_numeric($v)) {
                // @codeCoverageIgnoreStart
                $numeric[] = $v + 0;
                // @codeCoverageIgnoreEnd
            }
        }

        switch ($op) {
            case 'sum':
                $s = 0;
                foreach ($numeric as $n) { $s += $n; }
                return $s;
            case 'avg':
                if (count($numeric) === 0) { return 0; }
                $s = 0;
                foreach ($numeric as $n) { $s += $n; }
                return $s / count($numeric);
            case 'min':
                return count($numeric) ? min($numeric) : null;
            case 'max':
                return count($numeric) ? max($numeric) : null;
        }
        // @codeCoverageIgnoreStart
        return null;
        // @codeCoverageIgnoreEnd
    }
}
