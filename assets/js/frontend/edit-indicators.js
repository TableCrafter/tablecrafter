/**
 * TableCrafter — frontend/edit-indicators.js
 *
 * Edit-mode UX overlays. Sixteenth slice under #833. Two methods,
 * ~94 lines.
 *
 *   - showEditIndicator($row, entryId) — show the "editing row N" badge
 *     overlay; auto-hide on click-out.
 *   - showReadonlyIndicator($cell) — flash the readonly-cell tooltip
 *     when the user clicks a non-editable cell.
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.showEditIndicator = function ($row, entryId) {
        var self = this;

        // Don't show edit indicator if frontend editing is disabled
        if (!this.config.enable_frontend_editing) {
            //console.log('GT Frontend: Frontend editing is disabled, not showing edit indicator');
            return;
        }

        // Create edit indicator popup
        var $indicator = $('<div class="gt-edit-indicator">' +
            '<div class="gt-edit-indicator-content">' +
            '<span class="gt-edit-indicator-text">This field is read-only and cannot be edited</span>' +
            '<button type="button" class="gt-edit-row-btn" data-entry-id="' + entryId + '">Edit Row</button>' +
            '</div>' +
            '</div>');

        // Position the indicator relative to the row
        var rowOffset = $row.offset();
        var tableOffset = $('#' + this.wrapperId).offset();

        $indicator.css({
            position: 'absolute',
            top: (rowOffset.top - tableOffset.top + $row.outerHeight() + 5) + 'px',
            left: (rowOffset.left - tableOffset.left + 10) + 'px',
            zIndex: 1000
        });

        // Add to table wrapper
        $('#' + this.wrapperId).append($indicator);

        // Bind click event for edit button
        $indicator.find('.gt-edit-row-btn').on('click', function () {
            // Check if frontend editing is enabled
            if (!self.config.enable_frontend_editing) {
                //console.log('GT Frontend: Frontend editing is disabled');
                $indicator.remove();
                $row.removeClass('gt-row-selected');
                return;
            }

            var entryId = $(this).data('entry-id');
            self.editEntireRow(entryId);
            $indicator.remove();
            $row.removeClass('gt-row-selected');
        });

        // Auto-hide after 5 seconds
        setTimeout(function () {
            if ($indicator.is(':visible')) {
                $indicator.fadeOut(300, function () {
                    $indicator.remove();
                    $row.removeClass('gt-row-selected');
                });
            }
        }, 5000);
    };

    GravityTable.prototype.showReadonlyIndicator = function ($cell) {
        var self = this;

        // Remove any existing indicators
        $('#' + this.wrapperId).find('.gt-readonly-indicator').remove();

        // Create readonly indicator popup
        var $indicator = $('<div class="gt-readonly-indicator">' +
            '<div class="gt-readonly-indicator-content">' +
            '<span class="gt-readonly-indicator-text">This field is read-only and cannot be edited</span>' +
            '</div>' +
            '</div>');

        // Position the indicator relative to the cell
        var cellOffset = $cell.offset();
        var tableOffset = $('#' + this.wrapperId).offset();

        $indicator.css({
            position: 'absolute',
            top: (cellOffset.top - tableOffset.top - 35) + 'px',
            left: (cellOffset.left - tableOffset.left + ($cell.outerWidth() / 2) - 100) + 'px',
            zIndex: 1000
        });

        // Add to table wrapper
        $('#' + this.wrapperId).append($indicator);

        // Auto-hide after 3 seconds
        setTimeout(function () {
            if ($indicator.is(':visible')) {
                $indicator.fadeOut(300, function () {
                    $indicator.remove();
                });
            }
        }, 3000);
    };

})(window);
