<?php
/**
 * TC_Airtable_Credential_Service
 *
 * Issue #517 — slice 3a of N. Encrypts the Airtable API token at rest
 * and persists base id + table id + encrypted token in a single
 * non-autoloaded option (`gt_airtable_credentials`).
 *
 * Mirrors the AES-256-CBC pattern from TC_External_DB:
 *   - Key derived from AUTH_KEY + a service-specific salt.
 *   - Random 16-byte IV per encrypt, prepended to ciphertext.
 *   - Whole envelope base64-encoded for clean option storage.
 *
 * Slice 3b will layer the admin settings page + connection-test
 * button on top. When 3b lands, the orphan invariants in slice 1
 * (AC#7), slice 2 (AC#10/AC#11), and this slice (AC#9) all retire
 * together (admin starts referencing all three Airtable services).
 *
 * Slice 4+ tackles two-way sync (#613 territory).
 *
 * Pairs with: TC_Airtable_Request_Builder (slice 1, v4.32.0)
 *             TC_Airtable_Sync_Engine     (slice 2, v4.33.0)
 *             TC_Airtable_Field_Mapper    (orphan; schema mapping)
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Airtable_Credential_Service {

    const OPTION_KEY = 'gt_airtable_credentials';
    const CIPHER = 'AES-256-CBC';
    const KEY_SALT = 'gt_airtable_creds';
    // #1645 — envelope version sentinels. v2 = raw 256-bit key; v1 = legacy
    // hex key. Pre-#1645 envelopes from this service carry NO sentinel and
    // are treated as v1 (see decrypt()).
    const ENVELOPE_V2 = 'gt_enc_v2:';
    const ENVELOPE_V1 = 'gt_enc_v1:';

    public static function option_key(): string {
        return self::OPTION_KEY;
    }

    /**
     * Encrypt a plaintext token using AUTH_KEY-derived AES-256-CBC.
     * Returns a base64-encoded envelope (16-byte IV + ciphertext).
     * Empty input returns empty output (no-op).
     */
    public static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }
        // @codeCoverageIgnoreStart
        if (!function_exists('openssl_encrypt')) {
            // Fallback for environments without openssl. Not safe for prod —
            // but it keeps unit tests deterministic and avoids a hard fail.
            return self::ENVELOPE_V2 . base64_encode($plaintext);
        }
        // @codeCoverageIgnoreEnd
        // #1645 — emit v2 envelopes keyed by the RAW 256-bit sha256 digest.
        // The v1 key was substr(sha256_hex, 0, 32) = 128 bits of effective
        // entropy; v2 uses the raw 32-byte digest. decrypt() still reads v1
        // (legacy unprefixed) envelopes so stored credentials keep working.
        $key = self::derive_key_v2();
        if ($key === '') {
            return ''; // #1637 — no secure key; refuse to encrypt.
        }
        $iv = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $key, 0, $iv);
        if ($cipher === false) {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        return self::ENVELOPE_V2 . base64_encode($iv . $cipher);
    }

    /**
     * Decrypt the envelope produced by encrypt(). Returns empty string
     * on tampered / garbage input (fail-closed).
     */
    public static function decrypt(string $envelope): string {
        if ($envelope === '') {
            return '';
        }
        // #1645 — pick the key by envelope version. v2 = raw 256-bit key;
        // v1 = legacy hex key. Pre-#1645 envelopes from this service have NO
        // sentinel — treat them as v1 so stored credentials keep decrypting
        // and migrate to v2 on the next store().
        if (strpos($envelope, self::ENVELOPE_V2) === 0) {
            $b64 = substr($envelope, strlen(self::ENVELOPE_V2));
            $key = self::derive_key_v2();
        } elseif (strpos($envelope, self::ENVELOPE_V1) === 0) {
            $b64 = substr($envelope, strlen(self::ENVELOPE_V1));
            $key = self::derive_key();
        } else {
            $b64 = $envelope; // legacy unprefixed == v1
            $key = self::derive_key();
        }
        // @codeCoverageIgnoreStart
        if (!function_exists('openssl_decrypt')) {
            return base64_decode($b64) ?: '';
        }
        // @codeCoverageIgnoreEnd
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        if ($key === '') {
            return ''; // #1637 — no secure key; fail closed.
        }
        $plain = openssl_decrypt($cipher, self::CIPHER, $key, 0, $iv);
        return $plain !== false ? $plain : '';
    }

    /**
     * Persist the credential triple. Token is encrypted before write.
     * Refuses any empty field — no point persisting half-config.
     *
     * @param string        $base_id   Airtable base id (e.g. "appAbc123")
     * @param string        $table_id  table name or id
     * @param string        $token     plaintext API token
     * @param callable|null $writer    fn(string $key, mixed $value): bool
     *                                  (e.g. WP's update_option). Injected for tests.
     */
    public static function store(string $base_id, string $table_id, string $token, ?callable $writer = null): bool {
        if ($base_id === '' || $table_id === '' || $token === '') {
            return false;
        }
        $writer = $writer ?: self::default_writer();
        $payload = [
            'base_id'  => $base_id,
            'table_id' => $table_id,
            'token'    => self::encrypt($token),
        ];
        return (bool) $writer(self::OPTION_KEY, $payload);
    }

    /**
     * Load the credential triple, decrypting the token. Returns null
     * when nothing has been stored.
     *
     * @param callable|null $reader  fn(string $key, mixed $default): mixed
     *                                (e.g. WP's get_option). Injected for tests.
     */
    public static function load(?callable $reader = null): ?array {
        $reader = $reader ?: self::default_reader();
        $raw = $reader(self::OPTION_KEY, null);
        if (!is_array($raw)) {
            return null;
        }
        return [
            'base_id'  => (string) ($raw['base_id'] ?? ''),
            'table_id' => (string) ($raw['table_id'] ?? ''),
            'token'    => self::decrypt((string) ($raw['token'] ?? '')),
        ];
    }

    /**
     * Wipe the credential option entirely.
     *
     * @param callable|null $deleter  fn(string $key): bool
     *                                 (e.g. WP's delete_option). Injected for tests.
     */
    public static function clear(?callable $deleter = null): bool {
        $deleter = $deleter ?: self::default_deleter();
        return (bool) $deleter(self::OPTION_KEY);
    }

    /**
     * True only when all three fields are non-empty after decrypt.
     */
    public static function is_configured(?callable $reader = null): bool {
        $loaded = self::load($reader);
        if (!is_array($loaded)) {
            return false;
        }
        return $loaded['base_id'] !== '' && $loaded['table_id'] !== '' && $loaded['token'] !== '';
    }

    // ---- internals ----

    private static function derive_key(): string {
        // #1637 — fail closed when AUTH_KEY is unavailable. The previous
        // fallback to a hardcoded literal made the key derivable from public
        // plugin source on installs missing WordPress salts.
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            return '';
        }
        // #1645 — LEGACY v1 key (32 hex chars = 128 bits effective). Retained
        // only to decrypt envelopes stored before the v2 upgrade.
        return substr(hash('sha256', AUTH_KEY . self::KEY_SALT), 0, 32);
    }

    /**
     * #1645 — full 256-bit AES key: the RAW 32-byte sha256 digest. Mirrors
     * _gt_derive_secret_key_v2('gt_airtable_creds') in helpers-secrets.php so
     * v2 envelopes are interoperable between the two paths.
     */
    private static function derive_key_v2(): string {
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            return '';
        }
        return hash('sha256', AUTH_KEY . self::KEY_SALT, true); // 32 raw bytes
    }

    private static function default_reader(): callable {
        if (function_exists('get_option')) {
            return function (string $key, $default) {
                return get_option($key, $default);
            };
        }
        // @codeCoverageIgnoreStart
        return function (string $key, $default) { return $default; };
        // @codeCoverageIgnoreEnd
    }

    private static function default_writer(): callable {
        if (function_exists('update_option')) {
            return function (string $key, $value): bool {
                return (bool) update_option($key, $value, false);
            };
        }
        // @codeCoverageIgnoreStart
        return function (string $key, $value): bool { return false; };
        // @codeCoverageIgnoreEnd
    }

    private static function default_deleter(): callable {
        if (function_exists('delete_option')) {
            return function (string $key): bool {
                return (bool) delete_option($key);
            };
        }
        // @codeCoverageIgnoreStart
        return function (string $key): bool { return false; };
        // @codeCoverageIgnoreEnd
    }
}
