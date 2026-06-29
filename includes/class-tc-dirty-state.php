<?php
/**
 * Admin table-builder dirty-state manager.
 *
 * Tracks all table mutations (add row, delete_row, edit cell, reorder row,
 * toggle column visibility) and enables the Save button whenever any change
 * is detected. Uses event delegation so dynamically added rows are covered
 * without re-binding listeners.
 *
 * Root cause of issue #159: row-deletion events were not wired into the
 * dirty-state detection, leaving the Save button disabled after a delete.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Dirty_State {

    private static $instance = null;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_footer', [ $this, 'enqueue' ] );
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the inline JS that wires up dirty-state tracking.
     *
     * All listeners use event delegation on `document` so they fire for
     * elements that exist now AND elements added dynamically later (e.g.
     * newly inserted rows). The save button is enabled by removing the
     * `disabled` attribute and toggling the `gt-save-btn--active` class.
     *
     * @return string  Raw JavaScript (no <script> wrapper).
     */
    public static function get_script(): string {
        return <<<'JS'
(function($) {
    'use strict';

    var isDirty = false;

    function markDirty() {
        if (isDirty) return;
        isDirty = true;

        // Enable save button by removing the disabled attribute and state class.
        var $saveBtn = $('.gt-save-btn, #gt-save-table, [data-gt-save]');
        $saveBtn.prop('disabled', false).removeAttr('disabled').addClass('gt-save-btn--active');

        // Warn the user if they try to leave without saving.
        $(window).off('beforeunload.gt').on('beforeunload.gt', function () {
            return 'You have unsaved table changes. Are you sure you want to leave?';
        });
    }

    function resetDirty() {
        isDirty = false;
        $(window).off('beforeunload.gt');
    }

    // ---- Event delegation via document ----

    // Row deletion: custom event fired by the builder on deleteRow / gt:row-deleted.
    $(document).on('gt:row-deleted gt:deleteRow delete_row', function() {
        markDirty();
    });

    // Row addition: custom event fired on addRow / gt:row-added.
    $(document).on('gt:row-added gt:addRow add_row', function() {
        markDirty();
    });

    // Cell edit: input/change on any editable cell inside the builder.
    $(document).on('change input', '.gt-table-builder input, .gt-table-builder textarea, .gt-table-builder select', function() {
        markDirty();
    });

    // Row reorder: jQuery UI sortable stop event.
    $(document).on('sortupdate sortstop reorder gt:row-reordered', function() {
        markDirty();
    });

    // Column visibility toggle.
    $(document).on('gt:column-toggled toggle_column', function() {
        markDirty();
    });

    // Intercept direct row-delete button clicks as an additional fallback.
    $(document).on('click', '[data-gt-delete-row], .gt-delete-row, .gt-row-delete', function() {
        // markDirty is called here; the custom event above may also fire depending on builder implementation.
        markDirty();
    });

    // Reset dirty flag after a successful save.
    $(document).on('gt:saved gt:table-saved', function() {
        resetDirty();
    });

}(jQuery));
JS;
    }

    // -------------------------------------------------------------------------
    // WP hooks
    // -------------------------------------------------------------------------

    /**
     * Output the dirty-state script on admin pages that show the table builder.
     * Uses `wp_add_inline_script` when the handle exists; falls back to a raw
     * admin_footer echo if the handle is not registered on this page.
     */
    public function enqueue(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) {
            return;
        }

        // Only inject on our own admin page.
        if ( strpos( $screen->id ?? '', 'gravity-tables' ) === false &&
             strpos( $screen->id ?? '', 'gravity_tables' ) === false ) {
            return;
        }

        if ( wp_script_is( 'gt-admin', 'enqueued' ) ) {
            wp_add_inline_script( 'gt-admin', self::get_script() );
        } else {
            // Fallback: output directly in admin_footer.
            echo '<script id="gt-dirty-state">' . self::get_script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }
}
