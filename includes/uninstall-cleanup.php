<?php
/**
 * Uninstall cleanup for TableCrafter (#2146).
 *
 * Originally shipped as a top-level uninstall.php, but Freemius forbids that
 * (it tracks the uninstall event itself and rejects any plugin whose main
 * folder contains uninstall.php). Instead the cleanup is registered on the
 * Freemius `after_uninstall` hook from tablecrafter.php, which fires for both
 * free and premium installs and preserves Freemius's uninstall tracking.
 *
 * Removes everything the plugin created: the wp_gravity_tables /
 * _audit_log tables, scheduled cron events, and gt_ options/transients
 * (plus the legacy gravity_tables_settings option). Multisite-aware.
 * Freemius manages its own state — not touched here.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if (!function_exists('tc_uninstall_cleanup_site')) {
// @codeCoverageIgnoreEnd
    /**
     * Remove all TableCrafter data for the current site/blog.
     */
    function tc_uninstall_cleanup_site() {
        global $wpdb;

        // 1. Custom tables. The only interpolation is the trusted
        //    {$wpdb->prefix}; table identifiers can't be parameterized.
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}gravity_tables`"); // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}gravity_tables_audit_log`"); // phpcs:ignore WordPress.DB.PreparedSQL

        // 2. Scheduled cron events.
        foreach (array('gt_auto_import_run', 'gt_cloud_storage_refresh', 'gt_scheduled_export') as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // 3. Options + transients by prefix. Transients are stored as options
        //    named _transient_<key> / _transient_timeout_<key>, so the LIKE
        //    patterns below catch gt_ transients as well as plain gt_ options.
        $like_patterns = array(
            $wpdb->esc_like('gt_') . '%',
            $wpdb->esc_like('_transient_gt_') . '%',
            $wpdb->esc_like('_transient_timeout_gt_') . '%',
            $wpdb->esc_like('_site_transient_gt_') . '%',
            $wpdb->esc_like('_site_transient_timeout_gt_') . '%',
        );
        foreach ($like_patterns as $pattern) {
            $wpdb->query(
                $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern)
            );
        }

        // 4. Legacy / non-prefixed options.
        delete_option('gravity_tables_settings');
    }
}

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if (!function_exists('tc_run_uninstall_cleanup')) {
// @codeCoverageIgnoreEnd
    /**
     * Freemius `after_uninstall` callback: run the cleanup across the network.
     */
    function tc_run_uninstall_cleanup() {
        if (is_multisite()) {
            $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                tc_uninstall_cleanup_site();
                restore_current_blog();
            }
        } else {
            tc_uninstall_cleanup_site();
        }
    }
}
