<?php
/**
 * TC_SEO_Rows_Renderer
 *
 * Issue #551 - opt-in `<noscript>` block emitted after the visible
 * Gravity Tables table that contains a server-rendered HTML `<table>`
 * with all rows. Mirrors a wpDataTables 1-star review pattern: when
 * pagination is JS-driven, only the first per-page rows are present
 * in the rendered HTML the crawler sees, killing long-tail SEO for
 * product / price / spec tables.
 *
 * Crawlers and JS-disabled users see every row in the noscript block.
 * JS-enabled users never see it (browsers skip noscript when JS is
 * enabled). The visible table continues to lazy-load via AJAX as
 * before.
 *
 * Opt-in via filter, not a UI setting in this slice - power users /
 * SEO-conscious site owners enable it with a one-line `add_filter`
 * call. A future slice can add a per-table UI toggle.
 *
 *   add_filter('gt_seo_emit_all_rows', '__return_true');
 *
 * Or per-table:
 *
 *   add_filter('gt_seo_emit_all_rows', function ($enabled, $table_id) {
 *       return $table_id === 7;
 *   }, 10, 2);
 *
 * Default `false` so existing tables don't suddenly grow ~50KB of
 * extra HTML on every page request without owner consent.
 *
 * @since 4.7.21
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_SEO_Rows_Renderer {

    /**
     * Whether SEO row dumping is enabled for this table.
     */
    public static function is_enabled(int $table_id): bool {
        return (bool) apply_filters('gt_seo_emit_all_rows', false, $table_id);
    }

    /**
     * Render a `<noscript>` block containing a server-rendered HTML
     * `<table>` with up to `$max_rows` entries from the underlying
     * Gravity Form. Returns empty string when there are no entries.
     */
    public static function render_seo_block(int $form_id, array $columns, int $max_rows = 1000): string {
        if (!class_exists('GFAPI')) {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        if (empty($columns)) {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        $paging = ['page_size' => max(1, $max_rows)];
        $entries = GFAPI::get_entries($form_id, [], null, $paging);
        if (!is_array($entries) || count($entries) === 0) {
            return '';
        }
        $entries = array_slice($entries, 0, $max_rows);

        $headers = '';
        foreach ($columns as $col) {
            $label = isset($col['label']) ? (string) $col['label'] : (string) ($col['id'] ?? '');
            $headers .= '<th>' . esc_html($label) . '</th>';
        }

        $body = '';
        foreach ($entries as $entry) {
            $body .= '<tr>';
            foreach ($columns as $col) {
                $field_id = (string) ($col['id'] ?? '');
                $type     = (string) ($col['type'] ?? 'text');
                $value    = isset($entry[$field_id]) ? $entry[$field_id] : '';
                $rendered = class_exists('TC_Cell_Renderer')
                    ? TC_Cell_Renderer::render($value, $type, ['name' => isset($col['label']) ? (string) $col['label'] : ''])
                    // @codeCoverageIgnoreStart
                    : esc_html((string) $value);
                    // @codeCoverageIgnoreEnd
                $body .= '<td>' . $rendered . '</td>';
            }
            $body .= '</tr>';
        }

        return '<noscript><table class="gt-seo-rows" hidden aria-hidden="true">'
            . '<thead><tr>' . $headers . '</tr></thead>'
            . '<tbody>' . $body . '</tbody>'
            . '</table></noscript>';
    }
}
