/**
 * TableCrafter - frontend/toggle-cell.js
 *
 * Toggle / boolean column cell renderer (#325). #832 slice 6 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - renderToggleCellHtml({ value, fieldId, entryId, colConfig,
 *                            isEditable, valignClass, colAlignStyle })
 *       Pure helper. Builds a `<td class="gt-toggle-cell ...">` with a
 *       nested `<span class="gt-toggle-switch">` for a toggle column.
 *       Reads `normalizeToggleValue` + `escapeHtml` off `this` so it
 *       can stay pure (no DOM, no jQuery).
 *
 *       isEditable=true adds:
 *         - `gt-editable-cell` to the <td> class list (vs gt-readonly-cell).
 *         - `role="switch"`, `aria-checked`, `tabindex="0"` on the span.
 *
 *       colConfig.toggle_on_label / toggle_off_label override the
 *       default "Yes" / "No" aria-label. Both are escapeHtml-ed.
 *
 *       Pre-requisite: util.js must load first (normalizeToggleValue,
 *       escapeHtml).
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        renderToggleCellHtml: function (opts) {
            var self = this;
            var value         = opts.value;
            var fieldId       = opts.fieldId;
            var entryId       = opts.entryId;
            var colConfig     = opts.colConfig || {};
            var isEditable    = !!opts.isEditable;
            var valignClass   = opts.valignClass || '';
            var colAlignStyle = opts.colAlignStyle || '';

            var toggleVal  = self.normalizeToggleValue(value);
            var onLabel    = colConfig.toggle_on_label  || 'Yes';
            var offLabel   = colConfig.toggle_off_label || 'No';
            var stateClass = toggleVal ? 'gt-toggle-on' : 'gt-toggle-off';
            var ariaLabel  = toggleVal ? onLabel : offLabel;

            var switchHtml = '<span class="gt-toggle-switch ' + stateClass + '" ' +
                'aria-label="' + self.escapeHtml(ariaLabel) + '" ' +
                'data-toggle-value="' + toggleVal + '" ' +
                'data-field-id="' + fieldId + '" ' +
                'data-entry-id="' + entryId + '" ' +
                (isEditable ? 'role="switch" aria-checked="' + (toggleVal ? 'true' : 'false') + '" tabindex="0"' : '') +
                '></span>';

            return '<td class="gt-toggle-cell ' + (isEditable ? 'gt-editable-cell' : 'gt-readonly-cell') + valignClass + '" ' +
                'data-field-id="' + fieldId + '" data-entry-id="' + entryId + '"' + colAlignStyle + '>' +
                switchHtml + '</td>';
        }

    });

})(window);
