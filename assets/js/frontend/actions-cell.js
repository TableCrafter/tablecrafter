/**
 * TableCrafter — frontend/actions-cell.js
 *
 * Per-row actions-cell renderer. #832 slice 7 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - renderActionsCellHtml(entryId, config)
 *       Pure helper. Builds the `<td class="gt-actions-cell">` with
 *       the per-row action icons:
 *         - 👁 view (always)
 *         - 🗑 delete (when config.enable_delete is truthy)
 *         - 🕘 history (always)
 *         - 🛒 WooCommerce create (when config.woocommerce.active +
 *                                  config.woocommerce.mapping_ready)
 *
 *       Order is intentional (v4.7.61): primary actions (View, Delete)
 *       come first because they get the most use and need to be
 *       reachable when the column gets clipped on narrow viewports.
 *       Secondary actions (Edit history, WC create) trail at the end
 *       where being slightly less reachable is fine.
 *
 *       Pure: no DOM, no jQuery, no `this` reads. The DOM check
 *       `$wrapper.find('.gt-actions-header').length > 0` stays in
 *       frontend.js — this helper assumes the caller already decided
 *       to render an actions cell.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        renderActionsCellHtml: function (entryId, config) {
            var html = '<td class="gt-actions-cell">';
            html += '<span class="gt-action gt-view-action" title="View Details" data-entry-id="' + entryId + '">👁</span>';
            if (config && config.enable_delete) {
                html += '<span class="gt-action gt-delete-action" title="Delete" data-entry-id="' + entryId + '">🗑</span>';
            }
            // #1747 — duplicate entry (Pro).
            if (config && config.is_pro && config.enable_duplicate) {
                html += '<span class="gt-action gt-duplicate-action" title="Duplicate row" data-entry-id="' + entryId + '">⧉</span>';
            }
            html += '<span class="gt-action gt-history-action" title="Edit history" data-entry-id="' + entryId + '">🕘</span>';
            if (config && config.woocommerce && config.woocommerce.active && config.woocommerce.mapping_ready) {
                html += '<span class="gt-action gt-wc-create-action" title="Create WooCommerce product" data-entry-id="' + entryId + '">🛒</span>';
            }
            html += '</td>';
            return html;
        }

    });

})(window);
