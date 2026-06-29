/**
 * TableCrafter — frontend/a11y-keyboard.js
 *
 * Row-link interaction (click + auxclick + keyboard activation) and
 * the isResponsiveMode helper. Sixth module under #830.
 *
 * Closes #839.
 *
 * Scope note: the umbrella issue estimated ~300 lines of "a11y +
 * keyboard nav" code in this module. In practice the keyboard handlers
 * in frontend.js are tightly coupled to their feature contexts (date
 * inputs, toggle switches, detail-row chevrons, undo/redo, etc.) and
 * don't factor cleanly into a single module without churning unrelated
 * feature code. The cohesive a11y block that DOES factor cleanly is
 * row-link interaction (#567 slice 2.x):
 *
 *   - click on a "blank" cell of a clickable row navigates to data-row-href
 *   - Cmd/Ctrl/Shift+click and middle-click open in a new tab with
 *     noopener,noreferrer
 *   - Enter/Space on the focused row navigates (with the same
 *     modifier rules) — keyboard parity with the click handler
 *   - inline interactives (a, button, input, textarea, select, label,
 *     editable cells, toggle switches, action cells, checkbox cells)
 *     suppress row navigation so the user can still click links and
 *     buttons inside the row
 *   - row_link_open_new_tab config opens every navigation in a new tab
 *
 * isResponsiveMode() is the only remaining standalone a11y-adjacent
 * helper. It comes along because it's a one-liner used by sort
 * handlers (frontend.js) to suppress sorting in card-view mode where
 * column headers aren't visible.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *   - bindRowLinkEvents($wrapper)   — wires click + auxclick + keydown.
 *   - isResponsiveMode()            — pure-ish helper.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery || window.$;

    // Inline interactives that suppress row navigation. The selector list
    // must cover real links, buttons, inputs, editable cells, toggle
    // switches, action cells, and checkbox cells — only the "blank" cell
    // areas of a clickable row should fire the navigation.
    var INLINE_INTERACTIVE_SELECTOR =
        'a, button, input, textarea, select, label, .gt-editable-cell, .gt-toggle-switch, .gt-actions-cell, .gt-checkbox-cell';

    Object.assign(window.GravityTable.prototype, {

        // Indirection layer so the test harness can stub navigation without
        // having to mutate window.location (jsdom blocks Object.defineProperty
        // on Location.href). Production behavior is unchanged: same-tab path
        // assigns location.href; new-tab path calls window.open with
        // noopener,noreferrer (security + privacy hardening — prevents the
        // opened page from reaching window.opener).
        _navigate: function (href, newTab) {
            if (newTab) {
                window.open(href, '_blank', 'noopener,noreferrer');
            } else {
                /* c8 ignore next */
                window.location.href = href;
            }
        },

        bindRowLinkEvents: function ($wrapper) {
            var self = this;

            function navigateRow(e, $row) {
                if ($(e.target).closest(INLINE_INTERACTIVE_SELECTOR).length > 0) {
                    return false;
                }
                var href = $row.attr('data-row-href');
                if (!href) { return false; }

                // #567 slice 2.4: per-table always-new-tab override.
                var alwaysNewTab = !!(self.config && self.config.row_link_open_new_tab);
                // Coerce to strict bool — keyboard events may have undefined
                // modifier keys instead of false (synthetic-event quirk).
                var newTab = !!(alwaysNewTab || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1);
                self._navigate(href, newTab);
                return true;
            }

            $wrapper.on('click.gt-table', 'tr.gt-row-clickable', function (e) {
                navigateRow(e, $(this));
            });

            // auxclick fires on middle-click and right-click. We only act on
            // middle-click (button === 1); right-click stays alone so the
            // browser context menu still works.
            $wrapper.on('auxclick.gt-table', 'tr.gt-row-clickable', function (e) {
                if (e.button !== 1) { return; }
                if (navigateRow(e, $(this))) { e.preventDefault(); }
            });

            // Keyboard activation — Enter or Space on the focused row.
            $wrapper.on('keydown.gt-table', 'tr.gt-row-clickable', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') { return; }
                if ($(e.target).closest(INLINE_INTERACTIVE_SELECTOR).length > 0) { return; }
                var href = $(this).attr('data-row-href');
                if (!href) { return; }
                e.preventDefault(); // avoid page-scroll on Space

                // #567 slice 2.4: keyboard path also honors the always-new-tab override.
                var alwaysNewTab = !!(self.config && self.config.row_link_open_new_tab);
                var newTab = !!(alwaysNewTab || e.ctrlKey || e.metaKey || e.shiftKey);
                self._navigate(href, newTab);
            });
        },

        isResponsiveMode: function () {
            var $wrapper = $('#' + this.wrapperId);

            // Card view mode based on screen size + responsive settings.
            if (this.config.responsive_mode === 'enhanced') {
                return window.innerWidth <= 768 && $wrapper.hasClass('gt-mobile-card-view');
            }

            return false;
        }

    });

})(window);
