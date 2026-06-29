<?php
/**
 * TC_SWR_Cache — stale-while-revalidate cache for external data sources.
 *
 * Issue #2012 (convergence epic #2006, Phase 3). Ported from the free plugin's
 * TC_Cache SWR pattern. When a cache entry is fresh it is returned directly;
 * when it is stale-but-within-grace the stale value is returned IMMEDIATELY and
 * a refresh is scheduled to run after the response is flushed (so a slow
 * upstream never blocks the page); when it is missing or fully expired the
 * fetcher runs synchronously.
 *
 * WP_Error results are never cached — they are returned through so the caller's
 * error short-circuit can handle them, and the next request retries.
 */

class TC_SWR_Cache
{
    /** Cron hook for optional cache warming (extensible via do_action). */
    const WARM_HOOK = 'gt_swr_warm';

    /**
     * Serve a cached value with stale-while-revalidate semantics.
     *
     * @param string   $key         Transient key.
     * @param int      $fresh_ttl   Seconds the value is considered fresh.
     * @param callable $fetcher     Returns the fresh value (may return WP_Error).
     * @param int      $stale_grace Extra seconds a stale value may be served while
     *                              a background refresh runs (0 = no SWR window).
     * @param int|null $now         Override for the current timestamp (tests).
     * @return mixed
     */
    public static function remember(string $key, int $fresh_ttl, callable $fetcher, int $stale_grace = 0, ?int $now = null)
    {
        $now = $now ?? time();
        $env = get_transient($key);

        if (is_array($env) && array_key_exists('data', $env) && isset($env['exp'])) {
            if ($now < (int) $env['exp']) {
                return $env['data']; // fresh
            }
            if ($stale_grace > 0 && $now < (int) $env['exp'] + $stale_grace) {
                // Stale but serviceable — return stale now, refresh after response.
                self::schedule_refresh($key, $fresh_ttl, $stale_grace, $fetcher);
                return $env['data'];
            }
        }

        // Miss or fully expired — fetch synchronously.
        $fresh = call_user_func($fetcher);
        self::store($key, $fresh, $fresh_ttl, $stale_grace, $now);
        return $fresh;
    }

    /**
     * Store a value (unless it's a WP_Error). The transient lives for the fresh
     * window plus the stale grace so SWR can still read it after expiry.
     */
    public static function store(string $key, $value, int $fresh_ttl, int $stale_grace = 0, ?int $now = null): void
    {
        if (is_wp_error($value)) {
            return; // never cache errors
        }
        $now = $now ?? time();
        set_transient(
            $key,
            array('data' => $value, 'exp' => $now + $fresh_ttl),
            $fresh_ttl + $stale_grace
        );
    }

    /**
     * Schedule a background refresh of $key to run after the response is sent.
     * Uses the shutdown hook so the current request returns the stale value
     * immediately (pairs with fastcgi_finish_request on most stacks).
     */
    protected static function schedule_refresh(string $key, int $fresh_ttl, int $stale_grace, callable $fetcher): void
    {
        // Guard against scheduling the same refresh twice in one request.
        static $scheduled = array();
        if (isset($scheduled[$key])) {
            return;
        }
        $scheduled[$key] = true;

        $cb = static function () use ($key, $fresh_ttl, $stale_grace, $fetcher) {
            $fresh = call_user_func($fetcher);
            self::store($key, $fresh, $fresh_ttl, $stale_grace);
        };

        if (function_exists('add_action')) {
            add_action('shutdown', $cb, 5);
        } else {
            // No WP runtime (CLI/tests without the hook) — refresh inline.
            $cb();
        }
    }

    /**
     * Register the optional warm cron. Background SWR refresh happens on access;
     * this hook lets integrations pre-warm zero-traffic tables (do_action seam).
     */
    public static function register_cron(): void
    {
        if (function_exists('add_action')) {
            add_action(self::WARM_HOOK, array(__CLASS__, 'warm'));
        }
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
            if (!wp_next_scheduled(self::WARM_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::WARM_HOOK);
            }
        }
    }

    /** Warm cron callback — extensible seam for source integrations. */
    public static function warm(): void
    {
        if (function_exists('do_action')) {
            do_action(self::WARM_HOOK . '_run');
        }
    }
}
