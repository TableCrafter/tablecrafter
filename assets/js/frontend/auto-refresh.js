/**
 * TableCrafter - frontend/auto-refresh.js
 *
 * Configurable table auto-refresh on interval (#1743, Free).
 * Last-updated timestamp display (#1912, Free).
 *
 * Public surface (GravityTable.prototype):
 *
 *   startAutoRefresh()
 *     Reads this.config.auto_refresh_interval (seconds). Enforces a 5-second
 *     minimum. Skips the loadEntries call when an inline edit is in progress
 *     (.gt-editing is present in the DOM). Clears any existing timer first
 *     so a double-call never stacks timers. Calls updateLastRefreshed() after
 *     each successful reload.
 *
 *   stopAutoRefresh()
 *     Clears the interval and nulls _autoRefreshTimer. Safe to call multiple
 *     times or before startAutoRefresh has been called.
 *
 *   updateLastRefreshed()
 *     Stamps the .gt-last-updated element inside the current table wrapper
 *     with the time of the most recent refresh. Text is updated live by a
 *     secondary 30-second interval so the "X seconds ago" stays accurate.
 *
 * Called from init.js after table initialisation when
 * config.auto_refresh_interval > 0.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var MIN_INTERVAL_MS = 5000; // 5 seconds floor

    GravityTable.prototype.startAutoRefresh = function () {
        var self = this;
        var intervalSec = parseInt(this.config && this.config.auto_refresh_interval, 10) || 0;
        if (intervalSec <= 0) { return; }

        var intervalMs = Math.max(intervalSec * 1000, MIN_INTERVAL_MS);

        // Clear any existing timer before setting a new one
        this.stopAutoRefresh();

        this._autoRefreshTimer = setInterval(function () {
            // Skip refresh while an inline edit is in progress
            if (document.querySelector('.gt-edit-input')) { return; }
            if (typeof self.loadEntries === 'function') {
                self.loadEntries();
                self.updateLastRefreshed();
            }
        }, intervalMs);
    };

    GravityTable.prototype.stopAutoRefresh = function () {
        if (this._autoRefreshTimer) {
            clearInterval(this._autoRefreshTimer);
            this._autoRefreshTimer = null;
        }
        if (this._lastUpdatedTimer) {
            clearInterval(this._lastUpdatedTimer);
            this._lastUpdatedTimer = null;
        }
    };

    GravityTable.prototype.updateLastRefreshed = function () {
        var self = this;
        var wrapperId = this.wrapperId;
        if (!wrapperId) { return; }

        var wrapper = document.getElementById(wrapperId);
        if (!wrapper) { return; }

        var el = wrapper.querySelector('.gt-last-updated');
        if (!el) { return; }

        var refreshedAt = new Date();

        function formatAgo(then) {
            var secs = Math.floor((new Date() - then) / 1000);
            if (secs < 60) { return secs + 's ago'; }
            var mins = Math.floor(secs / 60);
            return mins + 'm ago';
        }

        function render() {
            el.textContent = 'Last refreshed ' + formatAgo(refreshedAt);
        }

        render();

        // Clear any previous relative-time ticker for this instance
        if (self._lastUpdatedTimer) {
            clearInterval(self._lastUpdatedTimer);
        }
        // Update the "X seconds ago" text every 30 seconds
        self._lastUpdatedTimer = setInterval(render, 30000);
    };

})(window);
