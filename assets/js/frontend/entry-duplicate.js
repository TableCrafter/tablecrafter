/**
 * TableCrafter - frontend/entry-duplicate.js
 *
 * Pro-gated one-click entry duplicate. POSTs to gt_duplicate_entry
 * AJAX handler which creates a new GF entry copying all field values
 * from the source entry.
 *
 * Closes #1747.
 *
 * Surface (GravityTable.prototype):
 *   - duplicateEntry(entryId) - fires AJAX, reloads on success.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery || window.$;

    GravityTable.prototype.duplicateEntry = function (entryId) {
        var config = this.config || {};
        if (!config.is_pro) { return; }
        if (!entryId) { return; }

        var self = this;
        var ajaxUrl = config.ajax_url || (window.ajaxurl || '');

        $.post(ajaxUrl, {
            action:   'gt_duplicate_entry',
            nonce:    config.nonce || '',
            table_id: config.table_id || '',
            entry_id: entryId,
        })
        .done(function (response) {
            if (response && response.success) {
                if (typeof self.loadEntries === 'function') {
                    self.loadEntries();
                }
            }
        })
        .fail(function () {
            /* silently ignore network failures */
        });
    };

}(window));
