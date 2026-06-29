<?php
/**
 * TC_AI_Cleanup_Suggester
 *
 * Issue #497 (sub of #490) — slice 1 of 3. Pure rule-based cleanup
 * suggester. Scans a column of values and returns structured
 * suggestions for low-risk obvious edits: trim whitespace, normalize
 * internal spaces, empty-after-trim. Future slices add:
 *
 *   - Slice 2: mixed-capitalization detection in enum-like columns,
 *     mixed date format detection, mixed phone shape, typo clusters.
 *   - Slice 3: LLM pass replacing or augmenting the rule-based
 *     suggestions, plus the bulk preview-and-apply admin UI.
 *
 * Each suggestion is the canonical envelope:
 *
 *   [
 *     'value_index'     => 0,                  // 0-based row position
 *     'current_value'   => '  Alice  ',        // verbatim original
 *     'suggested_value' => 'Alice',            // what cleanup produces
 *     'reason'          => 'trim_whitespace',  // machine-readable code
 *     'confidence'      => 0.95,               // float 0-1
 *   ]
 *
 * Slices 2/3 plug in additively — they extend the suggestion list
 * for the same envelope shape, and callers (preview-and-apply UI,
 * future audit-log writer) bind to a single contract.
 *
 * Rules are evaluated in a deterministic order. The FIRST rule that
 * matches a value wins; subsequent rules don't double-fire on the
 * same row. Idempotence is by construction: applying a rule's
 * suggested_value to the input yields a value that no rule would
 * suggest editing further.
 *
 * @since 4.7.28
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_AI_Cleanup_Suggester {

    /**
     * Scan a column of values and return suggested edits.
     *
     * @param array  $values       Column values, in row order. Non-string
     *                             entries (int / bool / null / array) are
     *                             skipped — they're not text-cleanup targets.
     * @param string $column_type  Column-type hint (text / email / url / etc.).
     *                             Reserved for slice 2; slice 1 ignores it.
     * @return array<int,array{value_index:int,current_value:string,
     *                         suggested_value:string,reason:string,
     *                         confidence:float}>
     */
    public static function suggest_for_column(array $values, string $column_type = 'text'): array {
        $out = [];
        foreach ($values as $idx => $v) {
            if (!is_string($v)) {
                continue;
            }
            $envelope = self::evaluate_rules($idx, $v);
            if ($envelope !== null) {
                $out[] = $envelope;
            }
        }
        return $out;
    }

    /**
     * Internal: evaluate the rule chain for a single value. First
     * matching rule wins. Returns the suggestion envelope or null.
     */
    private static function evaluate_rules(int $idx, string $value): ?array {
        // Rule 1: empty_whitespace — value is all whitespace, suggest empty.
        // Must be tested BEFORE trim_whitespace so we don't classify
        // "   " as a trim (which would suggest empty already, but with
        // a less precise reason).
        if ($value !== '' && trim($value) === '') {
            return [
                'value_index'     => $idx,
                'current_value'   => $value,
                'suggested_value' => '',
                'reason'          => 'empty_whitespace',
                'confidence'      => 0.95,
            ];
        }

        // Rule 2: trim_whitespace — leading or trailing whitespace.
        $trimmed = trim($value);
        if ($trimmed !== $value) {
            // Could ALSO have multiple internal spaces; detect that and
            // chain it into the suggested_value.
            $normalized = self::normalize_internal_whitespace($trimmed);
            $suggested  = $normalized !== $trimmed ? $normalized : $trimmed;
            // Reason is trim_whitespace if that's the dominant edit;
            // if internal spaces also need normalization, prefer the
            // more comprehensive label since the suggested_value
            // applies both transforms.
            $reason = $normalized !== $trimmed ? 'trim_whitespace' : 'trim_whitespace';
            return [
                'value_index'     => $idx,
                'current_value'   => $value,
                'suggested_value' => $suggested,
                'reason'          => $reason,
                'confidence'      => 0.95,
            ];
        }

        // Rule 3: normalize_internal_spaces — multiple internal spaces / tabs / newlines.
        $normalized = self::normalize_internal_whitespace($value);
        if ($normalized !== $value) {
            return [
                'value_index'     => $idx,
                'current_value'   => $value,
                'suggested_value' => $normalized,
                'reason'          => 'normalize_internal_spaces',
                'confidence'      => 0.9,
            ];
        }

        return null;
    }

    /**
     * Collapse runs of internal whitespace (space / tab / newline) to a
     * single space. Does NOT trim — caller has already trimmed if
     * appropriate.
     */
    private static function normalize_internal_whitespace(string $value): string {
        return preg_replace('/[\s]+/u', ' ', $value);
    }
}
