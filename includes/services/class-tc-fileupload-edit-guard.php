<?php
/**
 * TC_Fileupload_Edit_Guard
 *
 * Issue #819 (child of #793 follow-up). GF `fileupload` fields can
 * store either a single URL string or a JSON-encoded array of URLs
 * (multi-file uploads with `multipleFiles` enabled on the form
 * field). The inline-edit AJAX path treated the incoming value as
 * a single scalar — submitting an edit on a multi-file column
 * would clobber the JSON array with the single string, losing every
 * URL except the one currently in the input.
 *
 * This guard surfaces that "would clobber" case so the update loop
 * can skip it and return an "edit blocked — multi-file field"
 * response instead of silently destroying data.
 *
 * Full wp.media multi-file modal is out of scope for this slice —
 * deferred to a separate ticket. This is the safety patch.
 *
 * @since 4.86.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Fileupload_Edit_Guard {

    /**
     * True iff `$existing` looks like a JSON-encoded array of URLs
     * (the GF multi-file shape). Conservative — only matches when:
     *   - the value parses as JSON
     *   - the decoded value is a non-empty array
     *   - every element is a string
     *
     * Single-file uploads store a plain URL string, which always
     * returns false.
     */
    public static function is_multi_file_shape(?string $existing): bool {
        if ($existing === null || $existing === '') {
            return false;
        }
        $trimmed = trim($existing);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            // Quick reject — JSON arrays start with '['.
            return false;
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded) || empty($decoded)) {
            return false;
        }
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when `$existing` is multi-file-shaped AND `$incoming` is
     * NOT — meaning a write would replace the JSON array with a
     * scalar string and lose every URL except the one the user
     * happens to have in the input.
     *
     * `$incoming` may be a string (the typical inline-edit shape),
     * an array (when caller passed structured data), or null/other
     * scalar. The guard fires only for the dangerous case:
     * existing=array-shape, incoming=string.
     */
    public static function would_clobber_multi_file(?string $existing, $incoming): bool {
        if (!self::is_multi_file_shape($existing)) {
            return false;
        }
        if (is_array($incoming)) {
            // Caller's writing a structured value — assume they
            // know what they're doing. Same-shape replacement isn't
            // a clobber.
            return false;
        }
        if (!is_scalar($incoming)) {
            // Anything non-scalar AND non-array is suspect; treat
            // as a clobber.
            return true;
        }
        $s = (string) $incoming;
        // If incoming is itself a JSON array of strings, treat as
        // legitimate replacement (caller posted the new full set).
        if ($s !== '' && $s[0] === '[' && self::is_multi_file_shape($s)) {
            return false;
        }
        // Scalar string write to a multi-file slot: this is the
        // clobber case we're guarding against.
        return true;
    }
}
