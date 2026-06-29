/**
 * TableCrafter — frontend/pivot-view.js
 *
 * #1596 — frontend pivot view + visitor raw/pivot toggle.
 *
 * The #562 slices shipped TC_Pivot_Service, the builder pivot config,
 * and the AJAX path that replaces entries with aggregated rows and
 * sets is_pivot=true on the response. No frontend code consumed the
 * flag: aggregated rows carry keys like `12_sum` that match no
 * configured column, so pivot-enabled tables rendered the group
 * column and blanks. This module renders the pivot column layout
 * (group-by + one column per aggregate) and injects a toolbar toggle
 * so visitors can flip between the pivot summary and the raw rows
 * (loadEntries sends pivot_view=raw; the AJAX block honors it).
 *
 * Pivot rows are read-only: no inline editing, selection, detail
 * chevrons, or row actions (aggregated rows have no entry_id).
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *   - isPivotResponse(data)
 *   - pivotColumns(pivotConfig, resolveLabel)
 *   - formatPivotValue(v)
 *   - renderPivotHeadHtml(cols)
 *   - renderPivotRowsHtml(rows, cols)
 *   - applyPivotView(data, $wrapper)
 *   - restoreRawHead($wrapper)
 *   - ensurePivotToggle($wrapper)
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var OP_LABELS = { sum: 'Sum', count: 'Count', avg: 'Avg', min: 'Min', max: 'Max' };

    Object.assign(window.GravityTable.prototype, {

        isPivotResponse: function (data) {
            return !!(data && data.is_pivot && data.pivot_config);
        },

        pivotColumns: function (pivotConfig, resolveLabel) {
            var resolve = function (id) {
                var label = typeof resolveLabel === 'function' ? resolveLabel(String(id)) : '';
                return (typeof label === 'string' && label !== '') ? label : String(id);
            };
            var cols = [{ key: String(pivotConfig.group_by), label: resolve(pivotConfig.group_by) }];
            var aggs = Array.isArray(pivotConfig.aggregates) ? pivotConfig.aggregates : [];
            for (var i = 0; i < aggs.length; i++) {
                var a = aggs[i];
                if (!a || !a.col || !a.op) { continue; }
                cols.push({
                    key: String(a.col) + '_' + String(a.op),
                    label: resolve(a.col) + ' (' + (OP_LABELS[a.op] || a.op) + ')'
                });
            }
            return cols;
        },

        formatPivotValue: function (v) {
            if (v === null || v === undefined) { return ''; }
            if (typeof v === 'number' && isFinite(v) && !Number.isInteger(v)) {
                return String(Math.round(v * 100) / 100);
            }
            return String(v);
        },

        renderPivotHeadHtml: function (cols) {
            var self = this;
            var parts = [];
            for (var i = 0; i < cols.length; i++) {
                parts.push('<th class="gt-pivot-th" data-pivot-key="' + self.escapeHtml(cols[i].key) + '">' + self.escapeHtml(cols[i].label) + '</th>');
            }
            return parts.join('');
        },

        /**
         * #1734 — pivot data-bar support.
         *
         * Optional third argument `barMaxes` is a map keyed by pivot column key
         * (e.g. `12_sum`) with the page-scoped numeric maximum, produced by
         * computePivotBarMaxes.  When a positive entry exists for a column the
         * <td> receives:
         *   data-gt-bar-pct="<0-100>"
         *   style="--gt-bar-pct:<N>;--gt-bar-color:#xxxxxx"
         * matching the same attribute surface as the regular data-bar path in
         * text-cell.js, so the existing ::after CSS underlay drives the fill
         * without any markup change inside the cell.
         */
        renderPivotRowsHtml: function (rows, cols, barMaxes) {
            var self = this;
            var DEFAULT_BAR_COLOR = '#3b82f6';
            var parts = [];
            barMaxes = barMaxes || {};
            for (var r = 0; r < rows.length; r++) {
                var row = rows[r] || {};
                parts.push('<tr class="gt-pivot-row">');
                for (var c = 0; c < cols.length; c++) {
                    var colKey = cols[c].key;
                    var raw = Object.prototype.hasOwnProperty.call(row, colKey) ? row[colKey] : null;
                    var displayText = self.escapeHtml(self.formatPivotValue(raw));

                    // Bar emission: only for aggregate columns that have a positive max.
                    var barAttr = '';
                    var barStyle = '';
                    var colMax = barMaxes[colKey];
                    if (typeof colMax === 'number' && colMax > 0 && raw !== null && raw !== undefined) {
                        var numVal = (typeof raw === 'number') ? raw
                            : (typeof self.gtParseNumeric === 'function' ? self.gtParseNumeric(String(raw)) : null);
                        if (numVal !== null) {
                            var pct = Math.round((numVal / colMax) * 100);
                            if (pct < 0) { pct = 0; }
                            if (pct > 100) { pct = 100; }
                            barAttr = ' data-gt-bar-pct="' + pct + '"';
                            barStyle = ' style="--gt-bar-pct:' + pct + ';--gt-bar-color:' + DEFAULT_BAR_COLOR + '"';
                        }
                    }

                    parts.push('<td class="gt-readonly-cell gt-pivot-cell"' + barAttr + barStyle + '>' + displayText + '</td>');
                }
                parts.push('</tr>');
            }
            return parts.join('');
        },

        /**
         * Swap the table into the pivot layout for an is_pivot
         * response: cache the raw thead once, render pivot head +
         * rows, latch the toggle into the toolbar.
         */
        applyPivotView: function (data, $wrapper) {
            var self = this;
            var $headRow = $wrapper.find('thead tr').first();
            if (typeof self._rawTheadRowHtml !== 'string') {
                self._rawTheadRowHtml = $headRow.html() || '';
            }
            // Resolve labels from the CACHED raw thead so they survive
            // the swap (th[data-field-id] carries the configured label).
            var probe = window.document.createElement('tr');
            probe.innerHTML = self._rawTheadRowHtml;
            var resolveLabel = function (fieldId) {
                var th = probe.querySelector('th[data-field-id="' + fieldId + '"]');
                if (!th) { return ''; }
                // First text chunk only — sort arrows / filter inputs
                // inside the th must not leak into the label.
                var txt = (th.textContent || '').trim();
                return txt.split('\n')[0].trim();
            };
            var cols = self.pivotColumns(data.pivot_config, resolveLabel);
            $headRow.html(self.renderPivotHeadHtml(cols));
            var $tbody = $wrapper.find('tbody');
            var rows = Array.isArray(data.entries) ? data.entries : [];
            // #1734 — compute per-pivot-column bar maxes before rendering rows
            // so each aggregate cell can scale its fill proportionally.
            // typeof guard keeps harnesses without data-bars.js on the old path.
            var barMaxes = (typeof self.computePivotBarMaxes === 'function')
                ? self.computePivotBarMaxes(rows, cols, self.config || {})
                : {};
            if ($tbody[0]) {
                $tbody[0].innerHTML = self.renderPivotRowsHtml(rows, cols, barMaxes);
            }
            self._pivotHeadActive = true;
            self._pivotConfigured = true;
            self.ensurePivotToggle($wrapper);
        },

        /**
         * Put the raw thead back when leaving pivot view (visitor
         * toggled to raw, or a non-pivot response arrived).
         */
        restoreRawHead: function ($wrapper) {
            if (this._pivotHeadActive && typeof this._rawTheadRowHtml === 'string') {
                $wrapper.find('thead tr').first().html(this._rawTheadRowHtml);
            }
            this._pivotHeadActive = false;
            if (this._pivotConfigured) {
                this.ensurePivotToggle($wrapper);
            }
        },

        /**
         * Inject (once) and label the visitor toggle. Clicking flips
         * this.pivotViewRaw and reloads entries; loadEntries sends
         * pivot_view=raw so the server skips aggregation.
         */
        ensurePivotToggle: function ($wrapper) {
            var self = this;
            var $controls = $wrapper.find('.gt-table-controls').first();
            if (!$controls.length) { return; }
            var $btn = $controls.find('.gt-pivot-toggle');
            if (!$btn.length) {
                var $ = window.jQuery || window.$;
                $btn = $('<button type="button" class="gt-pivot-toggle"></button>');
                $btn.on('click', function () {
                    self.pivotViewRaw = !self.pivotViewRaw;
                    self.currentPage = 1;
                    self.loadEntries();
                });
                $controls.append($btn);
            }
            $btn.text(self.pivotViewRaw ? 'View pivot summary' : 'View raw data');
        }

    });

})(window);
