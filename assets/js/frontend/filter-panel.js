/**
 * TableCrafter - frontend/filter-panel.js
 *
 * Advanced filter panel controls. #834 slice 2 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - bindFilterPanelEvents($wrapper)
 *       Wires 5 handlers in one call (all previously inlined in
 *       frontend.js's bindEvents):
 *         1. .gt-toggle-filters click - slideToggle the panel, flip
 *            button label ("Show Filters" / "Hide Filters") + arrow
 *            (▼/▲) + active class.
 *         2. .gt-apply-filters click - delegates to self.applyFilters().
 *         3. .gt-clear-filters click - delegates to self.clearFilters().
 *         4. .gt-filter-input keypress(Enter) - delegates to applyFilters.
 *         5. select.gt-filter-input change - delegates to applyFilters
 *            (text-input change deliberately does NOT trigger to avoid
 *            partial-typing noise; Enter or button click only).
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

        bindFilterPanelEvents: function ($wrapper) {
            var self = this;

            // Advanced filters - toggle panel visibility.
            $wrapper.find('.gt-toggle-filters').on('click', function () {
                var $filtersPanel = $wrapper.find('.gt-filters-panel');
                var $button = $(this);
                var $arrow = $button.find('.gt-filter-arrow');
                var $text = $button.find('.gt-filter-text');

                // Prevent multiple clicks during animation.
                if ($filtersPanel.is(':animated')) {
                    return;
                }

                $filtersPanel.slideToggle(300, function () {
                    if ($filtersPanel.is(':visible')) {
                        $text.text($button.data('hide-text') || 'Hide Filters');
                        $arrow.text('▲');
                        $button.addClass('active');
                    } else {
                        $text.text($button.data('show-text') || 'Advanced Filters');
                        $arrow.text('▼');
                        $button.removeClass('active');
                    }
                });
            });

            // Filter actions.
            $wrapper.find('.gt-apply-filters').on('click', function () {
                self.applyFilters();
            });

            $wrapper.find('.gt-clear-filters').on('click', function () {
                self.clearFilters();
            });

            // Apply filters on Enter key in filter inputs.
            $wrapper.find('.gt-filter-input').on('keypress', function (e) {
                if (e.key === 'Enter') {
                    self.applyFilters();
                }
            });

            // Apply filters on change for select fields (including lookup
            // dropdowns). Text-input change deliberately does NOT trigger
            // - Enter or button click only - to avoid partial-typing noise.
            $wrapper.find('select.gt-filter-input, .gt-filter-input[type="select"], .gt-filter-input:not([type])').on('change', function () {
                if ($(this).is('select')) {
                    self.applyFilters();
                }
            });
        }

    });

})(window);
