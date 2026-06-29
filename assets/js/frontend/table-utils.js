/**
 * TableCrafter — frontend/table-utils.js
 *
 * Small table-utility methods. Nineteenth slice under #833. Four
 * methods (~50 lines):
 *
 *   - destroy()          — clean up event handlers and remove the table.
 *   - adjustColumns()    — DataTables-style column-width recompute.
 *   - showMessage(msg, t)— flash a transient inline status message.
 *   - updateEntryCount(delta) — increment/decrement the visible entry count.
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.destroy = function () {
        var $wrapper = $('#' + this.wrapperId);
        $wrapper.off('.gt-table');
        $(document).off('click.gtTable' + this.wrapperId);
        $(document).off('keydown.gtUndo' + this.wrapperId);
        $wrapper.data('gt-initialized', false);
    };

    GravityTable.prototype.adjustColumns = function () {
        // Re-trigger an entry load so DataTables / column widths recalculate
        // now that the wrapper has non-zero dimensions.
        this.loadEntries();
    };

    GravityTable.prototype.showMessage = function (message, type) {
        var $wrapper = $('#' + this.wrapperId);
        var messageClass = type === 'success' ? 'gt-message-success' : 'gt-message-error';

        // Remove any existing messages
        $wrapper.find('.gt-message').remove();

        // Create and show new message
        var $message = $('<div class="gt-message ' + messageClass + '">' + message + '</div>');
        $wrapper.prepend($message);

        // Auto-hide after 5 seconds
        setTimeout(function () {
            $message.fadeOut(300, function () {
                $(this).remove();
            });
        }, 5000);
    };

    GravityTable.prototype.updateEntryCount = function (delta) {
        var $wrapper = $('#' + this.wrapperId);
        var $countElement = $wrapper.find('.gt-entry-count');

        if ($countElement.length > 0) {
            var currentCount = parseInt($countElement.text()) || 0;
            var newCount = Math.max(0, currentCount + delta);
            $countElement.text(newCount);
        }
    };


})(window);
