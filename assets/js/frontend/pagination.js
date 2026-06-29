/**
 * TableCrafter — frontend/pagination.js
 *
 * Pagination controls + length selector. Fifth module under #830.
 *
 * Closes #835.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - computeTotalPages(data, perPage)
 *       Pure helper. Returns the page count given an entries response
 *       envelope. Prefers data.total_pages from the server, falls back
 *       to Math.ceil(data.total / perPage), then 1. Issue #185 fixed
 *       a hard-coded 1 fallback that hid pagination permanently on
 *       2,000+ row responses; this helper preserves that behavior.
 *
 *   - formatPaginationInfoText(template, start, end, total)
 *       Pure helper. {start} / {end} / {total} token replacement.
 *       Used by updatePagination + reachable to any future consumer
 *       that wants to render the same info string elsewhere.
 *
 *   - bindPaginationEvents($wrapper)
 *       Wires the length-selector change handler + prev / next page
 *       click handlers (delegated, .gt-table namespace so the
 *       $wrapper.off('.gt-table') re-mount guard cleans them up — #128).
 *       Called once from bindEvents in frontend.js.
 *
 *   - updatePagination(data)
 *       Orchestrates the DOM update per AJAX response. Toggles the
 *       gt-pagination-single-page class on single-page responses
 *       (CSS-class hiding so the show_pagination table setting wins
 *       over the single-page check — #185). Updates entry-count text,
 *       current/total page text, prev/next disabled state.
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

        computeTotalPages: function (data, perPage) {
            return data.total_pages
                || Math.ceil((data.total || 0) / perPage)
                || 1;
        },

        formatPaginationInfoText: function (template, start, end, total) {
            return String(template)
                .replace('{start}', start)
                .replace('{end}', end)
                .replace('{total}', total);
        },

        bindPaginationEvents: function ($wrapper) {
            var self = this;

            // Length selector — visitor-side per_page change. Mutates
            // this.config.per_page in place so subsequent loadEntries calls
            // pick up the new size, then reloads from page 1. -1 means "All";
            // server-side enforces the max via existing per_page caps.
            $wrapper.on('change.gt-table', '.gt-length-select', function () {
                var val = parseInt($(this).val(), 10);
                if (isNaN(val) || (val !== -1 && val < 1)) return;
                self.config.per_page = val;
                self.currentPage = 1;
                self.loadEntries();
            });

            // Pagination — delegated on $wrapper with .gt-table namespace so the
            // $wrapper.off('.gt-table') re-mount guard removes them cleanly (#128).
            $wrapper.on('click.gt-table', '.gt-prev-page', function () {
                if (!$(this).prop('disabled') && self.currentPage > 1) {
                    self.currentPage--;
                    self.loadEntries();
                }
            });

            $wrapper.on('click.gt-table', '.gt-next-page', function () {
                if (!$(this).prop('disabled')) {
                    self.currentPage++;
                    self.loadEntries();
                }
            });
        },

        updatePagination: function (data) {
            var $wrapper = $('#' + this.wrapperId);
            var $pagination = $wrapper.find('.gt-pagination-container');
            var perPage = this.config.per_page || 25;
            var totalPages = this.computeTotalPages(data, perPage);

            // Toggle a CSS class rather than setting an inline style so the
            // show_pagination table setting always wins over the single-page check (#185).
            if (totalPages <= 1) {
                $pagination.addClass('gt-pagination-single-page');
                return;
            } else {
                $pagination.removeClass('gt-pagination-single-page');
            }

            var start = (this.currentPage - 1) * this.config.per_page + 1;
            var end = Math.min(this.currentPage * this.config.per_page, data.total);
            var total = data.total;

            var gtLabels = (this.config && this.config.pagination_labels) || {};
            var gtInfoText = gtLabels.info_text || 'Showing {start} to {end} of {total} entries';
            $wrapper.find('.gt-entry-count').text(
                this.formatPaginationInfoText(gtInfoText, start, end, total)
            );

            $wrapper.find('.gt-current-page').text(this.currentPage);
            $wrapper.find('.gt-total-pages').text('of ' + totalPages);

            var $prevBtn = $wrapper.find('.gt-prev-page');
            var $nextBtn = $wrapper.find('.gt-next-page');

            $prevBtn.prop('disabled', this.currentPage <= 1);
            $nextBtn.prop('disabled', this.currentPage >= totalPages);
        }

    });

})(window);
