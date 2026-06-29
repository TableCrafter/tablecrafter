<?php
/**
 * Evaluates per-column conditional formatting rules and generates inline styles / CSS classes.
 *
 * Rules are evaluated server-side at render time so formatting is visible without JavaScript
 * and carries through to HTML exports.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Conditional_Formatting_Service {

    const SUPPORTED_CONDITIONS = [
        'equals',
        'not_equals',
        'contains',
        'greater_than',
        'less_than',
        'is_empty',
        'is_not_empty',
    ];

    /**
     * Evaluate a single rule against a cell value.
     *
     * @param array  $rule       Rule definition: { condition, value, ... }
     * @param string $cell_value The raw cell value to test.
     * @param array  $row        Full row values keyed by field_id (for cross-column comparisons).
     * @return bool
     */
    public static function evaluate(array $rule, string $cell_value, array $row = []): bool {
        $condition = $rule['condition'] ?? '';
        $compare   = $rule['value'] ?? '';

        // Allow comparison against another column value
        if (!empty($rule['value_column']) && isset($row[$rule['value_column']])) {
            $compare = $row[$rule['value_column']];
        }

        switch ($condition) {
            case 'equals':
                return strtolower(trim($cell_value)) === strtolower(trim($compare));

            case 'not_equals':
                return strtolower(trim($cell_value)) !== strtolower(trim($compare));

            case 'contains':
                return $compare !== '' && stripos($cell_value, $compare) !== false;

            case 'greater_than':
                return is_numeric($cell_value) && is_numeric($compare) && (float) $cell_value > (float) $compare;

            case 'less_than':
                return is_numeric($cell_value) && is_numeric($compare) && (float) $cell_value < (float) $compare;

            case 'is_empty':
                return trim($cell_value) === '';

            case 'is_not_empty':
                return trim($cell_value) !== '';

            default:
                return false;
        }
    }

    /**
     * Apply matching rules from $rules to a cell and return inline style + class attributes.
     *
     * @param array  $rules      Array of rule definitions.
     * @param string $cell_value Raw cell value.
     * @param array  $row        Full row values.
     * @return array { style: string, classes: string[] }
     */
    public static function get_cell_attributes(array $rules, string $cell_value, array $row = []): array {
        $styles  = [];
        $classes = [];

        foreach ($rules as $rule) {
            if (empty($rule['condition'])) {
                continue;
            }
            if (!self::evaluate($rule, $cell_value, $row)) {
                continue;
            }

            // background_color
            if (!empty($rule['background_color'])) {
                $color = sanitize_hex_color($rule['background_color']);
                if ($color) {
                    $styles[] = 'background-color:' . esc_attr($color);
                }
            }

            // text_color
            if (!empty($rule['text_color'])) {
                $color = sanitize_hex_color($rule['text_color']);
                if ($color) {
                    $styles[] = 'color:' . esc_attr($color);
                }
            }

            // bold
            if (!empty($rule['bold'])) {
                $styles[] = 'font-weight:bold';
            }

            // italic
            if (!empty($rule['italic'])) {
                $styles[] = 'font-style:italic';
            }

            // css_class
            if (!empty($rule['css_class'])) {
                foreach (explode(' ', $rule['css_class']) as $cls) {
                    $cls = sanitize_html_class(trim($cls));
                    if ($cls !== '') {
                        $classes[] = $cls;
                    }
                }
            }

            // First-match-wins mode: stop after first matching rule unless 'apply_all' is set
            if (empty($rule['apply_all'])) {
                break;
            }
        }

        return [
            'style'   => implode(';', $styles),
            'classes' => $classes,
        ];
    }

    /**
     * Apply conditional formatting rules and return a style="" attribute string.
     *
     * @param array  $rules
     * @param string $cell_value
     * @param array  $row
     * @return string  e.g. 'style="background-color:#ff0000;font-weight:bold"'
     */
    public static function get_inline_styles(array $rules, string $cell_value, array $row = []): string {
        $attrs = self::get_cell_attributes($rules, $cell_value, $row);
        if (empty($attrs['style'])) {
            return '';
        }
        return 'style="' . esc_attr($attrs['style']) . '"';
    }

    /**
     * Inject conditional formatting attributes into a cell HTML string.
     *
     * @param string $cell_html  Existing <td ...> ... </td> HTML.
     * @param array  $rules
     * @param string $cell_value
     * @param array  $row
     * @return string
     */
    public static function apply_to_cell(string $cell_html, array $rules, string $cell_value, array $row = []): string {
        $attrs = self::get_cell_attributes($rules, $cell_value, $row);

        if (!empty($attrs['style'])) {
            $style_attr = 'style="' . esc_attr($attrs['style']) . '"';
            // Inject before the closing > of the opening tag
            $cell_html = preg_replace('/(<td\b[^>]*)>/i', '$1 ' . $style_attr . '>', $cell_html, 1);
        }

        if (!empty($attrs['classes'])) {
            $extra = implode(' ', $attrs['classes']);
            if (preg_match('/class="([^"]*)"/i', $cell_html)) {
                $cell_html = preg_replace('/class="([^"]*)"/i', 'class="$1 ' . esc_attr($extra) . '"', $cell_html, 1);
            } else {
                $cell_html = preg_replace('/(<td\b[^>]*)>/i', '$1 class="' . esc_attr($extra) . '">', $cell_html, 1);
            }
        }

        return $cell_html;
    }

    /**
     * Apply row-level rules: returns attributes to inject on the <tr> element.
     *
     * Row-level rules are identified by row_level => true in the rule definition.
     * The condition is evaluated against the specified trigger_column value.
     *
     * @param array $rules   Array of rule definitions (only row_level ones are used).
     * @param array $row     Full row values keyed by field_id.
     * @return array { style: string, classes: string[] }
     */
    public static function apply_to_row(array $rules, array $row): array {
        $row_rules = array_filter($rules, fn($r) => !empty($r['row_level']));
        if (empty($row_rules)) {
            return ['style' => '', 'classes' => []];
        }

        $row_rule_list = [];
        foreach ($row_rules as $rule) {
            $trigger_column = $rule['trigger_column'] ?? '';
            $cell_value     = $trigger_column !== '' ? ($row[$trigger_column] ?? '') : '';
            $row_rule_list[] = ['rule' => $rule, 'cell_value' => $cell_value];
        }

        // Flatten: evaluate each row_rule against its trigger column value
        $flat_rules  = [];
        $flat_values = '';
        $flat_row    = $row;

        // Build a synthetic flat evaluation
        $styles  = [];
        $classes = [];

        foreach ($row_rule_list as $item) {
            if (!self::evaluate($item['rule'], $item['cell_value'], $flat_row)) {
                continue;
            }
            $attrs = self::get_cell_attributes([$item['rule']], $item['cell_value'], $flat_row);
            if ($attrs['style']) {
                $styles[] = $attrs['style'];
            }
            $classes = array_merge($classes, $attrs['classes']);
            if (empty($item['rule']['apply_all'])) {
                break;
            }
        }

        return [
            'style'   => implode(';', $styles),
            'classes' => array_unique($classes),
        ];
    }
}
