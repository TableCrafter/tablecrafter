/**
 * TableCrafter — frontend/ssp.js
 *
 * Server-side processing entries pipeline. #832 slice 2 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - loadEntriesServerSide()
 *       AJAX to `gt_server_side_entries` with the
 *       DataTables-compatible draw/start/length envelope. Increments
 *       `self._ssDrawCounter` per call. Adds a loading row + class
 *       while in flight. On success: stores `totalEntries` +
 *       `totalFiltered`, calls `renderSSPEntries(response.data)`,
 *       calls `updatePagination(totalFiltered)`. On missing
 *       recordsTotal or AJAX failure: renders an inline error
 *       message into the tbody.
 *
 *   - renderSSPEntries(rows)
 *       Renders the SSP response payload. `rows` is an array of
 *       arrays of already-rendered cell strings (server-side
 *       formatted). Empty / null arrays render a "no entries"
 *       placeholder cell that spans every column.
 *       Null/undefined cell values render as empty string (avoids
 *       literal "null" / "undefined" leaking into the table).
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery || window.$;

    Object.assign(window.GravityTable.prototype, {

        loadEntriesServerSide: function () {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);
            var $tbody = $wrapper.find('tbody');

            if (!this._ssDrawCounter) {
                this._ssDrawCounter = 0;
            }
            this._ssDrawCounter += 1;
            var draw = this._ssDrawCounter;

            var start  = (this.currentPage - 1) * this.config.per_page;
            var length = this.config.per_page;

            $wrapper.addClass('gt-table-loading');
            $tbody.html('<tr class="gt-loading-row"><td colspan="' + ($wrapper.find('thead th').length) + '"><div class="gt-loading">' + ((this.config && this.config.pagination_labels && this.config.pagination_labels.loading) || 'Loading entries...') + '</div></td></tr>');

            var data = {
                action: 'gt_server_side_entries',
                nonce:    this.config.nonce,
                table_id: this.config.table_id,
                'draw':   draw,
                'start':  start,
                'length': length,
                search: { value: this.searchTerm || '' },
                order: [{ column: 0, dir: this.sortOrder || 'desc' }]
            };

            $.extend(data, this.filters);

            $.post(this.config.ajax_url, data, function (response) {
                $wrapper.removeClass('gt-table-loading');
                if (response && typeof response.recordsTotal !== 'undefined') {
                    self.totalEntries = parseInt(response.recordsTotal, 10) || 0;
                    self.totalFiltered = parseInt(response.recordsFiltered, 10) || self.totalEntries;
                    // #1735 — store server-supplied per-column bar maxes so
                    // renderSSPEntries can scale bars against the full filtered set.
                    self._sspBarMaxes = (response.bar_maxes && typeof response.bar_maxes === 'object')
                        ? response.bar_maxes
                        : {};
                    self.renderSSPEntries(response.data || []);
                    self.updatePagination(self.totalFiltered);
                } else {
                    $tbody.html('<tr><td colspan="' + ($wrapper.find('thead th').length) + '"><div class="gt-error">Server-side load error</div></td></tr>');
                }
            }).fail(function () {
                $wrapper.removeClass('gt-table-loading');
                $tbody.html('<tr><td colspan="' + ($wrapper.find('thead th').length) + '"><div class="gt-error">Error loading entries</div></td></tr>');
            });
        },

        renderSSPEntries: function (rows) {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);
            var $tbody = $wrapper.find('tbody');
            var html = '';
            if (!rows || rows.length === 0) {
                html = '<tr><td colspan="' + ($wrapper.find('thead th').length) + '" class="gt-no-entries">' + ((self.config.pagination_labels && self.config.pagination_labels.no_results) || self.config.no_entries_text || 'No entries found.') + '</td></tr>';
            } else {
                // #1735 — grab server-supplied bar maxes (set by loadEntriesServerSide).
                var barMaxes = (self._sspBarMaxes && typeof self._sspBarMaxes === 'object')
                    ? self._sspBarMaxes
                    : {};
                var columns = (self.config && self.config.columns) || [];
                for (var i = 0; i < rows.length; i++) {
                    html += '<tr>';
                    for (var j = 0; j < rows[i].length; j++) {
                        var cellVal = rows[i][j] !== null && rows[i][j] !== undefined ? rows[i][j] : '';
                        var tdAttrs = '';
                        // Emit bar attributes when the server supplied a max for this column.
                        var col = columns[j];
                        var fieldId = col ? (col.field_id !== undefined ? String(col.field_id) : null) : null;
                        if (
                            fieldId !== null &&
                            barMaxes[fieldId] > 0 &&
                            typeof self.computeDataBarParams === 'function'
                        ) {
                            var colCfg = self.config.column_config && self.config.column_config[fieldId];
                            var barParams = self.computeDataBarParams(
                                self.config,
                                fieldId,
                                colCfg,
                                cellVal,
                                { barMax: barMaxes[fieldId] }
                            );
                            if (barParams) {
                                tdAttrs = ' data-gt-bar-pct="' + barParams.pct + '"' +
                                          ' style="--gt-bar-pct:' + barParams.pct + ';--gt-bar-color:' + barParams.color + ';"';
                            }
                        }
                        html += '<td' + tdAttrs + '>' + cellVal + '</td>';
                    }
                    html += '</tr>';
                }
            }
            $tbody.html(html);
        }

    });

})(window);
