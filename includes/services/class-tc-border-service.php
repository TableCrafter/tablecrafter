<?php
/**
 * Configurable row and column border visibility for Gravity Tables.
 *
 * Generates scoped per-table CSS for horizontal row dividers, vertical column
 * dividers, outer table border, and header bottom border. Provides named
 * presets (Classic, Rows only, None, Outer only) and an inline-style
 * enqueue helper.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Border_Service {

    /**
     * Named border presets.
     *
     * Each preset is an array of border settings that can be passed directly
     * to get_border_css().
     */
    const PRESETS = [
        'classic'    => [
            'row_dividers'    => ['enabled' => true,  'color' => '#dee2e6', 'width' => 1],
            'column_dividers' => ['enabled' => true,  'color' => '#dee2e6', 'width' => 1],
            'outer_border'    => ['enabled' => true,  'color' => '#dee2e6', 'width' => 1, 'radius' => 0],
            'header_border'   => ['enabled' => true,  'color' => '#adb5bd', 'width' => 2],
        ],
        'rows_only'  => [
            'row_dividers'    => ['enabled' => true,  'color' => '#dee2e6', 'width' => 1],
            'column_dividers' => ['enabled' => false, 'color' => '',        'width' => 0],
            'outer_border'    => ['enabled' => false, 'color' => '',        'width' => 0, 'radius' => 0],
            'header_border'   => ['enabled' => true,  'color' => '#adb5bd', 'width' => 2],
        ],
        'none'       => [
            'row_dividers'    => ['enabled' => false, 'color' => '', 'width' => 0],
            'column_dividers' => ['enabled' => false, 'color' => '', 'width' => 0],
            'outer_border'    => ['enabled' => false, 'color' => '', 'width' => 0, 'radius' => 0],
            'header_border'   => ['enabled' => false, 'color' => '', 'width' => 0],
        ],
        'outer_only' => [
            'row_dividers'    => ['enabled' => false, 'color' => '',        'width' => 0],
            'column_dividers' => ['enabled' => false, 'color' => '',        'width' => 0],
            'outer_border'    => ['enabled' => true,  'color' => '#dee2e6', 'width' => 1, 'radius' => 4],
            'header_border'   => ['enabled' => false, 'color' => '',        'width' => 0],
        ],
    ];

    /**
     * Return the settings array for a named preset.
     *
     * @param string $preset One of: classic, rows_only, none, outer_only.
     * @return array Border settings array, or the 'classic' preset if unknown.
     */
    public static function get_preset_settings(string $preset): array {
        return self::PRESETS[$preset] ?? self::PRESETS['classic'];
    }

    /**
     * Generate scoped border CSS for a single table instance.
     *
     * All selectors are prefixed with the table wrapper ID so the rules never
     * bleed to other tables on the same page.
     *
     * @param int   $table_id Table ID — used to build the CSS scope selector.
     * @param array $settings Border settings. Expected keys:
     *   - row_dividers    (array): enabled, color, width
     *   - column_dividers (array): enabled, color, width
     *   - outer_border    (array): enabled, color, width, radius
     *   - header_border   (array): enabled, color, width
     * @return string CSS string (may be empty if all borders are disabled).
     */
    public static function get_border_css(int $table_id, array $settings): string {
        $scope = "#gt-table-{$table_id}";
        $css   = '';

        // ── Row dividers (horizontal lines between body rows) ────────────────
        $rd = $settings['row_dividers'] ?? [];
        if (!empty($rd['enabled'])) {
            $color = sanitize_hex_color($rd['color'] ?? '#dee2e6') ?: '#dee2e6';
            $width = absint($rd['width'] ?? 1);
            $css  .= "{$scope} .gt-table tbody tr { border-bottom: {$width}px solid {$color}; }\n";
        } else {
            $css .= "{$scope} .gt-table tbody tr { border-bottom: none; }\n";
        }

        // ── Column dividers (vertical lines between cells) ───────────────────
        $cd = $settings['column_dividers'] ?? [];
        if (!empty($cd['enabled'])) {
            $color = sanitize_hex_color($cd['color'] ?? '#dee2e6') ?: '#dee2e6';
            $width = absint($cd['width'] ?? 1);
            $css  .= "{$scope} .gt-table td, {$scope} .gt-table th { border-right: {$width}px solid {$color}; }\n";
            $css  .= "{$scope} .gt-table td:last-child, {$scope} .gt-table th:last-child { border-right: none; }\n";
        } else {
            $css .= "{$scope} .gt-table td, {$scope} .gt-table th { border-right: none; }\n";
        }

        // ── Outer border ─────────────────────────────────────────────────────
        $ob = $settings['outer_border'] ?? [];
        if (!empty($ob['enabled'])) {
            $color  = sanitize_hex_color($ob['color'] ?? '#dee2e6') ?: '#dee2e6';
            $width  = absint($ob['width'] ?? 1);
            $radius = absint($ob['radius'] ?? 0);
            $css   .= "{$scope} .gt-table { border: {$width}px solid {$color}; border-radius: {$radius}px; }\n";
        } else {
            $css .= "{$scope} .gt-table { border: none; }\n";
        }

        // ── Header bottom border ─────────────────────────────────────────────
        $hb = $settings['header_border'] ?? [];
        if (!empty($hb['enabled'])) {
            $color = sanitize_hex_color($hb['color'] ?? '#adb5bd') ?: '#adb5bd';
            $width = absint($hb['width'] ?? 2);
            $css  .= "{$scope} .gt-table thead tr { border-bottom: {$width}px solid {$color}; }\n";
        } else {
            $css .= "{$scope} .gt-table thead tr { border-bottom: none; }\n";
        }

        return $css;
    }

    /**
     * Output the border CSS for a table as WordPress inline style.
     *
     * Appended to the 'gravity-tables-frontend' stylesheet handle so it loads in the
     * correct place without an extra HTTP request.
     *
     * @param int   $table_id
     * @param array $settings
     */
    public static function enqueue_inline_css(int $table_id, array $settings): void {
        $css = self::get_border_css($table_id, $settings);
        if ($css !== '') {
            wp_add_inline_style('gravity-tables-frontend', $css);
        }
    }
}
