/**
 * TableCrafter - frontend/detail-row.js
 *
 * #556 expandable detail-rows render path. #832 slice 9 of N.
 *
 * Two helpers attached to GravityTable.prototype via Object.assign:
 *
 *   - renderDetailToggleCellHtml(entryId, detailRowId)
 *       Pure helper. Returns the `<td class="gt-detail-toggle-cell">`
 *       containing the chevron toggle button with aria-expanded="false",
 *       aria-controls pointing at the matching detail row, an
 *       aria-label, and a right-pointing chevron (&#9654;) glyph.
 *       Depends on `this.escapeHtml` for the aria-label.
 *
 *   - renderDetailRowHtml(entry, columns, detailMap, columnConfig,
 *                         detailRowId, detailColCount)
 *       Pure helper. Returns the hidden `<tr class="gt-detail-row">`
 *       sibling that renders all detail-only fields as a dl/dt/dd grid.
 *       Falls back to a `<div class="gt-detail-row-empty">` placeholder
 *       when no detail-flagged field has a value on this entry. Depends
 *       on `this.escapeHtml`.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        renderDetailToggleCellHtml: function (entryId, detailRowId) {
            var self = this;
            return '<td class="gt-detail-toggle-cell">'
                + '<button type="button" class="gt-detail-toggle"'
                + ' aria-expanded="false"'
                + ' aria-controls="' + detailRowId + '"'
                + ' aria-label="' + self.escapeHtml('Show row details') + '"'
                + ' data-entry-id="' + entryId + '">'
                + '<span class="gt-detail-toggle-chevron" aria-hidden="true">&#9654;</span>'
                + '</button></td>';
        },

        renderDetailRowHtml: function (entry, columns, detailMap, columnConfig, detailRowId, detailColCount) {
            var self = this;
            var detailCellsHtml = '';
            var anyDetailValue = false;

            for (var i = 0; i < columns.length; i++) {
                var fid = columns[i];
                if (!detailMap[fid]) { continue; }
                var rawVal = entry[fid];
                if (rawVal === undefined || rawVal === null || rawVal === '') { continue; }
                anyDetailValue = true;
                var detailColCfg = (columnConfig && columnConfig[fid]) ? columnConfig[fid] : {};
                var detailLabel = detailColCfg.label || fid;
                var detailVal = String(rawVal);
                var detailValHtml = detailVal.charAt(0) === '<' ? detailVal : self.escapeHtml(detailVal);
                detailCellsHtml += '<div class="gt-detail-row-cell">'
                    + '<dt class="gt-detail-row-label">' + self.escapeHtml(detailLabel) + '</dt>'
                    + '<dd class="gt-detail-row-value">' + detailValHtml + '</dd>'
                    + '</div>';
            }

            if (!anyDetailValue) {
                detailCellsHtml = '<div class="gt-detail-row-empty">' + self.escapeHtml('No additional details for this row.') + '</div>';
            }

            return '<tr class="gt-detail-row" id="' + detailRowId + '" hidden>'
                + '<td colspan="' + detailColCount + '">'
                + '<dl class="gt-detail-row-grid">' + detailCellsHtml + '</dl>'
                + '</td></tr>';
        }

    });

})(window);
