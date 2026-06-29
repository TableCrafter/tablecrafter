/**
 * TableCrafter — frontend/bulk-column-fill.js
 *
 * Pro-gated bulk column fill for selected rows.
 * Opens a modal with a field selector + value input;
 * POSTs to gt_bulk_fill_column AJAX handler on confirm.
 *
 * Closes #1745.
 *
 * Surface (GravityTable.prototype):
 *   - openBulkFillModal(entryIds)   — builds and shows the fill modal.
 *   - executeBulkFill({entryIds, fieldId, value}) — fires AJAX.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery || window.$;

    GravityTable.prototype.openBulkFillModal = function (entryIds) {
        var config = this.config || {};
        if (!config.is_pro) { return; }
        if (!entryIds || !entryIds.length) { return; }

        var self = this;
        // Build field_labels from column_config (field_id => label) so we don't need
        // a separate config key. Falls back to explicit field_labels if provided.
        var fieldLabels = config.field_labels || {};
        if (!Object.keys(fieldLabels).length && config.column_config) {
            var cc = config.column_config;
            Object.keys(cc).forEach(function (fid) {
                if (cc[fid] && cc[fid].label) { fieldLabels[fid] = cc[fid].label; }
            });
        }
        var fields = Object.keys(fieldLabels);
        if (!fields.length) { return; }

        document.getElementById('gt-bulk-fill-modal') && document.getElementById('gt-bulk-fill-modal').remove();

        var optionsHtml = fields.map(function (fid) {
            return '<option value="' + fid + '">' + (fieldLabels[fid] || fid) + '</option>';
        }).join('');

        var modal = document.createElement('div');
        modal.id = 'gt-bulk-fill-modal';
        modal.className = 'gt-modal-overlay';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML =
            '<div class="gt-modal-dialog" style="background:#fff;border-radius:6px;padding:24px;min-width:320px;max-width:480px;box-shadow:0 4px 24px rgba(0,0,0,.3);">' +
            '<h3 class="gt-modal-title">Fill Column for ' + entryIds.length + ' Row' + (entryIds.length === 1 ? '' : 's') + '</h3>' +
            '<div class="gt-modal-body">' +
            '<label class="gt-field-label">Column<br>' +
            '<select name="gt_fill_field">' + optionsHtml + '</select>' +
            '</label>' +
            '<label class="gt-field-label" style="margin-top:10px;">Value<br>' +
            '<input type="text" name="gt_fill_value" value="" placeholder="New value" style="width:100%;">' +
            '</label>' +
            '</div>' +
            '<div class="gt-modal-footer">' +
            '<button type="button" class="gt-bulk-fill-confirm button button-primary">Apply</button>' +
            '<button type="button" class="gt-bulk-fill-cancel button">Cancel</button>' +
            '</div>' +
            '</div>';

        document.body.appendChild(modal);

        // #1749 — live diff preview on the Apply button (Pro).
        var $applyBtn = modal.querySelector('.gt-bulk-fill-confirm');
        var $valInput = modal.querySelector('input[name="gt_fill_value"]');
        var $fieldSel = modal.querySelector('select[name="gt_fill_field"]');
        var _previewTimer = null;
        function updatePreview() {
            if (typeof self.updateBulkFillPreview === 'function') {
                var label = $fieldSel.options[$fieldSel.selectedIndex] ? $fieldSel.options[$fieldSel.selectedIndex].text : '';
                self._bulkFillApplyBtn = $applyBtn;
                self.updateBulkFillPreview(entryIds.length, label, $valInput.value.trim());
            }
        }
        $valInput.addEventListener('input', function () {
            clearTimeout(_previewTimer);
            _previewTimer = setTimeout(updatePreview, 300);
        });
        $fieldSel.addEventListener('change', updatePreview);

        modal.querySelector('.gt-bulk-fill-cancel').addEventListener('click', function () {
            modal.remove();
        });

        modal.querySelector('.gt-bulk-fill-confirm').addEventListener('click', function () {
            var fieldId = modal.querySelector('select[name="gt_fill_field"]').value;
            var value   = modal.querySelector('input[name="gt_fill_value"]').value;
            modal.remove();
            self.executeBulkFill({ entryIds: entryIds, fieldId: fieldId, value: value });
        });
    };

    GravityTable.prototype.executeBulkFill = function (opts) {
        var config    = this.config || {};
        if (!config.is_pro) { return; }

        var entryIds  = (opts && opts.entryIds) || [];
        var fieldId   = (opts && opts.fieldId)  || '';
        var value     = (opts && opts.value !== undefined) ? opts.value : '';

        if (!entryIds.length || !fieldId) { return; }

        var self = this;
        var ajaxUrl = config.ajax_url || (window.ajaxurl || '');

        $.post(ajaxUrl, {
            action:    'gt_bulk_fill_column',
            nonce:     config.nonce || '',
            table_id:  config.table_id || '',
            entry_ids: entryIds,
            field_id:  fieldId,
            value:     value,
        })
        .done(function (response) {
            if (response && response.success) {
                if (typeof self.loadEntries === 'function') {
                    self.loadEntries();
                }
            }
        })
        .fail(function () {
            /* silently ignore network failures — table state unchanged */
        });
    };

}(window));
