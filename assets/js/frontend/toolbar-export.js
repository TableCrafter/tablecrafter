/**
 * TableCrafter — frontend/toolbar-export.js
 *
 * Toolbar export helpers: copy-to-clipboard + CSV/Excel/PDF download.
 * #832 slice 13 of N (with getVisibleTableData added in slice 17).
 *
 * Seven helpers attached to GravityTable.prototype via Object.assign:
 *
 *   - getVisibleTableData()
 *       Scrapes the live table thead + visible tbody rows into a 2D
 *       array of cell text values. Strips sort arrows + inline input
 *       widgets from header cells, collapses internal whitespace in
 *       body cells. Used as the data source for all five export
 *       paths below.
 *
 *   - toolbarBuildCSV(rows)
 *       Pure helper. Takes a 2D array of cell values, returns the RFC
 *       4180-style CSV string. Cells containing `"`, `,`, `\n`, or `\r`
 *       are double-quoted with inner `"` doubled. Rows are joined with
 *       CRLF.
 *
 *   - toolbarTriggerDownload(content, filename, mimeType)
 *       Wraps `content` in a Blob, builds a temporary `<a download>`,
 *       triggers a click, then revokes the object URL on a 100ms delay.
 *
 *   - toolbarCopyToClipboard()
 *       Reads visible table data via `this.getVisibleTableData()`, joins
 *       each row with tabs (for spreadsheet paste), and writes to the
 *       clipboard via navigator.clipboard.writeText when available,
 *       falling back to a hidden textarea + execCommand('copy').
 *
 *   - toolbarDownloadCSV()
 *       Orchestrator: getVisibleTableData → toolbarBuildCSV →
 *       toolbarTriggerDownload as text/csv.
 *
 *   - toolbarDownloadExcel()
 *       Same as toolbarDownloadCSV but prepends a UTF-8 BOM (`﻿`)
 *       and serves the file as `.xls` with application/vnd.ms-excel.
 *
 *   - toolbarDownloadPDF()
 *       Routes through the existing #531 Print button when present so
 *       the user gets print-all-rows + afterprint-restore behavior.
 *       Falls back to window.print() when the Print button is
 *       suppressed via #521 show_print=false.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    Object.assign(window.GravityTable.prototype, {

        getVisibleTableData: function () {
            var $wrapper = $('#' + this.wrapperId);
            var rows = [];
            var headers = [];
            // #2340 — track which column indices are excluded from export so
            // the corresponding td cells are skipped in the data rows.
            // Columns marked data-export-exclude="true" (e.g. the index column)
            // must not appear in CSV/copy/Excel exports.
            var excludedColIndices = [];
            var colIdx = 0;

            $wrapper.find('.gt-table thead th').each(function () {
                if ($(this).attr('data-export-exclude') === 'true') {
                    excludedColIndices.push(colIdx);
                } else {
                    var text = $(this).clone().find('.gt-sort-arrows, input').remove().end().text().trim();
                    headers.push(text);
                }
                colIdx++;
            });
            if (headers.length) rows.push(headers);

            $wrapper.find('.gt-table tbody tr:visible').each(function () {
                var row = [];
                $(this).find('td').each(function (tdIdx) {
                    if (excludedColIndices.indexOf(tdIdx) === -1) {
                        row.push($(this).text().trim().replace(/\s+/g, ' '));
                    }
                });
                if (row.length) rows.push(row);
            });

            return rows;
        },

        toolbarBuildCSV: function (rows) {
            return rows.map(function (r) {
                return r.map(function (cell) {
                    var s = String(cell).replace(/"/g, '""');
                    return /[",\n\r]/.test(s) ? '"' + s + '"' : s;
                }).join(',');
            }).join('\r\n');
        },

        toolbarTriggerDownload: function (content, filename, mimeType) {
            var blob = new Blob([content], { type: mimeType });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            setTimeout(function () {
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }, 100);
        },

        toolbarCopyToClipboard: function () {
            var rows = this.getVisibleTableData();
            var text = rows.map(function (r) { return r.join('\t'); }).join('\n');

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
        },

        toolbarDownloadCSV: function () {
            var rows = this.getVisibleTableData();
            var csv = this.toolbarBuildCSV(rows);
            this.toolbarTriggerDownload(csv, 'table-export.csv', 'text/csv;charset=utf-8;');
        },

        toolbarDownloadExcel: function () {
            // Build a CSV with BOM for Excel compatibility; use vnd.ms-excel MIME.
            var rows = this.getVisibleTableData();
            var csv = '﻿' + this.toolbarBuildCSV(rows); // BOM for Excel
            this.toolbarTriggerDownload(csv, 'table-export.xls', 'application/vnd.ms-excel;charset=utf-8;');
        },

        toolbarDownloadPDF: function () {
            // Route through the existing #531 Print button when present.
            // Fall back to window.print() when the Print button is suppressed
            // via #521 show_print=false. See v4.8.15 — #636 closed honestly.
            var $wrapper = $('#' + this.wrapperId);
            var $printBtn = $wrapper.find('.gt-print-btn').first();
            if ($printBtn.length) {
                $printBtn.trigger('click');
                return;
            }
            window.print();
        },

        // #2285 — JSON visible-rows export helpers.

        /**
         * toolbarBuildJSON(rows)
         *
         * Takes a 2D array of cell values (same shape as getVisibleTableData)
         * and returns a pretty-printed JSON string — an array of objects keyed
         * by the column headers from the first row.
         *
         * @param  {string[][]} rows  2D array; rows[0] is the header row.
         * @return {string}           JSON string.
         */
        toolbarBuildJSON: function (rows) {
            if (!rows.length) { return '[]'; }
            var headers = rows[0];
            var data = rows.slice(1).map(function (row) {
                var obj = {};
                headers.forEach(function (h, i) {
                    obj[h] = row[i] !== undefined ? row[i] : '';
                });
                return obj;
            });
            return JSON.stringify(data, null, 2);
        },

        /**
         * toolbarDownloadJSON()
         *
         * Orchestrator: getVisibleTableData → toolbarBuildJSON →
         * toolbarTriggerDownload as application/json.
         */
        toolbarDownloadJSON: function () {
            var rows = this.getVisibleTableData();
            var json = this.toolbarBuildJSON(rows);
            this.toolbarTriggerDownload(json, 'table-export.json', 'application/json;charset=utf-8;');
        }

    });

})(window);
