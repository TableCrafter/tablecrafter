<?php
/**
 * TC_Detail_Rows_Service
 *
 * Issue #556 - slice 1 of 3. Pure column-partition helper for the
 * future expandable / collapsible detail-row feature. Classifies
 * columns as "main" vs "detail" via the `detail_only` boolean flag
 * and partitions them, preserving original order in each bucket.
 * No render hook, no admin UI yet - substrate slices 2 + 3 bind to.
 *
 * Slice 2 wires the per-column "Show in detail row only" toggle in
 * the column-config tab plus AJAX save sanitization (uses
 * `normalize_column()` here as the canonical coercion). Slice 3
 * ships the frontend: chevron / `+` toggle on the parent row, JS
 * expand/collapse of an inline `<tr class="gt-detail">`, keyboard
 * a11y (Enter / Space, `aria-expanded`), and ensures CSV export +
 * global search keep detail columns included.
 *
 * @since 4.7.49
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Detail_Rows_Service {

    private const TRUTHY_STRINGS = ['1', 'true', 'on', 'yes'];

    public static function is_detail_column(array $column): bool {
        return array_key_exists('detail_only', $column) && $column['detail_only'] === true;
    }

    /**
     * Coerce $column['detail_only'] into a strict boolean. Accepts
     * the form-post variants `'1'` / `'true'` / `'on'` / `'yes'`
     * (case-insensitive) plus literal `true` and integer `1`.
     * Everything else (including missing key) becomes `false`.
     */
    public static function normalize_column(array $column): array {
        $raw = $column['detail_only'] ?? false;
        $bool = false;
        if ($raw === true || $raw === 1) {
            $bool = true;
        } elseif (is_string($raw) && in_array(strtolower($raw), self::TRUTHY_STRINGS, true)) {
            $bool = true;
        }
        $column['detail_only'] = $bool;
        return $column;
    }

    /**
     * @return array{main: array<int,array>, detail: array<int,array>}
     */
    public static function partition_columns(array $columns): array {
        $main = [];
        $detail = [];
        foreach ($columns as $col) {
            if (is_array($col) && self::is_detail_column($col)) {
                $detail[] = $col;
            } elseif (is_array($col)) {
                $main[] = $col;
            }
        }
        return ['main' => $main, 'detail' => $detail];
    }

    public static function has_detail_columns(array $columns): bool {
        foreach ($columns as $col) {
            if (is_array($col) && self::is_detail_column($col)) {
                return true;
            }
        }
        return false;
    }
}
