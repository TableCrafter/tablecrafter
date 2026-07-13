<?php
/**
 * TC_Address_Field_Renderer
 *
 * Issue #796 (child of #793). GF address fields store sub-inputs:
 *   N.1 = street, N.2 = street2, N.3 = city, N.4 = state,
 *   N.5 = zip,    N.6 = country
 *
 * The cell renderer used to read only the parent column id `N`,
 * which means only the street (N.1) appeared in tables; city / state /
 * zip / country were lost. This service composes all six sub-inputs
 * into a single readable string.
 *
 * @since 4.73.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Address_Field_Renderer {

    /**
     * Six sub-input keys in canonical render order. Used by both
     * the inline render path and the filter substring index.
     *
     * @return array<int,string> ordered list of sub-keys (e.g. '5.1', '5.2', ...)
     */
    public static function sub_input_keys(string $field_id): array {
        $field_id = (string) $field_id;
        return [
            $field_id . '.1', // street
            $field_id . '.2', // street2
            $field_id . '.3', // city
            $field_id . '.4', // state
            $field_id . '.5', // zip
            $field_id . '.6', // country
        ];
    }

    /**
     * Compose the address sub-inputs from an entry into a single
     * line-broken string. Empty sub-inputs are skipped. Returns an
     * empty string when every sub-input is empty (caller emits dash).
     *
     * Format (mirrors typical postal layout):
     *   street
     *   street2
     *   city, state zip
     *   country
     *
     * Falls back to a simpler comma-joined form when the typical
     * city/state/zip cluster is partly missing.
     */
    public static function render_text(array $entry, string $field_id): string {
        $vals = self::sub_input_values($entry, $field_id);
        if ($vals === []) {
            return '';
        }
        // isset (not !empty) - sub_input_values() already strips
        // blank values, and !empty would drop a literal '0'. (#1603)
        $lines = [];
        if (isset($vals['street']))   { $lines[] = $vals['street']; }
        if (isset($vals['street2']))  { $lines[] = $vals['street2']; }

        $csz = [];
        if (isset($vals['city']))     { $csz[] = $vals['city']; }
        $state_zip = '';
        if (isset($vals['state']))    { $state_zip = $vals['state']; }
        if (isset($vals['zip']))      { $state_zip = trim($state_zip . ' ' . $vals['zip']); }
        if ($state_zip !== '')        { $csz[] = $state_zip; }
        if ($csz)                     { $lines[] = implode(', ', $csz); }

        if (isset($vals['country']))  { $lines[] = $vals['country']; }

        return implode("\n", $lines);
    }

    /**
     * Same as render_text() but returns an HTML string with `<br>`
     * separators, escaped via esc_html so cell content is safe.
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
        $parts = array_map($h, explode("\n", $text));
        return '<span class="gt-cell-address">' . implode('<br>', $parts) . '</span>';
    }

    /**
     * Substring-searchable concatenation - every sub-input joined by
     * spaces. Used by filter paths that test `LIKE %query%` against
     * a row. Empty sub-inputs contribute nothing.
     */
    public static function searchable_blob(array $entry, string $field_id): string {
        $vals = self::sub_input_values($entry, $field_id);
        if ($vals === []) {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        return trim(implode(' ', array_filter($vals, 'strlen')));
    }

    /**
     * Read the six sub-input values from the entry into a labelled
     * map. Skips empty values and trims whitespace. Returns [] when
     * every sub-input is empty.
     *
     * @return array{street?:string,street2?:string,city?:string,state?:string,zip?:string,country?:string}
     */
    private static function sub_input_values(array $entry, string $field_id): array {
        $keys = ['street', 'street2', 'city', 'state', 'zip', 'country'];
        $out = [];
        foreach (self::sub_input_keys($field_id) as $i => $key) {
            $val = isset($entry[$key]) ? trim((string) $entry[$key]) : '';
            if ($val !== '') {
                $out[$keys[$i]] = $val;
            }
        }
        return $out;
    }
}
