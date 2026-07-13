<?php
// @codeCoverageIgnoreStart
/**
 * Request-superglobal sanitisation helpers - issue #1073.
 *
 * Boundary-level helper for the four AJAX handlers flagged by the
 * #1073 input-sanitization audit. The audit found that
 * includes/class-tc-ajax.php::submit_new_entry() read
 * $_SERVER['REMOTE_ADDR'], HTTP_REFERER, and HTTP_USER_AGENT raw
 * before stuffing them into the Gravity Forms entry array. GF
 * downstream is defensive but the AJAX boundary is where the WP
 * sanitisation contract lives, so the read should sanitise at the
 * source.
 *
 * gt_request_server_text() does exactly that:
 *
 *   - Looks up $_SERVER[$key] with `?? $default` so missing keys
 *     (proxy environments where REMOTE_ADDR can legitimately be
 *     absent) do not emit an undefined-index notice.
 *   - Runs the value through wp_unslash() so wp_magic_quotes-added
 *     backslashes are stripped. Tracking wp_magic_quotes state is the
 *     whole reason wp_unslash() exists instead of stripslashes().
 *   - Routes the result through sanitize_text_field(), which strips
 *     HTML tags, collapses whitespace, and rejects invalid UTF-8.
 *     The same primitive every WP plugin uses for arbitrary text
 *     input read from a superglobal.
 *
 * Return shape: string. Always a string - never null, never the
 * unsanitised value. Callers can blindly assign the result to an
 * entry field without an additional cast.
 *
 * Audit reference:
 *   https://github.com/TableCrafter/gravity-tables/issues/1073
 *
 * @since 5.2.3 (slice 31 / issue #1073)
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!function_exists('gt_request_server_text')) {
    /**
     * Read a $_SERVER value with full WP-boundary sanitisation.
     *
     * @param string $key     The $_SERVER key to read (e.g. 'REMOTE_ADDR').
     * @param string $default Value to return when $_SERVER[$key] is missing
     *                       or non-scalar. Defaults to ''.
     * @return string Sanitised text - slashes stripped, tags stripped,
     *                whitespace collapsed. Returns $default verbatim when
     *                the key is missing, without running it through
     *                sanitize_text_field().
     */
    function gt_request_server_text(string $key, string $default = ''): string {
        if (!isset($_SERVER[$key])) {
            return $default;
        }
        $raw = $_SERVER[$key];
        if (!is_scalar($raw)) {
            // Defensive: $_SERVER should never contain non-scalars,
            // but a misbehaving SAPI / fastcgi setup could theoretically
            // surface an array. Treat as missing.
            return $default;
        }
        return sanitize_text_field(wp_unslash((string) $raw));
    }
}
// @codeCoverageIgnoreEnd
