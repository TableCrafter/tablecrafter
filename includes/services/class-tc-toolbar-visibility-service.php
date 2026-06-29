<?php
/**
 * TC_Toolbar_Visibility_Service
 *
 * Issue #521 — slice 1 of 3. Pure helper for granular per-component
 * toolbar visibility. Today the toolbar is largely all-or-nothing;
 * this service defines the canonical six-component list and a
 * normalization predicate so the future admin UI and template
 * render path can bind to a single contract.
 *
 * Components:
 *   global_search    — global filter input
 *   pagination       — page number controls + prev/next
 *   length_selector  — "Show N entries" dropdown
 *   info_label       — "Showing X to Y of Z entries" text
 *   column_filters   — per-column filter row
 *   export_buttons   — toolbar export-button block
 *
 * Defaults: every component visible (backward-compat — existing
 * installs that don't have these settings yet keep their pre-#521
 * appearance).
 *
 * `is_visible()` returns true for unknown components — defensive
 * default. Better to over-show than silently hide.
 *
 * Slice 2 ships the admin settings UI; slice 3 wires the template
 * render path AND ensures the underlying behaviour (sort / filter /
 * pagination) still functions via shortcode attrs / URL params even
 * when the visible UI is hidden.
 *
 * @since 4.7.38
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Toolbar_Visibility_Service {

    /**
     * Canonical ordered list of toolbar component ids.
     */
    public static function components(): array {
        return [
            'global_search',
            'pagination',
            'length_selector',
            'info_label',
            'column_filters',
            'export_buttons',
        ];
    }

    /**
     * Default visibility map. Every component visible — backward-compat
     * with installs that didn't have these settings before #521.
     */
    public static function defaults(): array {
        $out = [];
        foreach (self::components() as $c) {
            $out[$c] = true;
        }
        return $out;
    }

    /**
     * Normalize a (possibly-partial) settings map.
     *
     *   - Missing keys → defaults
     *   - Unknown keys → dropped
     *   - Values coerced to bool
     */
    public static function normalize(array $settings): array {
        $out = self::defaults();
        foreach (self::components() as $c) {
            if (array_key_exists($c, $settings)) {
                $out[$c] = (bool) $settings[$c];
            }
        }
        return $out;
    }

    /**
     * True iff the given component should render. Unknown components
     * default to true (defensive — prevents typos from silently
     * hiding parts of the UI).
     */
    public static function is_visible(string $component, array $settings): bool {
        if (!self::is_known_component($component)) {
            return true;
        }
        // Missing key → fall back to the documented default (visible).
        // Without this guard, the template's `is_visible($c, $table_config['toolbar_visibility'] ?? [])`
        // call returns false for every component on legacy installs that
        // never saved the setting, silently hiding pagination, search,
        // length selector, info label, and column filters. Surfaced by
        // Katie's pagination report on loadtracker.ajstrucking.com.
        if (!array_key_exists($component, $settings)) {
            return true;
        }
        return (bool) $settings[$component];
    }

    public static function is_known_component(string $component): bool {
        return in_array($component, self::components(), true);
    }
}
