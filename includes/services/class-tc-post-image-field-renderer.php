<?php
/**
 * TC_Post_Image_Field_Renderer
 *
 * Issue #808 (child of #793). GF `post_image` fields are composite:
 *   N.1 = URL, N.2 = title, N.3 = caption, N.4 = description, N.5 = alt
 *
 * Without this renderer, the cell shows the bare slot $entry[N]
 * (typically empty for composites) and the eye popup uses the
 * generic multi-input scanner that emits a flat comma-joined blob.
 *
 * Render strategies:
 *   - Cell: `<img src=N.1 alt=N.5 title=N.2>` with default max-height
 *     (filter `gt_post_image_max_height` overrides). Falls back to
 *     N.1 alone if both alt + title are missing.
 *   - Eye popup: full-size image + meta lines (title, caption,
 *     description). HTML-escaped.
 *
 * @since 4.79.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Post_Image_Field_Renderer {

    /**
     * Pull the five sub-input values into a labelled map. Missing /
     * empty sub-inputs are simply absent from the map.
     *
     * @return array{url?:string,title?:string,caption?:string,description?:string,alt?:string}
     */
    public static function sub_input_values(array $entry, string $field_id): array {
        $keys = ['url', 'title', 'caption', 'description', 'alt'];
        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $raw = isset($entry[$field_id . '.' . $i]) ? trim((string) $entry[$field_id . '.' . $i]) : '';
            if ($raw !== '') {
                $out[$keys[$i - 1]] = $raw;
            }
        }
        return $out;
    }

    /**
     * HTML for the cell render: `<img>` with safe attributes. Default
     * max-height 40px (filter `gt_post_image_max_height` overrides).
     * Returns '' when there's no URL.
     */
    public static function render_html(array $entry, string $field_id): string {
        $vals = self::sub_input_values($entry, $field_id);
        // isset (not empty) — sub_input_values() already strips blank
        // values, and empty('0') would drop a literal '0' URL. (#1603)
        if (!isset($vals['url'])) {
            return '';
        }
        $max_height = 40;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('gt_post_image_max_height', $max_height);
            if (is_numeric($filtered) && (int) $filtered > 0) {
                $max_height = (int) $filtered;
            }
        }
        $esc_url  = function_exists('esc_url')  ? esc_url($vals['url'])  : $vals['url'];
        $esc_attr = function (string $s): string {
            return function_exists('esc_attr') ? esc_attr($s) : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };
        $alt   = $esc_attr($vals['alt']   ?? '');
        $title = $esc_attr($vals['title'] ?? '');
        return sprintf(
            '<img src="%s" alt="%s" title="%s" class="gt-cell-post-image" style="max-height:%dpx;" />',
            $esc_url,
            $alt,
            $title,
            $max_height
        );
    }

    /**
     * Text shape for eye popup: each non-empty sub-input on its own
     * line with a label prefix. Used in contexts where HTML isn't
     * appropriate (CSV / eye-popup text mode).
     */
    public static function render_text(array $entry, string $field_id): string {
        $vals = self::sub_input_values($entry, $field_id);
        if (empty($vals)) {
            return '';
        }
        $labels = [
            'url'         => 'URL',
            'title'       => 'Title',
            'caption'     => 'Caption',
            'description' => 'Description',
            'alt'         => 'Alt text',
        ];
        $lines = [];
        foreach ($labels as $key => $label) {
            if (isset($vals[$key]) && $vals[$key] !== '') {
                $lines[] = $label . ': ' . $vals[$key];
            }
        }
        return implode("\n", $lines);
    }
}
