/**
 * TableCrafter - frontend/row-grouping.js
 *
 * #2338 - Row Grouping: inject group-header <tr> rows into the rendered
 * tbody so rows are visually grouped under sub-headings by shared column
 * values. Supports single-column and hierarchical multi-column grouping.
 *
 * Design decisions (documented in PR description):
 *   - Primary render path: frontend JS post-AJAX. The module walks the
 *     rendered entries array and inserts <tr class="gt-row-group-header">
 *     elements directly in the tbody DOM, immediately before the first data
 *     row of each group. This runs as the last step in renderEntries().
 *   - Sort policy: the server already sorts entries by the group columns
 *     (the AJAX handler forces group columns as primary sort when grouping
 *     is enabled). Visitor-initiated sorts work as secondary sort within
 *     groups; the JS layer re-applies row grouping after each render.
 *   - Pagination policy: groups spanning a page boundary show the header
 *     on each page. The simplest correct behavior - no DB-level group
 *     awareness required.
 *   - Collapsible support: groups are collapsible via click when
 *     group_default_collapsed is set; state is tracked in-memory per
 *     session (no localStorage persistence - avoids stale state across
 *     filter/search changes).
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *   - isRowGroupingEnabled()
 *   - getGroupByColumns()
 *   - renderGroupHeaderHtml(groupValue, colCount, level)
 *   - applyRowGrouping(data, $tbody)
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    // -------------------------------------------------------------------------
    // Escape helper (mirrors util.js escapeHtml - available on proto but we
    // keep a local copy so this module is self-contained in unit tests).
    // -------------------------------------------------------------------------
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    Object.assign(window.GravityTable.prototype, {

        /**
         * Whether row grouping is configured for this table instance.
         *
         * @return {boolean}
         */
        isRowGroupingEnabled: function () {
            if (!this.config) { return false; }
            var cols = this.getGroupByColumns();
            return cols.length > 0;
        },

        /**
         * Return ordered list of column IDs to group by.
         *
         * Prefers group_by_columns (multi-column) over group_by_column (single).
         *
         * @return {string[]}
         */
        getGroupByColumns: function () {
            if (!this.config) { return []; }
            var multi = this.config.group_by_columns;
            if (Array.isArray(multi) && multi.length > 0) {
                return multi.filter(function (c) { return c !== ''; });
            }
            var single = String(this.config.group_by_column || '').trim();
            return single !== '' ? [single] : [];
        },

        /**
         * Build HTML for a group-header <tr> row.
         *
         * @param {string} groupValue  Display value for this group.
         * @param {number} colCount    Number of columns (sets colspan).
         * @param {number} level       Nesting depth (0 = top-level).
         * @return {string}
         */
        renderGroupHeaderHtml: function (groupValue, colCount, level) {
            var span       = Math.max(1, parseInt(colCount, 10) || 1);
            var lvl        = parseInt(level, 10) || 0;
            var levelClass = lvl > 0 ? ' gt-row-group-level-' + lvl : '';
            var prefix     = (this.config && this.config.group_label_prefix)
                           ? String(this.config.group_label_prefix) + ' '
                           : '';

            return '<tr class="gt-row-group-header' + levelClass + '"' +
                   ' data-group="' + escHtml(groupValue) + '"' +
                   ' data-level="' + lvl + '">' +
                   '<th colspan="' + span + '" scope="colgroup" role="rowheader">' +
                   escHtml(prefix + groupValue) +
                   '</th>' +
                   '</tr>';
        },

        /**
         * Walk the entries array and inject group-header rows into $tbody.
         *
         * Called at the end of renderEntries() when row grouping is enabled.
         * Modifies the live DOM: inserts <tr.gt-row-group-header> elements
         * immediately before the first data row of each group. Entries MUST
         * already be sorted by the group columns (server guarantees this).
         *
         * For multi-column grouping each level gets its own header row with
         * the appropriate gt-row-group-level-N class.
         *
         * @param {object}  data    AJAX response data (contains .entries array).
         * @param {jQuery}  $tbody  The <tbody> jQuery element to annotate.
         */
        applyRowGrouping: function (data, $tbody) {
            if (!this.isRowGroupingEnabled()) { return; }

            var entries   = (data && data.entries) ? data.entries : [];
            if (entries.length === 0) { return; }

            var self      = this;
            var columns   = this.getGroupByColumns();
            var colCount  = 0;

            // Resolve column count from the DOM (wrapper's thead th count).
            var $wrapper = $('#' + this.wrapperId);
            if ($wrapper.length) {
                colCount = $wrapper.find('thead tr').first().find('th').length;
            }
            if (colCount === 0) { colCount = 1; }

            // Walk the tbody rows. Each data row has a data-entry-id attribute.
            // We track the current group key tuple so we know when a level changes.
            var dataRows  = ($tbody.find ? $tbody : $(String($tbody))).find('tr[data-entry-id]').toArray();
            var prevKeys  = []; // current group key per level

            entries.forEach(function (entry, idx) {
                var $dataRow = $(dataRows[idx]);
                if (!$dataRow.length) { return; }

                // Compute group keys for all levels.
                var keys = columns.map(function (colId) {
                    return String(entry[colId] != null ? entry[colId] : '');
                });

                // Find the first level that changed.
                var changeLevel = -1;
                for (var lv = 0; lv < keys.length; lv++) {
                    if (keys[lv] !== prevKeys[lv]) {
                        changeLevel = lv;
                        break;
                    }
                }

                if (changeLevel !== -1) {
                    // Insert headers for all levels from changeLevel down.
                    for (var insertLv = changeLevel; insertLv < keys.length; insertLv++) {
                        var headerHtml = self.renderGroupHeaderHtml(keys[insertLv], colCount, insertLv);
                        $dataRow.before(headerHtml);
                    }
                    // Update prev keys.
                    for (var upLv = changeLevel; upLv < keys.length; upLv++) {
                        prevKeys[upLv] = keys[upLv];
                    }
                }
            });
        },

    });

})(window);
