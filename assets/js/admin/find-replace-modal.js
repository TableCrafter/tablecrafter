/**
 * TableCrafter - admin/find-replace-modal.js
 *
 * #1614 - Find & Replace modal v2. Replaces the #538 slice-3 prompt
 * flow on the tables list: find + replace inputs, column-scope
 * multi-select, case-sensitivity + whole-cell toggles, debounced
 * live match-count preview via gt_find_matches, and an Apply button
 * that stays disabled until a non-zero count has previewed. The
 * endpoints have accepted case_sensitive / whole_cell / columns[]
 * since #538 - the prompt UI just never sent them.
 *
 * Surface (window.TC_FindReplace.*):
 *   - open(tableId, nonce, columns)
 *   - close()
 *   - collectOptions() - {case_sensitive, whole_cell, columns}
 *   - preview() - POST gt_find_matches, update count + Apply
 *   - schedulePreview() - 400ms debounce around preview()
 *   - apply() - confirm + POST gt_apply_replace
 */
(function (window, $) {
    'use strict';

    var state = { tableId: 0, nonce: '', timer: null };

    function cfg() {
        return window.gtFindReplace || window.gtAdmin || {};
    }

    function $modal() {
        return $('#gt-fr-modal');
    }

    function setCount(text) {
        $modal().find('.gt-fr-count').text(text);
    }

    function setApplyEnabled(enabled) {
        $modal().find('.gt-fr-apply').prop('disabled', !enabled);
    }

    var FR = {

        open: function (tableId, nonce, columns) {
            state.tableId = parseInt(tableId, 10) || 0;
            state.nonce = String(nonce || '');
            var $m = $modal();
            if (!$m.length) { return; }
            var $cols = $m.find('.gt-fr-columns');
            $cols.empty();
            (columns || []).forEach(function (c) {
                $('<option></option>').attr('value', String(c.id)).text(String(c.label || c.id)).appendTo($cols);
            });
            $m.find('.gt-fr-find').val('');
            $m.find('.gt-fr-replace').val('');
            $m.find('.gt-fr-case, .gt-fr-whole').prop('checked', false);
            setCount('');
            setApplyEnabled(false);
            $m.prop('hidden', false);
        },

        close: function () {
            $modal().prop('hidden', true);
            if (state.timer) { window.clearTimeout(state.timer); state.timer = null; }
        },

        collectOptions: function () {
            var $m = $modal();
            return {
                case_sensitive: $m.find('.gt-fr-case').is(':checked'),
                whole_cell: $m.find('.gt-fr-whole').is(':checked'),
                columns: ($m.find('.gt-fr-columns').val() || []).map(String)
            };
        },

        preview: function () {
            var $m = $modal();
            var needle = String($m.find('.gt-fr-find').val() || '');
            if (needle === '') {
                setCount('');
                setApplyEnabled(false);
                return;
            }
            var opts = FR.collectOptions();
            var data = {
                action: 'gt_find_matches',
                nonce: state.nonce,
                table_id: state.tableId,
                needle: needle
            };
            if (opts.case_sensitive) { data.case_sensitive = 1; }
            if (opts.whole_cell) { data.whole_cell = 1; }
            if (opts.columns.length) { data['columns[]'] = opts.columns; }
            $.post(cfg().ajax_url, data, function (response) {
                if (response && response.success && response.data) {
                    var count = parseInt(response.data.count, 10) || 0;
                    setCount(count + ' matches found');
                    setApplyEnabled(count > 0);
                } else {
                    setCount('Preview failed');
                    setApplyEnabled(false);
                }
            });
        },

        schedulePreview: function () {
            if (state.timer) { window.clearTimeout(state.timer); }
            state.timer = window.setTimeout(function () {
                state.timer = null;
                FR.preview();
            }, 400);
        },

        apply: function () {
            var $m = $modal();
            var needle = String($m.find('.gt-fr-find').val() || '');
            var replacement = String($m.find('.gt-fr-replace').val() || '');
            if (needle === '') { return; }
            if (!window.confirm('Replace all occurrences of "' + needle + '" with "' + replacement + '"? This writes back to Gravity Forms entries and cannot be undone.')) {
                return;
            }
            var opts = FR.collectOptions();
            var data = {
                action: 'gt_apply_replace',
                nonce: state.nonce,
                table_id: state.tableId,
                needle: needle,
                replacement: replacement
            };
            if (opts.case_sensitive) { data.case_sensitive = 1; }
            if (opts.whole_cell) { data.whole_cell = 1; }
            if (opts.columns.length) { data['columns[]'] = opts.columns; }
            $.post(cfg().ajax_url, data, function (response) {
                if (response && response.success && response.data) {
                    var d = response.data;
                    setCount('Done: ' + (d.replacements_count || 0) + ' replacements across ' + (d.entries_updated || 0) + ' entries.');
                    setApplyEnabled(false);
                } else {
                    setCount('Apply failed');
                }
            });
        }

    };

    window.TC_FindReplace = FR;

    $(function () {
        $(document).on('click', '.gt-find-replace', function (e) {
            e.preventDefault();
            var columns = [];
            try {
                columns = JSON.parse($(this).attr('data-columns') || '[]');
            } catch (err) { columns = []; }
            FR.open($(this).attr('data-table-id'), $(this).attr('data-nonce'), columns);
        });
        $(document).on('input', '#gt-fr-modal .gt-fr-find', function () { FR.schedulePreview(); });
        $(document).on('change', '#gt-fr-modal .gt-fr-case, #gt-fr-modal .gt-fr-whole, #gt-fr-modal .gt-fr-columns', function () { FR.schedulePreview(); });
        $(document).on('click', '#gt-fr-modal .gt-fr-apply', function () { FR.apply(); });
        $(document).on('click', '#gt-fr-modal .gt-fr-cancel', function () { FR.close(); });
    });

})(window, window.jQuery);
