<?php
/**
 * TC_Post_Content_Field_Renderer
 *
 * Issue #810 (child of #793). GF `post_content` fields store HTML
 * markup (a post body). The default cell render escapes the HTML
 * so tags appear as literal text — not useful.
 *
 * Strategy:
 *   - Cell render: strip HTML and truncate to N characters with
 *     trailing ellipsis. Default N = 100, filter
 *     `gt_post_content_preview_length` overrides.
 *   - Eye popup: render full HTML via `wp_kses_post` (allows safe
 *     HTML, blocks scripts).
 *
 * @since 4.81.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Post_Content_Field_Renderer {

    /**
     * Strip HTML + truncate. Default length 100; filter
     * `gt_post_content_preview_length` overrides.
     */
    public static function render_preview($value, ?int $max_length = null): string {
        if (is_array($value)) {
            $value = implode(' ', array_map('strval', array_filter($value, 'is_scalar')));
        }
        if (!is_string($value) || $value === '') {
            return '';
        }
        $stripped = function_exists('wp_strip_all_tags')
            ? wp_strip_all_tags($value)
            : trim(strip_tags($value));
        $stripped = trim(preg_replace('/\s+/', ' ', (string) $stripped));
        if ($stripped === '') {
            return '';
        }
        $len = $max_length;
        if ($len === null) {
            $len = 100;
            if (function_exists('apply_filters')) {
                $filtered = apply_filters('gt_post_content_preview_length', $len);
                if (is_numeric($filtered) && (int) $filtered > 0) {
                    $len = (int) $filtered;
                }
            }
        }
        // Multibyte-safe substring + length comparison.
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($stripped, 'UTF-8') > $len) {
                return mb_substr($stripped, 0, $len, 'UTF-8') . '…';
            }
            return $stripped;
        }
        // @codeCoverageIgnoreStart
        if (strlen($stripped) > $len) {
            return substr($stripped, 0, $len) . '…';
        // @codeCoverageIgnoreEnd
        }
        // @codeCoverageIgnoreStart
        return $stripped;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Full HTML render for eye popup. Uses `wp_kses_post` (allows
     * post-safe HTML, strips scripts / dangerous attrs). Fallback
     * to `strip_tags` in standalone test envs.
     */
    public static function render_full_html($value): string {
        if (is_array($value)) {
            $value = implode(' ', array_map('strval', array_filter($value, 'is_scalar')));
        }
        if (!is_string($value) || $value === '') {
            return '';
        }
        if (function_exists('wp_kses_post')) {
            return wp_kses_post($value);
        }
        // Fallback (test env) — strip every tag rather than risk
        // emitting unsafe markup.
        return strip_tags($value);
    }

    /**
     * Searchable plain-text shape — strip + collapse whitespace,
     * no truncation. For filter / sort substring matching.
     */
    public static function searchable_text($value): string {
        if (is_array($value)) {
            $value = implode(' ', array_map('strval', array_filter($value, 'is_scalar')));
        }
        if (!is_string($value) || $value === '') {
            return '';
        }
        $stripped = function_exists('wp_strip_all_tags')
            ? wp_strip_all_tags($value)
            : trim(strip_tags($value));
        return trim(preg_replace('/\s+/', ' ', (string) $stripped));
    }
}
