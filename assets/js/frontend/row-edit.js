/**
 * TableCrafter — frontend/row-edit.js
 *
 * Full-row edit feature. Fifteenth slice under #833. Two methods,
 * ~126 lines.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - editEntireRow(entryId) — expand a row into "edit-all-cells"
 *     mode: render every editable cell as an inline input at once.
 *   - saveAllFields(entryId) — batch-save AJAX: collect every cell
 *     value in the row, POST as a single gt_update_entry call.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.editEntireRow = function (entryId) {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $row = $wrapper.find('tr[data-entry-id="' + entryId + '"]');

        // Check if frontend editing is enabled
        if (!this.config.enable_frontend_editing) {
            alert('Frontend editing is not enabled for this table.');
            return;
        }

        // Get all editable fields in this row
        var $editableFields = $row.find('.gt-editable-field');

        if ($editableFields.length === 0) {
            alert('No editable fields found in this row.');
            return;
        }

        // Put all editable fields into edit mode
        $editableFields.each(function () {
            var $field = $(this);
            // Only edit if not already in edit mode
            if (!$field.find('.gt-edit-input').length) {
                self.editField($field);
            }
        });

        // Add a "Save All" button to the row
        if (!$row.find('.gt-save-all-btn').length) {
            var $actionsCell = $row.find('.gt-actions-cell');
            if ($actionsCell.length) {
                $actionsCell.prepend('<button type="button" class="gt-save-all-btn" data-entry-id="' + entryId + '">Save All</button> ');
            }

            // Bind save all event
            $wrapper.find('.gt-save-all-btn[data-entry-id="' + entryId + '"]').on('click', function () {
                self.saveAllFields(entryId);
            });
        }
    };

    GravityTable.prototype.saveAllFields = function (entryId) {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $row = $wrapper.find('tr[data-entry-id="' + entryId + '"]');
        var fieldsToSave = {};
        var hasChanges = false;

        // Collect all field values that are in edit mode
        $row.find('.gt-editable-field').each(function () {
            var $field = $(this);
            var $input = $field.find('.gt-edit-input');

            if ($input.length) {
                var fieldId = $field.data('field-id');
                var newValue = $input.val();
                var originalValue = $field.data('original-value') || '';

                if (newValue !== originalValue) {
                    fieldsToSave[fieldId] = newValue;
                    hasChanges = true;
                }
            }
        });

        if (!hasChanges) {
            alert('No changes to save.');
            return;
        }

        // Save all fields via AJAX
        //console.log('GT Frontend: Saving all fields:', fieldsToSave);
        var bulkData = {
            action: 'gt_update_entry_fields',
            nonce: this.config.nonce,
            entry_id: entryId,
            fields: fieldsToSave
        };
        // #553 slice 3 — WAF-safe payload encode for the bulk-edit path.
        // Mirrors the per-field encode above; same gt_waf_safe_payload_enabled
        // filter gate; same opaque base64 envelope under `payload`.
        if (this.config && this.config.waf_safe_payload) {
            var bulkEncoded = btoa(JSON.stringify({
                entry_id: bulkData.entry_id,
                fields: bulkData.fields
            }));
            bulkData = {
                action: 'gt_update_entry_fields',
                nonce: this.config.nonce,
                payload: bulkEncoded
            };
        }
        $.ajax({
            url: this.config.ajax_url,
            type: 'POST',
            data: bulkData,
            success: function (response) {
                //console.log('GT Frontend: Save all fields response:', response);
                if (response.success) {
                    // Update all field displays
                    Object.keys(fieldsToSave).forEach(function (fieldId) {
                        var $field = $row.find('.gt-editable-field[data-field-id="' + fieldId + '"]');
                        $field.html(self.escapeHtml(fieldsToSave[fieldId]));
                    });

                    // Remove save all button
                    $row.find('.gt-save-all-btn').remove();

                    // Reapply conditional formatting to updated cells
                    self.applyConditionalFormatting();

                    alert('All changes saved successfully!');
                    //console.log('GT Frontend: All fields saved successfully');
                } else {
                    console.error('GT Frontend: Save all fields error:', response.data);
                    alert('Error saving changes: ' + (response.data || 'Unknown error'));
                }
            },
            error: function (xhr, status, error) {
                console.error('GT Frontend: Save all fields AJAX failed:', xhr, status, error);
                console.error('GT Frontend: Response text:', xhr.responseText);
                alert('Error saving changes: ' + error);
            }
        });
    };

})(window);
