/**
 * TableCrafter — frontend/load-entries.js
 *
 * AJAX entry-fetch path. Twenty-first slice under #833. One method,
 * ~72 lines.
 *
 *   - loadEntries(onComplete) — dispatch to loadEntriesServerSide
 *     when server-side processing is on, otherwise POST gt_get_entries
 *     with current filters/sort/page state and fan out into
 *     renderEntries on success.
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.loadEntries = function (onComplete) {
        if (this.config.processing_mode === 'server') {
            return this.loadEntriesServerSide(onComplete);
        }
        if (this.config.data_source_type === 'woocommerce_products') {
            return this.loadWooCommerceProducts(onComplete);
        }
        //console.log('Loading entries with sort:', this.sortField, this.sortOrder);
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $tbody = $wrapper.find('tbody');

        // Show loading — add wrapper class so CSS can block premature pagination clicks
        $wrapper.addClass('gt-table-loading');
        // #1713 — render a layout-stable shimmer skeleton instead of a single
        // full-width "Loading…" colspan row (which collapsed the columns on
        // sort and read as a flash). Pins the header widths, paints multi-row
        // skeleton rows mirroring the real column/row count.
        self.showLoadingSkeleton($wrapper);

        // #2278 Phase 1 — grammar query detection.
        // When the search term contains grammar operators (field:, -, OR, ""),
        // suppress the server-side LIKE filter (which would match the raw
        // operator string literally) and apply grammar evaluation client-side
        // after the server returns the full row set for the page.
        // Plain queries are not affected: grammarQuery stays '' and the
        // existing server LIKE path runs unchanged.
        var grammarQuery = '';
        var serverSearch = this.searchTerm;
        if (typeof self._hasGrammarOperators === 'function'
                && self._hasGrammarOperators(self.searchTerm)) {
            grammarQuery = self.searchTerm;
            serverSearch = ''; // let server return all rows unfiltered
        }

        // Prepare data
        var data = {
            action: 'gt_get_entries',
            nonce: this.config.nonce,
            form_id: this.config.form_id,
            table_id: this.config.table_id,
            page: this.currentPage,
            per_page: this.config.per_page,
            search: serverSearch,
            sort_field: this.sortField,
            sort_order: this.sortOrder,
            // #565 — pass the full multi-sort stack as JSON. Server validates
            // via TC_Multi_Sort_Service::validate_sort_stack and wires it
            // into the SQL ORDER BY clause when length > 1.
            sort_stack: JSON.stringify(this.sortStack || []),
            // #568 slice 4 — pass drilldown filters (chips) for server-side filtering.
            drilldown_filters: JSON.stringify(this.drilldownFilters || []),
            columns: this.config.columns,
            lookup_fields: this.config.lookup_fields || {}
        };

        // Add preview settings if in preview mode
        if (this.config.is_preview && this.config.preview_settings) {
            data.is_preview = true;
            data.preview_settings = this.config.preview_settings;
        }

        // #1596 — visitor flipped the pivot toggle to raw: ask the
        // server to skip aggregation for this request.
        if (this.pivotViewRaw) {
            data.pivot_view = 'raw';
        }

        // Add filters
        $.extend(data, this.filters);

        // Enable debugging if URL contains debug parameter
        if (window.location.href.indexOf('gt_debug=1') !== -1) {
            data.debug = 'true';
        }

        // #916 — debug logging removed. Re-enable behind a debug flag if needed:
        // console.log('GT Frontend: Loading entries with filters:', this.filters);
        // console.log('GT Frontend: Full AJAX data:', data);

        // AJAX request
        $.post(this.config.ajax_url, data, function (response) {
            //console.log('AJAX response:', response);
            $wrapper.removeClass('gt-table-loading');
            self.releaseColumnWidths($wrapper);
            if (response.success) {
                // #2278 Phase 1 — apply grammar filter client-side when active.
                var renderData = response.data;
                if (grammarQuery && typeof self.grammarFilterEntries === 'function'
                        && renderData && Array.isArray(renderData.entries)) {
                    renderData = jQuery.extend({}, renderData);
                    renderData.entries = self.grammarFilterEntries(renderData.entries, grammarQuery);
                }
                self.renderEntries(renderData);
            } else {
                $tbody.html('<tr><td colspan="' + ($wrapper.find('thead th').length) + '"><div class="gt-error">Error loading entries: ' + response.data + '</div></td></tr>');
            }
            if (typeof onComplete === 'function') { try { onComplete(response); } catch (e) {} }
        }).fail(function (xhr, status, error) {
            //console.log('AJAX request failed:', xhr, status, error);
            $wrapper.removeClass('gt-table-loading');
            self.releaseColumnWidths($wrapper);
            $tbody.html('<tr><td colspan="' + ($wrapper.find('thead th').length) + '"><div class="gt-error">Error loading entries</div></td></tr>');
            if (typeof onComplete === 'function') { try { onComplete(null); } catch (e) {} }
        });
    };

    // #1713 — layout-stable loading skeleton.
    //
    // Replaces the old single full-width "Loading…" colspan row (which let
    // the columns collapse to min-content on every sort/filter/paginate —
    // the "squish" — then snap back when data arrived). Instead we:
    //   1. Pin the current header column widths inline so swapping the
    //      <tbody> can't reflow the header geometry.
    //   2. Paint multi-row shimmer rows that mirror the real column count
    //      and the on-screen row count, so it reads like data loading in
    //      place rather than a flash.
    // Released by releaseColumnWidths() once the real rows render.
    GravityTable.prototype.showLoadingSkeleton = function ($wrapper) {
        var $ths    = $wrapper.find('thead tr').first().find('th');
        var colCount = $ths.length || 1;
        var $tbody  = $wrapper.find('tbody');

        // Mirror the rows currently on screen so the table keeps its height
        // (no collapse / jump). Fall back to per_page on first load, and
        // clamp to a sane band so we never paint hundreds of shimmer rows.
        var existing = $tbody.children('tr')
            .not('.gt-loading-row, .gt-skeleton-row, .gt-no-entries-row, .gt-empty-row, .gt-no-results-row')
            .length;
        var perPage  = parseInt(this.config && this.config.per_page, 10) || 10;
        var rowCount = existing || perPage;
        if (rowCount < 3)  { rowCount = 3; }
        if (rowCount > 15) { rowCount = 15; }

        // Pin the rendered header widths so the body swap can't squish them.
        // jsdom reports 0 here (no layout); real browsers report px widths.
        // Use the .each element arg (not `this`) so the call reads off the DOM
        // node, never the GravityTable instance.
        this._gtPinnedThWidths = true;
        $ths.each(function (i, th) {
            var w = th.getBoundingClientRect ? th.getBoundingClientRect().width : 0;
            if (w && w > 0) {
                th.style.width    = w + 'px';
                th.style.minWidth = w + 'px';
            }
        });

        var cells = '';
        for (var c = 0; c < colCount; c++) {
            cells += '<td class="gt-skeleton-cell"><span class="gt-skeleton-bar"></span></td>';
        }
        var rowHtml = '<tr class="gt-skeleton-row" aria-hidden="true">' + cells + '</tr>';
        var rows = '';
        for (var r = 0; r < rowCount; r++) {
            rows += rowHtml;
        }
        if ($tbody[0]) {
            $tbody[0].innerHTML = rows;
        } else {
            $tbody.html(rows);
        }
    };

    // #1713 — release the header widths pinned by showLoadingSkeleton so the
    // freshly rendered rows can settle to their natural column widths.
    GravityTable.prototype.releaseColumnWidths = function ($wrapper) {
        if (!this._gtPinnedThWidths) { return; }
        $wrapper.find('thead th').each(function (i, th) {
            th.style.width    = '';
            th.style.minWidth = '';
        });
        this._gtPinnedThWidths = false;
    };

})(window);
