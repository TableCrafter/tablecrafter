<?php
/**
 * Collapsible table toggle for Gravity Tables.
 *
 * Renders an accessible expand/collapse toggle above the table container.
 * Key behaviours:
 *  - Toggle button uses aria-expanded + aria-controls for screen-reader compat.
 *  - Default collapsed state is respected on page load.
 *  - open/closed state is persisted per-table in localStorage.
 *  - DataTables columns.adjust() is called on expand to fix column widths
 *    that were miscalculated while the table was hidden.
 *  - Animations skip when prefers-reduced-motion: reduce is detected.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
// #2312's TC_Bool coercion is a hard dependency; require it directly so the
// class also works when this file is loaded in isolation (PHPUnit shims,
// direct includes) rather than via the plugin's full boot sequence.
require_once __DIR__ . '/class-tc-bool.php';

class TC_Collapsible_Service {

    /**
     * Return true when the collapsible feature is enabled for a table.
     *
     * @param array $settings Table settings array.
     * @return bool
     */
    public static function is_enabled( array $settings ): bool {
        // Use TC_Bool::cast() so string "false" from jQuery $.param()
        // serialisation is treated as false, not true. (#2308)
        return TC_Bool::cast( $settings['collapsible_enabled'] ?? false );
    }

    /**
     * Return true when the table should start in the collapsed state.
     *
     * @param array $settings Table settings array.
     * @return bool
     */
    public static function is_default_collapsed( array $settings ): bool {
        // Same coercion guard as is_enabled() - string "false" must be false.
        return TC_Bool::cast( $settings['collapsible_default_collapsed'] ?? false );
    }

    /**
     * Render the accessible toggle button HTML.
     *
     * The button uses aria-expanded to communicate state and aria-controls
     * to reference the collapsible table body wrapper by ID.
     *
     * @param int    $table_id  Table ID (used to generate unique IDs).
     * @param string $title     Table title shown on the button.
     * @param bool   $collapsed Whether the table starts collapsed.
     * @return string
     */
    public static function get_toggle_button_html( int $table_id, string $title, bool $collapsed = false ): string {
        $expanded      = $collapsed ? 'false' : 'true';
        $body_id       = 'gt-collapsible-body-' . $table_id;
        $btn_id        = 'gt-collapse-btn-' . $table_id;
        $chevron_class = $collapsed ? 'gt-chevron gt-chevron--down' : 'gt-chevron gt-chevron--up';
        $safe_title    = esc_html( $title );

        return sprintf(
            '<div class="gt-collapsible-header">' .
            '<button id="%s" class="gt-collapse-toggle" aria-expanded="%s" aria-controls="%s" type="button">' .
            '<span class="gt-collapse-title">%s</span>' .
            '<span class="%s" aria-hidden="true"></span>' .
            '</button>' .
            '</div>',
            esc_attr( $btn_id ),
            esc_attr( $expanded ),
            esc_attr( $body_id ),
            $safe_title,
            esc_attr( $chevron_class )
        );
    }

    /**
     * Return the HTML attributes for the collapsible table body wrapper.
     *
     * @param int  $table_id
     * @param bool $collapsed
     * @return string  e.g. ' id="gt-collapsible-body-1" class="gt-collapsible-body" hidden'
     */
    public static function get_body_wrapper_attrs( int $table_id, bool $collapsed = false ): string {
        $id     = 'gt-collapsible-body-' . $table_id;
        $hidden = $collapsed ? ' hidden' : '';
        return sprintf( ' id="%s" class="gt-collapsible-body"%s', esc_attr( $id ), $hidden );
    }

    /**
     * Generate a self-contained inline JavaScript snippet for the collapsible toggle.
     *
     * Behaviours:
     *  - Reads/writes open state to localStorage (key: gt_collapse_{table_id}).
     *  - Applies the stored state on page load (overriding the PHP default if set).
     *  - Calls DataTable columns.adjust() on expand to fix hidden-init width issues.
     *  - Checks window.matchMedia('(prefers-reduced-motion: reduce)') before animating.
     *
     * @param int  $table_id
     * @param bool $store_state Whether to persist state in localStorage.
     * @return string  JavaScript (no surrounding <script> tag).
     */
    public static function get_inline_script( int $table_id, bool $store_state = true ): string {
        $id           = (int) $table_id;
        $store        = $store_state ? 'true' : 'false';

        return <<<JS
(function () {
    'use strict';

    var tableId    = {$id};
    var storeState = {$store};
    var storageKey = 'gt_collapse_' + tableId;
    var btnId      = 'gt-collapse-btn-'  + tableId;
    var bodyId     = 'gt-collapsible-body-' + tableId;

    var btn  = document.getElementById(btnId);
    var body = document.getElementById(bodyId);
    if (!btn || !body) { return; }

    // Respect prefers-reduced-motion for expand/collapse animation.
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function applyState(collapsed) {
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (collapsed) {
            body.setAttribute('hidden', '');
        } else {
            body.removeAttribute('hidden');
            // DataTables miscomputes column widths when hidden on init - adjust on reveal.
            if (window.jQuery && typeof window.jQuery.fn.DataTable === 'function') {
                window.jQuery('#gt-table-' + tableId).DataTable().columns.adjust();
            }
        }
        var chevron = btn.querySelector('.gt-chevron');
        if (chevron) {
            chevron.className = collapsed ? 'gt-chevron gt-chevron--down' : 'gt-chevron gt-chevron--up';
        }
        // Animate height transition unless reduced motion is preferred.
        if (!reducedMotion) {
            body.classList.toggle('gt-collapsible-animating', true);
            setTimeout(function () { body.classList.remove('gt-collapsible-animating'); }, 350);
        }
    }

    // Restore persisted state from localStorage.
    if (storeState) {
        try {
            var saved = localStorage.getItem(storageKey);
            if (saved === 'collapsed') { applyState(true); }
            if (saved === 'expanded')  { applyState(false); }
        } catch (e) { /* localStorage may be blocked in private browsing */ }
    }

    btn.addEventListener('click', function () {
        var isExpanded = btn.getAttribute('aria-expanded') === 'true';
        var nextCollapsed = isExpanded; // toggling: expanded → collapse
        applyState(nextCollapsed);
        if (storeState) {
            try { localStorage.setItem(storageKey, nextCollapsed ? 'collapsed' : 'expanded'); } catch (e) {}
        }
    });
}());
JS;
    }
}
