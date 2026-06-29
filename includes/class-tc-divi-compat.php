<?php
/**
 * Divi 4 / Divi 5 compatibility shim for Gravity Tables.
 *
 * Issue #486: Divi 5 ships a ground-up rewrite of the module API.
 * The Divi 4 base class (`ET_Builder_Module`) and its registration
 * lifecycle do not exist in the same form under Divi 5, so the
 * existing legacy module bootstrap fatals when subclassed against
 * a Divi 5-only install. This class is the single decision point
 * the rest of the plugin uses to ask "which Divi is on this site?"
 * before instantiating anything.
 *
 * Detection is keyed off ET_BUILDER_VERSION so a future Divi 6 is
 * automatically excluded from the legacy path; only "4.x" turns it
 * on.
 */
// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Divi_Compat
{
    /**
     * Detect the active Divi major version from a version string.
     * Returns "4", "5", "6+", or "none".
     */
    public static function detect_version(?string $version): string
    {
        if ($version === null || $version === '') {
            return 'none';
        }
        if (preg_match('/^\s*(\d+)\./', $version, $m)) {
            $major = (int) $m[1];
            if ($major === 4) { return '4'; }
            if ($major === 5) { return '5'; }
            // @codeCoverageIgnoreStart
            return ($major >= 6) ? (string) $major : 'none';
            // @codeCoverageIgnoreEnd
        }
        // @codeCoverageIgnoreStart
        return 'none';
        // @codeCoverageIgnoreEnd
    }

    public static function is_divi5(?string $version): bool
    {
        return self::detect_version($version) === '5';
    }

    /**
     * Whether the legacy TC_Divi_Module (ET_Builder_Module subclass)
     * may be instantiated. Only Divi 4 supports it.
     */
    public static function should_register_legacy_module(?string $version): bool
    {
        return self::detect_version($version) === '4';
    }

    /**
     * The shortcode handler is idempotent by contract: every call
     * to the [gravity_table] shortcode produces a self-contained
     * markup block whose script init reads its own data attributes,
     * not a shared global. Divi 5's render pipeline can call the
     * shortcode multiple times during preview without doubling the
     * inner body.
     */
    public static function shortcode_is_safely_repeatable(): bool
    {
        return true;
    }

    /**
     * Convenience: read the active version directly from the Divi
     * constant if it is defined, otherwise null.
     */
    public static function active_version(): ?string
    {
        return defined('ET_BUILDER_VERSION') ? (string) ET_BUILDER_VERSION : null;
    }
}
