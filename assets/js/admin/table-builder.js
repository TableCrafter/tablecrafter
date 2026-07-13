/**
 * TableCrafter - admin/table-builder.js
 *
 * Second slice of #842 (admin.js monolith split). Drag-and-drop interaction layer.
 *
 *   - initDragAndDrop: SortableJS instances on available + selected field lists,
 *     handles cross-list moves and the free-plan column-limit gate.
 *   - updateFieldOrder: reads DOM back into selectedFields[] after a within-list reorder.
 *   - initRowReorder (#440): SortableJS on the row preview, plus ArrowUp/ArrowDown
 *     keyboard reorder on `.gt-drag-handle`.
 *   - saveRowOrder (#440): persists the row order via gt_save_row_order AJAX.
 *
 * Field-list rendering (renderAvailableFields/renderSelectedFields/
 * add/removeFieldFromSelection) and the saveTable AJAX flow are intentionally
 * deferred to follow-up sub-issues of #842.
 *
 * @since 4.150.0
 */
(function ($) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        initDragAndDrop: function () {
            var self = this;

            if (typeof Sortable === 'undefined') {
                console.warn('GT Admin: SortableJS not loaded; field reordering disabled.');
                return;
            }

            // Tear down previous SortableJS instances before recreating, otherwise
            // the same DOM node would carry multiple drag listeners after each
            // re-render of the lists.
            if (self._availableSortable && typeof self._availableSortable.destroy === 'function') {
                self._availableSortable.destroy();
            }
            if (self._selectedSortable && typeof self._selectedSortable.destroy === 'function') {
                self._selectedSortable.destroy();
            }

            var availableEl = document.getElementById('available-fields-list');
            var selectedEl = document.getElementById('selected-fields-list');
            if (!availableEl || !selectedEl) return;

            self._availableSortable = new Sortable(availableEl, {
                group: { name: 'gt-fields', pull: true, put: true },
                animation: 150,
                ghostClass: 'gt-field-placeholder',
                dragClass: 'gt-dragging',
                filter: '.gt-field-disabled',
                preventOnFilter: true,
                onAdd: function (evt) {
                    var fieldId = evt.item.getAttribute('data-field-id');
                    self.removeFieldFromSelection(fieldId);
                    self.renderAvailableFields();
                    self.generatePreview();
                }
            });

            self._selectedSortable = new Sortable(selectedEl, {
                group: { name: 'gt-fields', pull: true, put: true },
                animation: 150,
                ghostClass: 'gt-field-placeholder',
                dragClass: 'gt-dragging',
                onUpdate: function () {
                    self.updateFieldOrder();
                },
                onAdd: function (evt) {
                    var fieldId = evt.item.getAttribute('data-field-id');

                    // Free plan column limit - undo the drop by reattaching to source
                    if (gtAdmin.limits.max_columns !== -1 && self.selectedFields.length >= gtAdmin.limits.max_columns) {
                        if (evt.from && typeof evt.oldIndex === 'number') {
                            var siblings = evt.from.children;
                            if (evt.oldIndex >= siblings.length) {
                                evt.from.appendChild(evt.item);
                            } else {
                                evt.from.insertBefore(evt.item, siblings[evt.oldIndex]);
                            }
                        } else if (evt.from) {
                            evt.from.appendChild(evt.item);
                        }
                        self.showUpgradeNotice('column_limit',
                            'Free plan allows maximum ' + gtAdmin.limits.max_columns + ' columns. Upgrade to Pro for unlimited columns.');
                        return;
                    }

                    if (fieldId && self.selectedFields.indexOf(fieldId) === -1) {
                        self.selectedFields.push(fieldId);
                    }
                    self.renderSelectedFields();
                    self.renderAvailableFields();
                    self.generatePreview();
                }
            });
        },

        updateFieldOrder: function () {
            var newOrder = [];
            $('#selected-fields-list .gt-selected-field').each(function () {
                var fieldId = $(this).data('field-id');
                if (fieldId) {
                    newOrder.push(fieldId);
                }
            });

            this.selectedFields = newOrder;
            this.generatePreview();
        },

        // (#440) Initialise SortableJS on the row preview container so users can
        // drag rows to a new position. Persists the order via gt_save_row_order.
        initRowReorder: function () {
            var self = this;
            var rowsEl = document.querySelector('.gt-rows-sortable');
            if (!rowsEl || typeof Sortable === 'undefined') return;

            if (self._rowSortable && typeof self._rowSortable.destroy === 'function') {
                self._rowSortable.destroy();
            }

            self._rowSortable = new Sortable(rowsEl, {
                handle: '.gt-drag-handle',
                animation: 150,
                ghostClass: 'gt-row-placeholder',
                onEnd: function () {
                    self.saveRowOrder();
                }
            });

            // Keydown handler: ArrowUp/ArrowDown on .gt-drag-handle moves the row (#440)
            $(rowsEl).off('keydown.gtRowReorder').on('keydown.gtRowReorder', '.gt-drag-handle', function (e) {
                var key = e.key;
                if (key !== 'ArrowUp' && key !== 'ArrowDown') return;
                e.preventDefault();
                var $row = $(this).closest('tr, [data-entry-id]');
                if (key === 'ArrowUp') {
                    var $prev = $row.prev();
                    if ($prev.length) { $row.insertBefore($prev); }
                } else {
                    var $next = $row.next();
                    if ($next.length) { $row.insertAfter($next); }
                }
                self.saveRowOrder();
            });
        },

        // (#440) Read the current row order from the DOM and persist via AJAX.
        saveRowOrder: function () {
            var self = this;
            var tableId = parseInt($('input[name="table_id"]').val(), 10) || 0;
            if (!tableId) return;

            var order = [];
            $('.gt-rows-sortable tr[data-entry-id], .gt-rows-sortable [data-entry-id]').each(function () {
                var entryId = parseInt($(this).attr('data-entry-id'), 10);
                if (entryId) order.push(entryId);
            });

            $.ajax({
                url: gtAdmin.ajax_url,
                method: 'POST',
                data: {
                    action: 'gt_save_row_order',
                    table_id: tableId,
                    row_order: order,
                    nonce: gtAdmin.nonce
                }
            });
        }
    });

})(jQuery);
