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
    /**
     * Validate a cell value against column_validations config.
     *
     * @param {string|number} fieldId
     * @param {string} value
     * @param {number|string} [entryId]  Current entry ID — used by the unique rule to exclude
     *                                   the row being edited from the duplicate scan.
     * @returns {{valid: boolean, message: string}}
     */
    GravityTable.prototype.validateCell = function (fieldId, value, entryId) {
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

        // #2282 — oneOf: value must be in the allowed list (strict string comparison).
        // An empty oneOf array means no restriction (skip the rule).
        if (rules.oneOf && Array.isArray(rules.oneOf) && rules.oneOf.length > 0) {
            if (rules.oneOf.indexOf(str) === -1) {
                return { valid: false, message: 'Value is not one of the allowed options.' };
            }
        }

        // #2282 — notOneOf: value must NOT be in the blocked list (strict string comparison).
        if (rules.notOneOf && Array.isArray(rules.notOneOf) && rules.notOneOf.length > 0) {
            if (rules.notOneOf.indexOf(str) !== -1) {
                return { valid: false, message: 'Value is not allowed.' };
            }
        }

        // #2282 — phone validation: dual mode — permissive or E.164.
        if (rules.phone) {
            if (rules.phone === 'permissive') {
                // Permissive: strip formatting chars (spaces, parens, dots, +, dashes),
                // then require 7–15 remaining digit characters.
                var stripped = str.replace(/[\s().+\-]/g, '');
                if (!/^\d{7,15}$/.test(stripped)) {
                    return { valid: false, message: 'Please enter a valid phone number.' };
                }
            } else {
                // E.164 mode: optional + prefix, first significant digit [1-9], 1–14 more digits.
                if (!/^\+?[1-9]\d{1,14}$/.test(str)) {
                    return { valid: false, message: 'Please enter a valid phone number (E.164 format).' };
                }
            }
        }

        // #2282 — unique: client-side pre-check by scanning this.config.table_data.
        //
        // In non-SSP mode, when table_data is available (pre-loaded rows array), scan
        // it synchronously to catch obvious duplicates before the AJAX save round-trip.
        // The server enforces the authoritative unique constraint via GFAPI::get_entries
        // or via the gt_check_unique endpoint (used for SSP and any missed client cases).
        //
        // Skip in server-side processing (SSP) mode — all rows are not loaded locally.
        if (rules.unique) {
            var tableData = config.table_data;
            var isSSP = config.processing_mode === 'server';
            if (!isSSP && tableData && Array.isArray(tableData)) {
                var currentEntryId = entryId !== undefined ? String(entryId) : null;
                var fieldIdStr = String(fieldId);
                for (var i = 0; i < tableData.length; i++) {
                    var row = tableData[i];
                    if (currentEntryId !== null && String(row.entry_id) === currentEntryId) {
                        continue; // exclude current entry from duplicate check
                    }
                    if (String(row[fieldIdStr] || '') === str) {
                        return { valid: false, message: 'This value must be unique.' };
                    }
                }
            }
            // SSP mode or no table_data — skip client-side check; server enforces.
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
