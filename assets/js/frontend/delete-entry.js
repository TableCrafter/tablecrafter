/**
 * TableCrafter - frontend/delete-entry.js
 *
 * Single-entry delete flow. Eighteenth slice under #833. One method,
 * ~53 lines.
 *
 *   - deleteEntry(entryId, $row) - confirm dialog + AJAX delete +
 *     row removal + success/error message.
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.deleteEntry = function (entryId, $row) {
        var self = this;

        // Show loading state
        if ($row) {
            $row.addClass('gt-deleting');
            $row.find('.gt-delete-action').text('⏳').attr('title', 'Deleting...');
        }

        var data = {
            action: 'gt_delete_entry',
            nonce: this.config.nonce,
            entry_id: entryId,
            table_id: this.config.table_id || 0
        };

        $.post(this.config.ajax_url, data, function (response) {
            if (response.success) {
                // Remove row from table with animation
                if ($row) {
                    $row.fadeOut(300, function () {
                        $(this).remove();
                        // Update entry count if visible
                        self.updateEntryCount(-1);
                    });
                } else {
                    // Fallback: find row by entry ID
                    var $targetRow = $('#' + self.wrapperId).find('tr[data-entry-id="' + entryId + '"]');
                    $targetRow.fadeOut(300, function () {
                        $(this).remove();
                        self.updateEntryCount(-1);
                    });
                }

                // Show success message (less intrusive than alert)
                self.showMessage(response.data.message, 'success');
            } else {
                // Reset loading state on error
                if ($row) {
                    $row.removeClass('gt-deleting');
                    $row.find('.gt-delete-action').text('🗑').attr('title', 'Delete');
                }
                self.showMessage('Error deleting entry: ' + response.data, 'error');
            }
        }).fail(function () {
            // Reset loading state on failure
            if ($row) {
                $row.removeClass('gt-deleting');
                $row.find('.gt-delete-action').text('🗑').attr('title', 'Delete');
            }
            self.showMessage('Error deleting entry: Network error', 'error');
        });
    };

})(window);
