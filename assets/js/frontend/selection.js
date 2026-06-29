/**
 * TableCrafter — frontend/selection.js
 *
 * Row selection state + bulk action toolbar wire-up. Fourth module
 * under #830.
 *
 * Closes #836.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - bindSelectionEvents($wrapper)   — wires .gt-select-all change
 *                                       (toggle every gt-entry-checkbox)
 *                                       + .gt-bulk-action-btn click
 *                                       (delegates to performBulkAction
 *                                       when a non-empty action is
 *                                       selected). Called once from
 *                                       bindEvents in frontend.js.
 *
 *   - getSelectedEntryIds()           — collects values from every
 *                                       checked .gt-entry-checkbox
 *                                       inside the wrapper. Pure-DOM
 *                                       helper, no side effects.
 *
 *   - performBulkAction(action)       — collects selected ids; alerts
 *                                       and returns early when none;
 *                                       confirms on delete; POSTs to
 *                                       the gt_bulk_action AJAX
 *                                       endpoint; on success: alerts
 *                                       the response message, unchecks
 *                                       every checkbox (#130 — prevent
 *                                       stale selections after reload),
 *                                       reloads entries.
 *
 * Hot path note: getSelectedEntryIds is also reachable to any future
 * caller that needs the currently-checked rows without firing AJAX
 * (e.g. export "selected rows only", #836b future enhancement).
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

        bindSelectionEvents: function ($wrapper) {
            var self = this;

            $wrapper.find('.gt-select-all').on('change', function () {
                var checked = $(this).prop('checked');
                $wrapper.find('.gt-entry-checkbox').prop('checked', checked);
            });

            $wrapper.find('.gt-bulk-action-btn').on('click', function () {
                var action = $wrapper.find('.gt-bulk-action-select').val();
                if (action) {
                    self.performBulkAction(action);
                }
            });
        },

        getSelectedEntryIds: function () {
            var ids = [];
            var $wrapper = $('#' + this.wrapperId);
            $wrapper.find('.gt-entry-checkbox:checked').each(function () {
                ids.push($(this).val());
            });
            return ids;
        },

        performBulkAction: function (action) {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);
            var selectedEntries = self.getSelectedEntryIds();

            if (selectedEntries.length === 0) {
                alert('Please select entries to perform bulk action');
                return;
            }

            // #613 phase 2 slice 5 (v4.200.0) — intercept push_to_source and
            // delegate to pushRowToSource per entry. Reads each row's current
            // values from its tbody cells (keyed by data-field-id) so the
            // payload reflects the user's latest inline edits.
            // #613 phase 2 slice 6 (v4.201.0) — aggregate success/failure
            // feedback. After all per-row pushes complete, surface a single
            // alert with success and failure counts so the user sees the
            // outcome (previously silent).
            if (action === 'push_to_source') {
                if (typeof self.pushRowToSource !== 'function') {
                    /* c8 ignore next */
                    return;
                }
                var total = selectedEntries.length;
                var completed = 0;
                var succeeded = 0;
                var failed = 0;
                selectedEntries.forEach(function (rowId) {
                    var $row = $wrapper.find('tbody tr[data-entry-id="' + rowId + '"]');
                    var payload = {};
                    $row.find('td[data-field-id]').each(function () {
                        var $td = $(this);
                        var fieldId = String($td.attr('data-field-id'));
                        payload[fieldId] = ($td.text() || '').trim();
                    });
                    self.pushRowToSource(rowId, payload, function (response) {
                        completed++;
                        // #613 phase 2 slice 7 (v4.202.0) — per-row visual
                        // feedback. Mark each row with a class so CSS can
                        // tint it; on failure, surface the typed error code
                        // via a title attribute the user can hover to read.
                        $row.removeClass('gt-push-success gt-push-failed');
                        if (response && response.success) {
                            succeeded++;
                            $row.addClass('gt-push-success');
                            $row.removeAttr('title');
                        } else {
                            failed++;
                            $row.addClass('gt-push-failed');
                            var errCode = (response && response.data && response.data.code) ? response.data.code : 'push_error';
                            var errMsg  = (response && response.data && response.data.message) ? response.data.message : 'Push failed.';
                            $row.attr('title', errCode + ': ' + errMsg);
                        }
                        if (completed === total) {
                            if (failed === 0) {
                                alert('Pushed ' + succeeded + ' row' + (succeeded === 1 ? '' : 's') + ' successfully.');
                            } else {
                                alert('Pushed ' + succeeded + ' row' + (succeeded === 1 ? '' : 's') + ', ' + failed + ' failed.');
                            }
                        }
                    });
                });
                return;
            }

            if (action === 'delete') {
                if (!confirm('Are you sure you want to delete ' + selectedEntries.length + ' entries?')) {
                    return;
                }
            }

            var data = {
                action: 'gt_bulk_action',
                nonce: this.config.nonce,
                bulk_action: action,
                entry_ids: selectedEntries,
                table_id: this.config.table_id || 0
            };

            $.post(this.config.ajax_url, data, function (response) {
                if (response.success) {
                    alert(response.data.message);
                    // Uncheck all checkboxes before reloading so stale selections do not persist (#130).
                    $wrapper.find('.gt-entry-checkbox').prop('checked', false);
                    self.loadEntries();
                } else {
                    alert('Error performing bulk action: ' + response.data);
                }
            }).fail(function () {
                /* c8 ignore next */
                alert('Error performing bulk action');
            });
        }

    });

})(window);
