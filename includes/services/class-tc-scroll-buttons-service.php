<?php
/**
 * Horizontal scroll navigation buttons for wide Gravity Tables.
 *
 * When a table overflows its container, left/right arrow buttons appear
 * flanking the scrollable wrapper so users on touch devices and non-scroll-
 * wheel mice can navigate without grabbing a native scrollbar.
 *
 * The feature is opt-in per table via the show_scroll_buttons setting.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Scroll_Buttons_Service {

    /** Default scroll distance per button click (px). */
    const DEFAULT_SCROLL_AMOUNT = 200;

    /**
     * Render the left and right scroll button HTML.
     *
     * The buttons are hidden by default (via .gt-scroll-btn--hidden) and
     * revealed by JS only when the table actually overflows its container.
     *
     * @param array $settings Table settings. Keys used:
     *   - scroll_amount (int) px to scroll per click; defaults to DEFAULT_SCROLL_AMOUNT
     * @return string HTML string.
     */
    public static function render_buttons(array $settings = []): string {
        $scroll_amount = absint($settings['scroll_amount'] ?? self::DEFAULT_SCROLL_AMOUNT);
        if ($scroll_amount === 0) {
            $scroll_amount = self::DEFAULT_SCROLL_AMOUNT;
        }

        $left_label  = esc_attr__('Scroll table left',  'tc-data-tables');
        $right_label = esc_attr__('Scroll table right', 'tc-data-tables');

        return sprintf(
            '<button type="button"'
            . ' class="gt-scroll-btn gt-scroll-btn--left gt-scroll-btn--hidden"'
            . ' aria-label="%s"'
            . ' data-scroll-amount="%d"'
            . ' disabled>'
            . '<span class="gt-scroll-btn__icon" aria-hidden="true">&#8249;</span>'
            . '</button>'
            . '<button type="button"'
            . ' class="gt-scroll-btn gt-scroll-btn--right gt-scroll-btn--hidden"'
            . ' aria-label="%s"'
            . ' data-scroll-amount="%d">'
            . '<span class="gt-scroll-btn__icon" aria-hidden="true">&#8250;</span>'
            . '</button>',
            $left_label,
            $scroll_amount,
            $right_label,
            $scroll_amount
        );
    }

    /**
     * Enqueue the scroll-buttons JS asset.
     *
     * Should be called on wp_enqueue_scripts when at least one table on the
     * page has show_scroll_buttons enabled.
     */
    public static function enqueue_assets(): void {
        // #1673 - don't load the scroll-buttons JS on pages without a table
        // (matches the sibling accessibility service gate).
        if (class_exists('TC_Asset_Enqueue_Gate') && !TC_Asset_Enqueue_Gate::page_has_table()) {
            return;
        }
        wp_enqueue_script(
            'gt-scroll-buttons',
            TC_PLUGIN_URL . 'assets/js/gt-scroll-buttons.js',
            ['jquery'],
            TC_VERSION,
            true
        );
    }
}
