<?php
/**
 * TC_Vertical_Align_Service
 *
 * Issue #549 - slice 1 of 3. Pure resolver helper for the per-cell
 * vertical alignment feature. Whitelists alignment values, resolves
 * cell-override > column > default precedence, and produces the CSS
 * class / inline style. Slice 2 wires the admin UI (column dropdown
 * + cell-toolbar override) and the AJAX save sanitization (mirroring
 * the #365 round-trip fix). Slice 3 emits the resolved class on each
 * `<td>` from `templates/table.php` plus matching CSS rules.
 *
 * Default `middle` deliberately emits no class / no style - the browser
 * default for `<td>` is already `middle`, so we only render overrides.
 *
 * @since 4.7.48
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Vertical_Align_Service {

    private const ALIGNMENTS = ['top', 'middle', 'bottom'];
    private const DEFAULT_ALIGN = 'middle';

    public static function alignments(): array {
        return self::ALIGNMENTS;
    }

    public static function default_align(): string {
        return self::DEFAULT_ALIGN;
    }

    public static function normalize($value): string {
        if (is_string($value) && in_array($value, self::ALIGNMENTS, true)) {
            return $value;
        }
        return self::DEFAULT_ALIGN;
    }

    /**
     * Cell override wins over column wins over default. A null /
     * empty / invalid value at any tier falls through to the next.
     */
    public static function resolve($column_align, $cell_override): string {
        if (is_string($cell_override) && in_array($cell_override, self::ALIGNMENTS, true)) {
            return $cell_override;
        }
        if (is_string($column_align) && in_array($column_align, self::ALIGNMENTS, true)) {
            return $column_align;
        }
        return self::DEFAULT_ALIGN;
    }

    public static function to_class(string $align): string {
        $a = self::normalize($align);
        if ($a === self::DEFAULT_ALIGN) {
            return '';
        }
        return 'gt-valign-' . $a;
    }

    public static function to_inline_style(string $align): string {
        $a = self::normalize($align);
        if ($a === self::DEFAULT_ALIGN) {
            return '';
        }
        return 'vertical-align: ' . $a . ';';
    }

    /**
     * Sanitizes a nested map of cell-level vertical alignment overrides.
     * Expected shape: [entry_id][field_id] => alignment_string.
     * 
     * @since 4.58.0
     */
    public static function sanitize_cell_map($map): array {
        if (!is_array($map)) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }
        $sanitized = [];
        foreach ($map as $entry_id => $overrides) {
            if (!is_array($overrides)) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            $entry_sanitized = [];
            foreach ($overrides as $field_id => $val) {
                if (is_string($val) && in_array($val, self::ALIGNMENTS, true)) {
                    $entry_sanitized[$field_id] = $val;
                }
            }
            if (!empty($entry_sanitized)) {
                $sanitized[$entry_id] = $entry_sanitized;
            }
        }
        return $sanitized;
    }
}
