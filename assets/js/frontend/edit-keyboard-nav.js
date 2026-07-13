/**
 * TableCrafter - frontend/edit-keyboard-nav.js
 *
 * Keyboard navigation between inline-editable cells. Third slice
 * under #833. Two helpers, ~80 lines.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - findNextEditCell($field, direction)
 *       Returns { entryId, fieldId } descriptor for the next editable
 *       cell to focus, or null if no candidate exists. Direction is
 *       'next', 'prev', or 'down':
 *         - 'down': same column on the next row that has an editable
 *                   cell with the same data-field-id.
 *         - 'next': next editable cell on the current row; if at end,
 *                   wrap to the first editable cell of the next row.
 *         - 'prev': previous editable cell on the current row; if at
 *                   start, wrap to the last editable cell of the
 *                   previous row.
 *
 *   - scheduleEditOnTarget(target)
 *       Wait 250ms (for the in-flight saveField to settle) then enter
 *       edit mode on the target cell via this.editField. No-op when
 *       the target descriptor is null or the matching cell is gone.
 *
 * Callers: the editField keydown handlers in frontend.js. Both methods
 * route through this/self so they survive extraction.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    /**
     * Given the cell currently being edited, return a descriptor for the
     * next/prev editable cell to focus next. Returns { entryId, fieldId }
     * or null if no candidate exists. Direction is 'next', 'prev', or 'down'.
     */
    GravityTable.prototype.findNextEditCell = function ($field, direction) {
        var $wrapper = $('#' + this.wrapperId);
        var $row = $field.closest('tr');
        if (!$row.length) return null;

        var $cells = $row.find('td.gt-editable-cell');
        if (!$cells.length) return null;
        var idx = $cells.index($field);

        function descriptorFromCell($c) {
            if (!$c || !$c.length) return null;
            var fid = $c.attr('data-field-id');
            var eid = $c.attr('data-entry-id') || $c.closest('tr').attr('data-entry-id');
            if (!fid || !eid) return null;
            return { entryId: String(eid), fieldId: String(fid) };
        }

        if (direction === 'down') {
            var fid = $field.attr('data-field-id');
            if (!fid) return null;
            var $next = $row.next('tr');
            while ($next.length) {
                var $cell = $next.find('td.gt-editable-cell[data-field-id="' + fid + '"]').first();
                if ($cell.length) return descriptorFromCell($cell);
                $next = $next.next('tr');
            }
            return null;
        }

        if (direction === 'next') {
            // Try next editable cell on the same row
            if (idx >= 0 && idx + 1 < $cells.length) {
                return descriptorFromCell($cells.eq(idx + 1));
            }
            // Wrap to first editable cell of next row
            var $tr = $row.next('tr');
            while ($tr.length) {
                var $first = $tr.find('td.gt-editable-cell').first();
                if ($first.length) return descriptorFromCell($first);
                /* c8 ignore next */
                $tr = $tr.next('tr');
            }
            return null;
        }

        if (direction === 'prev') {
            if (idx > 0) {
                return descriptorFromCell($cells.eq(idx - 1));
            }
            // Wrap to last editable cell of previous row
            var $prev = $row.prev('tr');
            while ($prev.length) {
                var $last = $prev.find('td.gt-editable-cell').last();
                if ($last.length) return descriptorFromCell($last);
                /* c8 ignore next */
                $prev = $prev.prev('tr');
            }
            return null;
        }

        return null;
    };

    /**
     * Wait briefly for the in-flight save to settle, then enter edit mode on
     * the cell identified by { entryId, fieldId }.
     */
    GravityTable.prototype.scheduleEditOnTarget = function (target) {
        var self = this;
        if (!target) return;
        setTimeout(function () {
            var $wrapper = $('#' + self.wrapperId);
            var $cell = $wrapper.find(
                'td.gt-editable-cell[data-entry-id="' + target.entryId + '"][data-field-id="' + target.fieldId + '"]'
            ).first();
            if ($cell.length) {
                self.editField($cell);
            }
        }, 250);
    };

})(window);
