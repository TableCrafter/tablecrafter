/**
 * TableCrafter — admin/data-quality.js
 *
 * #1601 slice B — builder "Data Quality" panel. First UI consumer of
 * TC_AI_Cleanup_Suggester (rule-based, key-free): scan one column of
 * the form's entries via gt_ai_cleanup_suggest, list the suggestions
 * (current → suggested, reason, confidence), and write accepted
 * values back through the normal gt_update_entry path.
 *
 * Surface (window.TC_TableBuilder.*):
 *   - dqScanColumn(formId, fieldId)
 *   - dqRenderSuggestions(suggestions, fieldId)
 *   - dqApplySuggestion($row, fieldId)
 */
(function (window, $) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};
    var GT = window.TC_TableBuilder;

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function admin() {
        return window.gtAdmin || {};
    }

    GT.dqScanColumn = function (formId, fieldId) {
        var $results = $('.gt-dq-results').first();
        $results.html('<p class="gt-dq-loading">Scanning&hellip;</p>');
        $.post(admin().ajax_url, {
            action: 'gt_ai_cleanup_suggest',
            _ajax_nonce: admin().ai_cleanup_nonce,
            form_id: formId,
            field_id: fieldId
        }, function (response) {
            if (response && response.success && response.data) {
                GT.dqRenderSuggestions(response.data.suggestions || [], fieldId, response.data.scanned);
            } else {
                $results.html('<p class="gt-dq-error">' + esc((response && response.data && response.data.message) || 'Scan failed.') + '</p>');
            }
        }).fail(function () {
            $results.html('<p class="gt-dq-error">Scan failed.</p>');
        });
    };

    GT.dqRenderSuggestions = function (suggestions, fieldId, scanned) {
        var $results = $('.gt-dq-results').first();
        if (!suggestions.length) {
            $results.html('<p class="gt-dq-clean">No cleanup suggestions — the scanned values look clean.</p>');
            return;
        }
        var parts = ['<table class="widefat striped gt-dq-table"><thead><tr>'
            + '<th>Entry</th><th>Current</th><th>Suggested</th><th>Reason</th><th></th>'
            + '</tr></thead><tbody>'];
        for (var i = 0; i < suggestions.length; i++) {
            var s = suggestions[i];
            parts.push(
                '<tr class="gt-dq-row" data-entry-id="' + esc(s.entry_id) + '" data-suggested="' + esc(s.suggested_value) + '">'
                + '<td>#' + esc(s.entry_id) + '</td>'
                + '<td><code>' + esc(s.current_value) + '</code></td>'
                + '<td><code>' + esc(s.suggested_value) + '</code></td>'
                + '<td>' + esc(s.reason) + ' (' + esc(Math.round((s.confidence || 0) * 100)) + '%)</td>'
                + '<td><button type="button" class="button button-small gt-dq-apply">Apply</button></td>'
                + '</tr>'
            );
        }
        parts.push('</tbody></table>');
        $results.html(parts.join(''));
    };

    GT.dqApplySuggestion = function ($row, fieldId) {
        var entryId = $row.attr('data-entry-id');
        var suggested = $row.attr('data-suggested');
        var data = {
            action: 'gt_update_entry',
            nonce: admin().entry_update_nonce,
            entry_id: entryId
        };
        data['updates[' + fieldId + ']'] = suggested;
        $.post(admin().ajax_url, data, function (response) {
            if (response && response.success) {
                $row.addClass('gt-dq-applied');
                $row.find('.gt-dq-apply').prop('disabled', true).text('Applied');
            } else {
                $row.addClass('gt-dq-apply-failed');
            }
        });
    };

    // Panel wiring — delegated so the handlers survive rerenders.
    $(function () {
        var $panel = $('#gt-data-quality');
        if (!$panel.length) { return; }
        $panel.on('click', '.gt-dq-scan', function () {
            var fieldId = String($('#gt-dq-column').val() || '');
            if (!fieldId) { return; }
            var formId = parseInt($panel.attr('data-form-id'), 10) || 0;
            GT.dqScanColumn(formId, fieldId);
        });
        $panel.on('click', '.gt-dq-apply', function () {
            var fieldId = String($('#gt-dq-column').val() || '');
            GT.dqApplySuggestion($(this).closest('.gt-dq-row'), fieldId);
        });
    });

})(window, window.jQuery);
