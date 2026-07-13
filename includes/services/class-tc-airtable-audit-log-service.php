<?php
/**
 * TC_Airtable_Audit_Log_Service
 *
 * Issue #517 - slice 4d of N. Structured audit log for Airtable
 * sync events (push success / failure). Slice 4c shipped the
 * fire-and-log push-back; slice 4d adds a queryable surface so the
 * admin can inspect what's been pushed (and what failed) instead
 * of grepping debug.log.
 *
 * Storage: a single non-autoloaded option `gt_airtable_audit_log`
 * carrying an array of events. Capped at MAX_ENTRIES (most-recent
 * first; oldest dropped when full). Reader/writer/deleter callables
 * are injectable so unit tests don't require WP.
 *
 * Slice 4e will wire the service into the slice-4c
 * maybe_pushback_to_airtable helper and add an admin UI surface.
 *
 * Slice 4f+ adds conflict resolution + per-source rate limiting +
 * permission gate per #613's roadmap.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Airtable_Audit_Log_Service {

    const OPTION_KEY = 'gt_airtable_audit_log';
    const MAX_ENTRIES = 100;

    public static function option_key(): string {
        return self::OPTION_KEY;
    }

    public static function max_entries(): int {
        return self::MAX_ENTRIES;
    }

    /**
     * Append an event. Adds a `timestamp` field if not present.
     * Caps the log at MAX_ENTRIES (drops oldest when full).
     * Refuses empty events (defensive - the load shape would be
     * ambiguous otherwise).
     *
     * @param array         $event  e.g. ['entry_id' => 42, 'record_id' => 'recXyZ', 'http_code' => 200, 'error' => null, 'direction' => 'push']
     * @param callable|null $reader fn(string $key, mixed $default): mixed
     * @param callable|null $writer fn(string $key, mixed $value): bool
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

        $current = $reader(self::OPTION_KEY, []);
        if (!is_array($current)) {
            $current = [];
        }

        // Most-recent first: prepend.
        array_unshift($current, $event);

        // Cap the array. Slicing from the front keeps the most-recent N events.
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
        $current = $reader(self::OPTION_KEY, []);
        if (!is_array($current)) {
            return [];
        }
        if ($limit < 1) {
            return [];
        }
        return array_slice($current, 0, $limit);
    }

    /**
     * Wipe the audit log entirely.
     */
    public static function clear(?callable $deleter = null): bool {
        $deleter = $deleter ?: self::default_deleter();
        return (bool) $deleter(self::OPTION_KEY);
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

    private static function default_deleter(): callable {
        if (function_exists('delete_option')) {
            return function (string $key): bool {
                return (bool) delete_option($key);
            };
        }
        // @codeCoverageIgnoreStart
        return function (string $key): bool { return false; };
        // @codeCoverageIgnoreEnd
    }
}
