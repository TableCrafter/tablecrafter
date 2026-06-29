<?php
/**
 * TC_Push_Audit_Log_Service — phase 2 slice 10 of #613 (two-way sync).
 *
 * Generic audit log for outgoing push events across all 3 data source
 * engines (JSON / Airtable / Notion). Modeled on
 * TC_Airtable_Audit_Log_Service from #517 but source-agnostic.
 *
 * Stored in a single non-autoloaded option as a most-recent-first
 * bounded array.
 *
 * @since 4.205.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Push_Audit_Log_Service {

    const OPTION_KEY  = 'gt_push_audit_log';
    const MAX_ENTRIES = 500;

    public static function option_key(): string {
        return self::OPTION_KEY;
    }

    public static function max_entries(): int {
        return self::MAX_ENTRIES;
    }

    /**
     * Append an event. Empty events are rejected.
     * Expected shape: { source, table_id, row_id, success, error_code?, http_code?, timestamp? }
     */
    public static function append(array $event, ?callable $reader = null, ?callable $writer = null): bool {
        if (empty($event)) {
            return false;
        }
        $reader = $reader ?: self::default_reader();
        $writer = $writer ?: self::default_writer();

        if (!isset($event['timestamp'])) {
            $event['timestamp'] = time();
        }

        $current = $reader(self::OPTION_KEY, array());
        if (!is_array($current)) {
            $current = array();
        }

        array_unshift($current, $event);

        if (count($current) > self::MAX_ENTRIES) {
            $current = array_slice($current, 0, self::MAX_ENTRIES);
        }

        return (bool) $writer(self::OPTION_KEY, $current);
    }

    /**
     * Load the most-recent N events (most-recent first).
     */
    public static function load(int $limit = self::MAX_ENTRIES, ?callable $reader = null): array {
        $reader = $reader ?: self::default_reader();
        $current = $reader(self::OPTION_KEY, array());
        if (!is_array($current)) {
            return array();
        }
        if ($limit > 0 && count($current) > $limit) {
            return array_slice($current, 0, $limit);
        }
        return $current;
    }

    /**
     * Wipe the audit log option entirely.
     */
    public static function clear(?callable $deleter = null): bool {
        $deleter = $deleter ?: function ($key) { return delete_option($key); };
        return (bool) $deleter(self::OPTION_KEY);
    }

    private static function default_reader(): callable {
        return function ($key, $default) { return get_option($key, $default); };
    }

    private static function default_writer(): callable {
        return function ($key, $value) { return update_option($key, $value, false); };
    }
}
