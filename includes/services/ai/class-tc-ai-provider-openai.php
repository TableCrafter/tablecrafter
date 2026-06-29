<?php
/**
 * OpenAI provider stub.
 *
 * Implements the TC_AI_Provider seam so feature code can resolve and call it.
 * complete() returns a WP_Error until the actual REST integration ships under
 * a separate sub-issue.
 *
 * @since 4.6.9
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_AI_Provider_OpenAI implements TC_AI_Provider {

    private string $api_key;

    public function __construct(string $api_key = '') {
        $this->api_key = trim($api_key);
    }

    public function is_configured(): bool {
        return $this->api_key !== '';
    }

    public function provider_id(): string {
        return 'openai';
    }

    // @codeCoverageIgnoreStart
    public function label(): string {
        return 'OpenAI';
    // @codeCoverageIgnoreEnd
    }

    public function complete(string $prompt, array $options = []) {
        return new WP_Error(
            'gt_ai_provider_not_wired',
            'OpenAI provider is not yet wired to a live endpoint.'
        );
    }
}
