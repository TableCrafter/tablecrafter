/**
 * TableCrafter — frontend/date-inputs.js
 *
 * Date picker input wiring. Thirteenth slice under #833. One
 * method, ~158 lines.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - setupDateInputs() — wires the html5 <input type="date"> +
 *     display-format mirror inputs, preset chip clicks ("today",
 *     "yesterday", "last 7", etc.), range from/to validation,
 *     and the format-conversion helpers (parseDateInput / formatDate).
 *
 * Called once from init().
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.setupDateInputs = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);

        // Setup date input wrappers with HTML5 date picker overlay
        $wrapper.find('.gt-date-input-wrapper').each(function () {
            var $wrapper = $(this);
            var $html5Input = $wrapper.find('.gt-date-html5');
            var $displayInput = $wrapper.find('.gt-date-display');
            var $filterDiv = $wrapper.closest('.gt-date-filter');
            var dateFormat = $filterDiv.data('date-format') || self.config.date_format || 'm/d/Y';

            // When HTML5 date input changes, update the display input
            $html5Input.on('change', function () {
                var htmlValue = $(this).val(); // YYYY-MM-DD format
                if (htmlValue) {
                    var formattedValue = self.convertHtml5DateToFormat(htmlValue, dateFormat);
                    $displayInput.val(formattedValue);
                    // console.log('GT Frontend: Date selected - HTML5:', htmlValue, 'Formatted:', formattedValue);
                } else {
                    $displayInput.val('');
                }
                // Auto-apply filters when date changes
                self.applyFilters();
            });

            // When display input is clicked, trigger the HTML5 input
            $displayInput.on('click', function (e) {
                e.preventDefault();
                $html5Input.focus();
                if ($html5Input[0].showPicker) {
                    $html5Input[0].showPicker();
                } else {
                    $html5Input.click();
                }
            });

            // Make entire wrapper clickable - simplified approach
            $wrapper.on('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $html5Input.focus();
                if ($html5Input[0].showPicker) {
                    $html5Input[0].showPicker();
                } else {
                    $html5Input.click();
                }
            });

            // Make wrapper focusable for keyboard navigation
            $wrapper.attr('tabindex', '0');
            $wrapper.on('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { // Enter or Space
                    e.preventDefault();
                    $html5Input.focus();
                    if ($html5Input[0].showPicker) {
                        $html5Input[0].showPicker();
                    } else {
                        $html5Input.click();
                    }
                }
            });

            // Store references for easy access
            $html5Input.data('display-input', $displayInput);
            $displayInput.data('html5-input', $html5Input);
            $displayInput.data('date-format', dateFormat);
        });
    };

    // convertHtml5DateToFormat / parseInputDate / formatDateToTarget moved to
    // assets/js/frontend/util.js as part of #841 (split frontend.js — module 1).
    // The util module attaches them to GravityTable.prototype via Object.assign
    // so callsites here continue to use this.convertHtml5DateToFormat etc.
    // unchanged.
    //
    // #1561: orphan initializeModalDatePickers + private convertHtml5DateToFormat
    // copies were removed -- neither had any caller anywhere in the codebase.
    // The prototype-attached version in util.js is what every live consumer uses.

})(window);
