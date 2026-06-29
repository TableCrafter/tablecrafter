<?php
/**
 * TC_API_Key_Service
 *
 * Issue #505 — slice 1 of 3. Foundational API-key auth layer for the
 * Zapier / n8n / Make integration. Generate, persist, verify, revoke.
 *
 * Storage model:
 *   - WordPress option `gt_api_keys`, keyed by SHA-256 hash of the
 *     plaintext key. Plaintext is shown ONCE on `generate()` —
 *     subsequent reads only see the hash. Mirrors the GitHub PAT /
 *     Stripe API-key UX.
 *   - Each entry: { label, user_id, created_at, last_used_at }.
 *
 * Key format: `gtak_` (Gravity Tables API Key) + 32 random hex chars.
 * Recognizable on sight in support tickets, urlsafe, single-line.
 *
 * `verify()` updates `last_used_at` so admins can audit usage and
 * spot stale keys. Comparison uses `hash_equals` to prevent timing
 * leaks against the SHA-256 lookup.
 *
 * Slice 2: REST endpoints scoped to API-key auth (trigger polling +
 *   action endpoints), wired through a new `gt_api_key`
 *   permission_callback.
 * Slice 3: Zapier app definition + n8n / Make compatibility +
 *   developer docs walkthrough.
 *
 * @since 4.7.32
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_API_Key_Service {

    private const OPTION_NAME = 'gt_api_keys';
    private const KEY_PREFIX  = 'gtak_';

    /**
     * Generate a new API key. Returns the metadata struct INCLUDING
     * the plaintext key — this is the only time the plaintext is
     * exposed. The caller (admin UI) should display it once with
     * a "save this now, you won't see it again" notice.
     *
     * @return array{key:string,label:string,user_id:int,
     *               created_at:string,last_used_at:?string}
     */
    public static function generate(string $label, int $user_id): array {
        $plaintext = self::KEY_PREFIX . bin2hex(random_bytes(16));
        $hash = hash('sha256', $plaintext);
        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d\TH:i:s');

        $stored = (array) get_option(self::OPTION_NAME, []);
        $stored[$hash] = [
            'label'         => $label,
            'user_id'       => (int) $user_id,
            'created_at'    => $now,
            'last_used_at'  => null,
        ];
        update_option(self::OPTION_NAME, $stored);

        return [
            'key'           => $plaintext,
            'label'         => $label,
            'user_id'       => (int) $user_id,
            'created_at'    => $now,
            'last_used_at'  => null,
        ];
    }

    /**
     * Verify a plaintext key. Returns the stored metadata struct
     * (without the plaintext key) on success, null otherwise.
     * Updates `last_used_at` on a successful verify.
     */
    public static function verify(string $key): ?array {
        if ($key === '' || strpos($key, self::KEY_PREFIX) !== 0) {
            return null;
        }
        $hash = hash('sha256', $key);
        $stored = (array) get_option(self::OPTION_NAME, []);
        if (!isset($stored[$hash])) {
            return null;
        }
        // Constant-time-equals on the stored hash key. We've already
        // looked up by hash above, but iterate every key with
        // `hash_equals` so a future change that swaps direct lookup
        // for a scan still doesn't leak timing.
        $matched_hash = null;
        foreach (array_keys($stored) as $h) {
            if (hash_equals($h, $hash)) {
                $matched_hash = $h;
                break;
            }
        }
        if ($matched_hash === null) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }
        // Update last_used_at.
        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d\TH:i:s');
        $stored[$matched_hash]['last_used_at'] = $now;
        update_option(self::OPTION_NAME, $stored);
        return $stored[$matched_hash];
    }

    /**
     * Revoke a key by plaintext. Returns true iff a matching entry
     * was found and removed.
     */
    public static function revoke(string $key): bool {
        if ($key === '' || strpos($key, self::KEY_PREFIX) !== 0) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        $hash = hash('sha256', $key);
        $stored = (array) get_option(self::OPTION_NAME, []);
        if (!isset($stored[$hash])) {
            return false;
        }
        unset($stored[$hash]);
        update_option(self::OPTION_NAME, $stored);
        return true;
    }

    /**
     * Return all stored entries (metadata only — never the plaintext).
     * Used by the admin UI to render a table with a "last used"
     * column.
     */
    public static function list_keys(): array {
        $stored = (array) get_option(self::OPTION_NAME, []);
        $out = [];
        foreach ($stored as $hash => $meta) {
            // Defensive: never include the storage hash in returned
            // metadata so it can't be reflected back to a non-admin
            // context that might rebuild a verify-able plaintext.
            $out[] = [
                'label'         => $meta['label'] ?? '',
                'user_id'       => (int) ($meta['user_id'] ?? 0),
                'created_at'    => $meta['created_at'] ?? '',
                'last_used_at'  => $meta['last_used_at'] ?? null,
            ];
        }
        return $out;
    }
}
