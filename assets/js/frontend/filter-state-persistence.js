/**
 * TableCrafter — frontend/filter-state-persistence.js
 *
 * localStorage persistence of search term + per-column filter state.
 * #832 slice 14 of N.
 *
 * Four helpers attached to GravityTable.prototype via Object.assign:
 *
 *   - _filterStateStorageKey()
 *       Returns the localStorage key for this table. Format:
 *       `gt-filters-<table_id>` when this.config.table_id is set,
 *       otherwise falls back to `gt-filters-<wrapperId>`.
 *
 *   - persistFilterStateLocal()
 *       No-ops when `this.config.persist_filters_localstorage` is
 *       falsy or localStorage is unavailable. Otherwise writes
 *       `{ searchTerm, filters }` to the key. Errors are swallowed.
 *
 *   - restoreFilterStateLocal()
 *       No-ops the same way. Otherwise reads + merges into
 *       `this.searchTerm` and `this.filters` — URL takes precedence
 *       (only fills slots that applyUrlFilters left empty). Returns
 *       true on a successful restore.
 *
 *   - clearFilterStateLocal()
 *       Removes the key from localStorage when available.
 *
 * Depends on jQuery for the DOM sync after restore (search input +
 * per-column filter inputs).
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

        _filterStateStorageKey: function () {
            var id = (this.config && this.config.table_id) || this.wrapperId;
            return 'gt-filters-' + id;
        },

        persistFilterStateLocal: function () {
            if (!(this.config && this.config.persist_filters_localstorage)) return;
            if (!window.localStorage) return;
            try {
                var state = {
                    searchTerm: String(this.searchTerm || ''),
                    filters: this.filters && typeof this.filters === 'object' ? this.filters : {}
                };
                localStorage.setItem(this._filterStateStorageKey(), JSON.stringify(state));
            } catch (e) {}
        },

        restoreFilterStateLocal: function () {
            if (!(this.config && this.config.persist_filters_localstorage)) return false;
            if (!window.localStorage) return false;
            try {
                var raw = localStorage.getItem(this._filterStateStorageKey());
                if (!raw) return false;
                var state = JSON.parse(raw);
                if (!state || typeof state !== 'object') return false;
                if (typeof state.searchTerm === 'string' && state.searchTerm !== '') {
                    this.searchTerm = state.searchTerm;
                    $('#' + this.wrapperId).find('.gt-search-input').val(state.searchTerm);
                }
                if (state.filters && typeof state.filters === 'object') {
                    // Merge rather than replace — applyUrlFilters may have already
                    // seeded entries from the URL, and URL takes precedence.
                    for (var k in state.filters) {
                        if (Object.prototype.hasOwnProperty.call(state.filters, k) && !this.filters[k]) {
                            this.filters[k] = state.filters[k];
                            var fv = state.filters[k];
                            var colId = String(k).replace(/^filter_/, '');
                            var $input = $('#' + this.wrapperId).find('#gt-filter-' + colId);
                            if ($input.length && fv && typeof fv === 'object' && typeof fv.value !== 'undefined') {
                                $input.val(fv.value);
                            }
                        }
                    }
                }
                return true;
            } catch (e) { return false; }
        },

        clearFilterStateLocal: function () {
            if (!window.localStorage) return;
            try { localStorage.removeItem(this._filterStateStorageKey()); } catch (e) {}
        }

    });

})(window);
