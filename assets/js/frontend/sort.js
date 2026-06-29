/**
 * TableCrafter — frontend/sort.js
 *
 * Sort handler + multi-column shift-click state machine. #834 slice 3 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - computeNextSortState({stack, field, isShift, multiSortEnabled,
 *                          currentField, currentOrder})
 *       Pure state-machine helper. Given the current sort state and a
 *       click event's field + shift state, returns the next state as
 *       {stack, field, order, error?}. All multi-sort rules live here:
 *         - shift-click + multi-sort enabled: add field to stack or
 *           flip its direction in place. Cap at 3 (replace last when
 *           full).
 *         - plain click or multi-sort disabled: reset to single-column.
 *         - same-field plain click toggles asc <-> desc.
 *         - invalid (empty) field returns {error}.
 *       Pure: no DOM, no side effects, no jQuery.
 *
 *   - bindSortEvents($wrapper)
 *       Wires the .gt-sortable click handler. On click:
 *         1. Skip when click is inside .gt-resizer (column resize gesture).
 *         2. Skip when isResponsiveMode() — card view has no headers.
 *         3. Validate field; if missing from config.columns, push it.
 *         4. Call computeNextSortState to mutate stack/field/order.
 *         5. Call self.loadEntries() to refetch with new sort.
 *         6. Update DOM: gt-sort-indicator active class, aria-sort
 *            attribute, numbered badges (1=primary, 2=secondary, etc).
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery || window.$;

    Object.assign(window.GravityTable.prototype, {

        computeNextSortState: function (input) {
            var stack = (input && input.stack) ? input.stack.slice() : [];
            var field = input && input.field;
            var isShift = !!(input && input.isShift);
            var multiSortEnabled = !!(input && input.multiSortEnabled);
            var currentField = input && input.currentField;
            var currentOrder = input && input.currentOrder;

            if (!field) {
                return { error: 'missing field', stack: stack, field: currentField, order: currentOrder };
            }

            var order = 'asc';

            if (isShift && multiSortEnabled) {
                // Find existing entry for this column in the stack.
                var existingIdx = -1;
                for (var i = 0; i < stack.length; i++) {
                    if (stack[i].column_id === field) { existingIdx = i; break; }
                }
                if (existingIdx >= 0) {
                    // Flip direction in place.
                    var existing = { column_id: stack[existingIdx].column_id, direction: stack[existingIdx].direction === 'asc' ? 'desc' : 'asc' };
                    stack[existingIdx] = existing;
                    if (existingIdx === 0) {
                        order = existing.direction;
                    }
                } else {
                    // New column added — cap at 3.
                    if (stack.length < 3) {
                        stack.push({ column_id: field, direction: 'asc' });
                    } else {
                        stack[stack.length - 1] = { column_id: field, direction: 'asc' };
                    }
                }
            } else {
                // Plain click — reset to single-column.
                if (currentField === field && currentOrder === 'asc') {
                    order = 'desc';
                }
                stack = [{ column_id: field, direction: order }];
            }

            return {
                stack: stack,
                field: stack[0].column_id,
                order: stack[0].direction,
            };
        },

        bindSortEvents: function ($wrapper) {
            var self = this;

            $wrapper.on('click.gt-table', '.gt-sortable', function (e) {
                // Skip when clicking the resize handle.
                if ($(e.target).closest('.gt-resizer').length) {
                    return;
                }
                // Skip in responsive card view.
                if (self.isResponsiveMode()) {
                    return;
                }

                var field = $(this).data('sort-field');
                if (!field) {
                    if (typeof console !== 'undefined' && console.warn) {
                        console.warn('No sort field specified');
                    }
                    return;
                }

                // Auto-add field to columns if missing — preserves legacy behavior.
                if (self.config.columns.indexOf(field) === -1) {
                    self.config.columns.push(field);
                }

                var multiSortEnabled = !(self.config && self.config.enable_multi_sort === false);
                var isShift = !!(e && (e.shiftKey || (e.originalEvent && e.originalEvent.shiftKey)));

                var next = self.computeNextSortState({
                    stack: self.sortStack,
                    field: field,
                    isShift: isShift,
                    multiSortEnabled: multiSortEnabled,
                    currentField: self.sortField,
                    currentOrder: self.sortOrder,
                });

                if (next.error) { return; }

                self.sortStack = next.stack;
                self.sortField = next.field;
                self.sortOrder = next.order;
                self.loadEntries();

                // UI update: clear all + reapply per stack entry.
                $wrapper.find('.gt-sort-indicator').removeClass('active');
                $wrapper.find('.gt-sort-order-badge').remove();
                $wrapper.find('th[data-sort-field]').attr('aria-sort', 'none');
                for (var s = 0; s < self.sortStack.length; s++) {
                    var entry = self.sortStack[s];
                    var $th = $wrapper.find('th[data-sort-field="' + entry.column_id + '"]');
                    if (!$th.length) continue;
                    $th.find('.gt-sort-indicator').addClass('active');
                    $th.attr('aria-sort', entry.direction === 'asc' ? 'ascending' : 'descending');
                    if (self.sortStack.length > 1) {
                        $th.find('.gt-sort-arrows').first().append(
                            '<span class="gt-sort-order-badge" aria-hidden="true">' + (s + 1) + '</span>'
                        );
                    }
                }
            });
        }

    });

})(window);
