<?php
/**
 * TC_Airtable_Conflict_Detector
 *
 * Issue #517 - slice 4f of N. Pure conflict-detection helpers for
 * the future optimistic-locking write path. Strategy: when the
 * plugin reads an Airtable record, snapshot the record's
 * lastModifiedTime as a baseline keyed by the local entry id.
 * Before pushing, fetch again and compare; if the remote is newer
 * than the local baseline, the source has changed since we read it
 * - that's a conflict.
 *
 * Slice 4g will wire this into the slice-4c push helper. Slice 4h
 * adds rate limiting; slice 4i adds the permission gate.
 *
 * Fail-open: when either side is missing (no remote timestamp on
 * the record, or no baseline because this row was never read),
 * detect_conflict returns false. This avoids false-positive blocks
 * on tables without the lastModifiedTime field configured in their
 * Airtable schema.
 *
 * Storage: per-entry option `gt_airtable_baseline_<entry_id>`
 * carrying the ISO 8601 lastModifiedTime string. Reader/writer
 * callables are injectable so unit tests don't require WP.
 *
 * @since 4.51.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Airtable_Conflict_Detector {

    const BASELINE_OPTION_PREFIX = 'gt_airtable_baseline_';

    /**
     * Parse an Airtable lastModifiedTime to a Unix timestamp.
     * Accepts ISO 8601 strings, integer timestamps, and numeric
     * strings. Returns 0 for empty / unparseable input.
     *
     * @param mixed $value
     */
    public static function parse_lastmod($value): int {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts === false ? 0 : (int) $ts;
        }
        return 0;
    }

    /**
     * True when the remote modification time is newer than the
     * locally-stored baseline. Fail-open when either is missing.
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
     * Canonical option key for storing the per-entry baseline.
     * Returns empty string for non-positive entry ids.
     */
    public static function baseline_storage_key(int $entry_id): string {
        if ($entry_id <= 0) {
            return '';
        }
        return self::BASELINE_OPTION_PREFIX . $entry_id;
    }

    /**
     * Persist a baseline for the given entry. Refuses non-positive
     * entry ids (defensive - would namespace to a global key).
     *
     * @param callable|null $writer fn(string $key, mixed $value): bool
     */
    public static function snapshot_baseline(int $entry_id, string $lastmod, ?callable $writer = null): bool {
        $key = self::baseline_storage_key($entry_id);
        if ($key === '') {
            return false;
        }
        $writer = $writer ?: self::default_writer();
        return (bool) $writer($key, $lastmod);
    }

    /**
     * Load the stored baseline for an entry. Returns empty string
     * when nothing is stored.
     *
     * @param callable|null $reader fn(string $key, mixed $default): mixed
     */
    public static function load_baseline(int $entry_id, ?callable $reader = null): string {
        $key = self::baseline_storage_key($entry_id);
        if ($key === '') {
            return '';
        }
        $reader = $reader ?: self::default_reader();
        $value = $reader($key, '');
        return is_string($value) ? $value : '';
    }

    // ---- internals ----

    private static function default_reader(): callable {
        if (function_exists('get_option')) {
            return function (string $key, $default) {
                return get_option($key, $default);
            };
        }
        // @codeCoverageIgnoreStart
        return function (string $key, $default) { return $default; };
        // @codeCoverageIgnoreEnd
    }

    private static function default_writer(): callable {
        if (function_exists('update_option')) {
            return function (string $key, $value): bool {
                return (bool) update_option($key, $value, false);
            };
        }
        // @codeCoverageIgnoreStart
        return function (string $key, $value): bool { return false; };
        // @codeCoverageIgnoreEnd
    }
}
