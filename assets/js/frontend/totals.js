/**
 * TableCrafter — frontend/totals.js
 *
 * Column-totals row renderer. #832 slice 1 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - computeColumnTotal(cellValues, aggOverride, isNumeric)
 *       Pure helper. Given an array of raw cell text values, an
 *       optional aggregation override (SUM / AVG / MIN / MAX /
 *       COUNT / COUNT_DISTINCT) and a numeric-column flag, returns
 *       the computed value (Number or Integer) or null.
 *       - aggOverride wins. Mirrors TC_Formula_Service::compute_aggregation
 *         server-side switch.
 *       - When no override but isNumeric is true, falls back to the
 *         legacy auto-Sum-for-numeric behavior.
 *       - When neither applies, returns null (cell stays empty).
 *       - Numeric coercion: parseFloat on stripped digits ($, commas,
 *         non-digit prefixes are dropped before parseFloat).
 *
 *   - updateColumnTotals()
 *       DOM driver. Walks tbody, collects cell text per column,
 *       passes to computeColumnTotal, emits a <tr class="gt-totals-row">
 *       into tfoot.gt-column-totals. Formats:
 *         - COUNT / COUNT_DISTINCT → integer string.
 *         - total / calculation column type OR AVG override → 2 decimals.
 *         - Other numeric → integer if integral, otherwise 2 decimals.
 *       First non-numeric column gets the "Totals" label cell.
 *       Subsequent non-numeric columns get empty cells.
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

        computeColumnTotal: function (cellValues, aggOverride, isNumeric) {
            var self = this;
            // Shared numeric coercion (#1731): use the canonical
            // gtParseNumeric (util.js) so the column-totals row and the
            // Data Bars fill can never disagree. Falls back to the inline
            // form only if util.js isn't on the prototype (defensive —
            // never happens in production where util enqueues first).
            var coerce = (typeof self.gtParseNumeric === 'function')
                ? function (s) { return self.gtParseNumeric(s); }
                : function (s) { var v = parseFloat(String(s).replace(/[^0-9.\-]/g, '')); return isNaN(v) ? null : v; };
            var numeric = cellValues.map(coerce).filter(function (v) { return v !== null; });

            if (aggOverride) {
                switch (aggOverride) {
                    case 'SUM':
                        return numeric.reduce(function (a, b) { return a + b; }, 0);
                    case 'AVG':
                        return numeric.length ? numeric.reduce(function (a, b) { return a + b; }, 0) / numeric.length : 0;
                    case 'MIN':
                        return numeric.length ? Math.min.apply(null, numeric) : 0;
                    case 'MAX':
                        return numeric.length ? Math.max.apply(null, numeric) : 0;
                    case 'COUNT':
                        return cellValues.length;
                    case 'COUNT_DISTINCT':
                        return (new Set(cellValues)).size;
                    default:
                        return null;
                }
            }
            if (isNumeric) {
                return numeric.reduce(function (a, b) { return a + b; }, 0);
            }
            return null;
        },

        updateColumnTotals: function () {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);
            var $tfoot = $wrapper.find('tfoot.gt-column-totals');
            if (!$tfoot.length) return;

            var html = '<tr class="gt-totals-row">';
            var firstTextColumn = true;

            if ($wrapper.find('.gt-selection-header').length > 0) {
                html += '<td class="gt-checkbox-cell"></td>';
            }

            var columnAggregations = (self.config && self.config.column_aggregations) || {};

            $.each(this.config.columns, function (colIndex, fieldId) {
                var colConfig = self.config.column_config[fieldId];
                var colType = colConfig ? colConfig.type : 'text';
                var isNumeric = (colType === 'number' || colType === 'quantity' || colType === 'total' || colType === 'calculation');
                var aggOverride = columnAggregations[fieldId] || '';

                var cellValues = [];
                if (aggOverride || isNumeric) {
                    $wrapper.find('tbody tr').each(function () {
                        var $cell = $(this).find('td[data-field-id="' + fieldId + '"]');
                        if ($cell.length) cellValues.push($cell.text());
                    });
                }

                var result = self.computeColumnTotal(cellValues, aggOverride, isNumeric);

                if (result !== null) {
                    var formatted;
                    if (typeof result === 'number') {
                        formatted = (aggOverride === 'COUNT' || aggOverride === 'COUNT_DISTINCT')
                            ? String(result)
                            : (colType === 'total' || colType === 'calculation' || aggOverride === 'AVG')
                                ? result.toFixed(2)
                                : (result % 1 === 0 ? result.toString() : result.toFixed(2));
                    } else {
                        /* c8 ignore next */
                        formatted = String(result);
                    }
                    html += '<td class="gt-totals-cell gt-totals-numeric" data-field-id="' + fieldId + '"><strong>' + formatted + '</strong></td>';
                    firstTextColumn = false;
                } else if (firstTextColumn) {
                    html += '<td class="gt-totals-cell gt-totals-label" data-field-id="' + fieldId + '"><strong>Totals</strong></td>';
                    firstTextColumn = false;
                } else {
                    html += '<td class="gt-totals-cell" data-field-id="' + fieldId + '"></td>';
                }
            });

            if ($wrapper.find('.gt-actions-header').length > 0) {
                /* c8 ignore next */
                html += '<td class="gt-totals-cell"></td>';
            }

            html += '</tr>';
            $tfoot.html(html);
        }

    });

})(window);
