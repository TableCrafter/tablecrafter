/**
 * TableCrafter — frontend/post-render-gates.js
 *
 * Post-render DOM gates triggered at the tail of renderEntries.
 * #832 slice 18 of N.
 *
 * Two helpers attached to GravityTable.prototype via Object.assign:
 *
 *   - applyRowExpiry()
 *       #501: hide/strikethrough/sink-to-bottom rows whose date in
 *       `config.expiry_field_id` is past (or future, in inverse mode).
 *       Admin preview bails when config.is_preview is true. Fail-open
 *       on un-parseable date values. ISO date-only strings parse as
 *       local midnight to avoid timezone-induced false expiry (#501
 *       slice 2.1). Inverse-mode future rows get the .gt-row-future
 *       class; past-expired rows get .gt-row-expired (#501 slice 2).
 *
 *   - applyAutoMerge()
 *       #518: rowspan auto-merge. For each column flagged
 *       `config.column_auto_merge[field_id]=true`, collapses runs of
 *       consecutive cells with identical text content into a single
 *       `rowspan=N` cell. Empty cells terminate runs and don't start
 *       new ones (#518 slice 2). Skips `.gt-totals-row` (#518 slice
 *       2.1) — merging totals would visually misrepresent them.
 *       Re-runs from sort/filter/search are automatic because this
 *       method is invoked at the tail of renderEntries.
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

        applyRowExpiry: function () {
            var cfg = this.config || {};
            if (cfg.is_preview) { return; }
            var field = cfg.expiry_field_id ? String(cfg.expiry_field_id) : '';
            if (!field) { return; }
            // #501 slice 2.2: accept both 'bottom' (legacy v4.7.78) and
            // 'move_bottom' (canonical name in TC_Row_Expiry_Service PHP class).
            var rawBehavior = cfg.expiry_behavior;
            if (rawBehavior === 'move_bottom') { rawBehavior = 'bottom'; }
            var behavior = rawBehavior === 'strikethrough' || rawBehavior === 'bottom'
                ? rawBehavior : 'hide';
            var graceDays = Math.max(0, parseInt(cfg.expiry_grace_days, 10) || 0);
            var inverse = !!cfg.expiry_inverse;

            var $wrapper = $('#' + this.wrapperId);
            var $tbody = $wrapper.find('table.gt-table > tbody').first();
            if (!$tbody.length) { return; }

            // Anchor: now + grace (for normal mode) or now (for inverse mode).
            var now = Date.now();
            var graceMs = graceDays * 24 * 60 * 60 * 1000;
            var threshold = inverse ? (now + graceMs) : (now - graceMs);

            var sinkToBottom = [];
            $tbody.children('tr').each(function () {
                var $tr = $(this);
                var $cell = $tr.find('td[data-field-id="' + field + '"]').first();
                if (!$cell.length) { return; }
                var raw = ($cell.text() || '').trim();
                if (!raw) { return; }
                // #501 slice 2.1: ISO date-only strings parse as UTC midnight
                // by default — but we compare against local Date.now(), so
                // construct the Date with local-midnight semantics instead.
                var t;
                var isoOnly = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                if (isoOnly) {
                    t = new Date(+isoOnly[1], +isoOnly[2] - 1, +isoOnly[3]).getTime();
                } else {
                    t = Date.parse(raw);
                }
                if (isNaN(t)) { return; } // fail-open
                var isExpired = inverse ? (t > threshold) : (t < threshold);
                if (!isExpired) { return; }
                // #501 slice 2: distinct class per semantic.
                var stateClass = inverse ? 'gt-row-future' : 'gt-row-expired';
                if (behavior === 'hide') {
                    $tr.addClass(stateClass).hide();
                } else if (behavior === 'strikethrough') {
                    $tr.addClass(stateClass);
                } else if (behavior === 'bottom') {
                    $tr.addClass(stateClass);
                    sinkToBottom.push($tr);
                }
            });
            for (var i = 0; i < sinkToBottom.length; i++) {
                sinkToBottom[i].detach().appendTo($tbody);
            }
        },

        applyAutoMerge: function () {
            var cfg = this.config && this.config.column_auto_merge;
            if (!cfg) { return; }
            var fields = [];
            for (var k in cfg) {
                if (Object.prototype.hasOwnProperty.call(cfg, k) && cfg[k]) { fields.push(String(k)); }
            }
            if (fields.length === 0) { return; }

            var $wrapper = $('#' + this.wrapperId);
            var $tbody = $wrapper.find('table.gt-table > tbody').first();
            if (!$tbody.length) { return; }

            // #518 slice 2.1: exclude the column-totals row.
            var rows = $tbody.children('tr').not('.gt-totals-row').toArray();
            if (rows.length < 2) { return; }

            for (var fi = 0; fi < fields.length; fi++) {
                var fieldId = fields[fi];
                var firstCellOfRun = null;
                var runValue = null;
                var runLength = 0;
                var toRemove = [];

                for (var ri = 0; ri < rows.length; ri++) {
                    var $cell = $(rows[ri]).find('td[data-field-id="' + fieldId + '"]').first();
                    if (!$cell.length) {
                        if (firstCellOfRun && runLength > 1) {
                            firstCellOfRun.attr('rowspan', String(runLength));
                        }
                        firstCellOfRun = null;
                        runValue = null;
                        runLength = 0;
                        continue;
                    }
                    var thisVal = ($cell.text() || '').replace(/\s+/g, ' ').trim();
                    // #518 slice 2: skip empty cells entirely.
                    if (thisVal === '') {
                        if (firstCellOfRun && runLength > 1) {
                            firstCellOfRun.attr('rowspan', String(runLength));
                        }
                        firstCellOfRun = null;
                        runValue = null;
                        runLength = 0;
                        continue;
                    }
                    if (firstCellOfRun !== null && thisVal === runValue) {
                        runLength++;
                        toRemove.push($cell);
                    } else {
                        if (firstCellOfRun && runLength > 1) {
                            firstCellOfRun.attr('rowspan', String(runLength));
                        }
                        firstCellOfRun = $cell;
                        runValue = thisVal;
                        runLength = 1;
                    }
                }
                if (firstCellOfRun && runLength > 1) {
                    firstCellOfRun.attr('rowspan', String(runLength));
                }
                for (var di = 0; di < toRemove.length; di++) {
                    toRemove[di].remove();
                }
            }
        }

    });

})(window);
