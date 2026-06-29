/**
 * TableCrafter — frontend/print.js
 *
 * Print-preparation helpers. Sixth slice under #833. Three methods:
 *
 *   - initPrint()              — wire the .gt-print-btn click to a
 *                                preparePrintHeader + (optional)
 *                                load-all-rows + window.print sequence.
 *                                Honors config.print_all_rows (default
 *                                true) and the #531 5000-row cap with
 *                                an afterprint listener that restores
 *                                pagination.
 *   - preparePrintHeader()     — fill .gt-print-header with timestamp
 *                                and active-filter summary.
 *   - summarizeActiveFilters() — pure helper rendering the active
 *                                filter state as a human-readable
 *                                string (handles each filter type).
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.initPrint = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        $wrapper.on('click', '.gt-print-btn', function (e) {
            e.preventDefault();

            // #1024 — re-entrancy guard. If the previous print-prep is still
            // in flight (AJAX hasn't returned yet), a second click would
            // mutate per_page from its already-mutated value, corrupt the
            // restore state, and stack a second afterprint listener.
            // The user-visible symptom is the table sitting in a loading
            // state with no end in sight while requests pile up.
            if (self._printPrepInFlight) {
                return;
            }

            self.preparePrintHeader();

            // #531 slice 1: print-all-rows. When the per-table setting is on
            // (default) we fetch every entry into the DOM before calling
            // window.print() so the printout includes the full list rather
            // than just the currently visible page. The original pagination
            // state is restored on the browser's afterprint event.
            var printAll = (self.config && typeof self.config.print_all_rows !== 'undefined')
                ? !!self.config.print_all_rows
                : true;

            if (!printAll) {
                window.print();
                return;
            }

            // Hide the legacy "current page only" notice — no longer relevant
            // when we're loading everything.
            $wrapper.find('.gt-print-pagination-notice').hide();

            // #1024 — skip the heavy refetch when the table already has every
            // entry in the DOM. The original print-all-rows flow ALWAYS
            // pushed per_page to 5000 and re-fetched even when the current
            // pagination already covered every row, which on slow networks
            // looked like indefinite loading to the user. If we already
            // know totalEntries and the DOM has at least that many tbody
            // rows, just call window.print() directly.
            var visibleRowCount = $wrapper.find('tbody tr').length;
            if (typeof self.totalEntries === 'number'
                && self.totalEntries > 0
                && visibleRowCount >= self.totalEntries) {
                setTimeout(function () { window.print(); }, 50);
                return;
            }

            var originalPerPage = self.config.per_page;
            var originalPage = self.currentPage;

            // Bind a one-shot afterprint listener so the on-screen view goes
            // back to the paginated state once the user dismisses the print
            // dialog. addEventListener used so we don't clobber any other
            // afterprint handlers the host page may have set.
            var restored = false;
            var onAfterPrint = function () {
                if (restored) { return; }
                restored = true;
                self.config.per_page = originalPerPage;
                self.currentPage = originalPage;
                self.loadEntries();
                window.removeEventListener('afterprint', onAfterPrint);
                $wrapper.find('.gt-print-pagination-notice').show();
            };
            window.addEventListener('afterprint', onAfterPrint);

            // Cap at 5000 so a runaway dataset doesn't lock the browser. For
            // the typical roster / schedule / price-list use case this is
            // far above the realistic upper bound.
            //
            // #531 slice 1.1: never SHRINK below the original per_page. If a
            // table already has per_page=10000, the print path keeps that
            // higher value so the printout doesn't lose rows. Print is
            // supposed to ADD rows, not remove them.
            self.config.per_page = Math.max(originalPerPage || 0, 5000);
            self.currentPage = 1;
            self._printPrepInFlight = true; // #1024 — re-entrancy guard
            self.loadEntries(function (response) {
                self._printPrepInFlight = false; // clear in-flight flag
                // #531 slice 1.2: only print when the AJAX succeeded. On
                // failure the tbody now holds an error message; printing
                // that would just put garbage on the page.
                if (response && response.success) {
                    // Defer the print dialog until the next tick so the
                    // DOM has a chance to commit the newly rendered rows.
                    setTimeout(function () { window.print(); }, 50);
                } else {
                    // Restore pagination immediately — the afterprint event
                    // won't fire because the dialog never opened. Without
                    // this, the table stays at per_page=5000 with broken
                    // tbody content.
                    onAfterPrint();
                }
            });
        });
    };

    GravityTable.prototype.preparePrintHeader = function () {
        var $wrapper = $('#' + this.wrapperId);
        var $header = $wrapper.find('.gt-print-header');
        if (!$header.length) return;

        var now = new Date();
        var dateStr = now.toLocaleDateString(undefined, {
            year: 'numeric', month: 'long', day: 'numeric'
        }) + ' · ' + now.toLocaleTimeString(undefined, {
            hour: 'numeric', minute: '2-digit'
        });
        $header.find('.gt-print-header__date').text(dateStr);

        var summary = this.summarizeActiveFilters();
        var $filters = $header.find('.gt-print-header__filters');
        if (summary) {
            $filters.html('<strong>Filters:</strong> ' + this.escapeHtml(summary));
        } else {
            $filters.html('<em>' + this.escapeHtml($filters.attr('data-empty-text') || 'No filters applied') + '</em>');
        }
    };

    GravityTable.prototype.summarizeActiveFilters = function () {
        var $wrapper = $('#' + this.wrapperId);
        var parts = [];

        var search = $wrapper.find('.gt-search-input').val();
        if (search && search.trim() !== '') {
            parts.push('Search = "' + search.trim() + '"');
        }

        var filters = this.filters || {};
        Object.keys(filters).forEach(function (key) {
            var match = /^filter_(.+)$/.exec(key);
            if (!match) return;
            var fieldId = match[1];
            var label = $wrapper.find('.gt-filter-field[data-field-id="' + fieldId + '"] label').first().text().trim() || ('Field ' + fieldId);
            var f = filters[key];
            if (!f) return;
            // #1566: 0 must be treated as a real value, not falsy. min, max,
            // from, to may legitimately be 0 (or the string '0') -- a filter
            // "weight = 0 to 10" must show in the summary including the
            // literal 0 endpoint.
            var has = function (v) { return v !== undefined && v !== null && v !== ''; };
            var disp = function (v) { return has(v) ? v : '...'; };
            switch (f.type) {
                case 'date_range':
                    if (has(f.from) && has(f.to) && f.from === f.to) parts.push(label + ' = ' + f.from);
                    else if (has(f.from) || has(f.to)) parts.push(label + ' ' + disp(f.from) + ' to ' + disp(f.to));
                    break;
                case 'number_range':
                    if (has(f.min) || has(f.max)) parts.push(label + ' ' + disp(f.min) + ' to ' + disp(f.max));
                    break;
                case 'lookup':
                case 'dropdown':
                    if (f.values && f.values.length) parts.push(label + ' = ' + f.values.join(', '));
                    else if (f.value) parts.push(label + ' = ' + f.value);
                    break;
                case 'checkboxes':
                    if (f.values && f.values.length) parts.push(label + ' = ' + f.values.join(', '));
                    break;
                case 'text':
                default:
                    if (f.value) parts.push(label + ' = ' + f.value);
                    break;
            }
        });

        return parts.join(' · ');
    };

})(window);
