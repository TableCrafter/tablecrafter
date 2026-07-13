/**
 * TableCrafter - frontend/row-link-resolver.js
 *
 * Row-link template resolver (#567). #832 slice 3 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - resolveRowLinkAttrs(template, entry)
 *       Pure helper. Resolves the per-row href from a row-link
 *       template (e.g. "/orders/{12}") plus an entry record. Returns
 *       the attribute string to splice into the <tr> opening tag.
 *
 *       Empty template / empty resolved href → returns ''.
 *       Otherwise returns:
 *         ' class="gt-row-clickable" data-row-href="<escaped>"
 *           role="link" tabindex="0" aria-label="Open entry [#<id>]"'
 *
 *       Token replacement supports `{<fieldId>}` where fieldId is
 *       any alphanumeric / underscore / dot string. Each replacement
 *       passes through encodeURIComponent. Missing fields render as
 *       empty.
 *
 *       aria-label uses entry_id when present (#567 slice 2.1); falls
 *       back to plain "Open entry" when entry_id is undefined - custom
 *       data sources like woocommerce_products and JSON/REST adapters
 *       don't expose a numeric entry_id, and screen readers should
 *       never announce "Open entry #undefined" (#567 slice 2.2).
 *
 *       Pre-requisite: util.js must load first (escapeHtml).
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        resolveRowLinkAttrs: function (template, entry) {
            var self = this;
            if (!template) return '';
            var resolvedHref = String(template).replace(/\{([0-9a-zA-Z_.]+)\}/g, function (_, fieldId) {
                var v = entry[fieldId];
                return v == null ? '' : encodeURIComponent(String(v));
            });
            if (!resolvedHref) return '';

            var rowAriaLabel = entry.entry_id
                ? self.escapeHtml('Open entry #' + entry.entry_id)
                : self.escapeHtml('Open entry');
            return ' class="gt-row-clickable" data-row-href="'
                + self.escapeHtml(resolvedHref)
                + '" role="link" tabindex="0"'
                + ' aria-label="' + rowAriaLabel + '"';
        }

    });

})(window);
