/**
 * TableCrafter - Horizontal scroll navigation buttons
 *
 * For each .gt-table-wrapper that has scroll buttons rendered alongside it:
 *  - Detect whether the table overflows its container
 *  - Show/hide the buttons based on overflow and current scroll position
 *  - Scroll left/right by the configured amount on button click (smooth)
 *  - Update button disabled state on every scroll event
 *  - Hide buttons entirely in mobile card view (< 768 px or .gt-card-view active)
 */

/* global jQuery */
(function ($) {
    'use strict';

    var MOBILE_BREAKPOINT = 768; // px - matches the card-view media query

    /**
     * Return true if the table wrapper is in mobile card view.
     * Checks both viewport width and the presence of the .gt-card-view class.
     */
    function isMobileCardView($wrapper) {
        if (window.matchMedia && window.matchMedia('(max-width: ' + MOBILE_BREAKPOINT + 'px)').matches) {
            return true;
        }
        return $wrapper.hasClass('gt-card-view') || $wrapper.closest('.gt-card-view').length > 0;
    }

    /**
     * Determine whether the scrollable table container actually overflows.
     */
    function isOverflowing(el) {
        return el.scrollWidth > el.clientWidth;
    }

    /**
     * Update the visible/disabled state of both scroll buttons for one wrapper.
     */
    function updateButtons($wrapper) {
        var $scrollContainer = $wrapper.find('.gt-table-scroll-container, .gt-table-outer').first();
        if (!$scrollContainer.length) {
            $scrollContainer = $wrapper;
        }
        var el = $scrollContainer[0];

        var $btnLeft  = $wrapper.find('.gt-scroll-btn--left');
        var $btnRight = $wrapper.find('.gt-scroll-btn--right');

        if (!$btnLeft.length || !$btnRight.length) return;

        // Hide both buttons in mobile card view or when there is no overflow
        if (isMobileCardView($wrapper) || !isOverflowing(el)) {
            $btnLeft.addClass('gt-scroll-btn--hidden').prop('disabled', true);
            $btnRight.addClass('gt-scroll-btn--hidden').prop('disabled', true);
            return;
        }

        // Show buttons; update disabled state based on scroll position
        $btnLeft.removeClass('gt-scroll-btn--hidden');
        $btnRight.removeClass('gt-scroll-btn--hidden');

        var scrollLeft  = Math.round(el.scrollLeft);
        var maxScroll   = Math.round(el.scrollWidth - el.clientWidth);

        $btnLeft.prop('disabled',  scrollLeft <= 0);
        $btnRight.prop('disabled', scrollLeft >= maxScroll);
    }

    /**
     * Scroll the container by the configured amount in the given direction.
     */
    function scrollTable($wrapper, direction) {
        var $scrollContainer = $wrapper.find('.gt-table-scroll-container, .gt-table-outer').first();
        if (!$scrollContainer.length) {
            /* c8 ignore next */
            $scrollContainer = $wrapper;
        }
        var el     = $scrollContainer[0];
        var amount = parseInt($wrapper.find('.gt-scroll-btn').first().data('scroll-amount'), 10) || 200;

        el.scrollBy({
            left:     direction === 'right' ? amount : -amount,
            behavior: 'smooth',
        });
    }

    /**
     * Initialise scroll buttons for a single table wrapper.
     */
    function initWrapper($wrapper) {
        var $scrollContainer = $wrapper.find('.gt-table-scroll-container, .gt-table-outer').first();
        if (!$scrollContainer.length) {
            $scrollContainer = $wrapper;
        }

        // Initial state
        updateButtons($wrapper);

        // Update on scroll
        $scrollContainer.on('scroll.gt-scroll-buttons', function () {
            updateButtons($wrapper);
        });

        // Update on resize
        $(window).on('resize.gt-scroll-buttons', function () {
            updateButtons($wrapper);
        });

        // Button click handlers
        $wrapper.on('click.gt-scroll-buttons', '.gt-scroll-btn--left', function () {
            if (!$(this).prop('disabled')) {
                scrollTable($wrapper, 'left');
            }
        });

        $wrapper.on('click.gt-scroll-buttons', '.gt-scroll-btn--right', function () {
            if (!$(this).prop('disabled')) {
                scrollTable($wrapper, 'right');
            }
        });
    }

    // ── Init ───────────────────────────────────────────────────────────────

    $(document).ready(function () {
        // Initialise all wrappers that have scroll buttons rendered
        $('.gt-table-wrapper:has(.gt-scroll-btn)').each(function () {
            initWrapper($(this));
        });

        // Re-initialise when a new table is loaded via AJAX
        $(document).on('gt:table-rendered', function (e, data) {
            if (data && data.$wrapper) {
                initWrapper(data.$wrapper);
            }
        });
    });

}(jQuery));
