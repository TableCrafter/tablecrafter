<?php
/**
 * TC_WAF_Safe_Payload
 *
 * Issue #553 — opaque envelope for inline-edit AJAX payloads so
 * generic WAF rules (Cloudflare, mod_security, Wordfence, Sucuri)
 * cannot pattern-match SQLi / XSS tokens against legitimate user
 * cell content like `UNION SELECT * FROM users` or
 * `<script>example</script>`.
 *
 * The transform: `json_encode(payload)` then `base64_encode(json)`.
 * The result contains only `A-Za-z0-9+/=` so any rule keyed on
 * literal SQL keywords or angle brackets simply can't match.
 *
 * Wiring contract (for the follow-up slice that integrates this into
 * the AJAX layer):
 *
 *   - Client: when `gt_waf_safe_payload` filter (or per-table config
 *     flag) is true, the inline-edit save calls
 *     `JSON.stringify` + `btoa` on the payload object and posts it
 *     under `payload` instead of the legacy form-encoded fields.
 *
 *   - Server: `TC_Ajax::update_entry()` (and analogous handlers)
 *     check for `$_POST['payload']`. If `is_encoded()` returns true,
 *     `decode()` produces the canonical array with `entry_id`,
 *     `table_id`, `updates`. Legacy form-encoded callers continue to
 *     work unchanged because the handler falls through to the existing
 *     `$_POST['updates']` path when no encoded payload is present.
 *
 * @since 4.7.22
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_WAF_Safe_Payload {

    /**
     * JSON-encode then base64-encode the payload. Result contains only
     * base64 alphabet characters.
     */
    public static function encode(array $payload): string {
        $json = json_encode($payload);
        if ($json === false) {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        return base64_encode($json);
    }

    /**
     * Reverse of encode(). Returns the canonical array, or null on
     * any failure (malformed base64, malformed JSON, JSON-encoded
     * scalar / null at the top level).
     */
    public static function decode(string $encoded): ?array {
        if ($encoded === '') {
            return null;
        }
        // Reject obvious non-base64 input early (saves a json_decode call).
        if (preg_match('#^[A-Za-z0-9+/=]+$#', $encoded) !== 1) {
            return null;
        }
        $json = base64_decode($encoded, true);
        if ($json === false || $json === '') {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Heuristic: does `$candidate` look like the output of `encode()`?
     *
     * - Must be longer than 4 chars (rule out trivial padding strings).
     * - Must contain only base64-alphabet characters.
     * - Must round-trip through `decode()` to a non-null array.
     *
     * The roundtrip check is what actually validates — the regex is a
     * fast-fail to avoid running base64_decode + json_decode on every
     * incoming form value.
     */
    public static function is_encoded(string $candidate): bool {
        if (strlen($candidate) < 5) {
            return false;
        }
        if (preg_match('#^[A-Za-z0-9+/=]+$#', $candidate) !== 1) {
            return false;
        }
        return self::decode($candidate) !== null;
    }
}
