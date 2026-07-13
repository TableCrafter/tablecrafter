/**
 * TableCrafter - frontend/column-order-persistence.js
 *
 * localStorage persistence of the user's per-table column order.
 * #832 slice 15 of N.
 *
 * Four helpers attached to GravityTable.prototype via Object.assign:
 *
 *   - _columnOrderKey()
 *       Returns the localStorage key for this table. Format:
 *       `gt-col-order-<table_id_or_id>` when this.config.table_id or
 *       this.config.id is set, otherwise falls back to
 *       `gt-col-order-<wrapperId>`.
 *
 *   - readStoredColumnOrder()
 *       Returns the saved order as an array of stringified field IDs,
 *       or `[]` when nothing is stored / payload is malformed /
 *       localStorage is unavailable. Errors are swallowed.
 *
 *   - saveStoredColumnOrder(order)
 *       Writes the supplied order (array of any) to the key, coercing
 *       each entry to a string. Errors are swallowed.
 *
 *   - clearStoredColumnOrder()
 *       Removes the key from localStorage. Errors are swallowed.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        _columnOrderKey: function () {
            var id = (this.config && (this.config.table_id || this.config.id)) || this.wrapperId;
            return 'gt-col-order-' + id;
        },

        readStoredColumnOrder: function () {
            try {
                var raw = window.localStorage && localStorage.getItem(this._columnOrderKey());
                if (!raw) return [];
                var arr = JSON.parse(raw);
                return Array.isArray(arr) ? arr.map(String) : [];
            } catch (e) { return []; }
        },

        saveStoredColumnOrder: function (order) {
            try { localStorage.setItem(this._columnOrderKey(), JSON.stringify(order.map(String))); } catch (e) {}
        },

        clearStoredColumnOrder: function () {
            try { localStorage.removeItem(this._columnOrderKey()); } catch (e) {}
        }

    });

})(window);
