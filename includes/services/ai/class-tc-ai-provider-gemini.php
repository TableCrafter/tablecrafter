<?php
/**
 * Gemini provider stub.
 *
 * @since 4.6.9
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_AI_Provider_Gemini implements TC_AI_Provider {

    private string $api_key;

    public function __construct(string $api_key = '') {
        $this->api_key = trim($api_key);
    }

    // @codeCoverageIgnoreStart
    public function is_configured(): bool {
        return $this->api_key !== '';
    // @codeCoverageIgnoreEnd
    }

    public function provider_id(): string {
        return 'gemini';
    }

    // @codeCoverageIgnoreStart
    public function label(): string {
        return 'Google Gemini';
    // @codeCoverageIgnoreEnd
    }

    public function complete(string $prompt, array $options = []) {
        return new WP_Error(
            'gt_ai_provider_not_wired',
            'Gemini provider is not yet wired to a live endpoint.'
        );
    }
}
