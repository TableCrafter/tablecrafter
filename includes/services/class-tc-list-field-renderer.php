<?php
/**
 * TC_List_Field_Renderer
 *
 * Issue #795 (child of #793 audit). Gravity Forms `list` fields
 * store row-data as **serialised PHP** at the bare field-id slot
 * (e.g. `$entry['5']` is `a:2:{i:0;a:2:{s:5:"Name";s:5:"Alice";...}}`).
 * Without this renderer, every operation on a list-field column
 * passes the raw serialised string through `esc_html()` and the
 * cell shows unreadable garbage.
 *
 * Safe deserialisation: `unserialize` is called with
 * `allowed_classes => false` so even maliciously-crafted entry
 * values cannot instantiate arbitrary classes. The slice-1 service
 * is pure / unit-testable and binds the contract slice 2 (the
 * production wire-up) consumes.
 *
 * @since 4.71.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_List_Field_Renderer {

    /**
     * Deserialise the raw list-field value into a 2-D array.
     *
     *   - Single-column lists: `['v1', 'v2', 'v3']` -> `[['v1'], ['v2'], ['v3']]`
     *   - Multi-column lists: already-shaped `[ ['col1'=>'a','col2'=>'b'], ... ]`
     *
     * Returns an empty array for empty input or anything that
     * doesn't deserialise to an array.
     */
    public static function unserialize_to_array($raw): array {
        if (is_array($raw)) {
            return self::normalize_rows($raw);
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        // Safe-mode unserialise — block all class instantiation.
        $value = @unserialize($raw, ['allowed_classes' => false]);
        if ($value === false && $raw !== 'b:0;') {
            // Either an actual `false` payload (rare) or a malformed
            // value. Treat both as empty — don't pass garbage forward.
            return [];
        }
        if (!is_array($value)) {
            return [];
        }
        return self::normalize_rows($value);
    }

    /**
     * Normalise to a uniform 2-D shape: array of associative
     * row arrays. Single-column lists (`['a', 'b']`) become
     * `[['value' => 'a'], ['value' => 'b']]`.
     */
    private static function normalize_rows(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                // Already a row map — copy as-is.
                $out[] = $row;
            } elseif (is_scalar($row)) {
                $out[] = ['value' => (string) $row];
            }
            // Drop non-scalar / non-array entries silently.
        }
        return $out;
    }

    /**
     * Render the deserialised rows as an HTML `<table>`. Used by
     * `TC_Cell_Renderer::render()` and the eye popup. Returns an
     * empty string for an empty input so the caller can branch on
     * "no list content" if it wants to (the default is "render a
     * dash" via the surrounding render path).
     */
    public static function render_as_html_table($raw): string {
        $rows = self::unserialize_to_array($raw);
        if (empty($rows)) {
            return '';
        }

        // Build the column-header set from the union of all row keys.
        // Single-column lists end up with a single 'value' header
        // which we hide (no point showing a "value" label for a
        // 1-col list); multi-column lists keep their key labels.
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[(string) $key] = true;
            }
        }
        $columns = array_keys($columns);
        $single_col = (count($columns) === 1 && $columns[0] === 'value');

        $h = function (string $s): string {
            return function_exists('esc_html') ? esc_html($s) : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };

        $out = '<table class="gt-list-field">';
        if (!$single_col) {
            $out .= '<thead><tr>';
            foreach ($columns as $col) {
                $out .= '<th>' . $h((string) $col) . '</th>';
            }
            $out .= '</tr></thead>';
        }
        $out .= '<tbody>';
        foreach ($rows as $row) {
            $out .= '<tr>';
            foreach ($columns as $col) {
                $cell = isset($row[$col]) ? $row[$col] : '';
                if (!is_scalar($cell)) { $cell = ''; }
                $out .= '<td>' . $h((string) $cell) . '</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';
        return $out;
    }

    /**
     * Render as plain text (one row per line, semicolon between
     * columns within a row). For CSV / XLSX export cells where
     * embedding HTML doesn't make sense.
     */
    public static function render_as_text($raw): string {
        $rows = self::unserialize_to_array($raw);
        if (empty($rows)) {
            return '';
        }
        $lines = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($row as $key => $val) {
                if (!is_scalar($val)) { continue; }
                $val_str = (string) $val;
                $parts[] = (count($row) === 1 && isset($row['value']))
                    ? $val_str
                    : ((string) $key . ': ' . $val_str);
            }
            $lines[] = implode('; ', $parts);
        }
        return implode("\n", $lines);
    }
}
