/**
 * TableCrafter - frontend/util.js
 *
 * Pure helper methods extracted from the monolithic frontend.js as the
 * first module under #830 (split frontend.js into composable modules).
 *
 * Closes #841.
 *
 * Pattern: attach each method to `window.GravityTable.prototype` via
 * Object.assign so the public surface stays byte-identical to the
 * pre-extraction state. Every existing call (self.escapeHtml(...),
 * this.formatDate(...), etc.) continues to work because the prototype
 * carries the same methods - just defined in a different source file.
 *
 * Load order: this module must be enqueued BEFORE frontend.js so that
 * GravityTable's constructor finds the helpers on its prototype when
 * it runs. class-tc-shortcode.php registers the 'gravity-tables-
 * frontend' handle with a dependency on 'gravity-tables-frontend-util'
 * to pin this order.
 *
 * Defensive guard: if frontend.js hasn't loaded for some reason (e.g.
 * a customisation enqueues this module standalone), the IIFE bails
 * silently. Better than throwing - keeps a broken site recoverable.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        escapeHtml: function (text) {
            if (text === null || text === undefined) {
                return '';
            }
            text = String(text);
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function (m) { return map[m]; });
        },

        escapeRegex: function (str) {
            return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        },

        // Single source of truth for numeric coercion, shared by the
        // column-totals row (totals.js) and Data Bars (#1731) so a bar's
        // fill can never disagree with its own column total. Strips
        // currency symbols / thousands separators, then parseFloat.
        // Returns the Number, or null when not finite-numeric.
        // #1736 - locale-aware two-pass normalizer.
        //
        // Heuristic: scan from the right - the last separator is the decimal
        // separator ONLY when it is followed by 1 or 2 digits (not 3).
        // Three digits after a separator => it is a thousands-grouping char.
        //
        // Examples:
        //   "1.234,56"  -> last sep=comma, 2 digits after  => decimal comma  -> 1234.56
        //   "1,240.00"  -> last sep=period, 2 digits after => decimal period  -> 1240.0
        //   "1,899"     -> last sep=comma, 3 digits after  => thousands comma -> 1899
        //   "1,00"      -> last sep=comma, 2 digits after  => decimal comma   -> 1.0
        //   "1.00"      -> last sep=period, 2 digits after => decimal period  -> 1.0
        gtParseNumeric: function (s) {
            var str = String(s);
            // Strip everything except digits, commas, periods, minus sign.
            var stripped = str.replace(/[^0-9,.\-]/g, '');
            if (stripped === '' || stripped === '-') {
                return null;
            }

            var normalized;
            // Match: last separator (comma or period) and the digits that follow it.
            var lastSepMatch = stripped.match(/[,.](\d+)$/);

            if (!lastSepMatch) {
                // No separator at all - plain integer.
                normalized = stripped;
            } else {
                var afterLast = lastSepMatch[1].length; // digits after last separator
                var lastSepChar = lastSepMatch[0][0];   // ',' or '.'
                var isThou = (afterLast === 3);          // 3 digits => thousands grouping

                if (isThou) {
                    // Last separator is a thousands char - strip ALL commas and periods
                    // (they are all grouping chars; no decimal portion present).
                    normalized = stripped.replace(/[,.]/g, '');
                } else if (lastSepChar === ',') {
                    // Comma is the decimal separator (EU format).
                    // Remove all periods (thousands), swap trailing comma for a dot.
                    normalized = stripped.replace(/\./g, '').replace(',', '.');
                } else {
                    // Period is the decimal separator (US format).
                    // Remove all commas (thousands), keep the period.
                    normalized = stripped.replace(/,/g, '');
                }
            }

            var v = parseFloat(normalized);
            return isFinite(v) ? v : null;
        },

        normalizeToggleValue: function (value) {
            if (value === true || value === 1) return 1;
            if (typeof value === 'string') {
                var v = value.trim().toLowerCase();
                if (v === '1' || v === 'true' || v === 'yes') return 1;
            }
            return 0;
        },

        convertHtml5DateToFormat: function (html5Value, targetFormat) {
            if (!html5Value) return '';
            var parts = html5Value.split('-');
            if (parts.length === 3) {
                var year = parts[0];
                var month = parts[1];
                var day = parts[2];
                return targetFormat
                    .replace('Y', year)
                    .replace('y', year.substring(2))
                    .replace('m', parseInt(month, 10).toString())
                    .replace('d', parseInt(day, 10).toString());
            }
            return html5Value;
        },

        parseInputDate: function (inputValue, format) {
            if (!inputValue) return null;
            inputValue = inputValue.trim().replace(/[\s\-\.]/g, '/');
            var formatParts = format.split(/[\/\-\.]/);
            var valueParts = inputValue.split('/');
            if (valueParts.length !== formatParts.length) {
                return null;
            }
            var year, month, day;
            for (var i = 0; i < formatParts.length; i++) {
                var part = parseInt(valueParts[i], 10);
                switch (formatParts[i]) {
                    case 'Y': year = part; break;
                    case 'y': year = part < 50 ? 2000 + part : 1900 + part; break;
                    case 'm': month = part; break;
                    case 'd': day = part; break;
                }
            }
            if (year && month && day && month >= 1 && month <= 12 && day >= 1 && day <= 31) {
                return new Date(year, month - 1, day);
            }
            return null;
        },

        formatDateToTarget: function (date, targetFormat) {
            if (!date) return '';
            var year = date.getFullYear();
            var month = date.getMonth() + 1;
            var day = date.getDate();
            return targetFormat
                .replace('Y', year.toString())
                .replace('y', year.toString().substring(2))
                .replace('m', month.toString())
                .replace('d', day.toString());
        },

        formatDate: function (dateString, format) {
            if (!dateString) return '';
            format = format || (this.config && this.config.date_format) || 'm/d/Y';

            var iso8601Regex = /^(\d{4})-(\d{2})-(\d{2})/;
            var isoMatch = dateString.match(iso8601Regex);

            if (isoMatch) {
                var iYear = isoMatch[1];
                var iMonth = parseInt(isoMatch[2], 10);
                var iDay = parseInt(isoMatch[3], 10);
                var iDayStr = iDay < 10 ? '0' + iDay : iDay.toString();
                var iMonthStr = iMonth < 10 ? '0' + iMonth : iMonth.toString();
                return format
                    .replace('d', iDayStr)
                    .replace('j', iDay.toString())
                    .replace('m', iMonthStr)
                    .replace('n', iMonth.toString())
                    .replace('Y', iYear)
                    .replace('y', iYear.substr(-2));
            }

            var date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;

            var day = date.getDate();
            var month = date.getMonth() + 1;
            var year = date.getFullYear();
            var dayStr = day < 10 ? '0' + day : day.toString();
            var monthStr = month < 10 ? '0' + month : month.toString();
            return format
                .replace('d', dayStr)
                .replace('j', day.toString())
                .replace('m', monthStr)
                .replace('n', month.toString())
                .replace('Y', year.toString())
                .replace('y', year.toString().substr(-2));
        },

        // Converts an HTML5 date input value (YYYY-MM-DD) to the supplied
        // display format. Used when the inline editor's <input type="date">
        // commits a value and we need to mirror it back into a text cell.
        // Returns inputValue unchanged when the value doesn't match the
        // HTML5 regex. Falls back to this.config.date_format ('m/d/Y' default)
        // when format is omitted. #832 slice 16 - moved from frontend.js.
        parseDateInput: function (inputValue, format) {
            if (!inputValue) return '';

            var dateRegex = /^(\d{4})-(\d{2})-(\d{2})$/;
            var match = inputValue.match(dateRegex);

            if (match) {
                var year = match[1];
                var month = match[2];
                var day = match[3];

                format = format || (this.config && this.config.date_format) || 'm/d/Y';

                var monthInt = parseInt(month, 10);
                var dayInt = parseInt(day, 10);

                var dayStr = dayInt < 10 ? '0' + dayInt : dayInt.toString();
                var monthStr = monthInt < 10 ? '0' + monthInt : monthInt.toString();

                return format
                    .replace('d', dayStr)
                    .replace('j', dayInt.toString())
                    .replace('m', monthStr)
                    .replace('n', monthInt.toString())
                    .replace('Y', year)
                    .replace('y', year.substr(-2));
            }

            return inputValue;
        }

    });

})(window);
