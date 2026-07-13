/**
 * TableCrafter - frontend/observers.js
 *
 * Tabs / Accordion visibility observer (#77, #529) - extracted from
 * the monolithic frontend.js as the second module under #830.
 *
 * Closes #840.
 *
 * Surface: `GravityTable.prototype.initVisibilityObserver($wrapper)`.
 *
 * Three recovery paths, in order of preference:
 *
 *   1. IntersectionObserver - fires as soon as any pixel of the
 *      wrapper enters the viewport. Covers Gutenberg block tabs,
 *      native HTML5 details/summary, and most CSS-only reveals.
 *      One-shot: disconnects after the first reveal.
 *
 *   2. ResizeObserver - catches animated accordion height
 *      transitions that start at 0 height (IntersectionObserver
 *      may not fire for those because the wrapper has zero size).
 *      Fires adjustColumns only on the 0 -> >0 transition.
 *
 *   3. Public `gt:redraw` custom event (#529) - escape hatch for
 *      bespoke tab/accordion libraries (Bootstrap tabs, jQuery UI
 *      tabs, custom Elementor widgets) that swap visibility via
 *      mechanisms our two observers can't catch. Integrators
 *      dispatch the event from inside the wrapper:
 *
 *        // Native:
 *        wrapper.dispatchEvent(new CustomEvent('gt:redraw', { bubbles: true }));
 *        // jQuery:
 *        $wrapper.trigger('gt:redraw');
 *
 *      The handler calls `adjustColumns()`, the same code path the
 *      observers use, so all three recovery paths converge on a
 *      single re-measurement routine. Deliberately NOT one-shot - 
 *      integrators may need to redraw on every subsequent tab
 *      change, not just the first.
 *
 * Defensive guards: if either observer API is missing from the host
 * environment (older browsers, jsdom), that recovery path is skipped
 * silently. If the wrapper has no underlying DOM element, the
 * function returns early - nothing to observe.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        initVisibilityObserver: function ($wrapper) {
            var self = this;
            var el = $wrapper[0];
            if (!el) return;

            // 1. IntersectionObserver - fires once when the wrapper enters the viewport.
            if (typeof window.IntersectionObserver !== 'undefined') {
                var io = new window.IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting || entry.intersectionRatio > 0) {
                            self.adjustColumns();
                            io.disconnect();
                        }
                    });
                }, { threshold: 0.01 });
                io.observe(el);
            }

            // 2. ResizeObserver - catches 0 -> >0 height transitions.
            if (typeof window.ResizeObserver !== 'undefined') {
                var prevHeight = el.offsetHeight;
                var ro = new window.ResizeObserver(function () {
                    var h = el.offsetHeight;
                    if (prevHeight === 0 && h > 0) {
                        self.adjustColumns();
                    }
                    prevHeight = h;
                });
                ro.observe(el);
            }

            // 3. Public gt:redraw escape hatch.
            $wrapper.on('gt:redraw', function () {
                self.adjustColumns();
            });
        }

    });

})(window);
