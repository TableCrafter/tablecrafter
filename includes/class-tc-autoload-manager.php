<?php
/**
 * TC_Autoload_Manager - controls WordPress option autoload for Gravity Tables (#213).
 *
 * Plugin options that store per-table data (rows, columns, settings) should
 * NOT be autoloaded because WordPress loads all autoloaded options on every
 * page request. This class provides:
 *
 *  - get_autoload_stat(): returns the total byte-size of GT-owned options
 *    currently in the WP autoload set.
 *  - optimize_autoload(): re-saves every GT option with autoload = 'no' so
 *    it is only fetched on demand.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Autoload_Manager
{
    /** Option name prefixes owned by Gravity Tables. */
    private const TC_PREFIXES = [
        'gt_',
        'gravity_tables',
        'gravity-tables',
    ];

    /**
     * Return the total byte-size of all GT options currently in the WP autoload set.
     *
     * Queries the wp_options table directly for accuracy - get_option() would
     * already have loaded autoloaded options into the WP object cache and would
     * not reflect the on-disk autoload=yes state reliably.
     *
     * @return int Total size in bytes, or 0 if the DB query returns nothing.
     */
    public static function get_autoload_stat(): int
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $where_clauses = [];
        foreach (self::TC_PREFIXES as $prefix) {
            $where_clauses[] = $wpdb->prepare(
                "(`option_name` LIKE %s AND `autoload` IN ('yes','on','true','1'))",
                $wpdb->esc_like($prefix) . '%'
            );
        }

        $sql = "SELECT COALESCE(SUM(LENGTH(`option_value`)), 0) AS total_size
                FROM {$wpdb->options}
                WHERE " . implode(' OR ', $where_clauses);

        $result = $wpdb->get_var($sql);
        return (int) $result;
    }

    /**
     * Re-save every GT option with autoload = 'no' so it is fetched on demand.
     *
     * Returns the number of options updated.
     */
    public static function optimize_autoload(): int
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $where_clauses = [];
        foreach (self::TC_PREFIXES as $prefix) {
            $where_clauses[] = $wpdb->prepare(
                "`option_name` LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            );
        }

        // Collect the names of GT options that are currently autoloaded.
        $sql = "SELECT `option_name`
                FROM {$wpdb->options}
                WHERE (" . implode(' OR ', $where_clauses) . ")
                  AND `autoload` IN ('yes','on','true','1')";

        $option_names = $wpdb->get_col($sql);

        if (empty($option_names)) {
            return 0;
        }

        $updated = 0;
        foreach ($option_names as $name) {
            // update_option with $autoload = false writes autoload = 'no' to the DB.
            $value = get_option($name);
            if (update_option($name, $value, false)) {
                $updated++;
            } else {
                // update_option returns false when the value has not changed; still
                // update the autoload column directly in that case.
                $wpdb->update(
                    $wpdb->options,
                    ['autoload' => 'no'],
                    ['option_name' => $name]
                );
                $updated++;
            }
        }

        return $updated;
    }
}
