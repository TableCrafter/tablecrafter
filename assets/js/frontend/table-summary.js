/**
 * TableCrafter - frontend/table-summary.js
 *
 * #1601 slice A - table summary line. When the per-table
 * `show_table_summary` setting is on, fetch gt_ai_table_summary
 * (TC_AI_Table_Summarizer - rule-based slice-1 engine, no API key
 * required) once on init and render a one-line digest above the
 * table content: row count, date span, numeric min/max/avg, dominant
 * text values.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *   - initTableSummary() - gate on config, fetch, render
 *   - renderTableSummary(data) - escaped digest line (idempotent)
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        initTableSummary: function () {
            var self = this;
            if (!(this.config && this.config.show_table_summary)) {
                return;
            }
            var $ = window.jQuery || window.$;
            $.post(this.config.ajax_url, {
                action: 'gt_ai_table_summary',
                nonce: this.config.nonce,
                table_id: this.config.table_id
            }, function (response) {
                if (response && response.success && response.data) {
                    self.renderTableSummary(response.data);
                }
            });
        },

        renderTableSummary: function (data) {
            var bullets = (data && Array.isArray(data.bullets)) ? data.bullets : [];
            if (!bullets.length) {
                return;
            }
            var $ = window.jQuery || window.$;
            var $wrapper = $('#' + this.wrapperId);
            var $container = $wrapper.find('.gt-table-container').first();
            if (!$container.length) {
                /* c8 ignore next 2 */
                return;
            }
            var esc = typeof this.escapeHtml === 'function'
                ? this.escapeHtml.bind(this)
                : function (s) { return String(s); };
            var parts = [];
            for (var i = 0; i < bullets.length; i++) {
                parts.push(esc(String(bullets[i])));
            }
            var text = parts.join(' &middot; ');
            if (data.truncated) {
                text += ' <span class="gt-table-summary-note">(first 1,000 rows)</span>';
            }
            $wrapper.find('.gt-table-summary').remove();
            var $controls = $container.find('.gt-table-controls').first();
            var html = '<div class="gt-table-summary" role="note">' + text + '</div>';
            if ($controls.length) {
                $controls.after(html);
            } else {
                /* c8 ignore next 2 */
                $container.prepend(html);
            }
        }

    });

})(window);
