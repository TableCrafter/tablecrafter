/**
 * TableCrafter — frontend/conditional-format.js
 *
 * Conditional formatting rule engine. Third module under #830.
 *
 * Closes #838.
 *
 * Surface (all attached to GravityTable.prototype via Object.assign):
 *   - applyConditionalFormatting()          — entry point, walks rows.
 *   - getColumnIndex(fieldId)               — string/number lookup
 *                                             with selection-checkbox
 *                                             offset adjustment.
 *   - getCellValue($cell, fieldId)          — extracts text from a
 *                                             cell, preferring inline
 *                                             editor content when set.
 *   - evaluateCondition(cellValue, op,
 *                       criteriaValue,
 *                       columnType)         — pure rule evaluator.
 *                                             Supports eq/neq/lt/lteq/
 *                                             gt/gteq/contains/
 *                                             contains_not/empty/
 *                                             not_empty.
 *   - applyFormattingAction($cell, $row,
 *                            action,
 *                            setValue)      — dispatcher for
 *                                             setCellColor /
 *                                             setCellContent /
 *                                             setCellClass /
 *                                             setRowColor / setRowClass.
 *
 * evaluateCondition is intentionally pure — it does no DOM access and
 * no jQuery calls — so the behavioral test suite can exercise every
 * operator with controlled inputs.
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

        applyConditionalFormatting: function () {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);

            if (!this.config.column_config) {
                return;
            }

            // #1049 Option 2 v4.219.0 — hot-loop perf refactor. Behavior
            // unchanged (pinned by end-to-end vitest); just faster.
            // 1. Cache the tbody row list ONCE outside the outer loop
            //    (was re-queried per column_config entry).
            // 2. Use native iteration (forEach / for-of) instead of $.each.
            // 3. Use direct row.children[columnIndex] DOM access instead
            //    of $row.find('td').eq(columnIndex) (saves a jQuery
            //    selector parse + tree walk per cell).
            // 4. jQuery wrappers still constructed for the per-cell
            //    applyFormattingAction call since that helper expects $.
            var rowElements = $wrapper.find('tbody tr').toArray();
            if (rowElements.length === 0) {
                return;
            }
            var columnConfig = this.config.column_config;
            for (var fieldId in columnConfig) {
                if (!Object.prototype.hasOwnProperty.call(columnConfig, fieldId)) continue;
                var cfg = columnConfig[fieldId];
                if (!cfg.conditional_formatting || cfg.conditional_formatting.length === 0) continue;

                var columnIndex = self.getColumnIndex(fieldId);
                if (columnIndex === -1) continue;

                var rules = cfg.conditional_formatting;
                var cfgType = cfg.type;
                for (var i = 0, n = rowElements.length; i < n; i++) {
                    var rowEl = rowElements[i];
                    var cellEl = rowEl.children[columnIndex];
                    if (!cellEl) continue;
                    var $cell = $(cellEl);
                    var $row = $(rowEl);
                    var cellValue = self.getCellValue($cell, fieldId);
                    for (var r = 0, rn = rules.length; r < rn; r++) {
                        var rule = rules[r];
                        if (self.evaluateCondition(cellValue, rule.ifClause, rule.cellVal, cfgType)) {
                            self.applyFormattingAction($cell, $row, rule.action, rule.setVal);
                        }
                    }
                }
            }
        },

        getColumnIndex: function (fieldId) {
            var index = -1;
            if (this.config.columns) {
                var fieldIdStr = String(fieldId);
                index = this.config.columns.indexOf(fieldIdStr);

                if (index === -1) {
                    var fieldIdNum = parseInt(fieldId);
                    index = this.config.columns.indexOf(fieldIdNum);
                }

                if (index !== -1 && $('#' + this.wrapperId).find('.gt-selection-header').length > 0) {
                    index++;
                }
            }
            return index;
        },

        getCellValue: function ($cell, fieldId) {
            var value = $cell.find('.gt-editable-field').length > 0
                ? $cell.find('.gt-editable-field').text()
                : $cell.text();
            return value.trim();
        },

        evaluateCondition: function (cellValue, operator, criteriaValue, columnType) {
            if (cellValue === '' || cellValue === '&nbsp;') {
                cellValue = '';
            }

            var numericTypes = ['number', 'int', 'float'];
            if (numericTypes.includes(columnType)) {
                cellValue = parseFloat(cellValue) || 0;
                criteriaValue = parseFloat(criteriaValue) || 0;
            }

            switch (operator) {
                case 'empty':         return cellValue === '';
                case 'not_empty':     return cellValue !== '';
                case 'eq':            return cellValue == criteriaValue;
                case 'neq':           return cellValue != criteriaValue;
                case 'lt':            return cellValue < criteriaValue;
                case 'lteq':          return cellValue <= criteriaValue;
                case 'gt':            return cellValue > criteriaValue;
                case 'gteq':          return cellValue >= criteriaValue;
                case 'contains':      return cellValue.toString().toLowerCase().indexOf(criteriaValue.toLowerCase()) !== -1;
                case 'contains_not':  return cellValue.toString().toLowerCase().indexOf(criteriaValue.toLowerCase()) === -1;
                default:              return false;
            }
        },

        applyFormattingAction: function ($cell, $row, action, setValue) {
            switch (action) {
                case 'setCellColor':
                    // frontend.css line 802 forces td[style*="background-color"]
                    // to use var(--gt-cell-bg) via !important (for rowspan-merge
                    // bg coverage). Set both so the !important rule resolves to
                    // the intended color.
                    $cell.css({ 'background-color': setValue, '--gt-cell-bg': setValue });
                    break;
                case 'setCellContent':
                    if ($cell.find('.gt-editable-field').length > 0) {
                        $cell.find('.gt-editable-field').html(setValue);
                    } else {
                        $cell.html(setValue);
                    }
                    break;
                case 'setCellClass':
                    $cell.addClass(setValue);
                    break;
                case 'setRowColor':
                    $row.css('background-color', setValue);
                    break;
                case 'setRowClass':
                    $row.addClass(setValue);
                    break;
            }
        }

    });

})(window);
