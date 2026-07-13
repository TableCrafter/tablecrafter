<?php
/**
 * License-state observability across plugin auto-updates.
 *
 * A valid Freemius premium license must survive a WordPress plugin
 * auto-update unchanged - that's the regression #502 guards against.
 * This class adds a passive observer on `upgrader_process_complete`:
 * it records gt_is_premium() before/after, logs a warning if the
 * value flipped from true to false during an update of *this* plugin,
 * and never mutates license storage itself.
 *
 * It does NOT call delete_option / delete_site_option / delete_user_meta
 * against any key. Freemius owns its own storage; this class is read-only.
 *
 * @package GravityTables
 * @since 4.7.1
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_License_Persistence
{
    /**
     * Option key used to remember the last observed premium state. We
     * only ever write our own option here - never touch Freemius keys.
     */
    public const STATE_OPTION = 'gt_license_last_observed_state';

    /**
     * Plugin basename used to detect updates targeting this plugin only.
     * Resolved lazily so unit tests can stub it.
     */
    private static ?string $plugin_basename = null;

    public static function set_plugin_basename(string $basename): void
    {
        self::$plugin_basename = $basename;
    }

    public static function plugin_basename(): string
    {
        if (self::$plugin_basename !== null) {
            return self::$plugin_basename;
        }
        return 'gravity-tables/tablecrafter.php';
    }

    /**
     * Hook into WordPress. Wired up from tablecrafter.php on init.
     */
    public static function register(): void
    {
        // @codeCoverageIgnoreStart
        if (!function_exists('add_action')) {
            return;
        }
        // @codeCoverageIgnoreEnd
        add_action('upgrader_process_complete', array(__CLASS__, 'on_upgrader_complete'), 10, 2);
    }

    /**
     * Take a read-only snapshot of the current license state.
     *
     * @return array{is_premium:bool, observed_at:int}
     */
    public static function observe_license_state(): array
    {
        $is_premium = function_exists('gt_is_premium') ? (bool) gt_is_premium() : false;
        return array(
            'is_premium'  => $is_premium,
            'observed_at' => time(),
        );
    }

    /**
     * Callback for the upgrader_process_complete WP action.
     *
     * Returns false (and does nothing) for non-plugin updates or when
     * the update did not include this plugin. Returns the post-update
     * snapshot otherwise. Never deletes any license-related option.
     *
     * @param mixed $upgrader Unused - kept to match WP signature.
     * @param array $hook_extra Extra info passed by WP (type, action, plugins, etc).
     * @return array|false
     */
    public static function on_upgrader_complete($upgrader = null, $hook_extra = array())
    {
        if (!is_array($hook_extra)) {
            return false;
        }
        $type = $hook_extra['type'] ?? '';
        if ($type !== 'plugin') {
            return false;
        }
        $plugins = isset($hook_extra['plugins']) && is_array($hook_extra['plugins'])
            ? $hook_extra['plugins']
            : array();
        $self_plugin = isset($hook_extra['plugin']) ? (string) $hook_extra['plugin'] : '';
        if ($self_plugin !== '') {
            $plugins[] = $self_plugin;
        }
        if (empty($plugins) || !in_array(self::plugin_basename(), $plugins, true)) {
            return false;
        }

        $previous = function_exists('get_option') ? get_option(self::STATE_OPTION, null) : null;
        $current  = self::observe_license_state();

        if (function_exists('update_option')) {
            update_option(self::STATE_OPTION, $current, false);
        }

        // If we used to be premium and now we are not, log it. We do NOT
        // attempt to "fix" the license - Freemius owns that decision - 
        // we just leave a breadcrumb so support can detect the regression.
        if (is_array($previous)
            && !empty($previous['is_premium'])
            && empty($current['is_premium'])
            && function_exists('error_log')
        ) {
            // @codeCoverageIgnoreStart
            error_log('[gravity-tables] license state flipped premium=true -> false across plugin update; investigate Freemius integration (#502)');
            // @codeCoverageIgnoreEnd
        }

        return $current;
    }
}
