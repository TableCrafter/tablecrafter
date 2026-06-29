/**
 * TableCrafter — frontend/edit-validation.js
 *
 * Per-column inline-edit validation rules (#1742, Pro).
 *
 * Public surface (GravityTable.prototype):
 *
 *   validateCell(fieldId, value) → {valid: bool, message: string}
 *     Checks value against this.config.column_validations[fieldId].
 *     Returns {valid:true, message:''} when no rules are configured.
 *
 *   showValidationError($field, $input, message)
 *     Renders an inline error message inside $field below the input,
 *     adds .gt-input-error to $input, and removes any previous error.
 *
 * Consumed by edit-field.js before every saveField() call:
 *   var v = self.validateCell(fieldId, newValue);
 *   if (!v.valid) { self.showValidationError($field, $input, v.message); return; }
 *   self.saveField(entryId, fieldId, newValue, $field);
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    /**
     * Validate a cell value against column_validations config.
     *
     * @param {string|number} fieldId
     * @param {string} value
     * @returns {{valid: boolean, message: string}}
     */
    GravityTable.prototype.validateCell = function (fieldId, value) {
        var config = this.config || {};
        var validations = config.column_validations;
        if (!validations || typeof validations !== 'object') {
            return { valid: true, message: '' };
        }
        var rules = validations[String(fieldId)];
        if (!rules || typeof rules !== 'object') {
            return { valid: true, message: '' };
        }

        var str = String(value);

        // required
        if (rules.required && str.trim() === '') {
            return { valid: false, message: 'This field is required.' };
        }

        // min_length
        if (rules.min_length > 0 && str.length < rules.min_length) {
            return { valid: false, message: 'Minimum ' + rules.min_length + ' characters required.' };
        }

        // max_length
        if (rules.max_length > 0 && str.length > rules.max_length) {
            return { valid: false, message: 'Maximum ' + rules.max_length + ' characters allowed.' };
        }

        // regex
        if (rules.regex) {
            try {
                var re = new RegExp(rules.regex, 'u');
                if (!re.test(str)) {
                    return { valid: false, message: rules.regex_message || 'Invalid format.' };
                }
            } catch (e) {
                // invalid pattern — silently skip
            }
        }

        // min_value (numeric strings only)
        if (rules.min_value !== undefined && rules.min_value !== null && !isNaN(parseFloat(str)) && isFinite(str)) {
            if (parseFloat(str) < parseFloat(rules.min_value)) {
                return { valid: false, message: 'Minimum value is ' + rules.min_value + '.' };
            }
        }

        // max_value (numeric strings only)
        if (rules.max_value !== undefined && rules.max_value !== null && !isNaN(parseFloat(str)) && isFinite(str)) {
            if (parseFloat(str) > parseFloat(rules.max_value)) {
                return { valid: false, message: 'Maximum value is ' + rules.max_value + '.' };
            }
        }

        return { valid: true, message: '' };
    };

    /**
     * Show an inline validation error message in the editing cell.
     * Replaces any previously shown error (only one error at a time).
     *
     * @param {jQuery} $field  The td/span being edited.
     * @param {jQuery} $input  The .gt-edit-input inside $field.
     * @param {string} message Human-readable error text.
     */
    GravityTable.prototype.showValidationError = function ($field, $input, message) {
        var fieldEl = ($field && $field[0]) ? $field[0] : $field;
        var inputEl = ($input && $input[0]) ? $input[0] : $input;

        // Remove any existing error
        var prev = fieldEl.querySelectorAll('.gt-validation-error');
        for (var i = 0; i < prev.length; i++) { prev[i].parentNode.removeChild(prev[i]); }

        if (inputEl) { inputEl.classList.add('gt-input-error'); }

        var span = document.createElement('span');
        span.className = 'gt-validation-error';
        span.style.color = '#dc2626';
        span.style.fontSize = '0.78em';
        span.style.display = 'block';
        span.style.marginTop = '2px';
        span.textContent = message;
        fieldEl.appendChild(span);
    };

})(window);
