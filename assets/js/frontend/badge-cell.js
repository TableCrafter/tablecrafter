/**
 * #1741 — Status Badge Cell Type (Free).
 *
 * Post-render pass: walks td[data-field-id] cells for columns that have a
 * badge map configured, wraps matching values in a colored pill badge span.
 * Runs after JS-side entry rendering (initial inline load). Server-side
 * rendering (AJAX response) produces badge HTML directly via TC_Badge_Service.
 */
(function () {
    'use strict';

    window.GravityTable = window.GravityTable || function () {};

    Object.assign(window.GravityTable.prototype, {

        /**
         * Apply badge spans to all rendered cells that match a badge map entry.
         * Safe to call multiple times — skips cells that already have a .gt-badge.
         */
        applyBadges: function () {
            var config = this.config || {};
            var badgeMap = config.column_badge_map;
            if (!badgeMap || typeof badgeMap !== 'object') {
                return;
            }
            var fieldIds = Object.keys(badgeMap);
            if (!fieldIds.length) {
                return;
            }

            var self = this;
            fieldIds.forEach(function (fieldId) {
                var colMap = badgeMap[fieldId];
                if (!colMap || typeof colMap !== 'object') {
                    return;
                }
                var cells = document.querySelectorAll('td[data-field-id="' + fieldId + '"]');
                cells.forEach(function (td) {
                    if (td.querySelector('.gt-badge')) {
                        return;
                    }
                    var rawValue = td.textContent;
                    if (!Object.prototype.hasOwnProperty.call(colMap, rawValue)) {
                        return;
                    }
                    var entry = colMap[rawValue];
                    var bg    = entry.bg   || '#e5e7eb';
                    var text  = entry.text || '#111827';
                    var span  = document.createElement('span');
                    span.className = 'gt-badge';
                    span.style.background    = bg;
                    span.style.color         = text;
                    span.style.borderRadius  = '9999px';
                    span.style.padding       = '2px 10px';
                    span.style.fontSize      = '.8em';
                    span.style.fontWeight    = '600';
                    span.style.display       = 'inline-block';
                    span.style.whiteSpace    = 'nowrap';
                    span.textContent         = rawValue;
                    td.textContent           = '';
                    td.appendChild(span);
                });
            });
        },

    });

})();
