/**
 * TableCrafter - frontend/text-cell.js
 *
 * Default (non-toggle) text-cell renderer. #832 slice 8 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - renderTextCellHtml({ fieldId, entryId, displayValue, cellHtml,
 *                          isEditable, isFileUpload, colAlignStyle,
 *                          valignClass })
 *       Pure-ish helper. Depends on `this.renderFileUploadCell` and
 *       `this.escapeHtml`. Returns the `<td>...</td>` markup for the
 *       default (non-toggle) cell render path.
 *
 *       Branch table:
 *         - isFileUpload + displayValue: gt-readonly-cell with image
 *           or link HTML from renderFileUploadCell.
 *         - isEditable + displayValue:   gt-editable-cell with
 *           escapeHtml(displayValue).
 *         - isEditable + empty:          gt-editable-cell + gt-empty-
 *           field, no inner content (so the CSS `:empty` selector can
 *           apply the placeholder UX).
 *         - readonly + entry_id field:   gt-readonly-cell with
 *           <a class="gt-view-detail"> wrapper (#132).
 *         - readonly + other field:      gt-readonly-cell with cellHtml
 *           or `&nbsp;`.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        renderTextCellHtml: function (opts) {
            var self = this;
            var fieldId       = opts.fieldId;
            var entryId       = opts.entryId;
            var displayValue  = opts.displayValue;
            var cellHtml      = opts.cellHtml;
            var isEditable    = !!opts.isEditable;
            var isFileUpload  = !!opts.isFileUpload;
            var colAlignStyle = opts.colAlignStyle || '';
            var valignClass   = opts.valignClass || '';

            // Data Bars (#1731). When the caller passes a clamped barPct,
            // MERGE the bar CSS custom properties into the cell's style
            // attribute (never a second style="...") and add the
            // data-gt-bar-pct hook. Bars are attribute-only - never inner
            // markup - so totals / export / conditional-format /
            // inline-edit all keep reading the unchanged cell text.
            var barAttr = '';
            var barLabelHtml = '';
            var barPct = (typeof opts.barPct === 'number' && isFinite(opts.barPct)) ? opts.barPct : null;
            if (barPct !== null) {
                var barColor = opts.barColor || '#3b82f6';
                var barVars = '--gt-bar-pct:' + barPct + ';--gt-bar-color:' + barColor + ';';
                if (colAlignStyle && colAlignStyle.indexOf('style="') !== -1) {
                    colAlignStyle = colAlignStyle.replace('style="', 'style="' + barVars);
                } else {
                    colAlignStyle = colAlignStyle + ' style="' + barVars + '"';
                }
                barAttr = ' data-gt-bar-pct="' + barPct + '"';
                // #1738 - show_label: render the cell value as a visible
                // <span class="gt-bar-label"> alongside the ::after underlay.
                if (opts.showBarLabel) {
                    barLabelHtml = '<span class="gt-bar-label">' + (displayValue ? self.escapeHtml(String(displayValue)) : '') + '</span>';
                }
            }

            // #1606 - active global search term highlights matched
            // substrings (escape-then-highlight; plain text only).
            var searchTerm = (typeof self.searchTerm === 'string' && typeof self.highlightInEscapedHtml === 'function')
                ? self.searchTerm.trim()
                : '';

            if (isFileUpload && displayValue) {
                var fileHtml = self.renderFileUploadCell(displayValue);
                return '<td class="gt-readonly-cell' + valignClass + '" data-field-id="' + fieldId + '" data-entry-id="' + entryId + '"' + barAttr + colAlignStyle + '>' + fileHtml + barLabelHtml + '</td>';
            }

            if (isEditable) {
                if (displayValue) {
                    var editableContent = self.escapeHtml(displayValue);
                    if (searchTerm) {
                        editableContent = self.highlightInEscapedHtml(editableContent, searchTerm);
                    }
                    return '<td class="gt-editable-cell' + valignClass + '" data-field-id="' + fieldId + '" data-entry-id="' + entryId + '"' + barAttr + colAlignStyle + '>' + editableContent + barLabelHtml + '</td>';
                }
                return '<td class="gt-editable-cell gt-empty-field' + valignClass + '" data-field-id="' + fieldId + '" data-entry-id="' + entryId + '"' + barAttr + colAlignStyle + '>' + barLabelHtml + '</td>';
            }

            // Readonly branch. Highlight only plain-text cell content - 
            // server-rendered markup (links, images) is left untouched
            // so the wrapper can't land inside an attribute. (#1606)
            var isEntryId = fieldId === 'entry_id';
            var displayContent = cellHtml || '&nbsp;';
            if (searchTerm && cellHtml && String(cellHtml).indexOf('<') === -1) {
                displayContent = self.highlightInEscapedHtml(String(cellHtml), searchTerm);
            }
            if (isEntryId) {
                displayContent = '<a href="#" class="gt-view-detail" data-entry-id="' + entryId + '">' + displayContent + '</a>';
            }
            return '<td class="gt-readonly-cell' + valignClass + '" data-field-id="' + fieldId + '" data-entry-id="' + entryId + '"' + barAttr + colAlignStyle + '>' + displayContent + barLabelHtml + '</td>';
        }

    });

})(window);
