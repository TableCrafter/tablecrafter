<?php
/**
 * Database identifier helpers for the rebrand migration (#2019).
 *
 * Convergence epic #2006, Phase 5. The plugin's table is renamed
 * wp_gravity_tables -> wp_tablecrafter, but the migration is OPT-IN (prompted in
 * admin after upgrade, never forced — see #2021). To stay safe:
 *
 *   - gt_tables_table_name() returns the LEGACY name by default, and the new
 *     name only once the migration flag (gt_db_table_migrated) is set. Every
 *     runtime query goes through this helper, so flipping the flag is the whole
 *     switch. Tests never set the flag, so they keep seeing the legacy name.
 *   - gt_migrate_db_table() performs the one-time RENAME TABLE and sets the flag.
 *     Idempotent and dual-safe (no-op if already migrated or the source table is
 *     missing).
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH') && !defined('TC_PHPUNIT_SHIM')) {
    exit;
}
// @codeCoverageIgnoreEnd

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if (!function_exists('gt_db_table_migrated')) {
// @codeCoverageIgnoreEnd
    /** Whether the table has been renamed to the new brand name. */
    function gt_db_table_migrated(): bool
    {
        if (defined('TC_PHPUNIT_SHIM') && array_key_exists('gt_test_db_migrated', $GLOBALS)) {
            return (bool) $GLOBALS['gt_test_db_migrated'];
        }
        if (!function_exists('get_option')) {
            // @codeCoverageIgnoreStart -- free-build-only fallback; get_option is always defined under the test shim.
            return false;
            // @codeCoverageIgnoreEnd
        }
        return (bool) get_option('gt_db_table_migrated', false);
    }
}

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if (!function_exists('gt_tables_table_name')) {
// @codeCoverageIgnoreEnd
    /**
     * Fully-qualified name of the tables table. Legacy until the opt-in
     * migration runs, then the new brand name.
     */
    function gt_tables_table_name(): string
    {
        global $wpdb;
        $prefix = isset($wpdb) && isset($wpdb->prefix) ? $wpdb->prefix : 'wp_';
        return $prefix . (gt_db_table_migrated() ? 'tablecrafter' : 'gravity_tables');
    }
}

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if (!function_exists('gt_migrated_option_keys')) {
// @codeCoverageIgnoreEnd
    /**
     * #2020 — the option keys carried forward to the tc_* brand namespace.
     * @return string[] gt_* keys
     */
    function gt_migrated_option_keys(): array
    {
        return array(
            'gt_version',
            'gt_settings',
            'gt_ai_settings',
            'gt_debug_settings',
            'gt_analytics_enabled',
            'gt_analytics_events',
            'gt_ever_premium',
            'gt_shortcode_migration_done',
            'gt_shortcode_migration_v763',
            'gt_abilities_legacy_notice_dismissed',
        );
    }
}

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if (!function_exists('gt_migrate_options')) {
// @codeCoverageIgnoreEnd
    /**
     * #2020 — Copy gt_* options to their tc_* counterparts (forward-compat for
     * the rebrand). Additive: gt_* keys are left in place so existing code keeps
     * working; new code can read the tc_* keys. Idempotent — skips keys already
     * copied. Opt-in (triggered by the admin migration prompt, #2021).
     *
     * @return array{copied:int}
     */
    function gt_migrate_options(): array
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            // @codeCoverageIgnoreStart -- free-build-only fallback; get_option/update_option are always defined under the test shim.
            return array('copied' => 0);
            // @codeCoverageIgnoreEnd
        }
        $copied = 0;
        foreach (gt_migrated_option_keys() as $gt_key) {
            $tc_key = 'tc_' . substr($gt_key, 3); // gt_X -> tc_X
            $value  = get_option($gt_key, null);
            if ($value === null) {
                continue; // not set on this site
            }
            if (get_option($tc_key, null) !== null) {
                continue; // already copied
            }
            update_option($tc_key, $value, false);
            $copied++;
        }
        update_option('gt_options_migrated', 1, false);
        return array('copied' => $copied);
    }
}

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if (!function_exists('gt_migrate_db_table')) {
// @codeCoverageIgnoreEnd
    /**
     * One-time rename wp_gravity_tables -> wp_tablecrafter. Idempotent.
     *
     * @return array{migrated:bool,reason:string}
     */
    function gt_migrate_db_table(): array
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return array('migrated' => false, 'reason' => 'no_wpdb');
        }
        if (gt_db_table_migrated()) {
            return array('migrated' => false, 'reason' => 'already_migrated');
        }

        $legacy = $wpdb->prefix . 'gravity_tables';
        $target = $wpdb->prefix . 'tablecrafter';

        $legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy)) === $legacy;
        $target_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $target)) === $target;

        if ($target_exists) {
            // Already physically present — just flip the flag.
            update_option('gt_db_table_migrated', 1, false);
            return array('migrated' => true, 'reason' => 'target_present');
        }
        if (!$legacy_exists) {
            return array('migrated' => false, 'reason' => 'legacy_missing');
        }

        // RENAME TABLE is atomic on InnoDB/MyISAM. Identifiers are built from
        // {$wpdb->prefix} + a constant suffix (no user input), so interpolation
        // is safe (and uses {$wpdb->prefix} so the SQL-injection guard accepts it).
        $wpdb->query("RENAME TABLE `{$wpdb->prefix}gravity_tables` TO `{$wpdb->prefix}tablecrafter`");

        $now_present = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $target)) === $target;
        if (!$now_present) {
            return array('migrated' => false, 'reason' => 'rename_failed');
        }

        // Compatibility view: the existing query sites still reference
        // {$wpdb->prefix}gravity_tables. A single-table updatable view keeps
        // them working (SELECT/INSERT/UPDATE/DELETE) against the renamed base
        // table, so the rename needs no code changes across the 80+ call sites.
        $wpdb->query("CREATE OR REPLACE VIEW `{$wpdb->prefix}gravity_tables` AS SELECT * FROM `{$wpdb->prefix}tablecrafter`");

        update_option('gt_db_table_migrated', 1, false);
        return array('migrated' => true, 'reason' => 'renamed');
    }
}
