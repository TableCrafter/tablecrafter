<?php
/**
 * TC_Wrap_Mode_Service
 *
 * Per-column word-break / hyphenation control (#520).
 * Provides sanitization and CSS generation for the four supported wrap modes:
 *   - default     : current behavior (wrap on whitespace only)
 *   - break-word  : break inside long unbroken tokens (URLs, IDs, hashes)
 *   - hyphenate   : CSS hyphens:auto with a lang attribute on the cell
 *   - nowrap      : single line + ellipsis on overflow
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    // Allow standalone test loading without WordPress bootstrapped.
    if (!defined('TC_WRAP_MODE_SERVICE_STANDALONE')) {
        define('TC_WRAP_MODE_SERVICE_STANDALONE', true);
    }
// @codeCoverageIgnoreEnd
}

class TC_Wrap_Mode_Service {

    const MODES = ['default', 'break-word', 'hyphenate', 'nowrap'];

    /**
     * @return string[] Allowed wrap modes.
     */
    public static function get_modes(): array {
        return self::MODES;
    }

    /**
     * Sanitize a single value to one of the allowed modes.
     * Falls back to 'default' for unknown / empty input.
     */
    public static function sanitize($value): string {
        if (!is_string($value)) {
            return 'default';
        }
        $value = strtolower(trim($value));
        if (in_array($value, self::MODES, true)) {
            return $value;
        }
        return 'default';
    }

    /**
     * Sanitize an associative map of {column_id => mode}.
     */
    public static function sanitize_map($map): array {
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $column_id => $mode) {
            $sanitized_mode = self::sanitize($mode);
            // Only persist non-default values to keep stored settings minimal.
            if ($sanitized_mode !== 'default') {
                $out[(string) $column_id] = $sanitized_mode;
            }
        }
        return $out;
    }

    /**
     * Return CSS declarations (no surrounding braces) for a given mode.
     * Returns an empty string for the 'default' mode.
     */
    public static function css_for_mode(string $mode): string {
        switch ($mode) {
            case 'break-word':
                return 'overflow-wrap:anywhere;word-break:break-word';
            case 'hyphenate':
                return 'overflow-wrap:break-word;word-break:break-word;hyphens:auto;-webkit-hyphens:auto;-ms-hyphens:auto';
            case 'nowrap':
                return 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
            case 'default':
            default:
                return '';
        }
    }

    /**
     * Return the BCP-47 lang code that should be attached to cells using
     * `hyphenate` mode. Defaults to the WordPress site locale (or 'en' when
     * called outside of WordPress, e.g. in unit tests).
     */
    public static function lang_for_mode(string $mode, ?string $explicit_lang = null): string {
        if ($mode !== 'hyphenate') {
            return '';
        }
        if (is_string($explicit_lang) && $explicit_lang !== '') {
            return $explicit_lang;
        }
        if (function_exists('get_bloginfo')) {
            $locale = get_bloginfo('language');
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }
        return 'en';
    }
}
