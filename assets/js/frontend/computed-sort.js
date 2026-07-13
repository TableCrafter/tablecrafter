/**
 * TableCrafter - frontend/computed-sort.js
 *
 * #1621 - client-side sort for computed columns. Computed values
 * (gtc_*) have no DB column, so SQL ORDER BY can't see them; their
 * th carries .gt-client-sortable (instead of .gt-sortable) and this
 * module reorders the LOADED page's rows in the DOM. Numeric-aware:
 * formatted values ("1,234.50") parse; '#ERR' and non-numeric values
 * sink to the bottom in either direction.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *   - sortRenderedRowsByColumn($wrapper, fieldId, direction)
 *   - bindComputedSortEvents($wrapper)
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery || window.$;

    function numericValue(text) {
        var cleaned = String(text || '').trim().replace(/,/g, '');
        if (cleaned === '' || cleaned === '#ERR') { return null; }
        var n = parseFloat(cleaned);
        return isNaN(n) ? null : n;
    }

    Object.assign(window.GravityTable.prototype, {

        sortRenderedRowsByColumn: function ($wrapper, fieldId, direction) {
            var $tbody = $wrapper.find('tbody').first();
            var rows = $tbody.find('tr').toArray();
            var dir = direction === 'desc' ? -1 : 1;
            rows.sort(function (a, b) {
                var av = numericValue($(a).find('td[data-field-id="' + fieldId + '"]').text());
                var bv = numericValue($(b).find('td[data-field-id="' + fieldId + '"]').text());
                // Non-numeric (#ERR, text, missing) sinks to the bottom
                // in either direction.
                if (av === null && bv === null) { return 0; }
                if (av === null) { return 1; }
                if (bv === null) { return -1; }
                return (av - bv) * dir;
            });
            for (var i = 0; i < rows.length; i++) {
                $tbody.append(rows[i]);
            }
        },

        bindComputedSortEvents: function ($wrapper) {
            var self = this;
            $wrapper.on('click.gt-computed-sort', 'th.gt-client-sortable', function (e) {
                if ($(e.target).closest('.gt-resizer').length) {
                    return;
                }
                var $th = $(this);
                var field = $th.data('sort-field');
                if (!field) { return; }
                var current = $th.attr('aria-sort');
                var direction = current === 'ascending' ? 'desc' : 'asc';
                self.sortRenderedRowsByColumn($wrapper, String(field), direction);
                $wrapper.find('th.gt-client-sortable').attr('aria-sort', 'none')
                    .find('.gt-sort-indicator').removeClass('active');
                $th.attr('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
                $th.find('.gt-sort-indicator').addClass('active');
            });
        }

    });

})(window);
