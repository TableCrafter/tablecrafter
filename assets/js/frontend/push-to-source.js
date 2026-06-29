/**
 * TableCrafter — frontend/push-to-source.js
 *
 * #613 phase 2 slice 3 — pushRowToSource prototype method on GravityTable.
 * Calls the gt_push_row AJAX endpoint shipped in v4.197.0.
 *
 * Surface:
 *   - pushRowToSource(rowId, payload, [onResponse]) — posts the row update
 *     to the configured external data source (currently JSON; Airtable +
 *     Notion follow in later slices). Optional callback fires with the
 *     response object (success or error envelope).
 *
 * @since 4.198.0
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        // Stub constructor so this module can load in any order.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    window.GravityTable.prototype.pushRowToSource = function (rowId, payload, onResponse, opts) {
        var self = this;
        opts = opts || {};
        // #613 phase 2 slice 17 (v4.212.0) — auto-retry with backoff.
        // _retryAttempt is used internally to bound recursion to 1 retry.
        var retryAttempt = typeof opts._retryAttempt === 'number' ? opts._retryAttempt : 0;
        var retryDelayMs = typeof opts._retryDelay === 'number' ? opts._retryDelay : 1100;

        // Defensive guards. The server-side has the same checks (typed
        // WP_Errors); this avoids the round-trip cost for obviously-invalid
        // inputs.
        if (!rowId || typeof rowId !== 'string' || rowId === '') {
            return;
        }
        if (!payload || typeof payload !== 'object' || Object.keys(payload).length === 0) {
            return;
        }

        var data = {
            action:   'gt_push_row',
            nonce:    self.config && self.config.nonce ? self.config.nonce : '',
            table_id: self.config && self.config.table_id ? self.config.table_id : 0,
            row_id:   rowId,
            payload:  payload,
        };

        // #613 phase 2 slice 16 (v4.211.0) — conflict-detection baseline.
        // Send the last-known baseline so the server can refuse the push
        // if the row has been modified since this client loaded it.
        // Backward compat: callers that don't maintain self._pushBaselines
        // see no baseline_lastmod field, so the server-side check no-ops.
        if (self._pushBaselines && typeof self._pushBaselines[rowId] !== 'undefined') {
            data.baseline_lastmod = self._pushBaselines[rowId];
        }

        $.post(self.config.ajax_url, data, function (response) {
            // #613 — bump the baseline on success so subsequent pushes use
            // a fresh token. The server returns the new baseline in
            // response.data.new_baseline (added in v4.211.0).
            if (response && response.success && self._pushBaselines
                && response.data && typeof response.data.new_baseline !== 'undefined') {
                self._pushBaselines[rowId] = response.data.new_baseline;
            }

            // #613 phase 2 slice 17 (v4.212.0) — auto-retry once on
            // rate_limited. The server-side rate window is 1s (per
            // TC_Push_Rate_Limiter::WINDOW_SECONDS); waiting just past
            // 1s should clear it. Bound to one retry so a perpetually
            // throttled state surfaces the error instead of looping.
            if (response && response.success === false
                && response.data && response.data.code === 'rate_limited'
                && retryAttempt === 0) {
                var retry = function () {
                    // Use the prototype's method, not self's, so test stubs
                    // (plain ctx objects without the prototype chain) work too.
                    window.GravityTable.prototype.pushRowToSource.call(self, rowId, payload, onResponse, { _retryAttempt: 1, _retryDelay: retryDelayMs });
                };
                if (retryDelayMs > 0) {
                    setTimeout(retry, retryDelayMs);
                } else {
                    retry();
                }
                return;
            }

            if (typeof onResponse === 'function') {
                try {
                    onResponse(response);
                } catch (e) {
                    // Swallow user-callback errors so a bad onResponse can't
                    // poison the push flow.
                    if (window.console && window.console.error) {
                        window.console.error('GT pushRowToSource: callback threw', e);
                    }
                }
            }
        });
    };

})(window);
