/**
 * TableCrafter - frontend/search.js
 *
 * Search controls (search button + Enter keypress on search input).
 * First slice of #834 (the larger filter-sort surface).
 *
 * Closes #834 slice 1.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - bindSearchEvents($wrapper)
 *       Wires .gt-search-btn click + .gt-search-input keypress(Enter).
 *       Both flows: read input value, set self.searchTerm, reset
 *       currentPage to 1, persist filter state to localStorage, reload
 *       entries. Previously inlined in frontend.js's bindEvents.
 *
 * Scope-honest note (#834): the umbrella estimated ~700 lines for
 * filter-sort, but the filter, sort, and search handlers are tightly
 * intertwined in bindEvents - splitting them all in one PR would be
 * high-risk. This module ships ONLY the search controls (smallest
 * cohesive sub-piece). Sort handlers, per-column filter inputs,
 * applyFilters / clearFilters, and initTextFilterTypeaheads remain in
 * frontend.js for future slices.
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

        bindSearchEvents: function ($wrapper) {
            var self = this;

            $wrapper.find('.gt-search-btn').on('click', function () {
                self.searchTerm = $wrapper.find('.gt-search-input').val();
                self.currentPage = 1;
                self.persistFilterStateLocal();
                self.loadEntries();
            });

            $wrapper.find('.gt-search-input').on('keypress', function (e) {
                if (e.key === 'Enter') {
                    self.searchTerm = $(this).val();
                    self.currentPage = 1;
                    self.persistFilterStateLocal();
                    self.loadEntries();
                }
            });
        }

    });

})(window);
