<?php
/**
 * TC_Name_Field_Renderer
 *
 * Issue #817 (child of #793). GF `name` fields store sub-inputs:
 *   N.2 = prefix    (Mr, Ms, Dr, …)
 *   N.3 = first
 *   N.4 = middle
 *   N.6 = last
 *   N.8 = suffix    (Jr, Sr, III, …)
 *
 * Without this renderer, cells show the bare slot (empty for
 * composites). Eye popup picks up the value via the generic
 * multi-input scanner but order / spacing is unpredictable.
 *
 * @since 4.83.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Name_Field_Renderer {

    /**
     * Pull the five sub-inputs into a labelled map. Empty inputs are
     * omitted.
     *
     * @return array{prefix?:string,first?:string,middle?:string,last?:string,suffix?:string}
     */
    public static function sub_input_values(array $entry, string $field_id): array {
        $map = [
            '2' => 'prefix',
            '3' => 'first',
            '4' => 'middle',
            '6' => 'last',
            '8' => 'suffix',
        ];
        $out = [];
        foreach ($map as $sub => $key) {
            $raw = isset($entry[$field_id . '.' . $sub]) ? trim((string) $entry[$field_id . '.' . $sub]) : '';
            if ($raw !== '') {
                $out[$key] = $raw;
            }
        }
        return $out;
    }

    /**
     * Compose a readable name. Format string tokens (case-insensitive):
     *   {prefix} {first} {middle} {last} {suffix}
     *
     * Default format: "{first} {last}" with prefix prepended and
     * suffix appended when non-empty. Filter `gt_name_field_format`
     * lets themes override (e.g. to "{last}, {first}").
     *
     * Empty sub-inputs collapse cleanly (no double-spaces / trailing
     * commas).
     */
    public static function render_text(array $entry, string $field_id, ?string $format = null): string {
        $v = self::sub_input_values($entry, $field_id);
        if (empty($v)) { return ''; }

        if ($format === null) {
            $format = '{first} {last}';
            if (function_exists('apply_filters')) {
                $filtered = apply_filters('gt_name_field_format', $format);
                if (is_string($filtered) && $filtered !== '') {
                    $format = $filtered;
                }
            }
        }

        $out = preg_replace_callback('/\{(prefix|first|middle|last|suffix)\}/i', function ($m) use ($v) {
            $key = strtolower($m[1]);
            return $v[$key] ?? '';
        }, $format);

        // Prepend prefix / append suffix when the format didn't
        // already include them and they're non-empty. This matches
        // the typical "Mr. John Smith Jr." rendering people expect.
        // isset (not !empty) — sub_input_values() already strips blank
        // values, and !empty would drop a literal '0'. (#1603)
        if (stripos($format, '{prefix}') === false && isset($v['prefix'])) {
            $out = $v['prefix'] . ' ' . $out;
        }
        if (stripos($format, '{suffix}') === false && isset($v['suffix'])) {
            $out = $out . ' ' . $v['suffix'];
        }

        // Collapse runs of whitespace + strip leading/trailing
        // commas (defensive for "{last}, {first}" with empty last).
        $out = preg_replace('/\s+/', ' ', (string) $out);
        $out = trim((string) $out, " ,\t\n");
        return $out;
    }
}
