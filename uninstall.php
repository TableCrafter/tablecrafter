<?php
/**
 * Uninstall cleanup for TableCrafter (#2146).
 *
 * Runs when the plugin is deleted from the WordPress admin. v8 previously left
 * all of its state behind (options, the wp_gravity_tables / _audit_log tables,
 * scheduled cron events). 3.5.x users who delete the plugin expect a clean
 * removal, so this removes everything the plugin created.
 *
 * Strategy:
 *  - Options/transients are swept by the `gt_` prefix (covers current and
 *    future options without enumerating every key), plus the legacy
 *    `gravity_tables_settings` option.
 *  - The two custom tables are dropped.
 *  - Known scheduled cron hooks are cleared.
 *  - All of the above runs per-site on multisite.
 *
 * NOTE: this intentionally does NOT touch Freemius state — the SDK manages its
 * own uninstall via its registered hooks.
 */

// Only ever run from WordPress's uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all TableCrafter data for the current site/blog.
 */
function tc_uninstall_cleanup_site() {
	global $wpdb;

	// 1. Custom tables.
	$tables = array(
		$wpdb->prefix . 'gravity_tables',
		$wpdb->prefix . 'gravity_tables_audit_log',
	);
	foreach ( $tables as $table ) {
		// Table identifiers can't be parameterized; they're built from the
		// trusted $wpdb->prefix, not user input.
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	// 2. Scheduled cron events.
	foreach ( array( 'gt_auto_import_run', 'gt_cloud_storage_refresh', 'gt_scheduled_export' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// 3. Options + transients by prefix. Transients are stored as options named
	//    _transient_<key> / _transient_timeout_<key>, so the LIKE patterns below
	//    catch gt_ transients as well as plain gt_ options.
	$like_patterns = array(
		$wpdb->esc_like( 'gt_' ) . '%',
		$wpdb->esc_like( '_transient_gt_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gt_' ) . '%',
		$wpdb->esc_like( '_site_transient_gt_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_gt_' ) . '%',
	);
	foreach ( $like_patterns as $pattern ) {
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern )
		);
	}

	// 4. Legacy / non-prefixed options.
	delete_option( 'gravity_tables_settings' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		tc_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	tc_uninstall_cleanup_site();
}
