<?php
/**
 * TC_Push_Rate_Limiter - phase 2 slice 11 of #613 (two-way sync).
 *
 * Per-source rate limiter for outgoing push requests. Generic across
 * all 3 engines (JSON / Airtable / Notion). Modeled on the existing
 * TC_Airtable_Rate_Limiter (#517) but source-keyed rather than
 * base-id-keyed.
 *
 * Per-second limits (sane defaults aligned with each API's documented
 * cap):
 *   JSON     → 10 req/sec (user-controlled endpoints; assume forgiving)
 *   Airtable → 5  req/sec (matches existing TC_Airtable_Rate_Limiter)
 *   Notion   → 3  req/sec (Notion's documented limit)
 *
 * @since 4.206.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Push_Rate_Limiter {

    const OPTION_PREFIX = 'gt_push_ratelimit_';
    const WINDOW_SECONDS = 1;
    const DEFAULT_LIMIT = 5;

    /**
     * Per-source per-second limit. Unknown sources fall back to DEFAULT_LIMIT.
     */
    public static function limit_for(string $source): int {
        $map = array(
            'json'     => 10,
            'airtable' => 5,
            'notion'   => 3,
        );
        return $map[$source] ?? self::DEFAULT_LIMIT;
    }

    public static function window_seconds(): int {
        return self::WINDOW_SECONDS;
    }

    public static function option_key(string $source): string {
        return self::OPTION_PREFIX . preg_replace('/[^a-z0-9_]/i', '_', $source);
    }

    /**
     * Return true when the source is at or above its per-second cap.
     */
    public static function should_throttle(string $source, ?int $now = null, ?callable $reader = null): bool {
        $now = $now ?? time();
        $reader = $reader ?: function ($key, $default) { return get_option($key, $default); };
        $stored = $reader(self::option_key($source), array());
        if (!is_array($stored)) {
            $stored = array();
        }
        // Count timestamps within the window.
        $count = 0;
        foreach ($stored as $ts) {
            if (is_numeric($ts) && (int) $ts > ($now - self::WINDOW_SECONDS)) {
                $count++;
            }
        }
        return $count >= self::limit_for($source);
    }

    /**
     * Record a request and prune timestamps outside the window.
     */
    public static function record_request(string $source, ?int $now = null, ?callable $reader = null, ?callable $writer = null): bool {
        $now = $now ?? time();
        $reader = $reader ?: function ($key, $default) { return get_option($key, $default); };
        $writer = $writer ?: function ($key, $value) { return update_option($key, $value, false); };

        $stored = $reader(self::option_key($source), array());
        if (!is_array($stored)) {
            $stored = array();
        }
        // Append + prune.
        $stored[] = $now;
        $pruned = array();
        foreach ($stored as $ts) {
            if (is_numeric($ts) && (int) $ts > ($now - self::WINDOW_SECONDS)) {
                $pruned[] = (int) $ts;
            }
        }
        return (bool) $writer(self::option_key($source), $pruned);
    }

    public static function clear(string $source, ?callable $deleter = null): bool {
        $deleter = $deleter ?: function ($key) { return delete_option($key); };
        return (bool) $deleter(self::option_key($source));
    }
}
