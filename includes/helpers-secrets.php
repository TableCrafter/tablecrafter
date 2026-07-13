<?php
// @codeCoverageIgnoreStart
/**
 * Shared secret-encryption helpers - issue #1076.
 *
 * Extracts the AES-256-CBC cipher from TC_Airtable_Credential_Service
 * (#517 slice 3a) into a free-standing helper so every credential store
 * in the plugin uses the same primitive. The Airtable credential service
 * remains as the wp_options orchestrator for the Airtable PAT triple - 
 * its encrypt/decrypt internals continue to work as-is for backward
 * compatibility (envelopes produced by either path are decryptable by
 * the other, given the matching salt).
 *
 * Call sites (audited under #1076):
 *
 *   - includes/class-tc-cloud-storage.php          OAuth bearer tokens
 *                                                  (OneDrive / SharePoint / Google Drive)
 *   - includes/services/ai/class-tc-ai-provider-registry.php
 *                                                  OpenAI / Anthropic / Gemini api_key
 *   - includes/class-tc-rest-api.php               REST get_table settings allowlist
 *                                                  (gt_rest_filter_safe_settings)
 *   - tablecrafter.php admin_post_gt_bundle_export
 *                                                  bulk-migration bundle export
 *                                                  (gt_bundle_strip_secrets)
 *
 * Security audit reference:
 *   https://github.com/TableCrafter/gravity-tables/issues/1076
 *
 * Cipher choices, with rationale:
 *
 *   - AES-256-CBC: matches the existing Airtable PAT pattern; widely
 *     available; well-understood. We don't need authenticated encryption
 *     (AES-GCM) here because the threat model is "DB row read" not
 *     "active attacker mutates ciphertext" - the AUTH_KEY is co-located
 *     with the DB inside wp-config.php, so an attacker capable of
 *     forging ciphertext already has the key. CBC fail-closes on tamper
 *     via the decrypt-error return path.
 *
 *   - Key derivation: sha256(AUTH_KEY + salt), first 32 bytes. The
 *     per-call salt parameter gives us domain separation - cloud
 *     storage tokens and AI api_keys live in different keyspaces, so
 *     a leak of one envelope can't cross-decrypt the other. This is
 *     bit-identical to TC_Airtable_Credential_Service::derive_key().
 *
 *   - Random 16-byte IV per encrypt, prepended to ciphertext. Whole
 *     envelope base64-encoded with a "gt_enc_v1:" sentinel prefix so
 *     callers can distinguish "this option holds an encrypted blob"
 *     from "this option holds a legacy plaintext token". Backward
 *     compat: pre-#1076 customer installations (Katie's loadtracker.ajstrucking.com,
 *     lakeofthewoodspizza.com) already have plaintext OAuth tokens in
 *     wp_options; decrypt() of an unprefixed string returns the input
 *     unchanged so the existing connection keeps working until the
 *     next OAuth refresh, which writes encrypted.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!function_exists('gt_encrypt_secret')) {
    /**
     * Encrypt a plaintext secret using AES-256-CBC with AUTH_KEY-derived key.
     *
     * @param string $plaintext Plaintext to encrypt. Empty string returns ''.
     * @param string $salt      Domain-separation salt. Defaults to 'gt_default'.
     *                          Callers should pass a service-specific salt
     *                          (e.g. 'gt_cloud_tokens', 'gt_ai_keys') so a
     *                          leak of one envelope can't cross-decrypt
     *                          another service's secrets.
     * @return string Sentinel-prefixed base64 envelope, or '' on failure.
     */
    function gt_encrypt_secret(string $plaintext, string $salt = 'gt_default'): string {
        if ($plaintext === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            // Production safety: if openssl is missing we'd rather fail-closed
            // than emit a fake "encrypted" envelope. Return empty so the caller
            // sees the same shape as a tampered envelope and surfaces an error.
            return '';
        }
        // #1645 - emit v2 envelopes keyed by the RAW 256-bit sha256 digest.
        // The v1 key was substr(sha256_hex, 0, 32): 32 chars from a 16-symbol
        // alphabet = 128 bits of effective entropy. v2 uses the raw 32-byte
        // digest for the full 256 bits. Decrypt still accepts v1 (below).
        $key = _gt_derive_secret_key_v2($salt);
        if ($key === '') {
            // #1637 - no secure key available (AUTH_KEY missing). Fail
            // closed rather than emit an envelope under a public key.
            return '';
        }
        $iv  = openssl_random_pseudo_bytes(16);
        // Flag 0 == default: openssl returns a base64-encoded ciphertext
        // string. We prepend the raw IV bytes and base64 the whole envelope.
        // Matches TC_Airtable_Credential_Service::encrypt() bit-for-bit so
        // a v2 envelope produced by either path is decryptable by the other
        // (given matching salt).
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, 0, $iv);
        if ($cipher === false) {
            return '';
        }
        return 'gt_enc_v2:' . base64_encode($iv . $cipher);
    }
}
if (!function_exists('gt_decrypt_secret')) {
    /**
     * Decrypt a sentinel-prefixed envelope produced by gt_encrypt_secret().
     *
     * Backward-compat: if the input does NOT carry the 'gt_enc_v1:' sentinel,
     * it's treated as legacy plaintext and returned unchanged. This lets
     * existing customer installations with plaintext OAuth tokens keep
     * working after upgrade; the next save() rewrites encrypted.
     *
     * Fail-closed: tampered or malformed envelopes return '' (not the
     * legacy-plaintext value) so a caller can distinguish "configured but
     * broken" from "not configured" - empty string means "decrypt failed".
     *
     * @param string $envelope Envelope to decrypt (or legacy plaintext).
     * @param string $salt     Domain-separation salt; must match encrypt() call.
     * @return string Plaintext on success, '' on failure.
     */
    function gt_decrypt_secret(string $envelope, string $salt = 'gt_default'): string {
        if ($envelope === '') {
            return '';
        }
        // #1645 - version-tagged envelopes. v2 = raw 256-bit key; v1 = legacy
        // hex key (still readable so secrets stored before the upgrade keep
        // working and migrate to v2 on the next save). No sentinel == legacy
        // plaintext (pre-#1076 token) - passed through unchanged.
        if (strpos($envelope, 'gt_enc_v2:') === 0) {
            $b64 = substr($envelope, strlen('gt_enc_v2:'));
            $key = _gt_derive_secret_key_v2($salt);
        } elseif (strpos($envelope, 'gt_enc_v1:') === 0) {
            $b64 = substr($envelope, strlen('gt_enc_v1:'));
            $key = _gt_derive_secret_key($salt);
        } else {
            return $envelope; // legacy plaintext
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        if ($key === '') {
            // #1637 - fail closed when no secure key is available.
            return '';
        }
        // Flag 0 mirrors the encrypt path - openssl_decrypt expects the
        // ciphertext to be base64-encoded (which it is, because encrypt()
        // used flag 0 too).
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, 0, $iv);
        return $plain !== false ? $plain : '';
    }
}
if (!function_exists('gt_is_encrypted_secret')) {
    /**
     * True when the input carries a gt_enc_v2: (current) or gt_enc_v1:
     * (legacy) sentinel prefix. Use this to decide whether an option value
     * is already an envelope (no prefix == legacy plaintext == due for
     * encryption on next save). #1645 - also recognises v2.
     */
    function gt_is_encrypted_secret(string $value): bool {
        return $value !== ''
            && (strpos($value, 'gt_enc_v2:') === 0 || strpos($value, 'gt_enc_v1:') === 0);
    }
}
if (!function_exists('_gt_derive_secret_key')) {
    /**
     * 32-byte AES-256 key derived from AUTH_KEY + a service salt.
     * Bit-identical to TC_Airtable_Credential_Service::derive_key() when
     * salt == 'gt_airtable_creds', so the two paths produce
     * interoperable envelopes.
     */
    function _gt_derive_secret_key(string $salt): string {
        // #1637 - fail closed when AUTH_KEY is unavailable. The previous
        // fallback to a hardcoded literal made the encryption key fully
        // derivable from public plugin source on any install whose
        // WordPress salts were missing. Return '' so the encrypt/decrypt
        // callers refuse to operate under a public key.
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            return '';
        }
        // hex output, first 32 hex chars == 16 bytes when treated as binary
        // - but we want 32 RAW bytes for AES-256, so take 32 chars of the
        // sha256 hex and treat them as ASCII (matches the existing Airtable
        // pattern, where derive_key returns substr(hash('sha256', ...), 0, 32)
        // and openssl_* treats the result as a 32-byte string).
        //
        // #1645 - this is the LEGACY v1 key: 32 hex chars = 128 bits of
        // effective entropy. Retained only for decrypting pre-#1645
        // envelopes; new envelopes use _gt_derive_secret_key_v2().
        return substr(hash('sha256', AUTH_KEY . $salt), 0, 32);
    }
}
if (!function_exists('_gt_derive_secret_key_v2')) {
    /**
     * #1645 - full 256-bit AES key: the RAW 32-byte sha256 digest of
     * AUTH_KEY + salt (hash(..., true)). Replaces the v1 hex key, which
     * only carried 128 bits of effective entropy. Bit-identical to
     * TC_Airtable_Credential_Service::derive_key_v2() when
     * salt == 'gt_airtable_creds', so the two paths produce interoperable
     * v2 envelopes.
     */
    function _gt_derive_secret_key_v2(string $salt): string {
        if (!defined('AUTH_KEY') || AUTH_KEY === '') {
            return '';
        }
        return hash('sha256', AUTH_KEY . $salt, true); // 32 raw bytes
    }
}

// ----------------------------------------------------------------------------
// AI provider settings shape helpers (#1076 finding #2)
// ----------------------------------------------------------------------------
if (!function_exists('gt_ai_settings_encrypt')) {
    /**
     * Encrypt the api_key field in a gt_ai_settings dict. Idempotent on
     * already-encrypted input (re-encrypts with a fresh IV, but decrypt
     * still yields the original plaintext). Empty api_key passes through.
     *
     * @param array $settings ['provider' => ..., 'api_key' => ...]
     * @return array Same shape with api_key envelope-encrypted.
     */
    function gt_ai_settings_encrypt(array $settings): array {
        $api_key = isset($settings['api_key']) ? (string) $settings['api_key'] : '';
        if ($api_key === '') {
            return $settings;
        }
        // Always re-encrypt to a fresh envelope: if the caller hands us
        // ciphertext we still want a new IV. Decrypt first so we never
        // double-encrypt.
        $plaintext = gt_is_encrypted_secret($api_key)
            ? gt_decrypt_secret($api_key, 'gt_ai_keys')
            : $api_key;
        if ($plaintext === '') {
            // decrypt failed - leave the existing (broken) envelope rather
            // than wipe the user's settings.
            return $settings;
        }
        $settings['api_key'] = gt_encrypt_secret($plaintext, 'gt_ai_keys');
        return $settings;
    }
}
if (!function_exists('gt_ai_settings_decrypt')) {
    /**
     * Decrypt the api_key field in a gt_ai_settings dict. Legacy plaintext
     * api_keys (pre-#1076 customer state) pass through unchanged.
     *
     * @param array $settings
     * @return array Same shape with api_key as plaintext.
     */
    function gt_ai_settings_decrypt(array $settings): array {
        $api_key = isset($settings['api_key']) ? (string) $settings['api_key'] : '';
        if ($api_key === '') {
            return $settings;
        }
        $settings['api_key'] = gt_decrypt_secret($api_key, 'gt_ai_keys');
        return $settings;
    }
}

// ----------------------------------------------------------------------------
// REST allowlist + migration-export strip (#1076 findings #3 + #4)
// ----------------------------------------------------------------------------
if (!function_exists('gt_rest_filter_safe_settings')) {
    /**
     * Pure allowlist filter for the REST get_table settings payload.
     * Returns ONLY keys that have been explicitly marked safe for external
     * consumption. New / unknown keys are stripped by default (fail-closed).
     *
     * Extension point: features that legitimately need a new settings key
     * to ride out over REST should hook 'gt_rest_settings_safe_keys' and
     * append to the list.
     *
     * Sensitive keys (credentials, webhook URLs, notify emails, internal
     * connection state) are NEVER in the safe list and cannot be added
     * via the filter - feature code that needs to surface those over REST
     * must use a dedicated endpoint with its own permission gate.
     *
     * @param array $settings Decoded settings dict.
     * @return array Allowlisted subset.
     */
    function gt_rest_filter_safe_settings(array $settings): array {
        // Display config: every key here is something a frontend table
        // renderer or headless consumer legitimately needs.
        $safe_defaults = [
            'columns',
            'column_labels',
            'column_aggregations',
            'column_auto_merge',
            'column_detail_only',
            'column_vertical_alignments',
            'cell_vertical_alignments',
            'data_source_type',
            'date_format',
            'default_per_page',
            'desktop_visible',
            'tablet_visible',
            'mobile_visible',
            'drilldown_columns',
            'enable_delete',
            'enable_frontend_editing',
            'enable_pagination',
            'enable_search',
            'enable_sort',
            'enable_vertical_scroll',
            'expiry_behavior',
            'expiry_field_id',
            'expiry_grace_days',
            'expiry_inverse',
            'flip_breakpoint',
            'freeze_first_column',
            'horizontal_scroll',
            'length_selector_options',
            'persist_filters_localstorage',
            'persistent_filters',
            'print_all_rows',
            'print_settings',
            'processing_mode',
            'responsive_mode',
            'responsive_table',
            'row_link_open_new_tab',
            'row_link_template',
            'schema',
            'show_column_totals',
            'show_length_selector',
            'sticky_header',
            'border_preset',
            'css_framework',
            'custom_css',
            'sync_direction',
            'default_sort_column',
            'default_sort_direction',
        ];
        $safe = function_exists('apply_filters')
            ? (array) apply_filters('gt_rest_settings_safe_keys', $safe_defaults)
            : $safe_defaults;
        // Drop any caller-injected key that's in the always-sensitive list,
        // so a careless filter hook can't smuggle a secret out.
        $always_sensitive = [
            'webhook_url',
            'notify_emails',
            'airtable_pat',
            'airtable_credentials',
            'airtable_base_id',
            'airtable_table_id',
            'notion_token',
            'notion_database_id',
            'api_key',
            'cloud_oauth_secret',
            'cloud_source_url', // can embed credentials in query string
        ];
        $safe = array_values(array_diff($safe, $always_sensitive));

        $filtered = [];
        foreach ($safe as $key) {
            if (array_key_exists($key, $settings)) {
                $filtered[$key] = $settings[$key];
            }
        }
        return $filtered;
    }
}
if (!function_exists('gt_bundle_strip_secrets')) {
    /**
     * Strip credential-bearing keys from a table row before it enters the
     * bulk-migration export bundle. Imports on a fresh site are expected
     * to re-enter credentials (audit deliverable, #1076 finding #4).
     *
     * Appends a '_stripped_secret_keys' sentinel listing what was removed
     * so the importer can warn "you need to re-enter credentials for: X, Y".
     *
     * @param array $row Table row with 'settings' (JSON string) and/or
     *                   'settings_decoded' (array).
     * @return array Same row with sensitive settings removed.
     */
    function gt_bundle_strip_secrets(array $row): array {
        if (!isset($row['settings_decoded']) || !is_array($row['settings_decoded'])) {
            return $row;
        }
        $sensitive = [
            'webhook_url',
            'notify_emails',
            'airtable_pat',
            'airtable_credentials',
            'airtable_base_id',
            'airtable_table_id',
            'notion_token',
            'notion_database_id',
            'api_key',
            'cloud_oauth_secret',
            'cloud_source_url',
        ];
        $stripped_keys = [];
        foreach ($sensitive as $k) {
            if (array_key_exists($k, $row['settings_decoded'])) {
                $stripped_keys[] = $k;
                unset($row['settings_decoded'][$k]);
            }
        }
        if (!empty($stripped_keys)) {
            $row['settings_decoded']['_stripped_secret_keys'] = $stripped_keys;
            // Re-serialize the raw 'settings' JSON string too so the
            // ciphertext doesn't leak through that channel.
            if (isset($row['settings']) && is_string($row['settings'])) {
                $row['settings'] = function_exists('wp_json_encode')
                    ? wp_json_encode($row['settings_decoded'])
                    : json_encode($row['settings_decoded']);
            }
        }
        return $row;
    }
}
// @codeCoverageIgnoreEnd
