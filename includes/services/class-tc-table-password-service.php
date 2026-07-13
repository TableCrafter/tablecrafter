<?php
/**
 * TC_Table_Password_Service
 *
 * Issue #607 - slice 1 of N. Pure helper for per-table password
 * protection. Slice 2 wires the admin "Password (optional)" field +
 * AJAX save sanitization. Slice 3 wires the frontend gate (visitor
 * sees a password form instead of the table; correct password
 * remembered via signed cookie).
 *
 * The token format is a self-contained string that lets the visitor's
 * cookie verify against the current stored password hash without a
 * server-side session table:
 *
 *     <issued_at>.<base64url(hmac_sha256(table_id|stored_hash|issued_at, secret))>
 *
 * Properties:
 * - Rotating the password (stored_hash) invalidates every existing token.
 * - Rotating the secret (e.g. wp_salt rotation) invalidates every existing token.
 * - The token carries its own issued-at timestamp, so the cookie alone
 *   determines expiry (no server-side TTL store).
 * - Constant-time signature comparison via hash_equals.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Table_Password_Service {

    const COOKIE_PREFIX = 'gt_table_pw_';
    const TOKEN_HASH_ALGO = 'sha256';
    const HASH_COST = 10;
    const DEFAULT_TTL_SECONDS = 86400;

    /**
     * Hash a plaintext password for storage on the table row.
     *
     * @param string $plaintext
     * @return string bcrypt hash
     */
    public static function hash($plaintext) {
        if (!is_string($plaintext) || $plaintext === '') {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        return password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => self::HASH_COST]);
    }

    /**
     * Verify a plaintext attempt against a stored hash.
     *
     * @param string $plaintext
     * @param string $stored_hash
     * @return bool
     */
    public static function verify($plaintext, $stored_hash) {
        if (!is_string($plaintext) || $plaintext === '') {
            return false;
        }
        if (!is_string($stored_hash) || $stored_hash === '') {
            return false;
        }
        $info = password_get_info($stored_hash);
        if (empty($info['algo'])) {
            return false;
        }
        return password_verify($plaintext, $stored_hash);
    }

    /**
     * Cookie name keyed by table id. Empty string for non-positive ids.
     *
     * @param int $table_id
     * @return string
     */
    public static function cookie_name($table_id) {
        $id = is_numeric($table_id) ? (int)$table_id : 0;
        if ($id <= 0) {
            return '';
        }
        return self::COOKIE_PREFIX . $id;
    }

    /**
     * Default cookie TTL (24h).
     *
     * @return int
     */
    public static function default_ttl_seconds() {
        return self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Generate a signed unlock token to store in the visitor's cookie.
     *
     * @param int    $table_id
     * @param string $stored_hash current password hash for the table
     * @param int    $issued_at   unix timestamp at which the token is issued
     * @param string $secret      site-wide secret (wp_salt('auth') in production)
     * @return string "<issued_at>.<signature>"
     */
    public static function generate_unlock_token($table_id, $stored_hash, $issued_at, $secret) {
        $sig = self::compute_signature((int)$table_id, (string)$stored_hash, (int)$issued_at, (string)$secret);
        return ((int)$issued_at) . '.' . $sig;
    }

    /**
     * Verify a cookie value against the current stored hash + secret.
     *
     * @param string $cookie_value e.g. "1715000000.abc123..."
     * @param int    $table_id
     * @param string $stored_hash
     * @param int    $now           unix timestamp "now"
     * @param int    $ttl_seconds   max age for the token
     * @param string $secret
     * @return bool
     */
    public static function is_unlocked($cookie_value, $table_id, $stored_hash, $now, $ttl_seconds, $secret) {
        if (!is_string($cookie_value) || $cookie_value === '') {
            return false;
        }
        $dot = strpos($cookie_value, '.');
        if ($dot === false || $dot === 0) {
            return false;
        }
        $issued_part = substr($cookie_value, 0, $dot);
        $sig_part    = substr($cookie_value, $dot + 1);
        if ($issued_part === '' || $sig_part === '') {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        if ((string)(int)$issued_part !== $issued_part) {
            return false;
        }
        $issued_at = (int)$issued_part;
        $now       = (int)$now;
        $ttl       = (int)$ttl_seconds;
        if ($issued_at > $now) {
            return false;
        }
        if (($now - $issued_at) > $ttl) {
            return false;
        }
        $expected = self::compute_signature((int)$table_id, (string)$stored_hash, $issued_at, (string)$secret);
        return hash_equals($expected, $sig_part);
    }

    /**
     * #1632 - request-context unlock check for stateless callers (REST /
     * AJAX). Reads the visitor's signed cookie and verifies it the same
     * way TC_Shortcode::apply_password_gate() does. Returns true when the
     * table has no password set, or when a valid unlock cookie is present.
     *
     * @param int    $table_id
     * @param string $stored_hash settings['table_password_hash']
     * @return bool
     */
    public static function request_is_unlocked($table_id, $stored_hash) {
        $stored_hash = (string) $stored_hash;
        if ($stored_hash === '') {
            return true; // no password configured → not gated
        }
        $cookie_name = self::cookie_name($table_id);
        if ($cookie_name === '') {
            return false;
        }
        $cookie_value = isset($_COOKIE[$cookie_name]) ? (string) $_COOKIE[$cookie_name] : '';
        $secret = function_exists('wp_salt') ? wp_salt('auth') : 'gt-fallback-salt';
        $now    = time();
        $ttl    = self::default_ttl_seconds();
        return self::is_unlocked($cookie_value, (int) $table_id, $stored_hash, $now, $ttl, $secret);
    }

    private static function compute_signature($table_id, $stored_hash, $issued_at, $secret) {
        $payload = $table_id . '|' . $stored_hash . '|' . $issued_at;
        $raw = hash_hmac(self::TOKEN_HASH_ALGO, $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
