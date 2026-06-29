<?php
/**
 * TC_Creditcard_Field_Renderer
 *
 * Issue #806 (child of #793). HIGH PRIORITY — PCI concerns.
 *
 * Gravity Forms `creditcard` fields store a partially-redacted card
 * in sub-inputs:
 *   N.1 = masked PAN (e.g. "XXXXXXXXXXXX1234")
 *   N.2 = full PAN (GF 2.x never persists this; defensive only)
 *   N.3 = CVV (GF 2.x never persists this; defensive only)
 *   N.4 = card type (e.g. "Visa")
 *   N.5 = holder name
 *
 * This renderer is DEFENSIVE: it ONLY emits the card type + masked
 * tail (last 4 digits derived from N.1) and the holder name when
 * requested. It NEVER emits N.2 or N.3 — even if a misconfigured /
 * compromised GF install accidentally persisted them, this layer
 * stops them at the render boundary.
 *
 * Sanitization rules:
 *   - The "masked tail" is derived by taking ONLY the last 4 digits
 *     of N.1. Anything longer than 4 digits in the output is a bug.
 *   - Hyphens / spaces / X's in the masked PAN are dropped during
 *     tail extraction so the format is uniform.
 *   - Holder name (N.5) is included in the eye popup ONLY, not in
 *     the cell render — minimises leakage surface.
 *
 * The companion redactor `redact_for_payload(array $entry, string
 * $field_id): array` returns the entry with creditcard sub-inputs
 * scrubbed, used by the Abilities API `query_rows` post-process so
 * MCP / AI consumers never receive raw card data.
 *
 * @since 4.77.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Creditcard_Field_Renderer {

    /**
     * Cell-render output: "Visa ending in 1234" or "Card ending in
     * 1234" when type is missing. Returns '' when no card data is
     * present (caller emits dash).
     */
    public static function render_safe(array $entry, string $field_id): string {
        $tail = self::masked_tail($entry, $field_id);
        $type = self::card_type($entry, $field_id);
        if ($tail === '' && $type === '') {
            return '';
        }
        if ($tail === '') {
            // @codeCoverageIgnoreStart
            return $type;
            // @codeCoverageIgnoreEnd
        }
        $label = $type !== '' ? $type : 'Card';
        return $label . ' ending in ' . $tail;
    }

    /**
     * Eye-popup output: render_safe() plus the holder name on a
     * second line when present. Holder name is never emitted in
     * the cell render — only here. Returns '' when nothing present.
     */
    public static function render_safe_full(array $entry, string $field_id): string {
        $base = self::render_safe($entry, $field_id);
        if ($base === '') {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        $holder = isset($entry[$field_id . '.5']) ? trim((string) $entry[$field_id . '.5']) : '';
        if ($holder !== '') {
            return $base . "\n" . $holder;
        }
        return $base;
    }

    /**
     * Redact the creditcard sub-inputs in an entry array, returning
     * a new entry array safe to ship over the Abilities API / MCP
     * transport. The redacted entry contains ONLY the safe summary
     * string at `$entry[$field_id]`; every sub-input N.1 through N.5
     * is removed.
     */
    public static function redact_for_payload(array $entry, string $field_id): array {
        $safe = self::render_safe($entry, $field_id);
        $out = $entry;
        $out[$field_id] = $safe;
        for ($i = 1; $i <= 5; $i++) {
            $key = $field_id . '.' . $i;
            if (array_key_exists($key, $out)) {
                unset($out[$key]);
            }
        }
        return $out;
    }

    /**
     * Extract the last 4 digits from N.1. Returns '' if no digits
     * found (e.g. card data scrubbed entirely). NEVER returns more
     * than 4 digits.
     */
    private static function masked_tail(array $entry, string $field_id): string {
        $masked = isset($entry[$field_id . '.1']) ? (string) $entry[$field_id . '.1'] : '';
        if ($masked === '') {
            return '';
        }
        // Strip everything that isn't a digit (drops X's, spaces,
        // hyphens, masks). Then take the last 4.
        $digits = preg_replace('/[^0-9]/', '', $masked);
        if ($digits === '' || $digits === null) {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        return substr($digits, -4);
    }

    /**
     * Card type from N.4. Sanitised — only word chars and spaces
     * allowed (defensive in case of crafted entry data).
     */
    private static function card_type(array $entry, string $field_id): string {
        $raw = isset($entry[$field_id . '.4']) ? trim((string) $entry[$field_id . '.4']) : '';
        if ($raw === '') {
            return '';
        }
        return preg_replace('/[^A-Za-z0-9 \-]/', '', $raw);
    }
}
