<?php
/**
 * Third-party CDN opt-out gate - issues #600 slice 1 + slice 2.
 *
 * EU site owners need explicit ways to disable or replace every external
 * CDN connection the plugin would otherwise make (Google Fonts, Leaflet
 * from unpkg, etc.) for GDPR / Schrems II compliance.
 *
 * Slice 1 - global opt-out:
 *   add_filter('gt_disable_third_party_cdn', '__return_true');
 *
 * Slice 2 - per-source opt-out (overrides nothing on its own; the global
 * filter still wins as a kill-switch):
 *   add_filter('gt_disable_third_party_cdn_leaflet',      '__return_true');
 *   add_filter('gt_disable_third_party_cdn_google_fonts', '__return_true');
 *
 * Slice 2 - self-host hooks: every external base URL is filterable so
 * site owners can route each enqueue through their own mirror without
 * disabling the feature outright. See gt_leaflet_url_base and
 * gt_google_fonts_url_base in their respective call sites.
 *
 * @since 4.7.68
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
if (!function_exists('gt_third_party_cdn_disabled')) {
    /**
     * True iff the site owner has opted out of every third-party CDN
     * connection. Code paths that would otherwise enqueue a CDN URL
     * MUST consult this helper (or the per-source variant below)
     * before doing so.
     *
     * @return bool
     */
    function gt_third_party_cdn_disabled(): bool {
        return (bool) apply_filters('gt_disable_third_party_cdn', false);
    }
}

if (!function_exists('gt_third_party_cdn_source_disabled')) {
    /**
     * True iff the named source has been opted out, either by the
     * global kill-switch (`gt_disable_third_party_cdn`) or by a
     * per-source filter (`gt_disable_third_party_cdn_<source>`).
     *
     * Per-source filter names are documented at the call sites:
     *   - 'leaflet' - includes/class-tc-map.php
     *   - 'google_fonts' - includes/services/class-tc-typography-service.php
     *
     * The global kill-switch is checked first so site owners can flip
     * one switch and disable everything; per-source filters layer on
     * top for fine-grained control.
     *
     * @param string $source Identifier - '/^[a-z0-9_]+$/i' recommended.
     * @return bool
     */
    function gt_third_party_cdn_source_disabled(string $source): bool {
        if (gt_third_party_cdn_disabled()) {
            return true;
        }
        $filter = 'gt_disable_third_party_cdn_' . $source;
        return (bool) apply_filters($filter, false);
    }
}
