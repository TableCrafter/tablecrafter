/**
 * TableCrafter - frontend/render-entries.js
 *
 * Central DOM render for entries. Twenty-second slice under #833.
 * One method, ~81 lines.
 *
 *   - renderEntries(data) - wipe tbody, render row HTML via
 *     renderEntryRowHtml (entry-row.js, slice 11), bindEntryEvents
 *     (bind-entry-events.js, slice 11), trigger gt-entries-loaded
 *     custom event for scroll indicators.
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.renderEntries = function (data) {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $tbody = $wrapper.find('tbody');
        var html = '';

        // #613 phase 2 slice 18 (v4.213.0) - initial baseline population.
        // If the server provides per-entry baselines (data.push_baselines),
        // copy them into self._pushBaselines so subsequent pushes carry
        // the right optimistic-concurrency token. Backward compat: when
        // the server doesn't provide push_baselines, _pushBaselines stays
        // undefined and pushRowToSource omits baseline_lastmod as before.
        if (data && data.push_baselines && typeof data.push_baselines === 'object') {
            self._pushBaselines = self._pushBaselines || {};
            for (var k in data.push_baselines) {
                if (Object.prototype.hasOwnProperty.call(data.push_baselines, k)) {
                    self._pushBaselines[String(k)] = String(data.push_baselines[k]);
                }
            }
        }

        // #1596 - pivot responses render through the pivot layout
        // (group-by + aggregate columns) instead of the raw column
        // set. typeof guards keep harnesses without the pivot module
        // on the old path.
        if (typeof self.isPivotResponse === 'function'
            && typeof self.applyPivotView === 'function'
            && self.isPivotResponse(data)) {
            self.applyPivotView(data, $wrapper);
            if (self.config.show_pagination) {
                self.updatePagination(data);
            }
            $(document).trigger('gt-entries-loaded-' + self.wrapperId);
            return;
        }
        if (typeof self.restoreRawHead === 'function') {
            self.restoreRawHead($wrapper);
        }

        // Data Bars (#1731): compute the page-scoped per-column max from
        // the in-memory entries BEFORE rendering rows, so each cell can
        // scale its fill against it. One O(rows) pass, zero extra DB
        // queries. typeof guard keeps harnesses without the module on the
        // old path. Returns {} for SSP / no-bars / no-entries.
        self._barMax = (typeof self.computeBarMaxes === 'function')
            ? self.computeBarMaxes(data.entries, self.config)
            : {};

        // #1733 - server-supplied full-filtered-set maxes override the
        // page-local max so bars scale against ALL matching rows, not
        // just the current page. bar_column_maxes is only present when
        // the Pro gate and column_data_bars are both active.
        if (data && data.bar_column_maxes && typeof data.bar_column_maxes === 'object') {
            for (var barFid in data.bar_column_maxes) {
                if (Object.prototype.hasOwnProperty.call(data.bar_column_maxes, barFid)) {
                    self._barMax[barFid] = data.bar_column_maxes[barFid];
                }
            }
        }

        if (data.entries && data.entries.length > 0) {
            // Per-row HTML builder moved to assets/js/frontend/entry-row.js (#832 slice 11).
            // DOM probes resolved once here so the helper stays jQuery-free.
            // #2340 - rowOffset: (page-1)*perPage so index cells show 1-based
            // global counter that renumbers on every sort/filter/page change.
            var rowOffset = (self.currentPage - 1) * (self.config.per_page || 25);
            var ctx = {
                rowLinkTpl: (self.config && self.config.row_link_template) ? String(self.config.row_link_template) : '',
                hasSelectionHeader: $wrapper.find('.gt-selection-header').length > 0,
                hasDetailHeader: $wrapper.find('.gt-detail-toggle-header').length > 0,
                hasActionsHeader: $wrapper.find('.gt-actions-header').length > 0,
                hasIndexHeader: $wrapper.find('.gt-index-header').length > 0,
                detailColCount: $wrapper.find('thead tr').first().find('th').length || $wrapper.find('thead th').length,
            };
            // #1049 Option 2 v4.222.0 - hot-loop perf refactor. Behavior
            // pinned by 4 e2e vitest tests; equivalence verified pre/post.
            // 1. $.each -> native for-loop (saves callback indirection per
            //    entry; on a 1000-row table that's 1000 fewer Function
            //    invocations).
            // 2. String concat with html += -> array.push + join. Array.join
            //    is faster than repeated string concat in V8 when the
            //    string list grows past ~50 items.
            var parts = [];
            for (var i = 0, n = data.entries.length; i < n; i++) {
                ctx.rowIndex = rowOffset + i + 1;
                parts.push(self.renderEntryRowHtml(data.entries[i], ctx));
            }
            html = parts.join('');
        } else {
            var colCount = $wrapper.find('thead th').length;
            var noResultsLabel = (self.config && self.config.pagination_labels && self.config.pagination_labels.no_results) || 'No entries found';
            html += self.renderNoEntriesRowHtml(colCount, noResultsLabel);
        }

        // #1049 Option 2 v4.222.0 - $tbody.html(html) -> direct
        // .innerHTML assignment. jQuery's .html() does extra
        // bookkeeping (script execution, event cleanup) that we
        // don't need here - innerHTML is a single DOM-parse pass.
        if ($tbody[0]) {
            $tbody[0].innerHTML = html;
        } else {
            $tbody.html(html);
        }

        // Re-apply user-customized column order to freshly rendered rows
        if (typeof self.applyStoredColumnOrderToBody === 'function') {
            self.applyStoredColumnOrderToBody();
        }

        // Update pagination
        if (self.config.show_pagination) {
            self.updatePagination(data);
        }

        // Bind events for new elements
        self.bindEntryEvents();

        // Apply conditional formatting to the rendered entries
        self.applyConditionalFormatting();

        // #501 slice 1: row-expiry gate. Hide / strike / sink-to-bottom
        // rows whose date in expiry_field_id is past (or future, in
        // inverse mode). Runs BEFORE auto-merge so merger sees the
        // expiry-filtered DOM.
        self.applyRowExpiry();

        // #2338 - row grouping: inject group-header <tr> rows before the
        // first data row of each group. Runs BEFORE auto-merge so that
        // the merge pass sees the correct row positions after headers are
        // inserted. typeof guard keeps harnesses without the module on the
        // old path.
        if (typeof self.applyRowGrouping === 'function') {
            self.applyRowGrouping(data, $tbody);
        }

        // #518 slice 1: post-render rowspan auto-merge.
        // Walks each column flagged column_auto_merge[field_id]=true and
        // collapses runs of consecutive duplicate cells into a single
        // rowspan=N cell. Runs as the LAST step so it sees the final
        // post-conditional-formatting DOM and any sort / filter / search
        // re-render automatically re-evaluates the merges.
        self.applyAutoMerge();

        // Update cards if in responsive mode
        if (this.config.responsive_mode === 'enhanced') {
            this.updateCardsAfterDataChange($wrapper);
        }

        // Update column totals if enabled
        if (this.config.show_column_totals) {
            this.updateColumnTotals();
        }

        // #1612 - DOM text-node highlight pass: HTML cells, card view,
        // and per-column text-filter terms. Runs LAST so it sees the
        // final DOM (post-merge, post-card-update). typeof guard keeps
        // harnesses without the module on the old path.
        if (typeof self.applyDomSearchHighlight === 'function') {
            self.applyDomSearchHighlight($wrapper);
        }

        // Reset vertical scroll position to the top of the new page (#324)
        var $tableContent = $wrapper.find('.gt-table-content');
        if ($tableContent.length) {
            $tableContent.scrollTop(0);
        }

        // Trigger custom event for scroll indicators
        $(document).trigger('gt-entries-loaded-' + self.wrapperId);
    };

})(window);
