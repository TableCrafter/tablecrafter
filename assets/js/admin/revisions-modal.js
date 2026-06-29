/**
 * TableCrafter — admin/revisions-modal.js
 *
 * #1615 — Table history modal v2. Replaces the #536 slice-2b
 * index-prompt restore UX: the Revisions row action opens a modal
 * listing snapshots (date, user, diff summary from the new
 * gt_list_revisions endpoint) with two actions per row:
 *
 *   - "Load into builder" — review-before-commit: navigates to the
 *     builder with &gt_revision=N; the snapshot populates the form
 *     but NOTHING is saved until the admin clicks Save there.
 *   - "Restore now" — the existing #536 admin-post direct write,
 *     kept for one-click recovery.
 *
 * Surface (window.TC_Revisions.*):
 *   - open(ctx)     — ctx: {tableId, nonce, restoreNonce, adminPost, builderUrl}
 *   - render(list)
 *   - navigate(url) — seam; window.location.href in production
 */
(function (window, $) {
    'use strict';

    var state = { ctx: null };

    function cfg() {
        return window.gtRevisions || window.gtAdmin || {};
    }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    var RV = {

        navigate: function (url) {
            /* c8 ignore next 2 — seam; stubbed in tests. */
            window.location.href = url;
        },

        open: function (ctx) {
            state.ctx = ctx || {};
            var $m = $('#gt-rev-modal');
            if (!$m.length) { return; }
            $m.find('.gt-rev-list').html('<p>Loading&hellip;</p>');
            $m.prop('hidden', false);
            $.post(cfg().ajax_url, {
                action: 'gt_list_revisions',
                nonce: state.ctx.nonce,
                table_id: state.ctx.tableId
            }, function (response) {
                if (response && response.success && response.data && Array.isArray(response.data.revisions)) {
                    RV.render(response.data.revisions);
                } else {
                    $m.find('.gt-rev-list').html('<p>Could not load revisions.</p>');
                }
            });
        },

        close: function () {
            $('#gt-rev-modal').prop('hidden', true);
        },

        render: function (list) {
            var $list = $('#gt-rev-modal .gt-rev-list');
            if (!list.length) {
                $list.html('<p>No saved revisions yet — a snapshot is captured on every save.</p>');
                return;
            }
            var parts = ['<table class="widefat striped"><thead><tr>'
                + '<th>Saved</th><th>By user</th><th>Changes</th><th></th>'
                + '</tr></thead><tbody>'];
            for (var i = 0; i < list.length; i++) {
                var r = list[i];
                parts.push(
                    '<tr class="gt-rev-row" data-index="' + esc(r.index) + '">'
                    + '<td>' + esc(r.created_at) + '</td>'
                    + '<td>#' + esc(r.user_id) + '</td>'
                    + '<td>' + esc(r.summary) + '</td>'
                    + '<td>'
                    + '<button type="button" class="button button-small gt-rev-load">Load into builder</button> '
                    + '<button type="button" class="button-link gt-rev-restore">Restore now</button>'
                    + '</td>'
                    + '</tr>'
                );
            }
            parts.push('</tbody></table>');
            $list.html(parts.join(''));
        }

    };

    window.TC_Revisions = RV;

    // Document-level delegation binds immediately — no ready-wait
    // needed, and the handlers survive list re-renders.
    (function bindDelegation() {
        $(document).on('click', '.gt-revisions', function (e) {
            e.preventDefault();
            RV.open({
                tableId: parseInt($(this).attr('data-table-id'), 10) || 0,
                nonce: $(this).attr('data-list-nonce') || '',
                restoreNonce: $(this).attr('data-restore-nonce') || '',
                adminPost: cfg().admin_post || '',
                builderUrl: cfg().builder_url || ''
            });
        });
        $(document).on('click', '#gt-rev-modal .gt-rev-load', function () {
            var index = $(this).closest('.gt-rev-row').attr('data-index');
            var base = (state.ctx && state.ctx.builderUrl) || '';
            RV.navigate(base + (base.indexOf('?') === -1 ? '?' : '&')
                + 'id=' + encodeURIComponent(state.ctx.tableId)
                + '&gt_revision=' + encodeURIComponent(index));
        });
        $(document).on('click', '#gt-rev-modal .gt-rev-restore', function () {
            var index = $(this).closest('.gt-rev-row').attr('data-index');
            if (!window.confirm('Restore revision #' + index + ' now? This overwrites the current title + settings immediately (the current state is itself captured as a revision on next save).')) {
                return;
            }
            RV.navigate((state.ctx.adminPost || '')
                + '?action=gt_action_restore_revision'
                + '&table=' + encodeURIComponent(state.ctx.tableId)
                + '&index=' + encodeURIComponent(index)
                + '&_wpnonce=' + encodeURIComponent(state.ctx.restoreNonce));
        });
        $(document).on('click', '#gt-rev-modal .gt-rev-cancel', function () {
            RV.close();
        });
    }());

})(window, window.jQuery);
