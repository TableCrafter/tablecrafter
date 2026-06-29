<?php
/**
 * TC_Push_Conflict_Detector — phase 2 slice 13 of #613 (two-way sync).
 *
 * Source-agnostic counterpart to TC_Airtable_Conflict_Detector (#517).
 * Stores per-{source,row_id} baseline lastmod timestamps so the push
 * flow can compare against the remote's current lastmod before
 * overwriting.
 *
 * Wire-up to the push flow lands in the next slice — this slice ships
 * the service + tests.
 *
 * @since 4.208.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Push_Conflict_Detector {

    const OPTION_PREFIX = 'gt_push_baseline_';

    /**
     * Parse a lastmod value to epoch seconds. Accepts ISO 8601 strings
     * and integer/numeric epoch values. Returns 0 on parse failure.
     */
    public static function parse_lastmod($value): int {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (!is_string($value) || $value === '') {
            return 0;
        }
        $ts = strtotime($value);
        return ($ts === false) ? 0 : (int) $ts;
    }

    /**
     * Returns true when the remote source has been modified more
     * recently than our baseline. False on equal, earlier, or
     * unparseable inputs (safer to assume no conflict than to block
     * the push on garbage data).
     */
    public static function detect_conflict($remote_lastmod, $baseline_lastmod): bool {
        $remote = self::parse_lastmod($remote_lastmod);
        $baseline = self::parse_lastmod($baseline_lastmod);
        if ($remote <= 0 || $baseline <= 0) {
            return false;
        }
        return $remote > $baseline;
    }

    /**
     * Canonical option key for a per-{source, row_id} baseline.
     */
    public static function baseline_storage_key(string $source, string $row_id): string {
        $safe_source = preg_replace('/[^a-z0-9_]/i', '_', $source);
        $safe_row    = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $row_id);
        return self::OPTION_PREFIX . $safe_source . '_' . $safe_row;
    }

    /**
     * Persist the latest known lastmod for a given source/row.
     */
    public static function snapshot_baseline(string $source, string $row_id, string $lastmod, ?callable $writer = null): bool {
        $writer = $writer ?: function ($key, $value) { return update_option($key, $value, false); };
        return (bool) $writer(self::baseline_storage_key($source, $row_id), (string) $lastmod);
    }

    /**
     * Load the stored baseline lastmod for a given source/row.
     * Returns empty string for unseen rows.
     */
    public static function load_baseline(string $source, string $row_id, ?callable $reader = null): string {
        $reader = $reader ?: function ($key, $default) { return get_option($key, $default); };
        $value = $reader(self::baseline_storage_key($source, $row_id), '');
        return is_string($value) ? $value : '';
    }
}
