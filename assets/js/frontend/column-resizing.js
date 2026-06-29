/**
 * TableCrafter — frontend/column-resizing.js
 *
 * Column drag-to-resize feature. Seventeenth slice under #833. One
 * method, ~77 lines.
 *
 *   - initializeColumnResizing($wrapper) — wire mousedown handles on
 *     .gt-resizer elements, drag to resize columns, persist widths
 *     to localStorage per table_id.
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.initializeColumnResizing = function ($wrapper) {
        var self = this;
        var startX, startWidth;
        var $activeTh = null;
        var isResizing = false;

        $wrapper.on('mousedown', '.gt-resizer', function (e) {
            e.preventDefault();
            e.stopPropagation();

            isResizing = true;
            $activeTh = $(this).closest('th');
            startX = e.pageX;
            startWidth = $activeTh.outerWidth();

            $activeTh.addClass('resizing');
            $wrapper.addClass('resizing-active');
            $('body').addClass('gt-resizing-active');

            $(document).on('mousemove.gt-resize', function (e) {
                if (!isResizing) return;

                var width = startWidth + (e.pageX - startX);
                if (width > 50) { // Minimum width
                    $activeTh.css({
                        'width': width + 'px',
                        'min-width': width + 'px',
                        'max-width': width + 'px'
                    });
                }
            });

            $(document).on('mouseup.gt-resize', function () {
                isResizing = false;
                if ($activeTh) {
                    $activeTh.removeClass('resizing');

                    // Persist column width to localStorage so resize works for all users
                    var fieldId = $activeTh.attr('data-field-id');
                    var newWidth = $activeTh.outerWidth();
                    if (fieldId && newWidth && window.localStorage) {
                        var storageKey = 'gt-col-width-' + self.config.table_id + '-' + fieldId;
                        try { localStorage.setItem(storageKey, newWidth); } catch (e) {}
                    }
                }
                $wrapper.removeClass('resizing-active');
                $('body').removeClass('gt-resizing-active');
                $(document).off('mousemove.gt-resize');
                $(document).off('mouseup.gt-resize');
                // Sticky-header layout recalc is no longer needed — native
                // position:sticky on <th> handles re-alignment automatically.
            });
        });

        // Prevent click from bubbling up to sort header when clicking resizer
        $wrapper.on('click', '.gt-resizer', function (e) {
            e.stopPropagation();
            e.preventDefault();
        });

        // Restore saved column widths from localStorage (#111 — works for all users)
        if (window.localStorage && self.config.table_id) {
            $wrapper.find('thead th[data-field-id]').each(function () {
                var fid = $(this).attr('data-field-id');
                var storageKey = 'gt-col-width-' + self.config.table_id + '-' + fid;
                try {
                    var saved = localStorage.getItem(storageKey);
                    if (saved) {
                        var w = parseInt(saved, 10);
                        if (w > 50) {
                            $(this).css({ width: w + 'px', 'min-width': w + 'px', 'max-width': w + 'px' });
                        }
                    }
                } catch (e) {}
            });
        }
    };

})(window);
