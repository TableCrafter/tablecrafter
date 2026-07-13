<?php
/**
 * TC_Airtable_Rate_Limiter
 *
 * Issue #517 - slice 4h of N. Per-base sliding-window rate limiter
 * for Airtable REST API calls. Airtable caps each base at ~5 req/s;
 * exceeding that returns 429 Too Many Requests. Without client-side
 * rate limiting, a busy admin (or a programmatic batch via the
 * push-back path) can hammer the base and temporarily lock everyone
 * out.
 *
 * Storage: per-base option `gt_airtable_ratelimit_<base_id>` carrying
 * a list of timestamps recorded within the active window. On every
 * read or record, timestamps older than the window are pruned.
 *
 * Pure helper: reader/writer/deleter callables are injectable so
 * unit tests don't require WP. Production wraps WP option API with
 * autoload=false (the bucket changes too often to autoload).
 *
 * Slice 4i wires this into the slice-4c push helper. Slice 4j adds
 * the permission gate per #613's roadmap.
 *
 * @since 4.53.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Airtable_Rate_Limiter {

    const OPTION_PREFIX = 'gt_airtable_ratelimit_';
    const MAX_REQUESTS_PER_WINDOW = 5;
    const WINDOW_SECONDS = 1;

    public static function max_requests_per_window(): int {
        return self::MAX_REQUESTS_PER_WINDOW;
    }

    public static function window_seconds(): int {
        return self::WINDOW_SECONDS;
    }

    /**
     * Per-base option key. Sanitizes base_id to alphanumeric +
     * underscore (matches Airtable id constraints + protects against
     * accidental option-key namespace collisions).
     */
    public static function option_key(string $base_id): string {
        if ($base_id === '') {
            return '';
        }
        $clean = preg_replace('/[^A-Za-z0-9_]+/', '', $base_id);
        if ($clean === '') {
            return '';
        }
        return self::OPTION_PREFIX . $clean;
    }

    /**
     * True when the bucket already holds MAX_REQUESTS_PER_WINDOW
     * timestamps within the active window. Empty / missing buckets
     * return false. Empty base_id returns false (no key to track).
     *
     * @param string        $base_id
     * @param int|null      $now      Unix timestamp; defaults to time()
     * @param callable|null $reader   fn(string $key, mixed $default): mixed
     */
    public static function should_throttle(string $base_id, ?int $now = null, ?callable $reader = null): bool {
        $key = self::option_key($base_id);
        if ($key === '') {
            return false;
        }
        $now = $now ?? time();
        $reader = $reader ?: self::default_reader();

        $bucket = $reader($key, []);
        if (!is_array($bucket)) {
            return false;
        }
        $cutoff = $now - self::WINDOW_SECONDS;
        $live = array_values(array_filter($bucket, function ($ts) use ($cutoff) {
            return is_numeric($ts) && (int) $ts > $cutoff;
        }));
        return count($live) >= self::MAX_REQUESTS_PER_WINDOW;
    }

    /**
     * Record a request in the bucket, pruning expired timestamps in
     * the same write. Refuses empty base_id (defensive).
     *
     * @param callable|null $writer fn(string $key, mixed $value): bool
     */
    public static function record_request(
        string $base_id,
        ?int $now = null,
        ?callable $reader = null,
        ?callable $writer = null
    ): bool {
        $key = self::option_key($base_id);
        if ($key === '') {
            return false;
        }
        $now = $now ?? time();
        $reader = $reader ?: self::default_reader();
        $writer = $writer ?: self::default_writer();

        $bucket = $reader($key, []);
        if (!is_array($bucket)) {
            $bucket = [];
        }
        $cutoff = $now - self::WINDOW_SECONDS;
        $live = array_values(array_filter($bucket, function ($ts) use ($cutoff) {
            return is_numeric($ts) && (int) $ts > $cutoff;
        }));
        $live[] = $now;
        return (bool) $writer($key, $live);
    }

    /**
     * Wipe the per-base bucket entirely.
     *
     * @param callable|null $deleter fn(string $key): bool
     */
    public static function clear(string $base_id, ?callable $deleter = null): bool {
        $key = self::option_key($base_id);
        if ($key === '') {
            return false;
        }
        $deleter = $deleter ?: self::default_deleter();
        return (bool) $deleter($key);
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
