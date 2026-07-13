<?php
/**
 * TC_AI_Table_Summarizer
 *
 * Issue #498 (sub of #490) - slice 1 of 3. Pure rule-based table
 * summarizer. Generates deterministic factual bullets from a row +
 * column dataset. Future slices add:
 *
 *   - Slice 2: outlier detection, recency clustering, cross-column
 *     correlation hints.
 *   - Slice 3: LLM rewrite of bullets in natural-language prose,
 *     plus the admin builder-page widget with privacy notice.
 *
 * The canonical envelope:
 *
 *   [
 *     'bullets'   => [...],   // string[] of factual observations
 *     'truncated' => false,   // true when rows exceeded the cap
 *   ]
 *
 * Slice 3 LLM pass uses these bullets as input grounding (factual
 * anchors the model cannot hallucinate around) and produces a
 * natural-language paragraph as output.
 *
 * @since 4.7.29
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_AI_Table_Summarizer {

    /** Cap on rows processed per call. Configurable in slice 3. */
    private const ROW_CAP = 5000;

    /** Threshold for "dominant value" bullet - fraction of column. */
    private const DOMINANT_THRESHOLD = 0.40;

    /**
     * @param array $rows     Row data, each row is field_id => value.
     * @param array $columns  Column definitions, each entry has
     *                        `id`, `label`, optional `type`.
     * @return array{bullets:string[],truncated:bool}
     */
    public static function summarize_data(array $rows, array $columns): array {
        $bullets = [];
        $truncated = false;

        $row_count = count($rows);
        if ($row_count > self::ROW_CAP) {
            $rows = array_slice($rows, 0, self::ROW_CAP);
            $truncated = true;
        }

        if ($row_count === 0 || empty($columns)) {
            return ['bullets' => $bullets, 'truncated' => $truncated];
        }

        // Row count bullet.
        $bullets[] = sprintf('%d rows total', $row_count);

        // Per-column observations.
        foreach ($columns as $col) {
            $field_id = (string) ($col['id'] ?? '');
            $label    = (string) ($col['label'] ?? $field_id);
            $type     = (string) ($col['type'] ?? 'text');
            if ($field_id === '') {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $values = self::collect_column_values($rows, $field_id);
            if (empty($values)) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            switch ($type) {
                case 'date':
                case 'datetime':
                    self::add_date_bullets($bullets, $label, $values);
                    break;
                case 'number':
                case 'numeric':
                    self::add_numeric_bullets($bullets, $label, $values);
                    break;
                default:
                    self::add_dominant_value_bullet($bullets, $label, $values);
                    break;
            }
        }

        return ['bullets' => $bullets, 'truncated' => $truncated];
    }

    /**
     * Pull the non-empty string values for a column out of the row
     * array. Empty / null values are skipped because they don't
     * contribute to summary statistics.
     */
    private static function collect_column_values(array $rows, string $field_id): array {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (!isset($row[$field_id])) continue;
            $v = $row[$field_id];
            if ($v === null || $v === '') continue;
            $out[] = (string) $v;
        }
        return $out;
    }

    private static function add_dominant_value_bullet(array &$bullets, string $label, array $values): void {
        $count = count($values);
        if ($count === 0) return;
        $freq = array_count_values($values);
        arsort($freq);
        $top_value = (string) array_key_first($freq);
        $top_count = (int) $freq[$top_value];
        $pct = ($top_count / $count) * 100;
        if ($pct >= (self::DOMINANT_THRESHOLD * 100)) {
            $bullets[] = sprintf(
                'Most frequent %s: %s (%d%%)',
                $label,
                $top_value,
                (int) round($pct)
            );
        }
    }

    private static function add_date_bullets(array &$bullets, string $label, array $values): void {
        $timestamps = [];
        foreach ($values as $v) {
            $ts = strtotime($v);
            if ($ts !== false) {
                $timestamps[] = $ts;
            }
        }
        if (empty($timestamps)) return;
        sort($timestamps);
        $bullets[] = sprintf('Earliest %s: %s', $label, gmdate('Y-m-d', $timestamps[0]));
        $bullets[] = sprintf('Latest %s: %s', $label, gmdate('Y-m-d', end($timestamps)));
    }

    private static function add_numeric_bullets(array &$bullets, string $label, array $values): void {
        $numbers = [];
        foreach ($values as $v) {
            // Strip common currency symbols / commas to be permissive.
            $clean = preg_replace('/[\$€£¥₹,\s]/u', '', (string) $v);
            if (is_numeric($clean)) {
                $numbers[] = (float) $clean;
            }
        }
        if (empty($numbers)) return;
        $min = min($numbers);
        $max = max($numbers);
        $mean = array_sum($numbers) / count($numbers);
        $bullets[] = sprintf(
            '%s: min %s, max %s, mean %s',
            $label,
            self::format_number($min),
            self::format_number($max),
            self::format_number($mean)
        );
    }

    private static function format_number(float $n): string {
        // Show ints without decimals; floats with up to 2 places.
        if (abs($n - round($n)) < 1e-9) {
            return (string) (int) round($n);
        }
        // @codeCoverageIgnoreStart
        return rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
        // @codeCoverageIgnoreEnd
    }
}
