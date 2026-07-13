<?php
/**
 * TC_Product_Field_Renderer
 *
 * Issue #807 (child of #793). GF `product` fields are composite:
 *   N.1 = product name
 *   N.2 = unit price (string like "$10.00" or "10")
 *   N.3 = quantity (numeric string)
 *
 * Without this renderer, the cell shows the bare slot $entry[N]
 * (typically empty for composites).
 *
 * Render shape: "Widget × 3 @ $10.00 = $30.00" or simpler variants
 * when sub-inputs are missing. Empty input → ''.
 *
 * @since 4.80.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Product_Field_Renderer {

    /**
     * Read the three sub-inputs into a labelled map. Empty sub-inputs
     * are omitted.
     *
     * @return array{name?:string,price?:string,quantity?:string}
     */
    public static function sub_input_values(array $entry, string $field_id): array {
        $keys = ['name', 'price', 'quantity'];
        $out = [];
        for ($i = 1; $i <= 3; $i++) {
            $raw = isset($entry[$field_id . '.' . $i]) ? trim((string) $entry[$field_id . '.' . $i]) : '';
            if ($raw !== '') {
                $out[$keys[$i - 1]] = $raw;
            }
        }
        return $out;
    }

    /**
     * Compose a readable summary. Possible shapes (in order of
     * completeness):
     *
     *   "Widget × 3 @ $10.00 = $30.00"   (all three present + numeric)
     *   "Widget × 3 @ $10.00"            (no line total - price not numeric)
     *   "Widget × 3"                     (no price)
     *   "Widget @ $10.00"                (no quantity)
     *   "Widget"                         (name only)
     *   ""                               (everything empty)
     */
    public static function render_text(array $entry, string $field_id): string {
        $v = self::sub_input_values($entry, $field_id);
        if (empty($v)) { return ''; }
        $name     = $v['name']     ?? '';
        $price    = $v['price']    ?? '';
        $quantity = $v['quantity'] ?? '';

        $parts = [];
        if ($name !== '') {
            if ($quantity !== '') {
                $parts[] = $name . ' × ' . $quantity;
            } else {
                $parts[] = $name;
            }
        // @codeCoverageIgnoreStart
        } elseif ($quantity !== '') {
            $parts[] = '× ' . $quantity;
        // @codeCoverageIgnoreEnd
        }

        if ($price !== '') {
            $parts[] = '@ ' . $price;
        }

        $base = implode(' ', $parts);
        $total = self::line_total($price, $quantity);
        if ($total !== null) {
            // Preserve a leading currency symbol if the price string
            // had one. /u modifier so multibyte currency chars (€, £,
            // ¥, ₹) are captured as whole code points, not broken
            // into individual bytes.
            $prefix = '';
            if (preg_match('/^\s*([^\d.\-]+)/u', $price, $m)) {
                $prefix = $m[1];
            }
            $base .= ' = ' . $prefix . self::format_money($total);
        }
        return $base;
    }

    /**
     * Numeric line total (price × quantity) or null when either
     * factor isn't numeric. Used by sort_key().
     */
    public static function line_total($price, $quantity): ?float {
        $p = self::parse_money((string) $price);
        $q = is_numeric($quantity) ? (float) $quantity : null;
        if ($p === null || $q === null) {
            return null;
        }
        return $p * $q;
    }

    /**
     * Numeric line-total sort key. Returns -INF for empty cells so
     * they sort last in ASC. Used by server-side sort path.
     */
    public static function sort_key(array $entry, string $field_id): float {
        $v = self::sub_input_values($entry, $field_id);
        $total = self::line_total($v['price'] ?? '', $v['quantity'] ?? '');
        if ($total !== null) { return $total; }
        // Empty cells: sort to the bottom in ASC. PHP min/max-safe.
        return -PHP_FLOAT_MAX;
    }

    /**
     * Strip currency / thousands separators to a float.
     *   "$10.00"   → 10.0
     *   "$1,234.5" → 1234.5
     *   "abc"      → null
     */
    private static function parse_money(string $s): ?float {
        if ($s === '') { return null; }
        // Drop everything except digits, dot, minus.
        $clean = preg_replace('/[^0-9.\-]/', '', $s);
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * Format a float with two decimal places, no thousands separator.
     * Theme code can override via the `gt_product_money_format`
     * filter if needed (rarely required - the rendered cell goes
     * inside a table and respects the column's text alignment).
     */
    private static function format_money(float $amount): string {
        $formatted = number_format($amount, 2, '.', '');
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('gt_product_money_format', $formatted, $amount);
            if (is_string($filtered) && $filtered !== '') {
                return $filtered;
            }
        }
        // @codeCoverageIgnoreStart
        return $formatted;
        // @codeCoverageIgnoreEnd
    }
}
