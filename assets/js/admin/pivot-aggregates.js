/**
 * TableCrafter - admin/pivot-aggregates.js
 *
 * #1617 - builder pivot multi-aggregate repeater. The engine
 * (TC_Pivot_Service) and the v6.1.6 frontend pivot view always
 * supported several aggregates per group; the builder exposed one.
 * Rows clone the PHP-rendered <template id="gt-pa-row-template">
 * (which carries the form's field options); save-table.js posts the
 * collected list as JSON and TC_Admin composes it through
 * TC_Pivot_Service::parse_aggregates_input() + normalize().
 *
 * Surface (window.TC_TableBuilder.*):
 *   - paAddRow(col?, op?)
 *   - collectPivotAggregates() - [{col, op}] from the DOM
 */
(function (window, $) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};
    var GT = window.TC_TableBuilder;

    GT.paAddRow = function (col, op) {
        var $rows = $('.gt-pivot-aggregates .gt-pa-rows').first();
        var tpl = window.document.getElementById('gt-pa-row-template');
        if (!$rows.length || !tpl) { return; }
        var node = tpl.content.firstElementChild.cloneNode(true);
        $rows.append(node);
        var $row = $(node);
        if (col !== undefined) { $row.find('.gt-pa-col').val(String(col)); }
        if (op !== undefined) { $row.find('.gt-pa-op').val(String(op)); }
    };

    GT.collectPivotAggregates = function () {
        var out = [];
        $('.gt-pivot-aggregates .gt-pa-rows .gt-pa-row').each(function () {
            var col = String($(this).find('.gt-pa-col').val() || '');
            var op = String($(this).find('.gt-pa-op').val() || 'sum');
            if (col) {
                out.push({ col: col, op: op });
            }
        });
        return out;
    };

    $(function () {
        var $panel = $('.gt-pivot-aggregates');
        if (!$panel.length) { return; }
        $panel.on('click', '.gt-pa-add', function () {
            GT.paAddRow();
        });
        $panel.on('click', '.gt-pa-remove', function () {
            $(this).closest('.gt-pa-row').remove();
        });
    });

})(window, window.jQuery);
