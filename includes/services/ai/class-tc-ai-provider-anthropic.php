<?php
/**
 * Anthropic provider stub.
 *
 * @since 4.6.9
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_AI_Provider_Anthropic implements TC_AI_Provider {

    private string $api_key;

    public function __construct(string $api_key = '') {
        $this->api_key = trim($api_key);
    }

    public function is_configured(): bool {
        return $this->api_key !== '';
    }

    public function provider_id(): string {
        return 'anthropic';
    }

    // @codeCoverageIgnoreStart
    public function label(): string {
        return 'Anthropic';
    // @codeCoverageIgnoreEnd
    }

    public function complete(string $prompt, array $options = []) {
        return new WP_Error(
            'gt_ai_provider_not_wired',
            'Anthropic provider is not yet wired to a live endpoint.'
        );
    }
}
