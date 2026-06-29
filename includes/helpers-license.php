<?php
/**
 * Tiny license-helper functions, extracted from tablecrafter.php so
 * unit tests can require them in isolation without booting the whole
 * plugin (#481).
 *
 * @package GravityTables
 * @since 4.6.4
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
// @codeCoverageIgnoreStart
if (!function_exists('gt_is_premium')) {
// @codeCoverageIgnoreEnd
    /**
     * True when the site has any premium / paying / trial Freemius state.
     * Null-safe — returns false (never warns or fatals) when wgt_fs() is
     * not loaded yet (e.g. during a plugin-deactivation cron sweep).
     */
    function gt_is_premium(): bool
    {
        // #667 slice 19 — PHPUnit-shim test seam.
        //
        // Production safety: this branch is gated on the TC_PHPUNIT_SHIM
        // constant which is ONLY defined by tests/PHPUnitShimTest.php.
        // Production WordPress never defines that constant; production
        // callers fall through to the Freemius-backed path below
        // byte-for-byte identically to the pre-seam code.
        //
        // Why the seam exists: under the PHPUnit shim, bootstrap.php
        // loads the plugin, which declares this production gt_is_premium().
        // The function_exists guards in test-issue files (#495 et al.)
        // then skip their own stubs, and every "premium plan" assertion
        // resolves to FALSE because Freemius is not configured in the
        // harness. The override global lets a test set premium without
        // building a fake Freemius object.
        //
        // Contract pinned by tests/GTIsPremiumSeamTest.php.
        if (defined('TC_PHPUNIT_SHIM') && array_key_exists('gt_test_is_premium', $GLOBALS)) {
            return (bool) $GLOBALS['gt_test_is_premium'];
        }

        if (!function_exists('wgt_fs')) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        $fs = wgt_fs();
        if (!$fs) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        $premium = method_exists($fs, 'is_premium') && $fs->is_premium();
        $paying  = method_exists($fs, 'is_paying') && $fs->is_paying();
        $can     = method_exists($fs, 'can_use_premium_code') && $fs->can_use_premium_code();
        return $premium || $paying || $can;
    }
}

// @codeCoverageIgnoreStart
if (!function_exists('gt_is_free_plan')) {
// @codeCoverageIgnoreEnd
    function gt_is_free_plan(): bool
    {
        return !gt_is_premium();
    }
}

// @codeCoverageIgnoreStart
if (!function_exists('gt_is_grandfathered')) {
// @codeCoverageIgnoreEnd
    /**
     * #2030 — True when this site has ever been premium (gt_ever_premium flag).
     *
     * On a subscription lapse gt_is_premium() flips false, but a grandfathered
     * site keeps READ access to its pro-source tables (they render read-only
     * instead of blanking). Writes (two-way sync) stay premium-only. Protects
     * the two controlled paying installs from a billing-hiccup blackout.
     */
    function gt_is_grandfathered(): bool
    {
        if (defined('TC_PHPUNIT_SHIM') && array_key_exists('gt_test_grandfathered', $GLOBALS)) {
            return (bool) $GLOBALS['gt_test_grandfathered'];
        }
        if (!function_exists('get_option')) {
            return false;
        }
        return (bool) get_option('gt_ever_premium', false);
    }
}

// @codeCoverageIgnoreStart
if (!function_exists('gt_mark_premium_seen')) {
// @codeCoverageIgnoreEnd
    /**
     * #2030 — Persist the gt_ever_premium flag the first time a site is premium.
     * Idempotent and cheap (only writes the option once).
     */
    function gt_mark_premium_seen(): void
    {
        if (!gt_is_premium()) {
            return;
        }
        if (function_exists('get_option') && function_exists('update_option') && !get_option('gt_ever_premium', false)) {
            update_option('gt_ever_premium', 1, false); // non-autoloaded
        }
    }
}

if (!function_exists('tc_tier_badge')) {
    /**
     * #2162 — Render a small Free / Pro tier badge for docs and admin UI.
     *
     * Pressure-free upsell: a Pro badge optionally links to the upgrade URL so
     * the reader sees exactly what upgrading unlocks, inline with the content.
     *
     * @param bool        $is_pro      True → "Pro" badge, false → "Free" badge.
     * @param string|null $upgrade_url Optional checkout/trial URL (Pro only).
     * @return string Escaped HTML span (anchor-wrapped when an upgrade URL is given).
     */
    function tc_tier_badge($is_pro, $upgrade_url = null) {
        $is_pro = (bool) $is_pro;
        $label  = $is_pro ? __('Pro', 'tc-data-tables') : __('Free', 'tc-data-tables');
        $class  = 'gt-tier-badge ' . ($is_pro ? 'gt-tier-pro' : 'gt-tier-free');
        $badge  = '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';

        if ($is_pro && $upgrade_url) {
            return '<a class="gt-tier-badge-link" href="' . esc_attr($upgrade_url) . '">' . $badge . '</a>';
        }
        return $badge;
    }
}
