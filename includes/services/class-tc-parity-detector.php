<?php
/**
 * TC_Parity_Detector - v3.5.6 takeover parity check (#2028).
 *
 * Convergence epic #2006, Phase 5 / D2. When the converged build takes over the
 * WordPress.org slug from the standalone free plugin, existing free installs
 * must keep working. By design (D1) the free tier is a superset of v3.5.6 - 
 * Airtable-read, JSON, CSV and public Google Sheets all stay free - so existing
 * tables continue to render. This detector runs once, records a parity-checked
 * flag, and surfaces a one-time reassurance notice listing what an existing
 * install keeps.
 *
 * The detection logic is pure (analyze()) so it is unit-tested without WordPress.
 */

class TC_Parity_Detector
{
    const FLAG = 'gt_parity_checked';

    /** Data-source types that remain free after the takeover (v3.5.6 parity). */
    const FREE_PARITY_SOURCES = array('gravity_forms', 'json', 'csv', 'google_sheets', 'airtable', 'woocommerce_products');

    /** Whether the one-time parity check still needs to run. */
    public static function needs_check(): bool
    {
        if (defined('TC_PHPUNIT_SHIM') && array_key_exists('gt_test_parity_checked', $GLOBALS)) {
            return !$GLOBALS['gt_test_parity_checked'];
        }
        return function_exists('get_option') ? !get_option(self::FLAG, false) : true;
    }

    /** Mark the parity check as done. */
    public static function mark_checked(): void
    {
        if (function_exists('update_option')) {
            update_option(self::FLAG, 1, false);
        }
    }

    /**
     * Analyze a set of existing tables (each with a data_source_type) and report
     * how many continue to work on the free tier vs. need Pro.
     *
     * @param array<int,array<string,mixed>> $tables  rows with 'data_source_type'
     * @return array{total:int,free:int,pro:int,sources:array<string,int>}
     */
    public static function analyze(array $tables): array
    {
        $free = 0;
        $pro  = 0;
        $sources = array();

        foreach ($tables as $table) {
            $type = isset($table['data_source_type']) && $table['data_source_type'] !== ''
                ? (string) $table['data_source_type']
                : 'gravity_forms';
            $sources[$type] = (isset($sources[$type]) ? $sources[$type] : 0) + 1;
            if (in_array($type, self::FREE_PARITY_SOURCES, true)) {
                $free++;
            } else {
                $pro++;
            }
        }

        return array(
            'total'   => count($tables),
            'free'    => $free,
            'pro'     => $pro,
            'sources' => $sources,
        );
    }
}
