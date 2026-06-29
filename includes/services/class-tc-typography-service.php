<?php
/**
 * Per-table typography CSS generation for Gravity Tables (#84)
 *
 * Generates scoped CSS from a typography config array and optionally enqueues
 * a Google Fonts stylesheet when a non-system font family is selected.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Typography_Service {

    /**
     * System font stacks that do NOT need a Google Fonts enqueue.
     */
    private const SYSTEM_FONTS = [
        '',
        'inherit',
        'system-ui',
        '-apple-system',
        'sans-serif',
        'serif',
        'monospace',
        'Arial',
        'Georgia',
        'Verdana',
        'Tahoma',
        'Times New Roman',
        'Courier New',
    ];

    /**
     * Generate scoped CSS for a table instance from a typography config array.
     *
     * The CSS is scoped to $table_selector (e.g. "#gt-table-abc123") so styles
     * do not bleed into other tables or surrounding page content.
     *
     * @param string $table_selector CSS selector for the table wrapper (e.g. "#gt-table-abc123").
     * @param array  $typography     Typography config; supported keys:
     *                               font_family, header_font_size, header_font_weight,
     *                               header_color, body_font_size, body_font_weight,
     *                               body_color, line_height.
     * @return string Generated CSS block, or empty string if $typography is empty.
     */
    public static function generate_css(string $table_selector, array $typography): string {
        if (empty($typography) || empty($table_selector)) {
            return '';
        }

        $selector = esc_attr($table_selector);
        $css      = '';

        // Wrapper-level: font-family and line-height apply to both header and body
        $wrapper_props = [];

        if (!empty($typography['font_family'])) {
            $wrapper_props[] = 'font-family: ' . self::sanitize_font_family($typography['font_family']);
        }
        if (!empty($typography['line_height'])) {
            $wrapper_props[] = 'line-height: ' . self::sanitize_dimension($typography['line_height']);
        }

        if ($wrapper_props) {
            $css .= $selector . ' { ' . implode('; ', $wrapper_props) . '; }' . "\n";
        }

        // Header (th) styles
        $header_props = [];

        if (!empty($typography['header_font_size'])) {
            $header_props[] = 'font-size: ' . self::sanitize_dimension($typography['header_font_size']);
        }
        if (!empty($typography['header_font_weight'])) {
            $header_props[] = 'font-weight: ' . self::sanitize_font_weight($typography['header_font_weight']);
        }
        if (!empty($typography['header_color'])) {
            $header_props[] = 'color: ' . self::sanitize_color($typography['header_color']);
        }

        if ($header_props) {
            $css .= $selector . ' th { ' . implode('; ', $header_props) . '; }' . "\n";
        }

        // Body (td) styles
        $body_props = [];

        if (!empty($typography['body_font_size'])) {
            $body_props[] = 'font-size: ' . self::sanitize_dimension($typography['body_font_size']);
        }
        if (!empty($typography['body_font_weight'])) {
            $body_props[] = 'font-weight: ' . self::sanitize_font_weight($typography['body_font_weight']);
        }
        if (!empty($typography['body_color'])) {
            $body_props[] = 'color: ' . self::sanitize_color($typography['body_color']);
        }

        if ($body_props) {
            $css .= $selector . ' td { ' . implode('; ', $body_props) . '; }' . "\n";
        }

        return $css;
    }

    /**
     * Enqueue a Google Fonts stylesheet for $font_family if it is not a system font.
     *
     * Skips enqueue silently when the font is a system/generic font or when
     * wp_enqueue_style() is not available (e.g. CLI context).
     *
     * @param string $font_family The CSS font-family value chosen by the user.
     */
    public static function maybe_enqueue_google_font(string $font_family): void {
        if (empty($font_family) || in_array($font_family, self::SYSTEM_FONTS, true)) {
            return;
        }

        if (!function_exists('wp_enqueue_style')) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $handle = 'gt-font-' . sanitize_title($font_family);
        if (wp_style_is($handle, 'enqueued')) {
            return;
        }

        // #600 slice 2: per-source CDN opt-out + self-host hook.
        //
        // Disable Google Fonts outright:
        //   add_filter('gt_disable_third_party_cdn',              '__return_true'); // global kill-switch
        //   add_filter('gt_disable_third_party_cdn_google_fonts', '__return_true'); // google_fonts only
        // When disabled, the configured font name still applies — browsers
        // fall back to the closest local typeface match.
        //
        // Self-host or use a privacy-respecting mirror by replacing the
        // base URL — e.g. point at the Bunny.net Google Fonts mirror:
        //   add_filter('gt_google_fonts_url_base', fn() => 'https://fonts.bunny.net/css2');
        if (!function_exists('gt_third_party_cdn_source_disabled')) {
            // @codeCoverageIgnoreStart
            require_once __DIR__ . '/../helpers-cdn.php';
            // @codeCoverageIgnoreEnd
        }
        if (gt_third_party_cdn_source_disabled('google_fonts')) {
            return;
        }

        $query = http_build_query([
            'family'  => str_replace(' ', '+', $font_family) . ':wght@400;600;700',
            'display' => 'swap',
        ]);

        $base = (string) apply_filters('gt_google_fonts_url_base', 'https://fonts.googleapis.com/css2');
        $base = rtrim($base, '?');
        $sep  = strpos($base, '?') === false ? '?' : '&';

        wp_enqueue_style(
            $handle,
            $base . $sep . $query,
            [],
            null
        );
    }

    // -------------------------------------------------------------------------
    // Default typography
    // -------------------------------------------------------------------------

    /**
     * Return the default typography settings array.
     *
     * Used to reset a table's typography back to theme defaults.
     *
     * @return array Default typography configuration.
     */
    public static function reset_to_defaults(): array {
        return [
            'font_family'        => '',
            'header_font_size'   => '',
            'header_font_weight' => '',
            'header_color'       => '',
            'body_font_size'     => '',
            'body_font_weight'   => '',
            'body_color'         => '',
            'line_height'        => '',
        ];
    }

    // -------------------------------------------------------------------------
    // Sanitization helpers
    // -------------------------------------------------------------------------

    private static function sanitize_font_family(string $value): string {
        // Allow letters, digits, spaces, commas, single quotes, hyphens, underscores
        return preg_replace('/[^a-zA-Z0-9 ,\'"\\-_]/', '', $value);
    }

    private static function sanitize_dimension(string $value): string {
        // Allow numbers, dots, and valid CSS units
        return preg_replace('/[^0-9.a-z%]/', '', strtolower($value));
    }

    private static function sanitize_font_weight(string $value): string {
        return preg_replace('/[^0-9a-z]/', '', strtolower($value));
    }

    private static function sanitize_color(string $value): string {
        // Allow hex colours, rgb/rgba/hsl values, and named colours
        return preg_replace('/[^a-zA-Z0-9#(),. %]/', '', $value);
    }
}
