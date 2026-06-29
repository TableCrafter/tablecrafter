/**
 * TableCrafter — frontend/column-reorder-dnd.js
 *
 * Column reorder drag-and-drop UI. #832 slice 19 of N.
 *
 * Eight helpers attached to GravityTable.prototype via Object.assign.
 * Pairs with column-order-persistence.js (#832 slice 15) — these methods
 * provide the UI, the persistence module provides the storage.
 *
 *   - getReorderableHeaders()       — return jQuery set of draggable <th>s
 *   - fieldIdFromTh(th)             — pure: extract field id from gt-column-* class
 *   - applyStoredColumnOrderToHead()— reorder thead per stored order, sync config.columns
 *   - applyStoredColumnOrderToBody()— reorder tbody cells to match stored order
 *   - bindColumnDragEvents()        — wire dragstart/dragover/drop on headers
 *   - reorderColumn(src, tgt)       — compute new order, persist, re-apply, update reset btn
 *   - initColumnReorder()           — entry point: skip on touch-only, inject reset button,
 *                                     snapshot defaults, apply stored order, bind drag, update btn
 *   - updateColumnReorderResetButton() — toggle reset button visibility based on stored order
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    Object.assign(window.GravityTable.prototype, {

        getReorderableHeaders: function () {
            var $wrapper = $('#' + this.wrapperId);
            return $wrapper.find('thead tr th').filter(function () {
                return /(?:^|\s)gt-column-/.test(this.className) && !$(this).hasClass('gt-actions-header') && !$(this).hasClass('gt-selection-header');
            });
        },

        fieldIdFromTh: function (th) {
            var m = /(?:^|\s)gt-column-([^\s]+)/.exec(th.className);
            return m ? m[1] : null;
        },

        applyStoredColumnOrderToHead: function () {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);
            var saved = this.readStoredColumnOrder();
            if (!saved.length) return;

            var $headerRow = $wrapper.find('thead tr').first();
            if (!$headerRow.length) return;

            var thMap = {};
            $headerRow.find('th').each(function () {
                var fid = self.fieldIdFromTh(this);
                if (fid) thMap[fid] = this;
            });

            var $insertAfter = $headerRow.find('th.gt-selection-header').last();
            var $beforeActions = $headerRow.find('th.gt-actions-header').first();

            saved.forEach(function (fid) {
                var th = thMap[fid];
                if (!th) return;
                if ($beforeActions.length) {
                    $beforeActions.before(th);
                } else if ($insertAfter.length) {
                    $insertAfter.after(th);
                    $insertAfter = $(th);
                } else {
                    $headerRow.append(th);
                }
            });

            // Sync config.columns so future renderEntries match.
            if (Array.isArray(this.config.columns)) {
                var pinned = this.config.columns.filter(function (c) { return saved.indexOf(String(c)) === -1; });
                this.config.columns = saved.concat(pinned);
            }
        },

        applyStoredColumnOrderToBody: function () {
            var saved = this.readStoredColumnOrder();
            if (!saved.length) return;
            // #1049 Option 2 v4.220.0 — hot-loop perf refactor. Behavior pinned
            // by 4 e2e vitest tests; equivalence verified pre/post.
            // 1. $.each -> native for-loop on the row list.
            // 2. $(this).attr -> el.getAttribute (saves a jQuery wrap per cell).
            // 3. $row.find('td[data-field-id]').each -> single querySelectorAll
            //    + native for-loop.
            // 4. $beforeActions.before(cell) / $row.append(cell) ->
            //    parentNode.insertBefore / rowEl.appendChild (saves a jQuery
            //    wrap per move).
            var wrapperEl = document.getElementById(this.wrapperId);
            if (!wrapperEl) return;
            var rowEls = wrapperEl.querySelectorAll('tbody tr');
            for (var ri = 0, rn = rowEls.length; ri < rn; ri++) {
                var rowEl = rowEls[ri];
                var cellEls = rowEl.querySelectorAll('td[data-field-id]');
                var cellMap = {};
                for (var ci = 0, cn = cellEls.length; ci < cn; ci++) {
                    var ce = cellEls[ci];
                    cellMap[String(ce.getAttribute('data-field-id'))] = ce;
                }
                var beforeActionsEl = rowEl.querySelector('td.gt-actions-cell');
                for (var si = 0, sn = saved.length; si < sn; si++) {
                    var cell = cellMap[saved[si]];
                    if (!cell) continue;
                    if (beforeActionsEl) {
                        beforeActionsEl.parentNode.insertBefore(cell, beforeActionsEl);
                    } else {
                        rowEl.appendChild(cell);
                    }
                }
            }
        },

        bindColumnDragEvents: function () {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);
            var $headers = this.getReorderableHeaders();
            $headers.attr('draggable', 'true').addClass('gt-col-draggable');

            $headers.off('.gtcoldrag');
            $headers.on('dragstart.gtcoldrag', function (e) {
                var fid = self.fieldIdFromTh(this);
                if (!fid) return;
                $(this).addClass('gt-col-dragging');
                try { e.originalEvent.dataTransfer.setData('text/plain', fid); } catch (err) {}
                try { e.originalEvent.dataTransfer.effectAllowed = 'move'; } catch (err) {}
            });

            $headers.on('dragend.gtcoldrag', function () {
                $wrapper.find('th.gt-col-dragging, th.gt-col-drop-target').removeClass('gt-col-dragging gt-col-drop-target');
            });

            $headers.on('dragover.gtcoldrag', function (e) {
                e.preventDefault();
                try { e.originalEvent.dataTransfer.dropEffect = 'move'; } catch (err) {}
                $wrapper.find('th.gt-col-drop-target').removeClass('gt-col-drop-target');
                $(this).addClass('gt-col-drop-target');
            });

            $headers.on('dragleave.gtcoldrag', function () {
                $(this).removeClass('gt-col-drop-target');
            });

            $headers.on('drop.gtcoldrag', function (e) {
                e.preventDefault();
                var sourceFid = '';
                try { sourceFid = e.originalEvent.dataTransfer.getData('text/plain'); } catch (err) {}
                var targetFid = self.fieldIdFromTh(this);
                $wrapper.find('th.gt-col-drop-target, th.gt-col-dragging').removeClass('gt-col-drop-target gt-col-dragging');
                if (!sourceFid || !targetFid || sourceFid === targetFid) return;
                self.reorderColumn(sourceFid, targetFid);
            });
        },

        reorderColumn: function (sourceFid, targetFid) {
            var current = [];
            var self = this;
            this.getReorderableHeaders().each(function () {
                current.push(self.fieldIdFromTh(this));
            });
            var srcIdx = current.indexOf(sourceFid);
            var tgtIdx = current.indexOf(targetFid);
            if (srcIdx === -1 || tgtIdx === -1) return;
            current.splice(srcIdx, 1);
            current.splice(tgtIdx, 0, sourceFid);
            this.saveStoredColumnOrder(current);
            this.applyStoredColumnOrderToHead();
            this.applyStoredColumnOrderToBody();
            this.updateColumnReorderResetButton();
        },

        initColumnReorder: function () {
            var $wrapper = $('#' + this.wrapperId);

            // Skip on touch-primary devices (mobile) — column drag isn't practical there.
            var isTouchOnly = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
            if (isTouchOnly) return;

            // Inject Reset button into the table controls if not already present.
            if (!$wrapper.find('.gt-col-reset-btn').length) {
                var $controls = $wrapper.find('.gt-table-controls').first();
                if ($controls.length) {
                    $controls.append('<button type="button" class="gt-col-reset-btn" style="display:none" title="Reset column order to default">Reset Columns</button>');
                }
            }

            var self = this;
            $wrapper.on('click', '.gt-col-reset-btn', function () {
                self.clearStoredColumnOrder();
                // Restore default column order from config and reapply.
                if (self.config && Array.isArray(self.config._defaultColumns)) {
                    self.config.columns = self.config._defaultColumns.slice();
                }
                self.loadEntries();
                // Reload page to get default thead order back from server.
                window.setTimeout(function () { window.location.reload(); }, 50);
            });

            // Snapshot the original column order from server-rendered config.
            if (this.config && Array.isArray(this.config.columns) && !this.config._defaultColumns) {
                this.config._defaultColumns = this.config.columns.slice();
            }

            this.applyStoredColumnOrderToHead();
            this.applyStoredColumnOrderToBody();
            this.bindColumnDragEvents();
            this.updateColumnReorderResetButton();
        },

        updateColumnReorderResetButton: function () {
            var $wrapper = $('#' + this.wrapperId);
            var hasCustom = this.readStoredColumnOrder().length > 0;
            $wrapper.find('.gt-col-reset-btn').toggle(hasCustom);
        }

    });

})(window);
