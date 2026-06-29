<?php
/**
 * TC_AI_Provider — single seam every AI feature talks to.
 *
 * Implementations wrap a third-party LLM vendor (OpenAI, Anthropic, Gemini, ...).
 * Feature code never imports a vendor SDK directly; it resolves a provider via
 * TC_AI_Provider_Registry::active() and calls complete().
 *
 * @since 4.6.9
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
interface TC_AI_Provider {

    /**
     * True when the provider has everything it needs to make a call (typically an API key).
     */
    public function is_configured(): bool;

    /**
     * Stable slug used in the gt_ai_settings option and in the settings dropdown.
     */
    public function provider_id(): string;

    /**
     * Human-readable name for the settings dropdown.
     */
    public function label(): string;

    /**
     * Send a prompt to the provider and return the completion as a string,
     * or a WP_Error on failure / when not yet wired.
     *
     * @param string $prompt
     * @param array  $options Optional per-call knobs (max_tokens, temperature, model_override, ...).
     * @return string|WP_Error
     */
    public function complete(string $prompt, array $options = []);
}
