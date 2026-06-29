/**
 * TableCrafter — admin/computed-columns.js
 *
 * #1598 — builder "Computed columns" repeater. Admin defines named
 * read-only columns from {field:N} expressions (TC_Formula_Service
 * evaluates per row server-side). save-table.js posts the collected
 * rows as JSON; TC_Admin sanitizes via
 * TC_Formula_Service::sanitize_computed_columns().
 *
 * Surface (window.TC_TableBuilder.*):
 *   - ccAddRow(label?, formula?)
 *   - collectComputedColumns()  — [{label, formula}] from the DOM
 */
(function (window, $) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};
    var GT = window.TC_TableBuilder;

    GT.ccAddRow = function (label, formula, format) {
        var $rows = $('#gt-computed-columns .gt-cc-rows');
        if (!$rows.length) { return; }
        var $row = $(
            '<div class="gt-cc-row" style="margin-bottom:6px;">'
            + '<input type="text" class="gt-cc-label" placeholder="Column label">'
            + '<input type="text" class="gt-cc-formula code" style="width:260px;" placeholder="{field:3} * {field:5}">'
            // #1621 — optional number format applied server-side.
            + '<select class="gt-cc-format">'
            + '<option value="">Raw</option>'
            + '<option value="int">1,234</option>'
            + '<option value="2dp">1,234.00</option>'
            + '</select>'
            + '<button type="button" class="button-link-delete gt-cc-remove" aria-label="Remove computed column">&times;</button>'
            + '</div>'
        );
        // .val() keeps hostile strings out of the markup parse path.
        $row.find('.gt-cc-label').val(label || '');
        $row.find('.gt-cc-formula').val(formula || '');
        $row.find('.gt-cc-format').val(format || '');
        $rows.append($row);
    };

    /**
     * #1621 — inline validation. Posts the row's formula to the
     * gt_cc_validate_formula endpoint (thin wrapper over
     * TC_Formula_Service::validate_formula) and toggles an error
     * note under the row.
     */
    GT.ccValidateRow = function ($row) {
        var formula = String($row.find('.gt-cc-formula').val() || '').trim();
        $row.find('.gt-cc-error').remove();
        if (!formula) { return; }
        var admin = window.gtAdmin || {};
        $.post(admin.ajax_url, {
            action: 'gt_cc_validate_formula',
            _ajax_nonce: admin.cc_validate_nonce,
            formula: formula
        }, function (response) {
            $row.find('.gt-cc-error').remove();
            if (response && response.success && response.data && response.data.valid === false) {
                $row.append('<span class="gt-cc-error" style="color:#b32d2e;margin-left:6px;">Formula does not parse - check parentheses and operators.</span>');
            }
        });
    };

    GT.collectComputedColumns = function () {
        var out = [];
        $('#gt-computed-columns .gt-cc-row').each(function () {
            var label = String($(this).find('.gt-cc-label').val() || '').trim();
            var formula = String($(this).find('.gt-cc-formula').val() || '').trim();
            var format = String($(this).find('.gt-cc-format').val() || '');
            if (label && formula) {
                out.push({ label: label, formula: formula, format: format });
            }
        });
        return out;
    };

    $(function () {
        var $panel = $('#gt-computed-columns');
        if (!$panel.length) { return; }
        $panel.on('click', '.gt-cc-add', function () {
            GT.ccAddRow();
        });
        $panel.on('click', '.gt-cc-remove', function () {
            $(this).closest('.gt-cc-row').remove();
        });
        // #1621 — validate on blur so admins see parse errors before save.
        $panel.on('blur', '.gt-cc-formula', function () {
            GT.ccValidateRow($(this).closest('.gt-cc-row'));
        });
    });

})(window, window.jQuery);
