<?php
/**
 * GT_* back-compat class aliases (#2017).
 *
 * Convergence epic #2006, Phase 4. The classes were renamed from the legacy
 * GT_* (gravity-tables) brand to the TC_* (TableCrafter) brand. This registers a
 * lazy autoloader so any lingering reference to the OLD GT_Foo name resolves to
 * the new TC_Foo class via class_alias — protecting external integrations and
 * any stored references during the transition. TC_* is canonical going forward.
 *
 * @package TableCrafter
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH') && !defined('TC_PHPUNIT_SHIM')) {
    exit;
}
// @codeCoverageIgnoreEnd

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if (!function_exists('gt_register_brand_aliases')) {
// @codeCoverageIgnoreEnd
    /**
     * Register the GT_* -> TC_* back-compat alias autoloader. Idempotent.
     */
    function gt_register_brand_aliases(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        // @codeCoverageIgnoreStart -- one-time registration runs at bootstrap load before coverage instrumentation starts.
        $registered = true;
        // @codeCoverageIgnoreEnd

        spl_autoload_register(function ($class) {
            if (strncmp($class, 'GT_', 3) !== 0) {
                return;
            }
            $tc = 'TC_' . substr($class, 3);
            // All TC_* classes are explicitly required by init time, so by the
            // time a legacy GT_* name is referenced the target already exists.
            if (class_exists($tc, false) || interface_exists($tc, false) || trait_exists($tc, false)) {
                class_alias($tc, $class);
            }
        });
    }
}

// @codeCoverageIgnoreStart -- one-time bootstrap call runs before coverage instrumentation starts.
gt_register_brand_aliases();
// @codeCoverageIgnoreEnd
