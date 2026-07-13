<?php
/**
 * TC_Row_Link_Service
 *
 * Issue #567 - slice 1 of 3. Pure URL-builder helper for the future
 * clickable-row feature. Defines the per-table row-link config
 * schema, expands `{column_name}` placeholders against a row, and
 * validates the result against a scheme whitelist (XSS hardening).
 *
 * Slice 2 ships the admin "Row link source" dropdown / template
 * input + open-in-new-tab toggle + AJAX save sanitization. Slice 3
 * wraps the row content in a real `<a>` (or `role="link"` with
 * keyboard handling), changes the cursor on hover, makes the row
 * focusable, and delegates clicks correctly so inline interactive
 * elements (buttons / links / inline edit / action buttons) keep
 * working - only blank cell areas trigger row navigation. Cell-level
 * hyperlinks (#532, #362) win when both exist.
 *
 * @since 4.7.52
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Row_Link_Service {

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];
    private const TRUTHY_STRINGS = ['1', 'true', 'on', 'yes'];
    private const UNSAFE_SCHEMES = ['javascript', 'data', 'vbscript', 'file'];

    public static function defaults(): array {
        return [
            'row_link_template'     => '',
            'row_link_open_new_tab' => false,
        ];
    }

    public static function normalize(array $settings): array {
        $out = self::defaults();

        if (array_key_exists('row_link_template', $settings)) {
            $raw = $settings['row_link_template'];
            $out['row_link_template'] = is_string($raw) ? trim($raw) : '';
        }

        if (array_key_exists('row_link_open_new_tab', $settings)) {
            $raw = $settings['row_link_open_new_tab'];
            if ($raw === true || $raw === 1) {
                $out['row_link_open_new_tab'] = true;
            } elseif (is_string($raw) && in_array(strtolower($raw), self::TRUTHY_STRINGS, true)) {
                $out['row_link_open_new_tab'] = true;
            } else {
                $out['row_link_open_new_tab'] = false;
            }
        }

        return $out;
    }

    public static function is_enabled(array $settings): bool {
        $tpl = $settings['row_link_template'] ?? '';
        if (!is_string($tpl)) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        return trim($tpl) !== '';
    }

    /**
     * Expand `{column_name}` placeholders against $row. Whole-cell
     * shortcut (template === `{col}`) returns the cell's value
     * verbatim if it passes `is_safe_url()` - preserves existing
     * fully-formed URLs without double-encoding. Other templates
     * URL-encode placeholder values before splicing.
     *
     * Returns null when:
     *   - template is empty
     *   - any referenced column is missing from $row
     *   - the resulting URL fails `is_safe_url()`
     */
    public static function build_url(array $row, string $template): ?string {
        $template = trim($template);
        if ($template === '') {
            return null;
        }

        // Whole-cell shortcut: template is exactly {col}
        if (preg_match('/^\{([a-zA-Z0-9_\-]+)\}$/', $template, $m)) {
            $col = $m[1];
            if (!array_key_exists($col, $row)) {
                // @codeCoverageIgnoreStart
                return null;
                // @codeCoverageIgnoreEnd
            }
            $value = $row[$col];
            if (!is_string($value) || !self::is_safe_url($value)) {
                return null;
            }
            return $value;
        }

        $missing = false;
        $url = preg_replace_callback(
            '/\{([a-zA-Z0-9_\-]+)\}/',
            function ($m) use ($row, &$missing) {
                $col = $m[1];
                if (!array_key_exists($col, $row)) {
                    $missing = true;
                    return '';
                }
                $v = $row[$col];
                if ($v === null) {
                    // @codeCoverageIgnoreStart
                    return '';
                    // @codeCoverageIgnoreEnd
                }
                if (!is_string($v) && !is_int($v) && !is_float($v) && !is_bool($v)) {
                    // @codeCoverageIgnoreStart
                    return '';
                    // @codeCoverageIgnoreEnd
                }
                return rawurlencode((string) $v);
            },
            $template
        );

        if ($missing) {
            return null;
        }

        if (!is_string($url) || !self::is_safe_url($url)) {
            return null;
        }
        return $url;
    }

    /**
     * Whitelist-based URL safety check. Allows http / https / mailto
     * / tel and site-relative URLs (starting with `/`). Everything
     * else - including dangerous schemes (javascript, data, vbscript,
     * file), unknown schemes, and bare relative strings - is
     * rejected.
     */
    public static function is_safe_url($url): bool {
        if (!is_string($url)) {
            return false;
        }
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        // Site-relative paths. A second leading slash would make the
        // URL protocol-relative (`//host/...`), which browsers resolve
        // to an external origin - reject that shape. (#1604)
        if ($trimmed[0] === '/') {
            return !isset($trimmed[1]) || $trimmed[1] !== '/';
        }

        // Lowercased scheme prefix check.
        $lower = strtolower($trimmed);
        foreach (self::UNSAFE_SCHEMES as $bad) {
            if (strpos($lower, $bad . ':') === 0) {
                return false;
            }
        }

        // Match known good schemes only.
        foreach (self::ALLOWED_SCHEMES as $ok) {
            if (strpos($lower, $ok . ':') === 0) {
                return true;
            }
        }

        return false;
    }
}
