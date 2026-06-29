<?php
/**
 * Controls configurable fixed / consistent row height per table.
 *
 * Admins may choose a named preset (compact / default / comfortable) or supply
 * a custom px/em value. The header row height is configurable independently.
 * Overflow content is handled via ellipsis clipping or an expand-row toggle.
 *
 * All generated CSS is scoped to #gt-table-{id} so multiple tables on the same
 * page never interfere with each other.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Row_Height_Service {

    const PRESETS = [
        'compact'     => '32px',
        'default'     => '48px',
        'comfortable' => '64px',
    ];

    // -------------------------------------------------------------------------
    // Settings accessors
    // -------------------------------------------------------------------------

    /**
     * Return the resolved row height value (e.g. '48px', '3em') or '' if unset.
     *
     * If the stored value matches a preset name the preset px value is returned.
     *
     * @param array $settings Table settings array.
     * @return string
     */
    public static function get_row_height(array $settings): string {
        $raw = trim($settings['row_height'] ?? '');
        if ($raw === '') {
            return '';
        }
        if (isset(self::PRESETS[strtolower($raw)])) {
            return self::PRESETS[strtolower($raw)];
        }
        // Validate: must be a number followed by px or em
        if (preg_match('/^\d+(\.\d+)?(px|em|rem|vh)$/', $raw)) {
            return $raw;
        }
        return '';
    }

    /**
     * Return the resolved header row height value or '' if unset.
     *
     * @param array $settings
     * @return string
     */
    public static function get_header_height(array $settings): string {
        $raw = trim($settings['header_height'] ?? '');
        if ($raw === '') {
            return '';
        }
        if (isset(self::PRESETS[strtolower($raw)])) {
            return self::PRESETS[strtolower($raw)];
        }
        if (preg_match('/^\d+(\.\d+)?(px|em|rem|vh)$/', $raw)) {
            return $raw;
        }
        return '';
    }

    /**
     * Return the overflow handling mode: 'ellipsis' (default) or 'expand'.
     *
     * @param array $settings
     * @return string
     */
    public static function get_overflow_mode(array $settings): string {
        $mode = $settings['row_overflow_mode'] ?? 'ellipsis';
        return $mode === 'expand' ? 'expand' : 'ellipsis';
    }

    // -------------------------------------------------------------------------
    // CSS generation
    // -------------------------------------------------------------------------

    /**
     * Generate scoped inline CSS for a table's row height settings.
     *
     * @param int   $table_id
     * @param array $settings
     * @return string CSS string (empty if no height is configured).
     */
    public static function get_css(int $table_id, array $settings): string {
        $row_height    = self::get_row_height($settings);
        $header_height = self::get_header_height($settings);
        $overflow_mode = self::get_overflow_mode($settings);

        if ($row_height === '' && $header_height === '') {
            return '';
        }

        $scope = '#gt-table-' . (int) $table_id;
        $css   = '';

        // Body row cells
        if ($row_height !== '') {
            $height = esc_attr($row_height);

            if ($overflow_mode === 'ellipsis') {
                $css .= "$scope tbody tr { height: $height; max-height: $height; overflow: hidden; }\n";
                $css .= "$scope tbody td { max-height: $height; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }\n";
            } else {
                // 'expand' mode — row is collapsible; default state is clamped
                $css .= "$scope tbody tr:not(.gt-row-expanded) { height: $height; max-height: $height; overflow: hidden; }\n";
                $css .= "$scope tbody td { overflow: hidden; }\n";
                $css .= "$scope tbody tr.gt-row-expanded { height: auto; max-height: none; }\n";
            }

            // Responsive stacked mode: restore auto height on mobile so cards aren't broken
            $css .= "@media (max-width: 768px) { $scope tbody tr { height: auto; max-height: none; } }\n";
        }

        // Header row cells
        if ($header_height !== '') {
            $h = esc_attr($header_height);
            $css .= "$scope thead th { height: $h; max-height: $h; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }\n";
        }

        return $css;
    }

    /**
     * Attach the scoped row-height CSS via wp_add_inline_style().
     *
     * @param int   $table_id
     * @param array $settings
     */
    public static function enqueue_inline_css(int $table_id, array $settings): void {
        $css = self::get_css($table_id, $settings);
        if ($css !== '') {
            wp_add_inline_style('gravity-tables-frontend', $css);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the list of available preset names and their resolved px values.
     *
     * @return array<string, string>
     */
    public static function get_presets(): array {
        return self::PRESETS;
    }
}
