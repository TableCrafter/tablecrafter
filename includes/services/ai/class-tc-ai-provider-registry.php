<?php
/**
 * Resolves the active AI provider from saved settings.
 *
 * Feature code calls TC_AI_Provider_Registry::active(). It returns null
 * (never throws) when:
 *   - the site is on the free plan,
 *   - no provider is configured,
 *   - the saved provider id is unknown,
 *   - the saved api_key is empty.
 *
 * @since 4.6.9
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_AI_Provider_Registry {

    /**
     * Map of provider_id => class name.
     */
    public static function available_providers(): array {
        return [
            'openai'    => 'TC_AI_Provider_OpenAI',
            'anthropic' => 'TC_AI_Provider_Anthropic',
            'gemini'    => 'TC_AI_Provider_Gemini',
        ];
    }

    /**
     * @return TC_AI_Provider|null
     */
    public static function active() {
        if (function_exists('gt_is_premium') && !gt_is_premium()) {
            return null;
        }

        $settings = get_option('gt_ai_settings', []);
        if (!is_array($settings) || empty($settings['provider']) || empty($settings['api_key'])) {
            return null;
        }

        // #1076 finding #2 — decrypt the api_key envelope before handing it
        // to the provider constructor. gt_ai_settings_decrypt() is a no-op
        // for legacy plaintext (backward-compat) and returns '' on tampered
        // envelopes; we fall through to the empty-key short-circuit below.
        if (function_exists('gt_ai_settings_decrypt')) {
            $settings = gt_ai_settings_decrypt($settings);
        }
        if (empty($settings['api_key'])) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        $providers = self::available_providers();
        $provider_id = (string) $settings['provider'];
        if (!isset($providers[$provider_id])) {
            return null;
        }

        $class = $providers[$provider_id];
        if (!class_exists($class)) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        $provider = new $class((string) $settings['api_key']);
        return $provider instanceof TC_AI_Provider ? $provider : null;
    }
}
