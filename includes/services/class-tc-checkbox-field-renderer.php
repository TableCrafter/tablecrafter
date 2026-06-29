<?php
/**
 * TC_Checkbox_Field_Renderer
 *
 * Issue #798 (child of #793). GF checkbox fields store each option
 * as a separate sub-input (`N.1`, `N.2`, `N.3`, ...). The bare
 * `$entry[N]` slot is empty for composites — without this renderer,
 * checkbox-field cells appear blank.
 *
 * This service composes the non-empty sub-inputs into a single
 * comma-joined string for the cell render path. Mirrors the
 * #796 address-renderer shape, scaled to a variable number of
 * sub-inputs (checkboxes can have any number of options).
 *
 * @since 4.74.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Checkbox_Field_Renderer {

    /**
     * Collect every non-empty sub-input value for the field from
     * the entry. Keys we look for: `{field_id}.{N}` for any positive
     * integer N. Values are trimmed; empties dropped.
     *
     * Sorted by the numeric sub-input index so the render order
     * matches the form-builder's choice order (GF stores choices in
     * declaration order; sub-input ids follow that).
     *
     * @return array<int,string> ordered list of non-empty selected option labels
     */
    public static function selected_values(array $entry, string $field_id): array {
        if ($field_id === '') {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }
        $prefix = $field_id . '.';
        $prefix_len = strlen($prefix);
        $hits = [];
        foreach ($entry as $key => $val) {
            $key_str = (string) $key;
            if (strpos($key_str, $prefix) !== 0) {
                continue;
            }
            $tail = substr($key_str, $prefix_len);
            // Tail must be a positive integer — guards against
            // accidental matches like "5.foo" or "5.1.2" (defensive).
            if (!ctype_digit($tail) || (int) $tail < 1) {
                continue;
            }
            if (!is_scalar($val)) { continue; }
            $val_str = trim((string) $val);
            if ($val_str === '') { continue; }
            $hits[(int) $tail] = $val_str;
        }
        ksort($hits, SORT_NUMERIC);
        return array_values($hits);
    }

    /**
     * Plain text render: comma-joined selected values. Returns ''
     * when nothing is selected so the caller can emit a dash.
     */
    public static function render_text(array $entry, string $field_id): string {
        $values = self::selected_values($entry, $field_id);
        if (empty($values)) {
            return '';
        }
        return implode(', ', $values);
    }

    /**
     * HTML render: same comma-joined list but wrapped in a
     * `<span class="gt-cell-checkbox">` and esc_html-safe.
     */
    public static function render_html(array $entry, string $field_id): string {
        $text = self::render_text($entry, $field_id);
        if ($text === '') {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        $h = function (string $s): string {
            return function_exists('esc_html') ? esc_html($s) : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };
        return '<span class="gt-cell-checkbox">' . $h($text) . '</span>';
    }

    /**
     * Substring-searchable concatenation — space-joined selected
     * values. Used by filter paths that test `LIKE %query%`.
     */
    public static function searchable_blob(array $entry, string $field_id): string {
        $values = self::selected_values($entry, $field_id);
        return implode(' ', $values);
    }
}
