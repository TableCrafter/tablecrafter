/**
 * TableCrafter — frontend/data-bars.js
 *
 * Data Bars (#1731): Pro-gated, per-column, CSS-only in-cell horizontal
 * value bars for numeric columns.
 *
 * Design: the bar is emitted ONLY as a `data-gt-bar-pct` attribute plus
 * the `--gt-bar-pct` / `--gt-bar-color` CSS custom properties on the
 * existing <td>, driving a low-opacity `::after` underlay (see
 * frontend.css). NO markup is ever injected inside the cell, so the
 * column-totals row (totals.js), CSV/Excel/PDF export, conditional
 * formatting, inline editing, and card-view labels all keep reading the
 * unchanged bare number.
 *
 * Two pure helpers attached to GravityTable.prototype via Object.assign
 * (idiomatic with resolveCellEditability / computeColumnTotal):
 *
 *   - computeBarMaxes(entries, config)
 *       Page-scoped per-column max over the in-memory entries array.
 *       Returns { fieldId: maxNumber } only for enabled, numeric-type
 *       columns with a positive domain. Returns {} (no bars) for
 *       server-side processing, since a page-scoped max is meaningless
 *       there. Reuses the shared gtParseNumeric coercion.
 *
 *   - computeDataBarParams(config, fieldId, colCfg, rawValue, ctx)
 *       Per-cell gate + clamp. Returns { pct (0-100 int), color } or
 *       null. ctx = { barMax, isFileUpload, hasLinkSettings, hasCellBg }.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    // Mirrors the numeric-column set used by totals.js (line 92) so a
    // bar and its column total always agree on what counts as numeric.
    var NUMERIC_TYPES = ['number', 'quantity', 'total', 'calculation'];
    var DEFAULT_COLOR = '#3b82f6';

    function isNumericType(colCfg) {
        return !!colCfg && NUMERIC_TYPES.indexOf(colCfg.type) !== -1;
    }

    Object.assign(window.GravityTable.prototype, {

        computeBarMaxes: function (entries, config) {
            var self = this;
            var out = {};
            config = config || {};
            var bars = config.column_data_bars;
            // No bars configured or no rows: nothing to scale against.
            // SSP guard: page-scoped max is wrong for server-side processing,
            // UNLESS the server has already supplied bar_column_maxes for this
            // response (#1733). In that case, render-entries.js will merge
            // bar_column_maxes into _barMax after computeBarMaxes returns, so
            // we still need to populate the map for any bars the server
            // didn't cover — but only when server_bar_max_available is set.
            if (!bars || !entries || !entries.length) {
                return out;
            }
            if (config.processing_mode === 'server' && !config.server_bar_max_available) {
                return out;
            }
            var columnConfig = config.column_config || {};
            // #1738 — sparkline series collector.
            var seriesMap = {};
            for (var fieldId in bars) {
                if (!Object.prototype.hasOwnProperty.call(bars, fieldId)) continue;
                if (!bars[fieldId] || !bars[fieldId].enabled) continue;
                if (!isNumericType(columnConfig[fieldId])) continue;
                var max = null;
                var sparkline = !!bars[fieldId].sparkline;
                var series = sparkline ? [] : null;
                for (var i = 0; i < entries.length; i++) {
                    var n = self.gtParseNumeric(entries[i][fieldId]);
                    if (n !== null && (max === null || n > max)) {
                        max = n;
                    }
                    if (sparkline && n !== null) {
                        series.push(n);
                    }
                }
                // Only a positive domain yields a meaningful fill.
                if (max !== null && max > 0) {
                    out[fieldId] = max;
                }
                // #1738 — persist sparkline series on the instance.
                if (sparkline && series && series.length) {
                    seriesMap[fieldId] = series;
                }
            }
            if (Object.keys(seriesMap).length) {
                self._barSeries = seriesMap;
            }
            return out;
        },

        /**
         * #1734 — Data Bars: pivot view support.
         *
         * Computes a page-scoped max for each aggregate pivot column so
         * renderPivotRowsHtml can scale in-cell bars against it.
         *
         * @param {Array}  pivotRows  Aggregated row objects (keys like `12_sum`).
         * @param {Array}  cols       pivot column descriptors from pivotColumns().
         * @param {Object} config     Table config (column_data_bars, column_config,
         *                            pivot_config).
         * @returns {Object}  { '<col>_<op>': maxNumber } for enabled numeric fields
         *                    with a positive domain. Returns {} when nothing qualifies.
         */
        computePivotBarMaxes: function (pivotRows, cols, config) {
            var self = this;
            var out = {};
            config = config || {};
            var bars = config.column_data_bars;
            if (!bars || !pivotRows || !pivotRows.length || !cols || !cols.length) {
                return out;
            }
            var columnConfig = config.column_config || {};
            var groupByKey = (config.pivot_config && String(config.pivot_config.group_by)) || null;

            for (var c = 0; c < cols.length; c++) {
                var col = cols[c];
                var pivotKey = col.key;
                // Skip the group-by column — it is a label, not a numeric aggregate.
                if (pivotKey === groupByKey) { continue; }

                // Derive the originating field ID from the pivot key format `<col>_<op>`.
                var underscoreIdx = pivotKey.lastIndexOf('_');
                if (underscoreIdx === -1) { continue; }
                var fieldId = pivotKey.slice(0, underscoreIdx);

                // Gate 1: bars must be enabled for this originating field.
                if (!bars[fieldId] || !bars[fieldId].enabled) { continue; }
                // Gate 2: originating field must be numeric.
                if (!isNumericType(columnConfig[fieldId])) { continue; }

                // Scan pivot rows for the maximum value in this pivot column.
                var max = null;
                for (var i = 0; i < pivotRows.length; i++) {
                    var row = pivotRows[i] || {};
                    var raw = Object.prototype.hasOwnProperty.call(row, pivotKey) ? row[pivotKey] : null;
                    if (raw === null || raw === undefined) { continue; }
                    var n = self.gtParseNumeric(String(raw));
                    if (n !== null && (max === null || n > max)) {
                        max = n;
                    }
                }
                // Only a positive domain yields a meaningful fill.
                if (max !== null && max > 0) {
                    out[pivotKey] = max;
                }
            }
            return out;
        },

        computeDataBarParams: function (config, fieldId, colCfg, rawValue, ctx) {
            config = config || {};
            ctx = ctx || {};
            var bars = config.column_data_bars && config.column_data_bars[fieldId];
            if (!bars || !bars.enabled) return null;
            // Structural suppressions (see #1731 out-of-scope list).
            // SSP mode is allowed when the caller supplies a server-scoped barMax
            // via ctx.barMax (set by renderSSPEntries from _sspBarMaxes — #1735).
            if (config.processing_mode === 'server' && !(ctx.barMax && ctx.barMax > 0)) return null;
            if (config.column_auto_merge && config.column_auto_merge[fieldId]) return null;
            if (!isNumericType(colCfg)) return null;
            if (ctx.isFileUpload || ctx.hasLinkSettings || ctx.hasCellBg) return null;
            // Value gate: empty / non-numeric / server-HTML passthrough.
            if (rawValue === null || rawValue === undefined || rawValue === '') return null;
            if (String(rawValue).charAt(0) === '<') return null;
            var max = ctx.barMax;
            if (!(typeof max === 'number' && max > 0)) return null;
            var v = this.gtParseNumeric(rawValue);
            if (v === null) return null;
            var pct = Math.round((v / max) * 100);
            if (pct < 0) pct = 0;
            if (pct > 100) pct = 100;
            var color = (bars.color && String(bars.color)) || DEFAULT_COLOR;

            // #1738 — visual sub-options.
            var gradient  = !!bars.gradient;
            var bipolar   = !!bars.bipolar;
            var result    = { pct: pct, color: color, gradient: gradient, bipolar: bipolar };

            // Bipolar: compute signed pct in range [-100, +100].
            if (bipolar) {
                var barMin    = (typeof ctx.barMin === 'number') ? ctx.barMin : 0;
                var signedPct = 0;
                if (v >= 0) {
                    signedPct = (max > 0) ? Math.round((v / max) * 100) : 0;
                } else {
                    signedPct = (barMin < 0) ? Math.round((v / Math.abs(barMin)) * 100) : 0;
                }
                if (signedPct > 100)  { signedPct = 100; }
                if (signedPct < -100) { signedPct = -100; }
                result.signedPct = signedPct;
            }

            return result;
        }

    });

})(window);
