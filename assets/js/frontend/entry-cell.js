/**
 * TableCrafter - frontend/entry-cell.js
 *
 * Per-column cell dispatcher. #832 slice 10 of N.
 *
 * Two helpers attached to GravityTable.prototype via Object.assign:
 *
 *   - resolveCellEditability(config, fieldId)
 *       Pure helper. Returns the boolean isEditable verdict given
 *       the table config + a fieldId. Branch table:
 *         1. config.enable_frontend_editing === false        → false
 *         2. fieldId is auto-generated (entry_id, date_created,
 *            date_updated, is_starred, is_read, ip, source_url,
 *            user_id)                                         → false
 *         3. column_config[fieldId].editable === false        → false
 *         4. else                                              → true
 *
 *   - renderEntryCellHtml(entry, fieldId)
 *       Orchestrator. Returns the full <td>…</td> markup for one
 *       (entry, fieldId) pair by composing:
 *         - resolveCellEditability (this module)
 *         - formatDate (util)              when column is a date type
 *         - resolveCellAlignment (alignment-resolver)
 *         - escapeHtml (util)              for #132/#439-safe cellHtml
 *         - buildLinkAnchorHtml (link-anchor)  when readonly + linkSettings
 *         - renderToggleCellHtml (toggle-cell)  when column.type === 'toggle'
 *         - renderTextCellHtml (text-cell)     otherwise (default branch)
 *
 *       Reads this.config so the call site stays tight:
 *         html += self.renderEntryCellHtml(entry, fieldId);
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var AUTO_GENERATED_FIELDS = [
        'entry_id', 'date_created', 'date_updated',
        'is_starred', 'is_read', 'ip', 'source_url', 'user_id'
    ];

    Object.assign(window.GravityTable.prototype, {

        resolveCellEditability: function (config, fieldId) {
            if (!config || !config.enable_frontend_editing) {
                return false;
            }
            if (AUTO_GENERATED_FIELDS.indexOf(fieldId) !== -1) {
                return false;
            }
            var colCfg = config.column_config && config.column_config[fieldId];
            if (colCfg && colCfg.editable === false) {
                return false;
            }
            return true;
        },

        renderEntryCellHtml: function (entry, fieldId) {
            var self = this;
            var config = self.config || {};
            var columnConfig = config.column_config || {};

            var value = entry[fieldId] || '';
            var isEditable = self.resolveCellEditability(config, fieldId);

            // Format dates according to user settings.
            var displayValue = value;
            var colCfg = columnConfig[fieldId] || {};
            if (value && (colCfg.type === 'date' || fieldId === 'date_created')) {
                displayValue = self.formatDate(value, config.date_format);
            }

            // Three-layer alignment cascade (#549).
            var _gtAlign = self.resolveCellAlignment(config, fieldId, entry['entry_id']);
            var colAlignStyle = _gtAlign.style;
            var valignClass = _gtAlign.valignClass;

            // Build cellHtml: if the value starts with '<' it contains
            // server-sanitized HTML (e.g. <img> tags) - pass through raw
            // so images render (#439). Otherwise escape the plain-text
            // value to prevent double-escaping when link settings later
            // wrap displayValue in an <a> tag (#132).
            var cellHtml = displayValue
                ? (displayValue.charAt(0) === '<' ? displayValue : self.escapeHtml(displayValue))
                : '';

            // Column link-settings anchor builder (#109/#362/#384).
            var linkSettings = (config.column_link_settings && config.column_link_settings[fieldId])
                ? config.column_link_settings[fieldId]
                : null;
            if (linkSettings && displayValue && !isEditable) {
                var anchorHtml = self.buildLinkAnchorHtml(linkSettings, displayValue);
                if (anchorHtml) {
                    cellHtml = anchorHtml;
                }
            }

            // Toggle / boolean column (#325).
            if (colCfg.type === 'toggle') {
                return self.renderToggleCellHtml({
                    value: displayValue,
                    fieldId: fieldId,
                    entryId: entry.entry_id,
                    colConfig: colCfg,
                    isEditable: isEditable,
                    valignClass: valignClass,
                    colAlignStyle: colAlignStyle,
                });
            }

            // Default text-cell branch (#132 anchor wrapper preserved).
            var isFileUpload = colCfg.type === 'fileupload' || colCfg.upload_capable;

            // Data Bars (#1731). Derive the per-cell bar params from the
            // page-scoped column max (self._barMax, set in renderEntries)
            // and the suppression context. computeDataBarParams returns
            // null whenever a bar should not render (free tier strips the
            // config upstream, so this only fires for premium tables).
            // Conditional-format backgrounds are suppressed by a CSS guard.
            var barParams = (typeof self.computeDataBarParams === 'function')
                ? self.computeDataBarParams(config, fieldId, colCfg, value, {
                    barMax: self._barMax ? self._barMax[fieldId] : undefined,
                    isFileUpload: isFileUpload,
                    hasLinkSettings: !!linkSettings,
                })
                : null;

            return self.renderTextCellHtml({
                fieldId: fieldId,
                entryId: entry.entry_id,
                displayValue: displayValue,
                cellHtml: cellHtml,
                isEditable: isEditable,
                isFileUpload: isFileUpload,
                colAlignStyle: colAlignStyle,
                valignClass: valignClass,
                barPct: barParams ? barParams.pct : null,
                barColor: barParams ? barParams.color : null,
            });
        }

    });

})(window);
