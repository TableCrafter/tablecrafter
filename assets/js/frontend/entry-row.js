/**
 * TableCrafter - frontend/entry-row.js
 *
 * Per-row HTML builder + no-entries fallback. #832 slice 11 of N.
 *
 * Two helpers attached to GravityTable.prototype via Object.assign:
 *
 *   - renderEntryRowHtml(entry, ctx)
 *       Pure-ish helper (no jQuery, no DOM access - all DOM probes
 *       are pre-resolved by the caller into the `ctx` flags object).
 *       Returns the `<tr>…</tr>` markup for one entry plus the
 *       optional hidden detail-row sibling.
 *
 *       ctx shape:
 *         {
 *           rowLinkTpl,          // #567 row-link template string (or '')
 *           hasSelectionHeader,  // emit gt-checkbox-cell when true
 *           hasDetailHeader,     // emit detail-toggle TD + detail-row sibling
 *           hasActionsHeader,    // emit actions cell
 *           detailColCount,      // colspan for the hidden detail row
 *         }
 *
 *   - renderNoEntriesRowHtml(colCount, noResultsLabel)
 *       Pure helper. Returns the
 *       `<tr><td colspan="N"><div class="gt-no-entries">…</div></td></tr>`
 *       fallback row used when data.entries is empty.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        renderEntryRowHtml: function (entry, ctx) {
            var self = this;
            ctx = ctx || {};

            var rowExtraAttrs = self.resolveRowLinkAttrs(ctx.rowLinkTpl || '', entry);
            var html = '<tr data-entry-id="' + entry.entry_id + '"' + rowExtraAttrs + '>';

            // Selection checkbox.
            if (ctx.hasSelectionHeader) {
                html += '<td class="gt-checkbox-cell"><input type="checkbox" class="gt-entry-checkbox" value="' + entry.entry_id + '"></td>';
            }

            // #556 chevron toggle TD.
            var detailRowId = '';
            if (ctx.hasDetailHeader) {
                detailRowId = self.wrapperId + '-detail-' + entry.entry_id;
                html += self.renderDetailToggleCellHtml(entry.entry_id, detailRowId);
            }

            // #2340 - index cell: 1-based counter, renumbers on sort/filter/page.
            if (ctx.hasIndexHeader) {
                html += '<td class="gt-index-cell">' + (ctx.rowIndex || 0) + '</td>';
            }

            // Per-column cell dispatch (#832 slice 10).
            var columns = (self.config && self.config.columns) ? self.config.columns : [];
            for (var i = 0; i < columns.length; i++) {
                html += self.renderEntryCellHtml(entry, columns[i]);
            }

            // Actions cell (#832 slice 7).
            if (ctx.hasActionsHeader) {
                html += self.renderActionsCellHtml(entry.entry_id, self.config);
            }

            html += '</tr>';

            // Hidden detail-row sibling (#556 / #832 slice 9).
            if (ctx.hasDetailHeader) {
                var detailMap = (self.config && self.config.column_detail_only) ? self.config.column_detail_only : {};
                var columnConfig = (self.config && self.config.column_config) ? self.config.column_config : {};
                html += self.renderDetailRowHtml(entry, columns, detailMap, columnConfig, detailRowId, ctx.detailColCount);
            }

            return html;
        },

        renderNoEntriesRowHtml: function (colCount, noResultsLabel) {
            var label = noResultsLabel || 'No entries found';
            return '<tr><td colspan="' + colCount + '"><div class="gt-no-entries">' + label + '</div></td></tr>';
        }

    });

})(window);
