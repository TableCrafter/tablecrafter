<?php
/**
 * Chart auto-refresh interval manager for Gravity Tables.
 *
 * Produces the inline JavaScript that polls a chart's data endpoint at a
 * configured interval. Fixes three common bugs:
 *
 *  1. Interval accumulation — calling setInterval() again on each successful
 *     fetch means N fetches → N intervals → exponential request storm. We
 *     always clear the previous ID before setting a new one.
 *
 *  2. Orphaned intervals — if the chart element is removed from the DOM (e.g.
 *     a tab widget hides it), the interval keeps firing. A MutationObserver on
 *     the parent clears the interval when the element is disconnected.
 *
 *  3. Hidden-tab waste — when document.visibilityState === 'hidden', each
 *     interval tick checks the flag and skips the fetch. The interval itself
 *     stays registered so timing is preserved when the tab becomes visible again.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Chart_Refresh_Service {

    /**
     * Whether auto-refresh is enabled for the given interval value.
     *
     * Treats 0 / negative values as "never" so authors can disable
     * auto-refresh per chart without removing the snippet.
     */
    public static function is_enabled( int $interval_ms ): bool {
        return $interval_ms > 0;
    }

    /**
     * Generate a self-contained inline JavaScript snippet that manages the
     * auto-refresh interval for a single chart container.
     *
     * The snippet is safe to embed in a <script> tag or pass to
     * wp_add_inline_script(). It does NOT depend on jQuery.
     *
     * @param int    $chart_id        Unique identifier for the chart (matches data-gt-chart-id).
     * @param int    $interval_ms     Refresh interval in milliseconds (minimum 5 000).
     * @param string $endpoint_url    Absolute URL to the AJAX endpoint that returns fresh chart data.
     * @return string  JavaScript snippet (no surrounding <script> tag).
     */
    public static function get_refresh_script( int $chart_id, int $interval_ms, string $endpoint_url ): string {
        if ( ! self::is_enabled( $interval_ms ) ) {
            return '';
        }

        $chart_id    = (int) $chart_id;
        $interval_ms = max( 5000, (int) $interval_ms );
        $url         = esc_url_raw( $endpoint_url );

        return <<<JS
(function () {
    'use strict';

    var chartId   = {$chart_id};
    var intervalMs = {$interval_ms};
    var endpoint  = '{$url}';

    var el = document.querySelector('[data-gt-chart-id="' + chartId + '"]');
    if (!el) { return; }

    // Per-element interval storage prevents multiple charts sharing a global.
    function getIntervalId() { return parseInt(el.dataset.gtIntervalId || '0', 10); }
    function setIntervalId(id) { el.dataset.gtIntervalId = String(id); }

    function doRefresh() {
        // Skip fetch when the tab is hidden — preserves interval timing.
        if (document.visibilityState === 'hidden') { return; }

        // Skip if element was removed from DOM between ticks.
        if (!el.isConnected) { return; }

        fetch(endpoint, { credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.json() : Promise.reject(res.status); })
            .then(function (data) {
                if (data && data.svg && el.isConnected) {
                    el.innerHTML = data.svg;
                }
            })
            .catch(function () {
                // Network or server error — log silently and let the next tick try again.
                // Do NOT call clearInterval here; the interval must survive failures.
                if (typeof console !== 'undefined') {
                    console.warn('[GT Chart] Refresh fetch failed for chart ' + chartId + '. Will retry.');
                }
            });
    }

    // Ensure no existing interval is running for this element (prevents accumulation).
    var previous = getIntervalId();
    if (previous) { clearInterval(previous); }
    setIntervalId(setInterval(doRefresh, intervalMs));

    // Clear the interval when the chart element is removed from the DOM.
    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function () {
            if (!el.isConnected) {
                clearInterval(getIntervalId());
                observer.disconnect();
            }
        });
        if (el.parentNode) {
            observer.observe(el.parentNode, { childList: true });
        }
    }
}());
JS;
    }

    /**
     * Resolve the active frontend script handle.
     *
     * When the frontend bundle is enqueued (use_frontend_bundle = true, #1658)
     * the handle is 'gravity-tables-frontend-bundle'. Attaching inline scripts
     * to the un-enqueued 'gravity-tables-frontend' handle silently drops them
     * because WordPress only outputs inline scripts for enqueued handles. (#1712)
     */
    private static function resolve_frontend_handle(): string {
        if ( wp_script_is( 'gravity-tables-frontend-bundle', 'enqueued' )
            || wp_script_is( 'gravity-tables-frontend-bundle', 'done' ) ) {
            return 'gravity-tables-frontend-bundle';
        }
        return 'gravity-tables-frontend';
    }

    /**
     * Enqueue the refresh script for a chart via wp_add_inline_script.
     *
     * @param int    $chart_id     Matches the data-gt-chart-id attribute of the chart container.
     * @param int    $interval_ms  Refresh interval in milliseconds.
     * @param string $endpoint_url Absolute refresh endpoint URL.
     */
    public static function enqueue( int $chart_id, int $interval_ms, string $endpoint_url ): void {
        if ( ! self::is_enabled( $interval_ms ) ) {
            return;
        }

        $script = self::get_refresh_script( $chart_id, $interval_ms, $endpoint_url );
        if ( $script === '' ) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $handle = self::resolve_frontend_handle();
        if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'done' ) ) {
            wp_add_inline_script( $handle, $script );
        } else {
            add_action( 'wp_footer', static function () use ( $script ) {
                // @codeCoverageIgnoreStart
                echo '<script>' . $script . '</script>' . "\n"; // phpcs:ignore
                // @codeCoverageIgnoreEnd
            }, 99 );
        }
    }
}
