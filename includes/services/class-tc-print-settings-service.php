<?php
/**
 * TC_Print_Settings_Service
 *
 * Issue #531 — slice 1 of 3. Pure helper for the future per-table
 * print configuration. The existing `assets/css/frontend-print.css`
 * already provides the base print stylesheet (hides chrome, expands
 * rows). This service defines the per-table opt-in struct + paper
 * size + per-column exclusion list so future slices can wire the
 * toolbar Print button (slice 2) and the dynamic per-column
 * print-CSS generator (slice 3) against a single contract.
 *
 * Settings struct:
 *   enabled            bool      — show Print button in toolbar
 *   paper_size         string    — letter / a4 / legal / a3 / tabloid
 *   repeat_header      bool      — repeat <thead> on each printed page
 *   row_striping       bool      — alternate row backgrounds for readability
 *   excluded_columns   string[]  — column ids to hide in print
 *
 * @since 4.7.42
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
// #2312's TC_Bool coercion is a hard dependency; require it directly so the
// class also works when this file is loaded in isolation (PHPUnit shims,
// direct includes) rather than via the plugin's full boot sequence.
require_once __DIR__ . '/class-tc-bool.php';

class TC_Print_Settings_Service {

    public static function defaults(): array {
        return [
            'enabled'          => false,
            'paper_size'       => 'letter',
            'repeat_header'    => true,
            'row_striping'     => true,
            'excluded_columns' => [],
        ];
    }

    public static function paper_sizes(): array {
        return ['letter', 'a4', 'legal', 'a3', 'tabloid'];
    }

    public static function normalize(array $settings): array {
        $defaults = self::defaults();
        $out = $defaults;

        if (array_key_exists('enabled', $settings)) {
            // Use TC_Bool::cast() so string "false" from jQuery $.param() is
            // treated as false, not true. (#2308)
            $out['enabled'] = TC_Bool::cast($settings['enabled']);
        }
        if (array_key_exists('repeat_header', $settings)) {
            $out['repeat_header'] = TC_Bool::cast($settings['repeat_header']);
        }
        if (array_key_exists('row_striping', $settings)) {
            $out['row_striping'] = TC_Bool::cast($settings['row_striping']);
        }
        if (array_key_exists('paper_size', $settings)) {
            $size = (string) $settings['paper_size'];
            $out['paper_size'] = in_array($size, self::paper_sizes(), true) ? $size : $defaults['paper_size'];
        }
        if (array_key_exists('excluded_columns', $settings) && is_array($settings['excluded_columns'])) {
            $clean = [];
            foreach ($settings['excluded_columns'] as $col) {
                if (!is_string($col) || $col === '') {
                    continue;
                }
                $clean[] = $col;
            }
            $out['excluded_columns'] = $clean;
        }

        return $out;
    }

    public static function is_enabled(array $settings): bool {
        // Use TC_Bool::cast() so string "false" is treated as false. (#2308)
        return TC_Bool::cast($settings['enabled'] ?? false);
    }

    public static function is_column_excluded(string $column_id, array $settings): bool {
        if (empty($settings['excluded_columns']) || !is_array($settings['excluded_columns'])) {
            return false;
        }
        return in_array($column_id, $settings['excluded_columns'], true);
    }
}
