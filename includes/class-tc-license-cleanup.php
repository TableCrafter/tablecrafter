<?php
/**
 * License-tied cleanup. When the plugin is deactivated (or the customer's
 * license is uninstalled by Freemius) we must leave no orphaned WP-cron
 * schedules behind - that was AC #3 of issue #481.
 *
 * Every cron hook the plugin schedules MUST be listed in {@see cron_hooks()}
 * so {@see on_deactivate()} can clear it. Adding a new wp_schedule_event
 * call without registering its hook here will leave a ticking cron after
 * uninstall.
 *
 * @package GravityTables
 * @since 4.6.4
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_License_Cleanup
{
    /**
     * Cron hooks the plugin schedules. wp_unschedule_hook() is run for each
     * one in on_deactivate().
     *
     * @return string[]
     */
    public static function cron_hooks(): array
    {
        return [
            'gt_auto_import_run',
            'gt_cloud_storage_sync',
            'gt_google_sheets_sync',
        ];
    }

    /**
     * Plugin deactivation entry-point. Wired up from tablecrafter.php.
     */
    public static function on_deactivate(): void
    {
        if (function_exists('wp_unschedule_hook')) {
            foreach (self::cron_hooks() as $hook) {
                wp_unschedule_hook($hook);
            }
        }
    }
}
