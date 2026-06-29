/**
 * TableCrafter — frontend/bind-entry-events.js
 *
 * Per-row event wiring. Eleventh slice under #833. One method,
 * ~186 lines.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - bindEntryEvents()
 *
 * Delegates per-row events on the tbody: action button clicks
 * (view / edit / delete / history / WC-create), row-link clicks
 * (#567), inline-edit cell clicks, file-upload triggers.
 * Called once from init() (or re-called after renderEntries swaps
 * out the tbody, namespaced for clean teardown).
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.bindEntryEvents = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);

        // #556 slice 3: chevron click toggles the sibling gt-detail-row.
        // Delegated so it stays bound across re-renders. Bound once via
        // $wrapper.data('gt-detail-bound') because bindEntryEvents fires on
        // every renderEntries — without the guard we'd stack N handlers.
        if (!$wrapper.data('gt-detail-bound')) {
            $wrapper.on('click', '.gt-detail-toggle', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $btn = $(this);
                var targetId = $btn.attr('aria-controls');
                var $detail = $wrapper.find('#' + targetId);
                if (!$detail.length) { return; }
                var expanded = $btn.attr('aria-expanded') === 'true';
                if (expanded) {
                    $detail.attr('hidden', 'hidden').prop('hidden', true);
                    $btn.attr('aria-expanded', 'false').attr('aria-label', 'Show row details');
                } else {
                    $detail.removeAttr('hidden').prop('hidden', false);
                    $btn.attr('aria-expanded', 'true').attr('aria-label', 'Hide row details');
                }
            });
            // Keyboard activation (Enter / Space) — buttons already do this
            // natively, but stopPropagation above means we also want to keep
            // it from bubbling into the row-click handler.
            $wrapper.on('keydown', '.gt-detail-toggle', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.stopPropagation();
                }
            });
            $wrapper.data('gt-detail-bound', true);
        }

        // Toggle switch click: flip boolean value and save via AJAX (#325)
        $wrapper.on('click keydown', '.gt-toggle-switch', function (e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            e.stopPropagation();
            var $switch   = $(this);
            var entryId   = $switch.data('entry-id');
            var fieldId   = $switch.data('field-id');
            var curVal    = parseInt($switch.data('toggle-value'), 10);
            var newVal    = curVal ? 0 : 1;
            $switch.data('toggle-value', newVal)
                   .toggleClass('gt-toggle-on', newVal === 1)
                   .toggleClass('gt-toggle-off', newVal === 0)
                   .attr('aria-checked', newVal ? 'true' : 'false');
            var $cell = $switch.closest('td');
            self.saveField(entryId, fieldId, String(newVal), $cell);
        });

        // Row click for inline editing option (but not on clickable elements)
        $wrapper.find('tbody tr').on('click', function (e) {
            // Don't trigger if clicking on buttons, checkboxes, or editable fields
            if ($(e.target).is('button, input, .gt-editable-field, .gt-edit-input, .gt-save-btn, .gt-cancel-btn, .gt-editable-cell')) {
                return;
            }

            var $row = $(this);
            var entryId = $row.data('entry-id');
            var $clickedCell = $(e.target).closest('td');

            // Check if clicked on a readonly cell
            if ($clickedCell.hasClass('gt-readonly-cell')) {
                // Only show readonly message if frontend editing is enabled
                // If editing is disabled globally, no message is needed
                if (self.config.enable_frontend_editing) {
                    self.showReadonlyIndicator($clickedCell);
                }
                return;
            }

            // Remove any existing edit indicators
            $wrapper.find('.gt-edit-indicator').remove();
            $wrapper.find('tbody tr').removeClass('gt-row-selected');

            // Add selected class
            $row.addClass('gt-row-selected');

            // Show edit indicator/tooltip
            self.showEditIndicator($row, entryId);
        });

        // Editable fields
        // Handle clicks on editable cells (spreadsheet-like behavior)
        $wrapper.find('.gt-editable-cell').on('click', function (e) {
            e.stopPropagation(); // Prevent row click

            // Check if we clicked on an existing input/select - don't re-edit
            if ($(e.target).is('input, select, option')) {
                //console.log('GT Frontend: Click on input/select, not re-editing field');
                return;
            }

            // Check if this cell already has an active input/select
            if ($(this).find('.gt-edit-input').length > 0) {
                //console.log('GT Frontend: Cell already has active input, focusing it instead');
                $(this).find('.gt-edit-input').focus();
                return;
            }

            // Check if frontend editing is enabled
            if (!self.config.enable_frontend_editing) {
                //console.log('GT Frontend: Frontend editing is disabled');
                return;
            }

            self.editField($(this));
        });

        // Keep backward compatibility for existing gt-editable-field elements
        $wrapper.find('.gt-editable-field').on('click', function (e) {
            e.stopPropagation(); // Prevent row click

            // Check if frontend editing is enabled
            if (!self.config.enable_frontend_editing) {
                //console.log('GT Frontend: Frontend editing is disabled');
                return;
            }

            self.editField($(this));
        });

        // #1921 — Mobile touch-tap: fast-edit on tap without the 300ms synthetic-click
        // delay on older iOS. A movement guard (>10px) prevents scroll from opening an
        // editor. preventDefault on the touchend cancels the subsequent synthetic click
        // so editField is not called twice when touch + click both fire.
        $wrapper.find('.gt-editable-cell, .gt-editable-field').on('touchstart', function (e) {
            var t = e.originalEvent.changedTouches && e.originalEvent.changedTouches[0];
            if (t) {
                $(this).data('gt-touch-start', { x: t.clientX, y: t.clientY });
            }
        }).on('touchend', function (e) {
            var $cell = $(this);
            var start = $cell.data('gt-touch-start');
            if (!start) { return; }
            var t = e.originalEvent.changedTouches && e.originalEvent.changedTouches[0];
            $cell.removeData('gt-touch-start');
            if (!t) { return; }
            var dx = Math.abs(t.clientX - start.x);
            var dy = Math.abs(t.clientY - start.y);
            if (dx > 10 || dy > 10) { return; } // scroll gesture — do not edit
            if ($(e.target).is('input, select, option')) { return; }
            if ($cell.find('.gt-edit-input').length > 0) {
                $cell.find('.gt-edit-input').focus();
                e.preventDefault();
                return;
            }
            if (!self.config.enable_frontend_editing) { return; }
            e.preventDefault(); // suppress synthetic click
            e.stopPropagation();
            self.editField($cell);
        });

        // Action buttons (edit button removed - using inline editing instead)

        $wrapper.find('.gt-delete-action').on('click', function (e) {
            e.stopPropagation(); // Prevent row click
            var entryId = $(this).data('entry-id');
            if (confirm('Are you sure you want to delete this entry?')) {
                self.deleteEntry(entryId);
            }
        });

        // View-detail click handlers moved to assets/js/frontend/detail-popup.js (#837).
        self.bindDetailViewEvents($wrapper);

        // #1747 — duplicate entry (Pro).
        $wrapper.on('click', '.gt-duplicate-action', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var entryId = $(this).data('entry-id');
            if (typeof self.duplicateEntry === 'function') {
                self.duplicateEntry(entryId);
            }
        });

        // History action
        $wrapper.on('click', '.gt-history-action', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var entryId = $(this).data('entry-id');
            self.viewEntryHistory(entryId);
        });

        // WooCommerce create-product action
        $wrapper.on('click', '.gt-wc-create-action', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var entryId = $(this).data('entry-id');
            self.createWooCommerceProduct(entryId, $(this));
        });

        // Edit row action
        $wrapper.on('click', '.gt-edit-action', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $row = $(this).closest('tr');
            var entryId = $(this).data('entry-id');
            
            // Remove any existing edit indicators
            $wrapper.find('.gt-edit-indicator').remove();
            $wrapper.find('tbody tr').removeClass('gt-row-selected');

            // Add selected class and show indicator
            $row.addClass('gt-row-selected');
            self.showEditIndicator($row, entryId);
        });

        // Click outside to remove edit indicators (namespaced per table to allow clean teardown)
        $(document).on('click.gtTable' + self.wrapperId, function (e) {
            if (!$(e.target).closest('#' + self.wrapperId).length) {
                $wrapper.find('.gt-edit-indicator').remove();
                $wrapper.find('tbody tr').removeClass('gt-row-selected');
            }
        });
    };

})(window);
