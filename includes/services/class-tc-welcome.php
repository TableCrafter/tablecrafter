<?php
/**
 * TC_Welcome - first-activation onboarding screen (#2064).
 *
 * Onboarding-port epic #2061. Ported from the standalone free plugin's welcome
 * flow. On first activation a capable admin is redirected once to a welcome page
 * that explains TableCrafter, offers the one-click demos (#2063), and links into
 * the builder.
 */

// @codeCoverageIgnoreStart -- ABSPATH guard; condition is always false under the test shim and runs pre-instrumentation.
if (!defined('ABSPATH') && !defined('TC_PHPUNIT_SHIM')) {
    exit;
}
// @codeCoverageIgnoreEnd

class TC_Welcome
{
    const FLAG = 'gt_welcome_shown';
    const PAGE = 'tablecrafter-welcome';

    public static function boot(): void
    {
        if (!function_exists('add_action')) {
            // @codeCoverageIgnoreStart -- add_action is always defined under the test shim.
            return;
            // @codeCoverageIgnoreEnd
        }
        add_action('admin_init', array(__CLASS__, 'maybe_redirect'));
        add_action('admin_menu', array(__CLASS__, 'register_page'), 20);
    }

    /**
     * Pure predicate: should we redirect to the welcome page on this request?
     */
    public static function should_redirect_for(bool $flag_set, bool $can_manage, bool $is_ajax, bool $bulk): bool
    {
        return !$flag_set && $can_manage && !$is_ajax && !$bulk;
    }

    /** Redirect once on first activation; mark shown so it never repeats. */
    public static function maybe_redirect(): void
    {
        $bulk = isset($_GET['activate-multi']); // bulk plugin activation - don't hijack
        $should = self::should_redirect_for(
            (bool) get_option(self::FLAG, false),
            current_user_can('manage_options'),
            function_exists('wp_doing_ajax') && wp_doing_ajax(),
            $bulk
        );
        if (!$should) {
            return;
        }
        update_option(self::FLAG, 1, false);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
        // @codeCoverageIgnoreStart -- post-redirect exit; not reachable under the test harness.
        exit;
        // @codeCoverageIgnoreEnd
    }

    /** Register the hidden welcome page (not in the menu). */
    public static function register_page(): void
    {
        add_submenu_page(
            '', // hidden - reachable only by URL / the redirect
            __('Welcome to TableCrafter', 'tc-data-tables'),
            __('Welcome', 'tc-data-tables'),
            'manage_options',
            self::PAGE,
            array(__CLASS__, 'render')
        );
    }

    /** Render the onboarding view. */
    public static function render(): void
    {
        $view = (defined('TC_PLUGIN_PATH') ? TC_PLUGIN_PATH : (defined('GT_PLUGIN_PATH') ? GT_PLUGIN_PATH : '')) . 'admin/views/welcome.php';
        if (file_exists($view)) {
            include $view;
        }
    }
}
