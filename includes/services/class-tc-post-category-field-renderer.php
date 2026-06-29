<?php
/**
 * TC_Post_Category_Field_Renderer
 *
 * Issue #809 (child of #793). GF `post_category` fields store the
 * term as `Name:ID` (e.g. "Uncategorized:1") in `$entry[$field_id]`.
 * Without this renderer the cell shows the raw blob.
 *
 * @since 4.78.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Post_Category_Field_Renderer {

    /**
     * Strip the trailing `:N` (term id) from a stored category
     * value, returning just the term name. Multiple categories
     * (comma-separated) are each stripped individually.
     *
     * Defensive: handles empty / non-string input as ''. Preserves
     * names that legitimately contain colons (only the trailing
     * `:<digits>$` suffix is stripped per segment).
     */
    public static function render_text($value): string {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
        }
        if (!is_string($value) || $value === '') {
            return '';
        }
        $parts = array_map('trim', explode(',', $value));
        $cleaned = [];
        foreach ($parts as $part) {
            if ($part === '') { continue; }
            // Strip trailing `:<digits>` (the term id GF appends).
            // Only the final colon-digits segment is stripped, so
            // category names containing colons (rare) are preserved.
            $stripped = preg_replace('/:\d+$/', '', $part);
            $stripped = is_string($stripped) ? trim($stripped) : '';
            if ($stripped !== '') {
                $cleaned[] = $stripped;
            }
        }
        return implode(', ', $cleaned);
    }
}
