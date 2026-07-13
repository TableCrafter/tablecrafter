/**
 * TableCrafter - frontend/edit-save.js
 *
 * Inline cell-edit AJAX save. Second slice under #833 / #889.
 * Pairs with edit-history.js (slice 1) - saveField calls into
 * pushHistoryEntry + getFieldLabel via this/self.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - saveField(entryId, fieldId, value, $field, displayValue)
 *
 * Behaviors preserved verbatim from the pre-#833 monolith:
 *
 *   - History interop: when `_suppressHistory` is false and
 *     oldValue !== value, push a history entry via this.pushHistoryEntry
 *     (defined in edit-history.js).
 *   - Optimistic UI: swap the cell content to "Saving..." immediately.
 *   - AJAX payload: action=gt_update_entry, nonce, entry_id, table_id,
 *     updates[fieldId] = value.
 *   - WAF envelope (#553): when this.config.waf_safe_payload is on,
 *     wrap entry_id + table_id + updates into a base64-encoded `payload`
 *     field so generic WAF rules don't false-positive on cell content.
 *   - Debug flag: when `?gt_debug=1` is on the URL, append `debug=true`.
 *   - Success: render escaped displayValue (or value), restore any
 *     `original-padding` data on the cell, fan out updated calc fields
 *     into sibling cells with a transient `gt-recalculated` highlight,
 *     reapply conditional formatting, refresh column totals if enabled.
 *   - Server-side error: alert the response.data message, restore the
 *     cell to its original value, restore padding.
 *   - Network failure: alert the network error, restore the cell value.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.saveField = function (entryId, fieldId, value, $field, displayValue) {
        var self = this;

        // Track this edit on the undo stack unless we're replaying from undo/redo
        if (!self._suppressHistory) {
            var oldValue = $field.data('original-value');
            if (oldValue === undefined) oldValue = '';
            // Skip pushing no-ops
            if (String(oldValue) !== String(value)) {
                self.pushHistoryEntry({
                    entryId: String(entryId),
                    fieldId: String(fieldId),
                    oldValue: String(oldValue),
                    newValue: String(value),
                    fieldLabel: self.getFieldLabel(fieldId)
                });
            }
        }

        // Show saving state
        $field.removeClass('gt-editing');
        $field.html('Saving...');

        var data = {
            action: 'gt_update_entry',
            nonce: this.config.nonce,
            entry_id: entryId,
            table_id: this.config.table_id || 0,
            updates: {}
        };
        data.updates[fieldId] = value;

        // #553 slice 3 - WAF-safe payload client-side encode. When the
        // self.config.waf_safe_payload flag is on (driven by the
        // gt_waf_safe_payload_enabled filter on the server side), wrap entry_id
        // + updates into an opaque base64 envelope under `payload` so generic
        // WAF rules (Cloudflare/Sucuri/mod_security/Wordfence) can't pattern-
        // match SQLi/XSS tokens against legitimate cell content. Server (slice
        // 2 v4.41.0) accepts both shapes - encoded or legacy form-encoded - 
        // and falls through cleanly when the envelope is absent.
        if (this.config && this.config.waf_safe_payload) {
            var encoded = btoa(JSON.stringify({
                entry_id: data.entry_id,
                table_id: data.table_id,
                updates: data.updates
            }));
            data = {
                action: 'gt_update_entry',
                nonce: this.config.nonce,
                payload: encoded
            };
        }

        // Enable debugging if URL contains debug parameter
        if (window.location.href.indexOf('gt_debug=1') !== -1) {
            /* c8 ignore next */
            data.debug = 'true';
        }

        //console.log('GT Frontend: Saving field data:', data);

        $.post(this.config.ajax_url, data, function (response) {
            //console.log('GT Frontend: Save field response:', response);
            if (response.success) {
                // For lookup fields, show the display value instead of the stored ID
                var finalDisplayValue = displayValue || value;

                // Special handling for empty values - show empty cell instead of cached old values
                if (value === '' || value === null) {
                    finalDisplayValue = '';
                }

                $field.html(self.escapeHtml(finalDisplayValue));

                // #1749 - show diff badge if value changed.
                if (typeof self.showDiffBadge === 'function') {
                    var preEditValue = $field.data('original-value');
                    self.showDiffBadge($field[0], String(preEditValue !== undefined ? preEditValue : ''), String(finalDisplayValue));
                }

                // Restore original padding if it was a cell-based edit
                if ($field.hasClass('gt-editable-cell') && $field.data('original-padding')) {
                    $field.css('padding', $field.data('original-padding'));
                    $field.removeData('original-padding');
                }

                // Update any recalculated calculation fields in the same row
                if (response.data && response.data.updated_fields) {
                    var $row = $field.closest('tr');
                    $.each(response.data.updated_fields, function (recalcFieldId, recalcValue) {
                        // Skip the field we just edited (already updated above).
                        // #893: object keys from $.each are always strings, so
                        // coerce both sides before compare. Without this, a
                        // caller passing fieldId as a Number would see the
                        // just-rendered cell clobbered by the echoed value.
                        if (String(recalcFieldId) === String(fieldId)) {
                            return;
                        }
                        var $calcCell = $row.find('td[data-field-id="' + recalcFieldId + '"]');
                        if ($calcCell.length) {
                            $calcCell.html(self.escapeHtml(recalcValue));
                            // Brief highlight animation to show the cell was updated
                            $calcCell.addClass('gt-recalculated');
                            setTimeout(function () {
                                $calcCell.removeClass('gt-recalculated');
                            }, 1500);
                        }
                    });
                }

                // Reapply conditional formatting to updated cell
                self.applyConditionalFormatting();

                // Refresh column totals after edit
                if (self.config.show_column_totals) {
                    self.updateColumnTotals();
                }

                //console.log('GT Frontend: Field saved successfully');
            } else {
                console.error('GT Frontend: Save field error:', response.data);
                $field.html(self.escapeHtml($field.data('original-value')));

                // Restore original padding on error too
                if ($field.hasClass('gt-editable-cell') && $field.data('original-padding')) {
                    $field.css('padding', $field.data('original-padding'));
                    $field.removeData('original-padding');
                }

                alert('Error saving field: ' + response.data);
            }
        }).fail(function (xhr, status, error) {
            console.error('GT Frontend: Save field AJAX failed:', xhr, status, error);
            console.error('GT Frontend: Response text:', xhr.responseText);
            $field.html(self.escapeHtml($field.data('original-value')));
            alert('Error saving field: ' + error);
        });
    };

})(window);
