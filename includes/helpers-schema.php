<?php
// @codeCoverageIgnoreStart
/**
 * Schema.org JSON-LD render helper - slice 2 of issue #547.
 *
 * The TC_Schema_Service builder lives in services/. This wrapper turns its
 * payload into the actual `<script type="application/ld+json">` tag that
 * gets emitted alongside each table by templates/table.php.
 *
 * @since 4.7.66
 */
if (!defined('ABSPATH')) { exit; }
if (!class_exists('TC_Schema_Service')) {
    require_once __DIR__ . '/services/class-tc-schema-service.php';
}

if (!function_exists('gt_render_schema_jsonld')) {
    /**
     * Build the schema.org JSON-LD payload for a table and wrap it in a
     * `<script type="application/ld+json">…</script>` tag. Returns an empty
     * string when schema is disabled or the type isn't supported yet.
     *
     * Escapes via wp_json_encode + JSON_UNESCAPED_SLASHES off + JSON_HEX_TAG
     * so a stored title containing `</script>` cannot break out of the
     * script element.
     *
     * @param array $table    Minimal table descriptor: ['title' => string].
     * @param array $settings Per-table schema settings; see TC_Schema_Service.
     * @return string HTML <script> tag, or empty string.
     */
    function gt_render_schema_jsonld(array $table, array $settings): string {
        $payload = TC_Schema_Service::build_jsonld($table, $settings);
        if ($payload === null || empty($payload)) {
            return '';
        }
        // JSON_HEX_TAG escapes `<` and `>` so a malicious title can't smuggle in
        // a `</script>` and break out of the wrapping tag.
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload, $flags)
            : json_encode($payload, $flags);
        if ($json === false) {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
// @codeCoverageIgnoreEnd
