/**
 * TableCrafter — Accessibility JS (WCAG 2.1 AA)
 *
 * Handles:
 *  - Keyboard navigation within tables (Tab/Shift+Tab/Enter/Escape)
 *  - aria-live announcements for filter results, sort changes, inline edit feedback
 *  - Focus trapping inside modals (View Details, Add/Edit Entry)
 *  - Focus restoration when modals close
 */

/* global jQuery */
(function ($) {
    'use strict';

    // ── Live region announcer ──────────────────────────────────────────────

    var $liveRegion;

    function initLiveRegion() {
        if ($('#gt-live-region').length === 0) {
            $liveRegion = $('<div>', {
                id: 'gt-live-region',
                class: 'gt-live-region gt-sr-only',
                role: 'status',
                'aria-live': 'polite',
                'aria-atomic': 'true',
            }).appendTo('body');
        } else {
            /* c8 ignore next */
            $liveRegion = $('#gt-live-region');
        }
    }

    function announce(message) {
        if (!$liveRegion) return;
        // Clear then set so repeated identical messages are re-announced.
        $liveRegion.text('');
        setTimeout(function () {
            $liveRegion.text(message);
        }, 50);
    }

    // ── Keyboard navigation ────────────────────────────────────────────────

    function initKeyboardNavigation() {
        $(document).on('keydown', '.gt-table-wrapper', function (e) {
            var $focused = $(document.activeElement);

            // Escape: close inline edit / modal
            if (e.key === 'Escape') {
                var $cancelBtn = $focused.closest('.gt-inline-edit-cell').find('.gt-cancel-btn');
                if ($cancelBtn.length) {
                    e.preventDefault();
                    $cancelBtn.trigger('click');
                }
            }

            // Enter on sortable header: trigger sort
            if (e.key === 'Enter' && $focused.is('th.gt-sortable, th.gt-sortable [role="button"]')) {
                e.preventDefault();
                $focused.closest('th').trigger('click');
            }
        });

        // Sort change announcements
        $(document).on('gt:sort-changed', function (e, data) {
            var direction = data && data.dir === 'desc' ? 'descending' : 'ascending';
            var column    = data && data.label ? data.label : 'column';
            announce('Sorted by ' + column + ', ' + direction);
        });

        // Filter results announcements
        $(document).on('gt:filter-applied', function (e, data) {
            var count = data && typeof data.count === 'number' ? data.count : '';
            announce(count !== '' ? count + ' results found.' : 'Filter applied.');
        });

        // Inline edit save/error announcements
        $(document).on('gt:inline-edit-saved', function () {
            announce('Changes saved.');
        });

        $(document).on('gt:inline-edit-error', function (e, data) {
            announce('Error saving: ' + (data && data.message ? data.message : 'please try again.'));
        });
    }

    // ── Focus trap for modals ──────────────────────────────────────────────

    var focusTrap = {
        $modal: null,
        $previouslyFocused: null,
        focusableSelector: [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
        ].join(', '),

        activate: function ($modal) {
            this.$modal            = $modal;
            this.$previouslyFocused = $(document.activeElement);

            // Focus the first focusable element inside the modal
            var $first = $modal.find(this.focusableSelector).first();
            if ($first.length) {
                $first.trigger('focus');
            } else {
                $modal.attr('tabindex', '-1').trigger('focus');
            }

            $(document).on('keydown.gt-focus-trap', this._trapKeys.bind(this));
        },

        deactivate: function () {
            $(document).off('keydown.gt-focus-trap');

            if (this.$previouslyFocused && this.$previouslyFocused.length) {
                this.$previouslyFocused.trigger('focus');
            }

            this.$modal            = null;
            this.$previouslyFocused = null;
        },

        _trapKeys: function (e) {
            if (!this.$modal) return;

            var $focusable = this.$modal.find(this.focusableSelector).filter(':visible');
            if ($focusable.length === 0) return;

            var $first = $focusable.first();
            var $last  = $focusable.last();

            if (e.key === 'Tab') {
                if (e.shiftKey) {
                    // Shift+Tab: wrap from first → last
                    if (document.activeElement === $first[0]) {
                        e.preventDefault();
                        $last.trigger('focus');
                    }
                } else {
                    // Tab: wrap from last → first
                    if (document.activeElement === $last[0]) {
                        e.preventDefault();
                        $first.trigger('focus');
                    }
                }
            }

            if (e.key === 'Escape') {
                var $closeBtn = this.$modal.find('.gt-modal-close');
                if ($closeBtn.length) {
                    $closeBtn.trigger('click');
                }
            }
        },
    };

    // Activate focus trap when a GT modal opens
    $(document).on('gt:modal-open', function (e, data) {
        if (data && data.$modal) {
            focusTrap.activate(data.$modal);
        }
    });

    // Deactivate and restore focus when modal closes
    $(document).on('gt:modal-close', function () {
        focusTrap.deactivate();
    });

    // ── Init ───────────────────────────────────────────────────────────────

    $(document).ready(function () {
        initLiveRegion();
        initKeyboardNavigation();
    });

}(jQuery));
