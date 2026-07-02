<?php
/**
 * Shortcode functionality for Gravity Tables
 * 
 * Handles the [gravity_table] shortcode rendering and configuration.
 * Manages table data loading, permission checking, and frontend display.
 * 
 * Supports both database-stored configurations and manual shortcode attributes.
 * Provides role-based access control and user-specific filtering.
 *
 * @package GravityTables
 * @author Fahad Murtaza <business@isupercoder.com>
 * @since 1.0.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Shortcode
{

    private static ?TC_Shortcode $instance = null;

    public static function get_instance(): TC_Shortcode
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode('gravity_table', array($this, 'render_deprecated_table'));
        add_shortcode('gravity_tables', array($this, 'render_deprecated_table'));
        add_shortcode('tablecrafter', array($this, 'render_table'));
        add_action('wp_enqueue_scripts', array($this, 'register_scripts'));
        add_filter('script_loader_tag', array($this, 'add_version_attribute'), 10, 3);
        // Prevent TinyMCE Visual mode from converting straight quotes in shortcode
        // attributes to curly/smart quotes, which breaks shortcode parsing (#129)
        add_filter('no_texturize_shortcodes', array($this, 'no_texturize_shortcodes'));
    }

    /**
     * Backward-compatible handler for deprecated [gravity_table] and [gravity_tables] shortcodes.
     * Still renders the table so existing content keeps working during the deprecation window.
     * Fires _doing_it_wrong() so developers are alerted in debug mode.
     * To be removed in TableCrafter 8.0.0.
     *
     * @since 7.6.3
     */
    public function render_deprecated_table(array $atts, string $content = '', string $tag = ''): string
    {
        _doing_it_wrong(
            $tag ?: 'gravity_table',
            sprintf(
                '[%s] is deprecated and will be removed in TableCrafter 8.0. Use [tablecrafter] instead. Update any shortcodes in your pages and in wp_gravity_tables.shortcode.',
                $tag ?: 'gravity_table'
            ),
            '7.6.3'
        );
        return $this->render_table($atts, $content, $tag);
    }

    public function no_texturize_shortcodes(array $shortcodes): array
    {
        $shortcodes[] = 'gravity_table';
        $shortcodes[] = 'gravity_tables';
        $shortcodes[] = 'tablecrafter';
        return $shortcodes;
    }

    public function add_version_attribute(string $tag, string $handle, string $src): string
    {
        if ($handle !== 'gravity-tables-frontend' && $handle !== ('gravity-tables-frontend' . '-bundle')) {
            return $tag;
        }
        return str_replace(' src=', ' data-gt-version="' . esc_attr(TC_VERSION) . '" src=', $tag);
    }

    public function register_scripts()
    {
        // Enqueue Thickbox for frontend popup
        add_thickbox();

        // #831 — frontend.js module 8: pure helpers (naturalSort, parseCurrency,
        // currencySort, checkVersionMismatch) extracted into a window.GTCore
        // namespace. Module is jQuery-independent so it can load very early.
        wp_register_script(
            'gravity-tables-frontend-core',
            TC_PLUGIN_URL . 'assets/js/frontend/core.js',
            array(),
            TC_VERSION,
            true
        );

        // #841 — frontend.js module 1: pure helpers (escapeHtml, formatDate,
        // normalizeToggleValue, etc.) extracted into assets/js/frontend/util.js.
        // Must register BEFORE 'gravity-tables-frontend' so the prototype
        // methods exist by the time the constructor/init runs.
        wp_register_script(
            'gravity-tables-frontend-util',
            TC_PLUGIN_URL . 'assets/js/frontend/util.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #840 — frontend.js module 2: tabs / accordion visibility observer
        // (initVisibilityObserver) extracted into assets/js/frontend/observers.js.
        wp_register_script(
            'gravity-tables-frontend-observers',
            TC_PLUGIN_URL . 'assets/js/frontend/observers.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #838 — frontend.js module 3: conditional formatting rule engine
        // (applyConditionalFormatting + getColumnIndex + getCellValue +
        // evaluateCondition + applyFormattingAction) extracted into
        // assets/js/frontend/conditional-format.js.
        wp_register_script(
            'gravity-tables-frontend-conditional-format',
            TC_PLUGIN_URL . 'assets/js/frontend/conditional-format.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #836 — frontend.js module 4: row selection + bulk-action toolbar
        // (bindSelectionEvents + getSelectedEntryIds + performBulkAction)
        // extracted into assets/js/frontend/selection.js.
        wp_register_script(
            'gravity-tables-frontend-selection',
            TC_PLUGIN_URL . 'assets/js/frontend/selection.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #835 — frontend.js module 5: pagination controls + length selector.
        wp_register_script(
            'gravity-tables-frontend-pagination',
            TC_PLUGIN_URL . 'assets/js/frontend/pagination.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #839 — frontend.js module 6: row-link a11y/keyboard nav.
        wp_register_script(
            'gravity-tables-frontend-a11y-keyboard',
            TC_PLUGIN_URL . 'assets/js/frontend/a11y-keyboard.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #837 — frontend.js module 7: entry-details popup overlay +
        // renderFileUploadCell. viewEntryDetails / showDetailsPopup /
        // closeDetailsPopup / bindDetailViewEvents / renderFileUploadCell.
        // Depends on the util module (escapeHtml) — listed in its deps.
        wp_register_script(
            'gravity-tables-frontend-detail-popup',
            TC_PLUGIN_URL . 'assets/js/frontend/detail-popup.js',
            array('jquery', 'gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #834 slice 1 — search controls. bindSearchEvents wires the
        // .gt-search-btn click + .gt-search-input keypress(Enter).
        wp_register_script(
            'gravity-tables-frontend-search',
            TC_PLUGIN_URL . 'assets/js/frontend/search.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #834 slice 2 — advanced filter panel controls.
        wp_register_script(
            'gravity-tables-frontend-filter-panel',
            TC_PLUGIN_URL . 'assets/js/frontend/filter-panel.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #834 slice 3 — sort handler + multi-column shift-click state machine.
        wp_register_script(
            'gravity-tables-frontend-sort',
            TC_PLUGIN_URL . 'assets/js/frontend/sort.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #834 slice 4 — applyFilters + clearFilters bodies.
        wp_register_script(
            'gravity-tables-frontend-filter-apply',
            TC_PLUGIN_URL . 'assets/js/frontend/filter-apply.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #834 slice 5 (FINAL) — text-filter typeahead (initTextFilterTypeaheads).
        // Closes #834.
        wp_register_script(
            'gravity-tables-frontend-typeahead',
            TC_PLUGIN_URL . 'assets/js/frontend/typeahead.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #832 slice 1 — column totals renderer (updateColumnTotals + computeColumnTotal).
        wp_register_script(
            'gravity-tables-frontend-totals',
            TC_PLUGIN_URL . 'assets/js/frontend/totals.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1731 — Data Bars (Pro): computeBarMaxes + computeDataBarParams.
        // Depends on -util for the shared gtParseNumeric coercion.
        wp_register_script(
            'gravity-tables-frontend-data-bars',
            TC_PLUGIN_URL . 'assets/js/frontend/data-bars.js',
            array('jquery', 'gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #1741 — Badge Cell (Free): applyBadges post-render pass.
        wp_register_script(
            'gravity-tables-frontend-badge-cell',
            TC_PLUGIN_URL . 'assets/js/frontend/badge-cell.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1743 — configurable table auto-refresh on interval (Free).
        // startAutoRefresh() + stopAutoRefresh() on GravityTable.prototype.
        // No AJAX of its own — calls loadEntries() which is already registered
        // as a dep of the main bundle. Minimum 5s enforced client-side.
        wp_register_script(
            'gravity-tables-frontend-auto-refresh',
            TC_PLUGIN_URL . 'assets/js/frontend/auto-refresh.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1744 — column visibility picker (Free).
        // initColumnPicker() + toggleColumnVisibility() + restoreColumnVisibility().
        wp_register_script(
            'gravity-tables-frontend-column-visibility-picker',
            TC_PLUGIN_URL . 'assets/js/frontend/column-visibility-picker.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1745 — bulk column fill (Pro).
        // openBulkFillModal() + executeBulkFill().
        wp_register_script(
            'gravity-tables-frontend-bulk-column-fill',
            TC_PLUGIN_URL . 'assets/js/frontend/bulk-column-fill.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1746 — per-column role visibility (Pro).
        // applyColumnRoleVisibility().
        wp_register_script(
            'gravity-tables-frontend-column-role-visibility',
            TC_PLUGIN_URL . 'assets/js/frontend/column-role-visibility.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1747 — one-click entry duplicate (Pro).
        // duplicateEntry().
        wp_register_script(
            'gravity-tables-frontend-entry-duplicate',
            TC_PLUGIN_URL . 'assets/js/frontend/entry-duplicate.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1749 — inline cell diff badge + bulk fill diff preview.
        wp_register_script(
            'gravity-tables-frontend-row-diff',
            TC_PLUGIN_URL . 'assets/js/frontend/row-diff.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #832 slice 2 — server-side-processing renderer
        // (loadEntriesServerSide + renderSSPEntries).
        wp_register_script(
            'gravity-tables-frontend-ssp',
            TC_PLUGIN_URL . 'assets/js/frontend/ssp.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #832 slice 3 — row-link template resolver (resolveRowLinkAttrs).
        // Depends on the util module (escapeHtml).
        wp_register_script(
            'gravity-tables-frontend-row-link-resolver',
            TC_PLUGIN_URL . 'assets/js/frontend/row-link-resolver.js',
            array('jquery', 'gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #832 slice 4 — cell alignment style + class builder (resolveCellAlignment).
        // Pure module; jQuery-independent.
        wp_register_script(
            'gravity-tables-frontend-alignment-resolver',
            TC_PLUGIN_URL . 'assets/js/frontend/alignment-resolver.js',
            array(),
            TC_VERSION,
            true
        );

        // #832 slice 5 — column link-settings anchor builder (buildLinkAnchorHtml).
        // Depends on util (escapeHtml).
        wp_register_script(
            'gravity-tables-frontend-link-anchor',
            TC_PLUGIN_URL . 'assets/js/frontend/link-anchor.js',
            array('gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #832 slice 6 — toggle / boolean column cell renderer (renderToggleCellHtml, #325).
        // Depends on util (normalizeToggleValue + escapeHtml).
        wp_register_script(
            'gravity-tables-frontend-toggle-cell',
            TC_PLUGIN_URL . 'assets/js/frontend/toggle-cell.js',
            array('gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #832 slice 7 — per-row actions-cell renderer (renderActionsCellHtml).
        // Pure module; jQuery-independent.
        wp_register_script(
            'gravity-tables-frontend-actions-cell',
            TC_PLUGIN_URL . 'assets/js/frontend/actions-cell.js',
            array(),
            TC_VERSION,
            true
        );

        // #1606 — search-term highlighting (highlightInEscapedHtml +
        // renderHighlightedText). Depends on util (escapeHtml).
        wp_register_script(
            'gravity-tables-frontend-search-highlight',
            TC_PLUGIN_URL . 'assets/js/frontend/search-highlight.js',
            array('gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #832 slice 8 — default text-cell renderer (renderTextCellHtml).
        // Depends on util (escapeHtml) + detail-popup (renderFileUploadCell)
        // + search-highlight (#1606 matched-substring wrapping).
        wp_register_script(
            'gravity-tables-frontend-text-cell',
            TC_PLUGIN_URL . 'assets/js/frontend/text-cell.js',
            array('gravity-tables-frontend-util', 'gravity-tables-frontend-detail-popup', 'gravity-tables-frontend-search-highlight'),
            TC_VERSION,
            true
        );

        // #1596 — frontend pivot view + visitor raw/pivot toggle.
        // Depends on jQuery + util (escapeHtml); consumed by
        // render-entries when the response carries is_pivot=true.
        wp_register_script(
            'gravity-tables-frontend-pivot-view',
            TC_PLUGIN_URL . 'assets/js/frontend/pivot-view.js',
            array('jquery', 'gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #1612 — DOM text-node search-highlight pass (HTML cells,
        // card view, per-column text filters).
        wp_register_script(
            'gravity-tables-frontend-search-highlight-dom',
            TC_PLUGIN_URL . 'assets/js/frontend/search-highlight-dom.js',
            array('gravity-tables-frontend-search-highlight'),
            TC_VERSION,
            true
        );

        // #1621 — client-side sort for computed columns.
        wp_register_script(
            'gravity-tables-frontend-computed-sort',
            TC_PLUGIN_URL . 'assets/js/frontend/computed-sort.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1601 — table summary line (rule-based summarizer digest).
        // Depends on jQuery + util (escapeHtml).
        wp_register_script(
            'gravity-tables-frontend-table-summary',
            TC_PLUGIN_URL . 'assets/js/frontend/table-summary.js',
            array('jquery', 'gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #832 slice 9 — #556 detail-row chevron + hidden detail row sibling
        // renderers (renderDetailToggleCellHtml + renderDetailRowHtml).
        // Depends on util (escapeHtml).
        wp_register_script(
            'gravity-tables-frontend-detail-row',
            TC_PLUGIN_URL . 'assets/js/frontend/detail-row.js',
            array('gravity-tables-frontend-util'),
            TC_VERSION,
            true
        );

        // #832 slice 10 — per-column cell dispatcher.
        // resolveCellEditability + renderEntryCellHtml. Composes every
        // other cell renderer (toggle-cell, text-cell, link-anchor,
        // alignment-resolver) plus util's formatDate + escapeHtml.
        wp_register_script(
            'gravity-tables-frontend-entry-cell',
            TC_PLUGIN_URL . 'assets/js/frontend/entry-cell.js',
            array(
                'gravity-tables-frontend-util',
                'gravity-tables-frontend-alignment-resolver',
                'gravity-tables-frontend-link-anchor',
                'gravity-tables-frontend-toggle-cell',
                'gravity-tables-frontend-text-cell',
            ),
            TC_VERSION,
            true
        );

        // #832 slice 19 — column reorder DnD UI. 8 helpers paired with
        // the column-order-persistence module from slice 15. Depends on
        // jQuery for the DnD wiring + DOM manipulation.
        wp_register_script(
            'gravity-tables-frontend-column-reorder-dnd',
            TC_PLUGIN_URL . 'assets/js/frontend/column-reorder-dnd.js',
            array('jquery', 'gravity-tables-frontend-column-order-persistence'),
            TC_VERSION,
            true
        );

        // #832 slice 20 — responsive view + card-view rendering. 13 helpers
        // for the desktop-table vs mobile/tablet-card layout switching plus
        // the card view itself. Depends on jQuery.
        wp_register_script(
            'gravity-tables-frontend-responsive-card-view',
            TC_PLUGIN_URL . 'assets/js/frontend/responsive-card-view.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 1 — undo/redo history for inline cell edits. 9 helpers
        // (initUndoRedo, pushHistoryEntry, updateUndoRedoButtons,
        // undoLastEdit, redoLastEdit, replayHistoryEntry, getFieldLabel,
        // shortValue, showUndoToast). Depends on jQuery for the toolbar
        // button + keybind wiring.
        wp_register_script(
            'gravity-tables-frontend-edit-history',
            TC_PLUGIN_URL . 'assets/js/frontend/edit-history.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #1742 — per-column inline-edit validation rules (Pro). Depends on
        // edit-history.js being loaded first so the GravityTable constructor
        // exists when the prototype methods are attached. The validate path
        // is entirely client-side; no AJAX needed.
        wp_register_script(
            'gravity-tables-frontend-edit-validation',
            TC_PLUGIN_URL . 'assets/js/frontend/edit-validation.js',
            array('jquery', 'gravity-tables-frontend-edit-history'),
            TC_VERSION,
            true
        );

        // #833 slice 2 / #889 — inline-edit AJAX save. Single method
        // (saveField) with history interop, optimistic UI, #553 WAF-safe
        // envelope, recalc fan-out, error rollback. Depends on jQuery for
        // $.post and DOM manipulation, and on edit-history.js for the
        // pushHistoryEntry + getFieldLabel callees (via this/self at runtime
        // so a hard enqueue dep is not strictly required, but listing it
        // makes the relationship explicit).
        wp_register_script(
            'gravity-tables-frontend-edit-save',
            TC_PLUGIN_URL . 'assets/js/frontend/edit-save.js',
            array('jquery', 'gravity-tables-frontend-edit-history', 'gravity-tables-frontend-edit-validation'),
            TC_VERSION,
            true
        );

        // #833 slice 3 — keyboard navigation between inline-editable cells.
        // Two helpers: findNextEditCell (next/prev/down descriptor lookup)
        // and scheduleEditOnTarget (250ms save-settle delay then editField).
        wp_register_script(
            'gravity-tables-frontend-edit-keyboard-nav',
            TC_PLUGIN_URL . 'assets/js/frontend/edit-keyboard-nav.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 4 — search-as-you-type AJS-Toolkit lookup editor.
        // Single method (editAjsToolkitLookupField) that calls
        // `ajs_toolkit_search` AJAX for clients/pits/destinations/materials
        // with 200ms debounce, then routes saves through this.saveField.
        wp_register_script(
            'gravity-tables-frontend-edit-ajs-lookup',
            TC_PLUGIN_URL . 'assets/js/frontend/edit-ajs-lookup.js',
            array('jquery', 'gravity-tables-frontend-edit-save'),
            TC_VERSION,
            true
        );

        // #833 slice 5 / #900 — saved-filter preset subsystem. 8 helpers
        // (initPresets, findPresetById, renderPresetOptions, loadPresets,
        // savePresetPrompt, deletePreset, applyPresetById, applyPresetFilters).
        // Reads/writes filter state via gt_get_filter_presets / gt_save_filter_preset
        // / gt_delete_filter_preset AJAX actions.
        wp_register_script(
            'gravity-tables-frontend-presets',
            TC_PLUGIN_URL . 'assets/js/frontend/presets.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 6 / #902 — print preparation. 3 helpers (initPrint
        // with #531 print-all-rows + afterprint restore + 5000-row cap;
        // preparePrintHeader; summarizeActiveFilters).
        wp_register_script(
            'gravity-tables-frontend-print',
            TC_PLUGIN_URL . 'assets/js/frontend/print.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #613 phase 2 slice 3 (v4.198.0) — pushRowToSource prototype method.
        // Posts the row update to the gt_push_row AJAX endpoint shipped in
        // v4.197.0 (which delegates to TC_JSON_Push_Engine).
        wp_register_script(
            'gravity-tables-frontend-push-to-source',
            TC_PLUGIN_URL . 'assets/js/frontend/push-to-source.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 7 / #904 — row-action button handlers. 4 helpers:
        // loadWooCommerceProducts, createWooCommerceProduct, viewEntryHistory,
        // triggerInlineEditForEntry. Pairs with actions-cell.js (#832 slice 7)
        // which renders the buttons themselves.
        wp_register_script(
            'gravity-tables-frontend-row-actions',
            TC_PLUGIN_URL . 'assets/js/frontend/row-actions.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 8 / #906 — generic lookup-field dropdown populator.
        // Single 275-line method (populateLookupDropdown) — the largest
        // single-method extraction yet. Three-tier fallback (cached config
        // → AJAX gt_get_lookup_options → plain text editor) with a 10s
        // timeout safety net. Saves through this.saveField.
        wp_register_script(
            'gravity-tables-frontend-lookup-dropdown',
            TC_PLUGIN_URL . 'assets/js/frontend/lookup-dropdown.js',
            array('jquery', 'gravity-tables-frontend-edit-save'),
            TC_VERSION,
            true
        );

        // #833 slice 9 / #908 — editField dispatcher (~280 lines). Decides
        // which inline editor to render based on the cell's field-type, then
        // delegates to editAjsToolkitLookupField (slice 4),
        // populateLookupDropdown (slice 8), or renders inline with saveField
        // (slice 2) on Enter/blur/change.
        wp_register_script(
            'gravity-tables-frontend-edit-field',
            TC_PLUGIN_URL . 'assets/js/frontend/edit-field.js',
            array('jquery', 'gravity-tables-frontend-edit-save'),
            TC_VERSION,
            true
        );

        // #833 slice 10 / #910 — global event wiring (~220 lines, bindEvents).
        // All top-level event listeners: toolbar buttons, filter inputs,
        // search, sort, pagination, delegated edit/detail-popup handlers.
        wp_register_script(
            'gravity-tables-frontend-bind-events',
            TC_PLUGIN_URL . 'assets/js/frontend/bind-events.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 11 / #912 — per-row event wiring (~186 lines,
        // bindEntryEvents). Action button clicks, row-link clicks (#567),
        // inline-edit cell clicks, file-upload triggers.
        wp_register_script(
            'gravity-tables-frontend-bind-entry-events',
            TC_PLUGIN_URL . 'assets/js/frontend/bind-entry-events.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 12 / #925 — horizontal scroll-indicator overlay
        // (~370 lines, setupScrollIndicators).
        wp_register_script(
            'gravity-tables-frontend-scroll-indicators',
            TC_PLUGIN_URL . 'assets/js/frontend/scroll-indicators.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 13 / #927 — date input wiring (~158 lines,
        // setupDateInputs). HTML5 date + display mirror + preset
        // chips + range validation.
        wp_register_script(
            'gravity-tables-frontend-date-inputs',
            TC_PLUGIN_URL . 'assets/js/frontend/date-inputs.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 14 / #929 — toolbar export pipeline (~150 lines,
        // initToolbarExport + exportTable). Pairs with toolbar-export.js
        // (#832 slice 13) which holds the pure formatters.
        wp_register_script(
            'gravity-tables-frontend-export',
            TC_PLUGIN_URL . 'assets/js/frontend/export.js',
            array('jquery', 'gravity-tables-frontend-toolbar-export'),
            TC_VERSION,
            true
        );

        // #833 slice 15 / #931 — full-row edit (~126 lines, editEntireRow +
        // saveAllFields).
        wp_register_script(
            'gravity-tables-frontend-row-edit',
            TC_PLUGIN_URL . 'assets/js/frontend/row-edit.js',
            array('jquery', 'gravity-tables-frontend-edit-save'),
            TC_VERSION,
            true
        );

        // #833 slice 16 / #933 — edit-mode UX overlays (~94 lines,
        // showEditIndicator + showReadonlyIndicator).
        wp_register_script(
            'gravity-tables-frontend-edit-indicators',
            TC_PLUGIN_URL . 'assets/js/frontend/edit-indicators.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 17 / #935 — column drag-to-resize (~77 lines,
        // initializeColumnResizing).
        wp_register_script(
            'gravity-tables-frontend-column-resizing',
            TC_PLUGIN_URL . 'assets/js/frontend/column-resizing.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 18 / #937 — single-entry delete flow (~53 lines, deleteEntry).
        wp_register_script(
            'gravity-tables-frontend-delete-entry',
            TC_PLUGIN_URL . 'assets/js/frontend/delete-entry.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 19 / #939 — table utilities (~50 lines, 4 methods:
        // destroy, adjustColumns, showMessage, updateEntryCount).
        wp_register_script(
            'gravity-tables-frontend-table-utils',
            TC_PLUGIN_URL . 'assets/js/frontend/table-utils.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 20 / #941 — URL drilldown state (~46 lines, 2 methods:
        // updateDrilldownUrlState + applyUrlFilters).
        wp_register_script(
            'gravity-tables-frontend-url-state',
            TC_PLUGIN_URL . 'assets/js/frontend/url-state.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #833 slice 21 / #943 — AJAX entry-fetch helper (~72 lines, loadEntries).
        // Dispatches to SSP or sends the entry-fetch AJAX call.
        wp_register_script(
            'gravity-tables-frontend-load-entries',
            TC_PLUGIN_URL . 'assets/js/frontend/load-entries.js',
            array('jquery', 'gravity-tables-frontend-ssp'),
            TC_VERSION,
            true
        );

        // #833 slice 22 / #945 — central DOM render (~81 lines, renderEntries).
        wp_register_script(
            'gravity-tables-frontend-render-entries',
            TC_PLUGIN_URL . 'assets/js/frontend/render-entries.js',
            array('jquery', 'gravity-tables-frontend-entry-row'),
            TC_VERSION,
            true
        );

        // #833 slice 23 / #947 — instance lifecycle bootstrap (~125 lines,
        // init). FINAL slice — after this frontend.js holds only the
        // DOM-ready IIFE + GravityTable constructor.
        wp_register_script(
            'gravity-tables-frontend-init',
            TC_PLUGIN_URL . 'assets/js/frontend/init.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #832 slice 18 — post-render DOM gates: applyRowExpiry (#501) and
        // applyAutoMerge (#518). Triggered at the tail of renderEntries.
        // Depends on jQuery for the DOM manipulation.
        wp_register_script(
            'gravity-tables-frontend-post-render-gates',
            TC_PLUGIN_URL . 'assets/js/frontend/post-render-gates.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #832 slice 15 — column order localStorage persistence (4 helpers:
        // _columnOrderKey, readStoredColumnOrder, saveStoredColumnOrder,
        // clearStoredColumnOrder). No external deps — pure localStorage I/O.
        wp_register_script(
            'gravity-tables-frontend-column-order-persistence',
            TC_PLUGIN_URL . 'assets/js/frontend/column-order-persistence.js',
            array(),
            TC_VERSION,
            true
        );

        // #832 slice 14 — filter state localStorage persistence (4 helpers:
        // _filterStateStorageKey, persistFilterStateLocal,
        // restoreFilterStateLocal, clearFilterStateLocal). Depends on jQuery
        // for the DOM sync after restore.
        wp_register_script(
            'gravity-tables-frontend-filter-state-persistence',
            TC_PLUGIN_URL . 'assets/js/frontend/filter-state-persistence.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #832 slice 13 — toolbar export helpers (6 helpers:
        // toolbarBuildCSV, toolbarTriggerDownload, toolbarCopyToClipboard,
        // toolbarDownloadCSV, toolbarDownloadExcel, toolbarDownloadPDF).
        // Depends on jQuery for the PDF print-button probe.
        wp_register_script(
            'gravity-tables-frontend-toolbar-export',
            TC_PLUGIN_URL . 'assets/js/frontend/toolbar-export.js',
            array('jquery'),
            TC_VERSION,
            true
        );

        // #832 slice 12 — responsive-visibility helpers (5 pure helpers:
        // isFieldVisibleInCards, isFieldVisibleOnMobile, isFieldVisibleOnTablet,
        // getMobileLabel, isFieldVisibleOnCurrentDevice). Read this.config.
        wp_register_script(
            'gravity-tables-frontend-responsive-visibility',
            TC_PLUGIN_URL . 'assets/js/frontend/responsive-visibility.js',
            array(),
            TC_VERSION,
            true
        );

        // #832 slice 11 — per-row HTML builder + no-entries fallback.
        // renderEntryRowHtml + renderNoEntriesRowHtml. Depends on every
        // per-cell renderer through the orchestrator's helpers, plus the
        // row-link-resolver, detail-row, and actions-cell renderers
        // invoked directly by the row builder.
        wp_register_script(
            'gravity-tables-frontend-entry-row',
            TC_PLUGIN_URL . 'assets/js/frontend/entry-row.js',
            array(
                'gravity-tables-frontend-row-link-resolver',
                'gravity-tables-frontend-detail-row',
                'gravity-tables-frontend-actions-cell',
                'gravity-tables-frontend-entry-cell',
            ),
            TC_VERSION,
            true
        );

        wp_register_script(
            'gravity-tables-frontend',
            TC_PLUGIN_URL . 'assets/js/frontend.js',
            array(
                'jquery',
                'thickbox',
                'gravity-tables-frontend-core',
                'gravity-tables-frontend-util',
                'gravity-tables-frontend-observers',
                'gravity-tables-frontend-conditional-format',
                'gravity-tables-frontend-selection',
                'gravity-tables-frontend-pagination',
                'gravity-tables-frontend-a11y-keyboard',
                'gravity-tables-frontend-detail-popup',
                'gravity-tables-frontend-search',
                'gravity-tables-frontend-filter-panel',
                'gravity-tables-frontend-sort',
                'gravity-tables-frontend-filter-apply',
                'gravity-tables-frontend-typeahead',
                'gravity-tables-frontend-totals',
                'gravity-tables-frontend-data-bars',
                'gravity-tables-frontend-badge-cell',
                'gravity-tables-frontend-auto-refresh',
                'gravity-tables-frontend-column-visibility-picker',
                'gravity-tables-frontend-bulk-column-fill',
                'gravity-tables-frontend-column-role-visibility',
                'gravity-tables-frontend-entry-duplicate',
                'gravity-tables-frontend-row-diff',
                'gravity-tables-frontend-ssp',
                'gravity-tables-frontend-row-link-resolver',
                'gravity-tables-frontend-alignment-resolver',
                'gravity-tables-frontend-link-anchor',
                'gravity-tables-frontend-toggle-cell',
                'gravity-tables-frontend-actions-cell',
                'gravity-tables-frontend-search-highlight',
                'gravity-tables-frontend-search-highlight-dom',
                'gravity-tables-frontend-text-cell',
                'gravity-tables-frontend-pivot-view',
                'gravity-tables-frontend-table-summary',
                'gravity-tables-frontend-computed-sort',
                'gravity-tables-frontend-detail-row',
                'gravity-tables-frontend-entry-cell',
                'gravity-tables-frontend-entry-row',
                'gravity-tables-frontend-responsive-visibility',
                'gravity-tables-frontend-toolbar-export',
                'gravity-tables-frontend-filter-state-persistence',
                'gravity-tables-frontend-column-order-persistence',
                'gravity-tables-frontend-post-render-gates',
                'gravity-tables-frontend-column-reorder-dnd',
                'gravity-tables-frontend-responsive-card-view',
                'gravity-tables-frontend-edit-history',
                'gravity-tables-frontend-edit-save',
                'gravity-tables-frontend-edit-keyboard-nav',
                'gravity-tables-frontend-edit-ajs-lookup',
                'gravity-tables-frontend-presets',
                'gravity-tables-frontend-print',
                'gravity-tables-frontend-push-to-source',
                'gravity-tables-frontend-row-actions',
                'gravity-tables-frontend-lookup-dropdown',
                'gravity-tables-frontend-edit-field',
                'gravity-tables-frontend-bind-events',
                'gravity-tables-frontend-bind-entry-events',
                'gravity-tables-frontend-scroll-indicators',
                'gravity-tables-frontend-date-inputs',
                'gravity-tables-frontend-export',
                'gravity-tables-frontend-row-edit',
                'gravity-tables-frontend-edit-indicators',
                'gravity-tables-frontend-column-resizing',
                'gravity-tables-frontend-delete-entry',
                'gravity-tables-frontend-table-utils',
                'gravity-tables-frontend-url-state',
                'gravity-tables-frontend-load-entries',
                'gravity-tables-frontend-render-entries',
                'gravity-tables-frontend-init',
            ),
            TC_VERSION,
            true
        );

        // #1049 Option 1B v4.218.0 — single-handle alternative to the 55-handle
        // chain above. Built by tools/build-frontend-bundle.sh as part of
        // tools/build-release.sh. Enqueued instead of 'gravity-tables-frontend'
        // when gt_settings.use_frontend_bundle is truthy (default: opt-in OFF).
        // #1661 — prefer the minified bundle when present (and SCRIPT_DEBUG off).
        $gt_bundle_rel = (!(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)
            && file_exists(TC_PLUGIN_PATH . 'assets/js/frontend-bundle.min.js'))
            ? 'assets/js/frontend-bundle.min.js'
            : 'assets/js/frontend-bundle.js';
        wp_register_script(
            'gravity-tables-frontend-bundle',
            TC_PLUGIN_URL . $gt_bundle_rel,
            array('jquery', 'thickbox'),
            TC_VERSION,
            true
        );

        // CSS Framework setting (#633 wire-up — was inert until v4.8.14).
        // The Settings page dropdown picks 'default', 'minimal', or 'none'.
        // Default = full frontend.css (current behavior).
        // Minimal = frontend-minimal.css (3.4 KB vs 125 KB — basic table
        //           styles only, intended for theme-styled sites).
        // None    = register the handle with `false` so subsequent
        //           wp_enqueue_style calls are no-ops; only the theme's
        //           CSS applies.
        $gt_plugin_settings = get_option('gt_settings', array());
        $gt_css_framework   = isset($gt_plugin_settings['css_framework'])
            ? $gt_plugin_settings['css_framework']
            : 'default';

        if ($gt_css_framework === 'none') {
            // Register an empty handle so dependent enqueues don't error.
            wp_register_style(
                'gravity-tables-frontend',
                false,
                array('thickbox'),
                TC_VERSION
            );
        } else {
            // #1661 — prefer the minified CSS when present (and SCRIPT_DEBUG off).
            $gt_css_base = ($gt_css_framework === 'minimal') ? 'frontend-minimal' : 'frontend';
            $gt_css_rel  = (!(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)
                && file_exists(TC_PLUGIN_PATH . 'assets/css/' . $gt_css_base . '.min.css'))
                ? 'assets/css/' . $gt_css_base . '.min.css'
                : 'assets/css/' . $gt_css_base . '.css';
            $gt_css_url = TC_PLUGIN_URL . $gt_css_rel;

            wp_register_style(
                'gravity-tables-frontend',
                $gt_css_url,
                array('thickbox'), // Add thickbox CSS dependency
                TC_VERSION
            );
        }

        wp_register_style(
            'gravity-tables-frontend-print',
            TC_PLUGIN_URL . 'assets/css/frontend-print.css',
            array('gravity-tables-frontend'),
            TC_VERSION,
            'print'
        );

        // #1006 v4.179.0 — Data-source render styles (extracted from inline
        // styles that shipped in v4.170.0 / v4.174.0 / v4.178.0).
        wp_register_style(
            'gravity-tables-data-source-render',
            TC_PLUGIN_URL . 'assets/css/data-source-render.css',
            array(),
            TC_VERSION
        );
        // #1673 — only enqueue on pages that actually render a table (was
        // loaded sitewide on every front-end page).
        if (!class_exists('TC_Asset_Enqueue_Gate') || TC_Asset_Enqueue_Gate::page_has_table()) {
            wp_enqueue_style('gravity-tables-data-source-render');
        }
    }

    public function render_table(array|string $atts): string
    {
        // SERVER-SIDE VALIDATION: Enforce plan limits on rendering
        $limit_error = $this->enforce_plan_limits($atts);
        if ($limit_error) {
            return $limit_error; // Return error message instead of table
        }

        // Check if this is a preview request
        $is_preview = defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action']) && $_POST['action'] === 'gt_preview_table';

        if ($is_preview) {
            // For preview, use the posted settings directly
            $atts = $_POST['settings'] ?? array();
            $atts['form_id'] = intval($_POST['form_id'] ?? 1);
            // Use the per_page setting from the form, or default from plugin settings
            if (!isset($atts['per_page'])) {
                $plugin_settings = get_option('gt_settings', array());
                $atts['per_page'] = isset($plugin_settings['default_per_page']) ? intval($plugin_settings['default_per_page']) : 25;
            }
        } else {
            // For regular shortcode, accept table ID and optional overrides
            $atts = shortcode_atts(array(
                'id' => 0, // Table ID from database - required
                'allowed_user_roles' => '', // Optional: comma-separated roles for manual shortcodes
                'user_role_filter' => '', // Backward compatibility
                // Roadmap features that have runtime support but no admin UI
                // yet (#655). Registering them as shortcode_atts keys lets
                // customers configure them per-shortcode without editing the
                // saved JSON or installing a custom developer hook.
                // See templates/table.php:146-154 for the runtime consumers.
                'row_link_template'     => '', // #567 slice 1: e.g. "/loads/{1}"; empty disables clickable rows
                'row_link_open_new_tab' => '', // #567 slice 2.4: truthy = always-open-in-new-tab
                'print_all_rows'        => '', // #531 slice 1: empty/truthy = print all rows; "false"/"0" = print only visible page
                // #324 / #656: scrollable-table viewport. Sanitizer + runtime
                // exist (templates/table.php:109-110); admin UI was removed at
                // some point and never restored. Registering here as the
                // Option-A escape hatch from #656.
                'enable_vertical_scroll'      => '', // truthy = wrap table in a max-height container with overflow-y: auto
                'vertical_scroll_max_height'  => '', // CSS height (e.g. "500px", "60vh"); defaults to "400px"
                // #657: horizontal scroll + row-expiry (#501). Same orphan-
                // with-runtime pattern. templates/table.php:341-345 +
                // 137-140 read them; class-tc-admin.php:702-720 sanitizes
                // expiry_*; no admin UI for any of these today.
                'horizontal_scroll'   => '', // truthy = wrap table in horizontal-scroll container
                'expiry_field_id'     => '', // GF field id holding row expiry date; empty disables feature
                'expiry_behavior'     => '', // one of: hide, strikethrough, bottom, move_bottom
                'expiry_grace_days'   => '', // days past expiry before behavior kicks in
                'expiry_inverse'      => '', // truthy = treat field as start-date instead of end-date
                // #659: six more orphan-with-runtime keys. table_width has its
                // own dimension validation at class-tc-shortcode.php:303;
                // the others are simple boolean / string passes through to
                // templates/table.php.
                'table_width'          => '', // 'auto', percentage, or numeric+unit (px/rem/em/%/vw/ch)
                'css_class'            => '', // extra class string appended to the wrapper element
                'show_export'          => '', // toolbar export-button group toggle (default true)
                'show_print'           => '', // print toolbar button toggle (default true)
                'show_pagination_info' => '', // "Showing X of Y" text toggle (default true)
                'table_style'          => '', // style slug appended to gt_table_classes (default 'default')
                // #2139 — legacy 3.5.x inline-source params. When `source` is set
                // and `id` is not, the shortcode renders the URL live (JSON / CSV
                // / public Google Sheet) so pre-v8 shortcodes keep working.
                'source'               => '', // legacy: JSON / CSV / Google Sheet URL
                'root'                 => '', // legacy: JSON dot-path to the data array
                'include'              => '', // legacy: comma-separated allow-list of columns
                'exclude'              => '', // legacy: comma-separated columns to hide
                // #2142 — legacy inline view toggles (client-side enhancement).
                'per_page'             => '', // rows per page (default 25)
                'search'               => '', // "false" to hide the search box (default shown)
                'export'               => '', // "true" to show a CSV export button
                'filters'              => '', // "true" to show per-column filter inputs
                // #2143 — legacy inline auto-refresh params. Must be registered
                // here or shortcode_atts() strips them before build_refresh_opts()
                // ever sees them (the bug a browser smoke caught: auto_refresh was
                // dropped end-to-end despite the unit test passing).
                'auto_refresh'         => '', // "true" to poll + refresh the table
                'refresh_interval'     => '', // ms between refreshes (min 5000)
                'refresh_indicator'    => '', // "false" to hide the refreshing indicator
                'refresh_countdown'    => '', // "true" to show a countdown
                'refresh_last_updated' => '', // "false" to hide the last-updated time
            ), $atts, 'gravity_table');

            // Parse comma-separated roles if provided
            if (!empty($atts['allowed_user_roles'])) {
                $atts['allowed_user_roles'] = array_map('trim', explode(',', $atts['allowed_user_roles']));
            } elseif (!empty($atts['user_role_filter'])) {
                // Backward compatibility
                $atts['allowed_user_roles'] = array(trim($atts['user_role_filter']));
            }

            // #2139 — legacy inline source (3.5.x): no id but a source URL.
            // Render it live so pre-v8 shortcodes don't break after upgrade.
            if (class_exists('TC_Inline_Shortcode_Compat')
                && TC_Inline_Shortcode_Compat::has_inline_source($atts)) {
                return $this->render_inline_source_table($atts);
            }

            // Table ID is required for regular shortcode
            if (!$atts['id']) {
                return '<p>' . __('Error: Table ID is required. Use [tablecrafter id="123"]', 'tc-data-tables') . '</p>';
            }

            // Load all settings from database
            $admin = TC_Admin::get_instance();
            $table_data = $admin->get_table($atts['id']);


            if (!$table_data) {
                return '<p>' . __('Error: Table not found with ID: ' . intval($atts['id']), 'tc-data-tables') . '</p>';
            }

            // #2116 — funnel: a real table is rendering on the public frontend,
            // i.e. it is published and live. Guarded + front-end-only so the
            // option is written once and never on admin/preview renders.
            if (!is_admin()
                && class_exists('TC_Activation_Funnel')
                && !TC_Activation_Funnel::has('table_published')) {
                TC_Activation_Funnel::record('table_published');
            }

            // Known/registered table types — guards against unknown source types (#342).
            $registered_table_types = apply_filters('gt_registered_table_types', array('gravity', 'merged'));

            // Guard: unknown source type → graceful fallback, no fatal error (#342).
            if (isset($table_data->type) && $table_data->type !== '' && !in_array($table_data->type, $registered_table_types, true)) {
                $unknown_type = esc_html($table_data->type);
                $admin_notice = '';
                if (current_user_can('manage_options')) {
                    $admin_notice = '<p class="gt-unknown-type-admin-notice" style="color:#b00;">'
                        . sprintf(
                            /* translators: %s = the unrecognised data source type string */
                            __('TableCrafter: The data source type "%s" for this table is unavailable — please check that the required add-on or module is active.', 'tc-data-tables'),
                            $unknown_type
                        )
                        . '</p>';
                }
                return '<div class="gt-table-unavailable gt-unknown-type">'
                    . '<p>' . __('This table\'s data source is currently unavailable. Please contact the site administrator.', 'tc-data-tables') . '</p>'
                    . $admin_notice
                    . '</div>';
            }

            // Delegate to TC_Merged_Table for combined views (#82)
            if (isset($table_data->type) && $table_data->type === 'merged') {
                $source_ids = json_decode($table_data->merged_source_ids ?? '[]', true) ?: [];
                $merged     = new TC_Merged_Table();
                $page       = max(1, (int) ($atts['page'] ?? 1));
                $per_page   = max(1, (int) ($atts['per_page'] ?? 25));
                $entries    = $merged->get_entries($source_ids, $page, $per_page);
                $total      = $merged->get_total_count($source_ids);
                // Return a simple JSON-encoded placeholder; real rendering handled by frontend
                return '<div class="gt-merged-table-placeholder" '
                    . 'data-source-ids="' . esc_attr(json_encode($source_ids)) . '" '
                    . 'data-total="' . esc_attr($total) . '">'
                    . esc_html(sprintf(
                        /* translators: %d = total number of merged entries */
                        __('Merged table: %d entries from %d sources.', 'tc-data-tables'),
                        $total,
                        count($source_ids)
                    ))
                    . '</div>';
            }

            $settings = json_decode($table_data->settings, true);
            if ($settings === null) {
                // error_log("JSON decode failed for table ID {$atts['id']}! JSON error: " . json_last_error_msg());
                return '<p>' . __('Error: Invalid table configuration.', 'tc-data-tables') . '</p>';
            }

            // #988 v4.170.0 — JSON data source render path (slice 3b-3b of #512).
            // CLOSES the JSON-as-data-source feature. Minimum-viable v1 render
            // separate from the GFAPI path: fetches via cached helper, renders a
            // basic styled <table> with inferred columns. Advanced features
            // (filters, sorting, exports, frontend editing) are GF-specific and
            // remain for the GF render path until a future polish slice adds
            // them to the JSON render.
            // #2026 (D1) — gate PRO data sources behind the premium plan. The
            // registry's 'pro' flag is the single source of truth (Notion /
            // External DB / XML are Pro; Gravity Forms / JSON / CSV / public
            // Google Sheets / Airtable-read are free).
            $gt_src_type = isset($settings['data_source_type']) ? (string) $settings['data_source_type'] : '';
            if ($gt_src_type !== '' && class_exists('TC_Source_Registry')) {
                $gt_src_def = TC_Source_Registry::get($gt_src_type);
                // #2030 — grandfathered (ever-premium) sites keep read-only
                // access to pro-source tables on a lapse; writes stay premium-only.
                if ($gt_src_def && !empty($gt_src_def['pro']) && !gt_is_premium() && !gt_is_grandfathered()) {
                    if (current_user_can('manage_options')) {
                        return '<div class="gt-upgrade-notice" style="background:#fff3cd;border:1px solid #ffeaa7;color:#856404;padding:15px;border-radius:5px;margin:20px 0;">'
                            . '<strong>⚠️ ' . esc_html__('Pro data source', 'tc-data-tables') . ':</strong> '
                            . esc_html(sprintf(__('The %s data source is a Pro feature.', 'tc-data-tables'), $gt_src_def['label']))
                            . ' <a href="' . esc_url(function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#') . '">' . esc_html__('Upgrade to Pro', 'tc-data-tables') . '</a>'
                            . '</div>';
                    }
                    return '<p>' . esc_html__('This table is unavailable on the current plan.', 'tc-data-tables') . '</p>';
                }
            }

            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'json') {
                return $this->render_json_source_table((int) $atts['id'], $settings);
            }

            // #996 v4.174.0 — Airtable data source render path (phase D of #517).
            // CLOSES the Airtable-as-data-source feature. Same architectural pattern
            // as the JSON render — separate path that uses the Airtable cached
            // helper (#994) instead of going through GFAPI.
            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'airtable') {
                return $this->render_airtable_source_table((int) $atts['id'], $settings);
            }

            // #1004 v4.178.0 — Notion data source render path (phase 4 of #592).
            // CLOSES the Notion-as-data-source feature. Third repetition of the
            // data-source pattern from #512 (JSON) and #517 (Airtable).
            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'notion') {
                return $this->render_notion_source_table((int) $atts['id'], $settings);
            }

            // #2002 — Google Sheets data source (convergence epic #2006). The
            // TC_Google_Sheets engine already fetched/parsed public sheets; this
            // wires it into the render path like the JSON/Airtable/Notion sources.
            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'google_sheets') {
                return $this->render_google_sheets_source_table((int) $atts['id'], $settings);
            }

            // #2004 — XML data source (convergence epic #2006). TC_XML_Source
            // already fetched/parsed XML; this wires it into the render path.
            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'xml') {
                return $this->render_xml_source_table((int) $atts['id'], $settings);
            }

            // #2010 — live CSV URL data source (convergence epic #2006).
            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'xlsx') {
                return $this->render_xlsx_source_table((int) $atts['id'], $settings);
            }

            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'csv') {
                return $this->render_csv_source_table((int) $atts['id'], $settings);
            }

            // #2003 — External database (MySQL / SQL Server) data source.
            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'external_db') {
                return $this->render_external_db_source_table((int) $atts['id'], $settings);
            }

            // #2196 — WooCommerce products data source. The id-based render path
            // had no case for it, so a woo table fell through to the
            // "Gravity Forms is required" check below and never rendered.
            if (isset($settings['data_source_type']) && $settings['data_source_type'] === 'woocommerce_products') {
                return $this->render_woocommerce_source_table((int) $atts['id'], $settings);
            }

            // #607 slice 3 — frontend password gate. Defense in depth:
            // when the table has a password hash and the visitor's cookie
            // doesn't verify (or no correct POST), short-circuit BEFORE
            // any data fetch, before the template include. The table data
            // never reaches the HTML output when locked.
            $password_form_html = $this->apply_password_gate(
                (int) $atts['id'],
                isset($settings['table_password_hash']) ? (string) $settings['table_password_hash'] : ''
            );
            if ($password_form_html !== null) {
                return $password_form_html;
            }


            // Use all settings from database - flatten if nested
            if (isset($settings['settings']) && is_array($settings['settings'])) {
                // Settings are nested - flatten them
                $flattened_settings = $settings['settings'];
                // Merge other top-level settings
                foreach ($settings as $key => $value) {
                    if ($key !== 'settings') {
                        $flattened_settings[$key] = $value;
                    }
                }
                $db_settings = $flattened_settings;
            } else {
                // Settings are already flat
                $db_settings = $settings;
            }

            // Merge database settings with shortcode overrides (shortcode takes priority)
            $shortcode_overrides = [];
            if (!empty($atts['allowed_user_roles']) && is_array($atts['allowed_user_roles'])) {
                $shortcode_overrides['allowed_user_roles'] = $atts['allowed_user_roles'];
            }
            // Carry through customer-set unlock shortcode attributes (#658).
            // Until v4.8.6 the merge below silently dropped every shortcode
            // attribute that wasn't `allowed_user_roles`, so the unlocks
            // landed in v4.8.1 (#655) / v4.8.2 (#656) / v4.8.3 (#657) never
            // actually reached runtime. shortcode_atts() defaults each of
            // these to '' so non-empty == customer explicitly passed it.
            $unlock_keys = array(
                'row_link_template',
                'row_link_open_new_tab',
                'print_all_rows',
                'enable_vertical_scroll',
                'vertical_scroll_max_height',
                'horizontal_scroll',
                'expiry_field_id',
                'expiry_behavior',
                'expiry_grace_days',
                'expiry_inverse',
                // #659:
                'table_width',
                'css_class',
                'show_export',
                'show_print',
                'show_pagination_info',
                'table_style',
            );
            foreach ($unlock_keys as $override_key) {
                if (isset($atts[$override_key]) && $atts[$override_key] !== '') {
                    $shortcode_overrides[$override_key] = $atts[$override_key];
                }
            }

            $atts = array_merge($db_settings, $shortcode_overrides);

            // Ensure enable_frontend_editing is properly set based on user capabilities
            if (!isset($atts['enable_frontend_editing'])) {
                // Default to true for users with editing capabilities, false otherwise
                $atts['enable_frontend_editing'] = (current_user_can('edit_posts') || current_user_can('publish_posts') || current_user_can('driver')) ? true : false;
            }

            // Debug output removed for production

            $atts['form_id'] = $table_data->form_id;
            $atts['table_id'] = $table_data->id;

            // Allow plugins to modify the full table config before render
            $atts = apply_filters('gravity_tables_table_config', $atts, $atts['table_id']);
        }

        // Check if Gravity Forms is active
        if (!class_exists('GFAPI')) {
            // @codeCoverageIgnoreStart
            return '<p>' . __('Gravity Forms is required for this table.', 'tc-data-tables') . '</p>';
            // @codeCoverageIgnoreEnd
        }

        // Ensure bulk_actions has the documented actions when missing or
        // empty. Tables saved after v4.7.178 carry settings.bulk_actions = []
        // because admin.js still reads from the deleted bulk_delete /
        // bulk_export / bulk_edit checkboxes (#651). The empty-array case
        // wasn't covered by the prior `!is_array()` guard, so the bulk-action
        // toolbar silently disappeared on every new table. Treat empty as
        // "use the documented default".
        //
        // #1684: 'export' dropped from the default — exporting is consolidated
        // into the Export menu (#1680). A shortcode can still opt back in via
        // an explicit bulk_actions including 'export'.
        if (!isset($atts['bulk_actions']) || !is_array($atts['bulk_actions']) || empty($atts['bulk_actions'])) {
            $atts['bulk_actions'] = array('delete', 'edit');
        }

        // Get form structure and build column configuration
        $form_id = intval($atts['form_id']);

        // Check table access permissions
        if (!$this->checkTableAccessPermission($form_id, $atts)) {
            return '<div class="gt-access-denied"><p>' .
                __('Access denied. You do not have permission to view this table.', 'tc-data-tables') .
                '</p></div>';
        }

        $form_fields = $this->get_form_fields($form_id);
        $column_config = $this->build_column_config($form_fields, $atts);

        if (empty($column_config)) {
            return '<p>' . __('Error: Could not load form fields or no fields configured.', 'tc-data-tables') . '</p>';
        }

        // Generate unique ID for this table instance
        // @codeCoverageIgnoreStart
        $table_instance_id = 'gt-table-' . uniqid();
        // @codeCoverageIgnoreEnd

        // Generate scoped typography CSS when the table config carries typography settings (#84)
        // @codeCoverageIgnoreStart
        $gt_typography_css = '';
        if (!empty($atts['typography']) && is_array($atts['typography'])) {
            $gt_typography_css = TC_Typography_Service::generate_css(
                '#' . $table_instance_id,
                $atts['typography']
            );
            if (!empty($atts['typography']['font_family'])) {
                TC_Typography_Service::maybe_enqueue_google_font($atts['typography']['font_family']);
        // @codeCoverageIgnoreEnd
            }
        }

        // Resolve table_width setting for the wrapper element (#85)
        // Allowed values: 'auto', '100%', or a CSS dimension (e.g. '800px', '60rem')
        // When absent/empty the wrapper carries no explicit width — inherits theme layout.
        // @codeCoverageIgnoreStart
        $gt_table_width = '';
        if (!empty($atts['table_width'])) {
            $raw_width = sanitize_text_field($atts['table_width']);
        // @codeCoverageIgnoreEnd
            // Accept 'auto', percentage, or numeric+unit values only
            // @codeCoverageIgnoreStart
            if ($raw_width === 'auto' || preg_match('/^\d+(\.\d+)?(px|rem|em|%|vw|ch)$/', $raw_width) || $raw_width === '100%') {
                $gt_table_width = $raw_width;
            // @codeCoverageIgnoreEnd
            }
        }

        // Make lookup configurations available to template as $lookup_fields
        // @codeCoverageIgnoreStart
        $lookup_fields = isset($atts['lookup_fields']) ? $atts['lookup_fields'] : array();
        // @codeCoverageIgnoreEnd

        // Both preview and regular shortcode use the same template now
        // Preview will be marked with a CSS class for styling differences


        // Ensure form_id is available to template
        // @codeCoverageIgnoreStart
        if (!isset($form_id)) {
            error_log('GT Shortcode: form_id not set before template include');
            $form_id = intval($atts['form_id']);
        // @codeCoverageIgnoreEnd
        }

        // Resolve dynamic placeholder tokens ({current_date} etc.) in column
        // default values before the template renders cells (#83).
        // Also apply do_shortcode() to default_value for render_shortcodes columns (#87).
        // @codeCoverageIgnoreStart
        foreach ($column_config as &$col) {
            if (!empty($col['default_value']) && is_string($col['default_value'])) {
                $col['default_value'] = TC_Placeholder_Service::resolve($col['default_value']);
                if (!empty($col['render_shortcodes'])) {
                    $col['default_value'] = do_shortcode($col['default_value']);
        // @codeCoverageIgnoreEnd
                }
            }
            // @codeCoverageIgnoreStart
            if (!empty($col['header']) && is_string($col['header'])) {
                $col['header'] = TC_Placeholder_Service::resolve($col['header']);
            // @codeCoverageIgnoreEnd
            }
        }
        // @codeCoverageIgnoreStart
        unset($col);
        // @codeCoverageIgnoreEnd

        // @codeCoverageIgnoreStart
        $table_id_for_hooks = isset($atts['table_id']) ? (int) $atts['table_id'] : 0;
        do_action('gravity_tables_before_render_table', $table_id_for_hooks, $atts);
        ob_start();
        include TC_PLUGIN_PATH . 'templates/table.php';
        $html = trim(ob_get_clean());
        do_action('gravity_tables_after_render_table', $table_id_for_hooks, $atts);
        $html = apply_filters('gravity_tables_render_html', $html, $table_id_for_hooks, $atts);
        return $html;
        // @codeCoverageIgnoreEnd
    }

    /**
     * #988 v4.170.0 — Render a JSON-source table on the frontend.
     *
     * Slice 3b-3b of #512. Minimum-viable v1: fetch rows via the cached
     * helper (slice 3b-3a), infer columns, render a basic styled <table>.
     *
     * Out of scope for this slice (deferred polish):
     *   - Filters / search bar
     *   - Sorting (header clicks)
     *   - Pagination
     *   - Frontend editing
     *   - Export / print toolbar
     *
     * The advanced features above are tightly coupled to the Gravity Forms
     * render path. Adding them to the JSON render is its own workstream
     * (sub-issues to be filed as customers report need).
     *
     * @param int   $table_id The wp_gravity_tables row id.
     * @param array $settings Already-decoded settings array.
     * @return string Rendered HTML.
     */
    /**
     * #2139 — Render a legacy inline-source shortcode live.
     *
     * Reproduces the 3.5.x `[tablecrafter source="…" root="…" include="…"]`
     * behaviour on v8 by fetching the URL through the existing source services
     * (which carry the SSRF guard + caching) and rendering via the shared
     * external-source HTML renderer. Honours `root` (JSON dot-path) and
     * `include` / `exclude` column curation.
     *
     * @param array $atts Resolved shortcode attributes (source set, id empty).
     */
    private function render_inline_source_table(array $atts): string
    {
        $url = isset($atts['source']) ? trim((string) $atts['source']) : '';

        $type        = TC_Inline_Shortcode_Compat::detect_source_type($url);
        $rows        = array();
        $column_keys = array();

        // SSRF guard — reuse the JSON service's allow-list (blocks loopback /
        // private networks) for http(s) sources. Airtable (#2148) uses an
        // airtable:// pseudo-URL whose real request always targets the fixed
        // api.airtable.com host, so the http allow-list doesn't apply to it.
        if ($type !== 'airtable' && !TC_JSON_Source_Service::is_safe_url($url)) {
            return '<p class="gt-inline-source-error">'
                . esc_html__('TableCrafter: this source URL is not allowed.', 'tc-data-tables')
                . '</p>';
        }

        if ($type === 'airtable') {
            return $this->render_inline_airtable($url, $atts);
        }

        if ($type === 'google_sheets') {
            $sheets = TC_Google_Sheets::get_instance();
            $csv    = $sheets->get_cached($url, 3600);
            $sc     = $this->render_external_source_short_circuit($csv, 'google_sheets',
                __('Google Sheets error:', 'tc-data-tables'),
                __('No rows in the Google Sheet.', 'tc-data-tables'));
            if ($sc !== null) { return $sc; }

            $parsed   = $sheets->parse_csv_to_rows((string) $csv);
            $headers  = isset($parsed['headers']) ? array_map('strval', (array) $parsed['headers']) : array();
            $raw_rows = isset($parsed['rows']) ? (array) $parsed['rows'] : array();
            if (empty($headers) || empty($raw_rows)) {
                return '<p class="gt-google_sheets-source-empty">'
                    . esc_html__('No rows in the Google Sheet.', 'tc-data-tables') . '</p>';
            }
            $column_keys = $headers;
            foreach ($raw_rows as $cells) {
                $cells = (array) $cells;
                $assoc = array();
                foreach ($column_keys as $i => $key) {
                    $assoc[$key] = isset($cells[$i]) ? $cells[$i] : '';
                }
                $rows[] = $assoc;
            }
            $meta_format = _n('%d row from Google Sheets.', '%d rows from Google Sheets.', count($rows), 'tc-data-tables');
        } elseif ($type === 'csv') {
            $fetched = TC_CSV_Source::get_cached($url, TC_CSV_Source::DEFAULT_TTL);
            $sc      = $this->render_external_source_short_circuit($fetched, 'csv',
                __('CSV source error:', 'tc-data-tables'),
                __('No rows in the CSV source.', 'tc-data-tables'));
            if ($sc !== null) { return $sc; }

            $rows = (array) $fetched;
            foreach ($rows as $row) {
                foreach ((array) $row as $key => $_val) {
                    $key = (string) $key;
                    if (!in_array($key, $column_keys, true)) {
                        $column_keys[] = $key;
                    }
                }
            }
            $meta_format = _n('%d row from CSV source.', '%d rows from CSV source.', count($rows), 'tc-data-tables');
        } else { // json
            $root    = (isset($atts['root']) && $atts['root'] !== '') ? (string) $atts['root'] : null;
            $fetched = TC_JSON_Source_Service::fetch_from_url($url, array(), 15, $root);
            $sc      = $this->render_external_source_short_circuit($fetched, 'json',
                __('JSON source error:', 'tc-data-tables'),
                __('No rows in the JSON source.', 'tc-data-tables'));
            if ($sc !== null) { return $sc; }

            foreach ((array) $fetched as $row) {
                $rows[] = TC_JSON_Source_Service::flatten_row(is_array($row) ? $row : array());
            }
            foreach (TC_JSON_Source_Service::infer_columns($rows) as $col) {
                if (isset($col['id'])) {
                    $column_keys[] = (string) $col['id'];
                }
            }
            $meta_format = _n('%d row from JSON source.', '%d rows from JSON source.', count($rows), 'tc-data-tables');
        }

        // Legacy include / exclude column curation.
        $column_keys = TC_Inline_Shortcode_Compat::resolve_columns($column_keys, $atts);

        return $this->render_external_source_table_html(
            $rows,
            $column_keys,
            $type,
            '',
            0,
            $meta_format,
            $this->inline_render_opts($atts)
        );
    }

    /**
     * #2143 — Build the render opts for an inline source: the view toggles
     * (#2142) plus the auto-refresh suite and the sanitized source atts the
     * auto-refresh poller re-renders from.
     */
    private function inline_render_opts(array $atts): array
    {
        $opts            = TC_Inline_Shortcode_Compat::build_view_opts($atts);
        $opts['refresh'] = TC_Inline_Shortcode_Compat::build_refresh_opts($atts);

        // Whitelist the keys needed to reconstruct the inline shortcode on
        // refresh. Secrets (e.g. an airtable:// token) ride inside `source`
        // exactly as the page author wrote it — no extra exposure.
        $keep = array('source', 'root', 'include', 'exclude', 'per_page', 'search', 'export', 'filters');
        $payload = array();
        foreach ($keep as $k) {
            if (isset($atts[$k]) && $atts[$k] !== '') {
                $payload[$k] = (string) $atts[$k];
            }
        }
        $opts['refresh_atts'] = $payload;

        return $opts;
    }

    /**
     * #2148 — Render a legacy 3.5.x inline Airtable source
     * ([tablecrafter source="airtable://base/table?token=..."]). Airtable read
     * is a free source in v8 (registry pro => false), so this restores the
     * upgrade path rather than gating it. Token precedence: URL query token,
     * then v8 stored credentials, then the saved 3.5.x option.
     */
    private function render_inline_airtable(string $url, array $atts): string
    {
        // Airtable is a Pro integration. Free plans get an upsell instead of the
        // table (grandfathered ever-premium sites keep read access).
        if (function_exists('gt_is_premium') && !gt_is_premium()
            && !(function_exists('gt_is_grandfathered') && gt_is_grandfathered())) {
            if (function_exists('current_user_can') && current_user_can('manage_options')) {
                return '<div class="gt-upgrade-notice" style="background:#fff3cd;border:1px solid #ffeaa7;color:#856404;padding:15px;border-radius:5px;margin:20px 0;">'
                    . '<strong>⚠️ ' . esc_html__('Pro data source', 'tc-data-tables') . ':</strong> '
                    . esc_html__('The Airtable data source is a Pro feature.', 'tc-data-tables')
                    . ' <a href="' . esc_url(function_exists('wgt_fs') && wgt_fs() ? wgt_fs()->get_upgrade_url() : '#') . '">' . esc_html__('Upgrade to Pro', 'tc-data-tables') . '</a></div>';
            }
            return '<p>' . esc_html__('This table is unavailable on the current plan.', 'tc-data-tables') . '</p>';
        }

        $parsed = TC_Inline_Shortcode_Compat::parse_airtable_url($url);
        $token  = $this->resolve_airtable_inline_token($parsed['token']);

        if ($parsed['base_id'] === '' || $parsed['table'] === '' || $token === '') {
            return '<p class="gt-airtable-source-error">'
                . esc_html__('Airtable source error: missing base, table, or API token.', 'tc-data-tables')
                . '</p>';
        }

        $opts   = array('page_size' => 100);
        $result = TC_Airtable_Sync_Engine::fetch_records($parsed['base_id'], $parsed['table'], $token, $opts);

        if (empty($result['ok'])) {
            return '<p class="gt-airtable-source-error">'
                . esc_html__('Airtable source error:', 'tc-data-tables') . ' '
                . esc_html((string) ($result['error'] ?? 'request failed'))
                . '</p>';
        }

        $rows = array();
        foreach ((array) ($result['records'] ?? array()) as $record) {
            $fields = (isset($record['fields']) && is_array($record['fields'])) ? $record['fields'] : array();
            // Flatten any array/object field values so the cell renderer gets scalars.
            foreach ($fields as $k => $v) {
                if (is_array($v)) {
                    $fields[$k] = implode(', ', array_map('strval', $v));
                }
            }
            $rows[] = $fields;
        }

        if (empty($rows)) {
            return '<p class="gt-airtable-source-empty">'
                . esc_html__('No rows in the Airtable source.', 'tc-data-tables') . '</p>';
        }

        $column_keys = array();
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $column_keys, true)) {
                    $column_keys[] = (string) $key;
                }
            }
        }
        $column_keys = TC_Inline_Shortcode_Compat::resolve_columns($column_keys, $atts);

        return $this->render_external_source_table_html(
            $rows,
            $column_keys,
            'airtable',
            '',
            0,
            _n('%d row from Airtable source.', '%d rows from Airtable source.', count($rows), 'tc-data-tables'),
            $this->inline_render_opts($atts)
        );
    }

    /**
     * Resolve the Airtable token for an inline source. Precedence: the token in
     * the airtable:// URL, then v8's stored credential token, then the saved
     * 3.5.x `tablecrafter_airtable_token` option (best-effort decrypt).
     */
    private function resolve_airtable_inline_token(string $url_token): string
    {
        if ($url_token !== '') {
            return $url_token;
        }

        if (class_exists('TC_Airtable_Credential_Service')) {
            $cred = TC_Airtable_Credential_Service::load();
            if (is_array($cred) && !empty($cred['token'])) {
                return (string) $cred['token'];
            }
        }

        if (function_exists('get_option')) {
            $saved = (string) get_option('tablecrafter_airtable_token', '');
            if ($saved !== '') {
                // 3.5.x stored this encrypted with its own scheme; try the v8
                // secret helper, else fall back to the raw value.
                if (function_exists('gt_decrypt_secret')) {
                    $dec = gt_decrypt_secret($saved, 'gt_airtable_credentials');
                    if ($dec !== '') {
                        return $dec;
                    }
                }
                return $saved;
            }
        }

        return '';
    }

    private function render_json_source_table(int $table_id, array $settings): string
    {
        $rows = TC_JSON_Source_Service::get_cached_rows_for_table($table_id);

        $error_or_empty = $this->render_external_source_short_circuit($rows, 'json',
            __('JSON source error:', 'tc-data-tables'),
            __('No rows in the JSON source.', 'tc-data-tables')
        );
        if ($error_or_empty !== null) {
            return $error_or_empty;
        }

        // JSON path uses flatten_row + infer_columns so nested objects become
        // "user.email" dot-key columns. Airtable + Notion don't need this —
        // their cached helpers already pre-flatten.
        $flat = array();
        foreach ($rows as $row) {
            $flat[] = TC_JSON_Source_Service::flatten_row(is_array($row) ? $row : array());
        }
        $columns = TC_JSON_Source_Service::infer_columns($flat);
        $column_keys = array();
        foreach ($columns as $col) {
            if (isset($col['id'])) {
                $column_keys[] = (string) $col['id'];
            // @codeCoverageIgnoreStart
            } elseif (isset($col['name'])) {
                $column_keys[] = (string) $col['name'];
            // @codeCoverageIgnoreEnd
            }
        }

        // If the builder recorded a column selection, honour it as a filter + order.
        if (!empty($settings['selected_fields']) && is_array($settings['selected_fields'])) {
            $allowed = array_map('strval', $settings['selected_fields']);
            $ordered = array();
            foreach ($allowed as $key) {
                if (in_array($key, $column_keys, true)) {
                    $ordered[] = $key;
                }
            }
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }

        return $this->render_external_source_table_html(
            $flat,
            $column_keys,
            'json',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d row from JSON source.', '%d rows from JSON source.', count($rows), 'tc-data-tables')
        );
    }

    /**
     * #2002 — Render a Google Sheets-source table on the frontend.
     *
     * Convergence epic #2006, Phase 2. Mirrors render_json_source_table():
     * TC_Google_Sheets::get_cached() returns the published-CSV body (or a
     * WP_Error), parse_csv_to_rows() splits it into headers + positional rows,
     * and we map those into header-keyed associative rows for the shared
     * external-source HTML renderer.
     */
    private function render_google_sheets_source_table(int $table_id, array $settings): string
    {
        $url = isset($settings['google_sheets_url']) ? trim((string) $settings['google_sheets_url']) : '';
        if ($url === '') {
            return '<p class="gt-google_sheets-source-empty">'
                . esc_html__('No Google Sheet URL is configured for this table.', 'tc-data-tables')
                . '</p>';
        }

        $sheets = TC_Google_Sheets::get_instance();
        $ttl    = isset($settings['google_sheets_refresh_minutes'])
            ? max(60, (int) $settings['google_sheets_refresh_minutes'] * 60)
            : 3600;
        $csv = $sheets->get_cached($url, $ttl);

        $error_or_empty = $this->render_external_source_short_circuit($csv, 'google_sheets',
            __('Google Sheets error:', 'tc-data-tables'),
            __('No rows in the Google Sheet.', 'tc-data-tables')
        );
        if ($error_or_empty !== null) {
            return $error_or_empty;
        }

        $parsed   = $sheets->parse_csv_to_rows((string) $csv);
        $headers  = isset($parsed['headers']) ? array_map('strval', (array) $parsed['headers']) : array();
        $raw_rows = isset($parsed['rows']) ? (array) $parsed['rows'] : array();

        if (empty($headers) || empty($raw_rows)) {
            return '<p class="gt-google_sheets-source-empty">'
                . esc_html__('No rows in the Google Sheet.', 'tc-data-tables')
                . '</p>';
        }

        // Header-keyed associative rows for the shared renderer.
        $column_keys = $headers;
        $rows = array();
        foreach ($raw_rows as $cells) {
            $cells = (array) $cells;
            $assoc = array();
            foreach ($column_keys as $i => $key) {
                $assoc[$key] = isset($cells[$i]) ? $cells[$i] : '';
            }
            $rows[] = $assoc;
        }

        // Honour a builder column selection as a filter + order.
        if (!empty($settings['selected_fields']) && is_array($settings['selected_fields'])) {
            $allowed = array_map('strval', $settings['selected_fields']);
            $ordered = array();
            foreach ($allowed as $key) {
                if (in_array($key, $column_keys, true)) {
                    $ordered[] = $key;
                }
            }
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }

        return $this->render_external_source_table_html(
            $rows,
            $column_keys,
            'google_sheets',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d row from Google Sheets.', '%d rows from Google Sheets.', count($rows), 'tc-data-tables')
        );
    }

    /**
     * #2003 — Render an external-database (MySQL / SQL Server) source table.
     *
     * Convergence epic #2006, Phase 2. Runs the table's stored read-only SELECT
     * against the selected connection via TC_External_DB::execute_query(), which
     * enforces the gravity_tables_view_external capability + readonly guard and
     * returns FETCH_ASSOC rows (or a WP_Error). Column keys = union of row keys.
     */
    private function render_external_db_source_table(int $table_id, array $settings): string
    {
        // #2083 — engine ships only in premium (@fs_premium_only). Guard so a
        // grandfathered site on the stripped free build degrades gracefully.
        if (!class_exists('TC_External_DB')) {
            return '<p>' . esc_html__('This data source is available in the Pro version.', 'tc-data-tables') . '</p>';
        }
        $query      = isset($settings['external_db_query']) ? trim((string) $settings['external_db_query']) : '';
        $conn_index = isset($settings['external_db_connection']) && $settings['external_db_connection'] !== ''
            ? (int) $settings['external_db_connection']
            : -1;

        if ($query === '' || $conn_index < 0) {
            return '<p class="gt-external_db-source-empty">'
                . esc_html__('No external database connection or query is configured for this table.', 'tc-data-tables')
                . '</p>';
        }

        // #2012 — External DB has no caching of its own; wrap the live query in
        // stale-while-revalidate so repeat views don't re-hit the remote DB and
        // a slow DB never blocks the page (stale served, refresh after response).
        $fresh_ttl = isset($settings['external_db_refresh_minutes'])
            ? max(60, (int) $settings['external_db_refresh_minutes'] * 60)
            : 300;
        $rows = TC_SWR_Cache::remember(
            'gt_swr_extdb_' . $table_id,
            $fresh_ttl,
            static function () use ($conn_index, $query) {
                return TC_External_DB::get_instance()->execute_query($conn_index, $query);
            },
            $fresh_ttl // stale grace == fresh window
        );

        $error_or_empty = $this->render_external_source_short_circuit($rows, 'external_db',
            __('External database error:', 'tc-data-tables'),
            __('No rows returned by the query.', 'tc-data-tables')
        );
        if ($error_or_empty !== null) {
            return $error_or_empty;
        }

        $column_keys = array();
        foreach ($rows as $row) {
            foreach ((array) $row as $key => $_val) {
                $key = (string) $key;
                if (!in_array($key, $column_keys, true)) {
                    $column_keys[] = $key;
                }
            }
        }

        if (!empty($settings['selected_fields']) && is_array($settings['selected_fields'])) {
            $allowed = array_map('strval', $settings['selected_fields']);
            $ordered = array();
            foreach ($allowed as $key) {
                if (in_array($key, $column_keys, true)) {
                    $ordered[] = $key;
                }
            }
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }

        return $this->render_external_source_table_html(
            $rows,
            $column_keys,
            'external_db',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d row from the external database.', '%d rows from the external database.', count($rows), 'tc-data-tables')
        );
    }

    /**
     * #2010 — Render a live CSV-URL-source table on the frontend.
     *
     * Convergence epic #2006, Phase 2. TC_CSV_Source::get_cached() returns
     * header-keyed associative rows (or a WP_Error), so column keys are the
     * union of row keys — same shape as the XML source.
     */
    private function render_csv_source_table(int $table_id, array $settings): string
    {
        $url = isset($settings['csv_url']) ? trim((string) $settings['csv_url']) : '';
        if ($url === '') {
            return '<p class="gt-csv-source-empty">'
                . esc_html__('No CSV URL is configured for this table.', 'tc-data-tables')
                . '</p>';
        }

        $ttl = isset($settings['csv_refresh_minutes'])
            ? max(60, (int) $settings['csv_refresh_minutes'] * 60)
            : TC_CSV_Source::DEFAULT_TTL;

        $rows = TC_CSV_Source::get_cached($url, $ttl);

        $error_or_empty = $this->render_external_source_short_circuit($rows, 'csv',
            __('CSV source error:', 'tc-data-tables'),
            __('No rows in the CSV source.', 'tc-data-tables')
        );
        if ($error_or_empty !== null) {
            return $error_or_empty;
        }

        $column_keys = array();
        foreach ($rows as $row) {
            foreach ((array) $row as $key => $_val) {
                $key = (string) $key;
                if (!in_array($key, $column_keys, true)) {
                    $column_keys[] = $key;
                }
            }
        }

        if (!empty($settings['selected_fields']) && is_array($settings['selected_fields'])) {
            $allowed = array_map('strval', $settings['selected_fields']);
            $ordered = array();
            foreach ($allowed as $key) {
                if (in_array($key, $column_keys, true)) {
                    $ordered[] = $key;
                }
            }
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }

        return $this->render_external_source_table_html(
            $rows,
            $column_keys,
            'csv',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d row from CSV source.', '%d rows from CSV source.', count($rows), 'tc-data-tables')
        );
    }

    /**
     * #1998 — Render an Excel (.xlsx) source table on the frontend.
     */
    private function render_xlsx_source_table(int $table_id, array $settings): string
    {
        $url = isset($settings['xlsx_url']) ? trim((string) $settings['xlsx_url']) : '';
        if ($url === '') {
            return '<p class="gt-xlsx-source-empty">'
                . esc_html__('No Excel (.xlsx) URL is configured for this table.', 'tc-data-tables')
                . '</p>';
        }

        $ttl = isset($settings['xlsx_refresh_minutes'])
            ? max(60, (int) $settings['xlsx_refresh_minutes'] * 60)
            : TC_XLSX_Source::DEFAULT_TTL;

        $rows = TC_XLSX_Source::get_cached($url, $ttl);

        $error_or_empty = $this->render_external_source_short_circuit($rows, 'xlsx',
            __('Excel source error:', 'tc-data-tables'),
            __('No rows in the Excel source.', 'tc-data-tables')
        );
        if ($error_or_empty !== null) {
            return $error_or_empty;
        }

        $column_keys = array();
        foreach ($rows as $row) {
            foreach ((array) $row as $key => $_val) {
                $key = (string) $key;
                if (!in_array($key, $column_keys, true)) {
                    $column_keys[] = $key;
                }
            }
        }

        if (!empty($settings['selected_fields']) && is_array($settings['selected_fields'])) {
            $allowed = array_map('strval', $settings['selected_fields']);
            $ordered = array();
            foreach ($allowed as $key) {
                if (in_array($key, $column_keys, true)) {
                    $ordered[] = $key;
                }
            }
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }

        return $this->render_external_source_table_html(
            $rows,
            $column_keys,
            'xlsx',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d row from Excel source.', '%d rows from Excel source.', count($rows), 'tc-data-tables')
        );
    }

    /**
     * #2004 — Render an XML-source table on the frontend.
     *
     * Convergence epic #2006, Phase 2. TC_XML_Source::get_cached() returns
     * header-keyed associative rows (or a WP_Error) directly, so no CSV-style
     * remap is needed — column keys are the union of row keys.
     */
    private function render_xml_source_table(int $table_id, array $settings): string
    {
        // #2083 — engine ships only in premium (@fs_premium_only). Guard so a
        // grandfathered site on the stripped free build degrades gracefully.
        if (!class_exists('TC_XML_Source')) {
            return '<p>' . esc_html__('This data source is available in the Pro version.', 'tc-data-tables') . '</p>';
        }
        $url = isset($settings['xml_url']) ? trim((string) $settings['xml_url']) : '';
        if ($url === '') {
            return '<p class="gt-xml-source-empty">'
                . esc_html__('No XML URL is configured for this table.', 'tc-data-tables')
                . '</p>';
        }

        $element_path = isset($settings['xml_row_path']) ? trim((string) $settings['xml_row_path']) : '';
        $ttl = isset($settings['xml_refresh_minutes'])
            ? max(60, (int) $settings['xml_refresh_minutes'] * 60)
            : 3600;

        $rows = TC_XML_Source::get_cached($url, $element_path, $ttl);

        $error_or_empty = $this->render_external_source_short_circuit($rows, 'xml',
            __('XML source error:', 'tc-data-tables'),
            __('No rows in the XML source.', 'tc-data-tables')
        );
        if ($error_or_empty !== null) {
            return $error_or_empty;
        }

        // Column keys = union of associative row keys, preserving first-seen order.
        $column_keys = array();
        foreach ($rows as $row) {
            foreach ((array) $row as $key => $_val) {
                $key = (string) $key;
                if (!in_array($key, $column_keys, true)) {
                    $column_keys[] = $key;
                }
            }
        }

        // Honour a builder column selection as a filter + order.
        if (!empty($settings['selected_fields']) && is_array($settings['selected_fields'])) {
            $allowed = array_map('strval', $settings['selected_fields']);
            $ordered = array();
            foreach ($allowed as $key) {
                if (in_array($key, $column_keys, true)) {
                    $ordered[] = $key;
                }
            }
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }

        return $this->render_external_source_table_html(
            $rows,
            $column_keys,
            'xml',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d row from XML source.', '%d rows from XML source.', count($rows), 'tc-data-tables')
        );
    }

    /**
     * #1008 v4.180.0 — Shared short-circuit for the three external data-source
     * render methods. Returns the WP_Error or empty-state HTML when the cached
     * rows aren't usable; null when the caller should proceed with rendering.
     */
    private function render_external_source_short_circuit($rows, string $source_kind, string $error_prefix, string $empty_msg): ?string {
        if (is_wp_error($rows)) {
            if (current_user_can('manage_options')) {
                return '<div class="gt-' . esc_attr($source_kind) . '-source-error">'
                    . '<strong>' . esc_html($error_prefix) . '</strong> '
                    . esc_html($rows->get_error_message())
                    . ' <code>[' . esc_html($rows->get_error_code()) . ']</code>'
                    . '</div>';
            }
            return '<p>' . esc_html__('This table is temporarily unavailable.', 'tc-data-tables') . '</p>';
        }
        if (empty($rows)) {
            return '<p class="gt-' . esc_attr($source_kind) . '-source-empty">' . esc_html($empty_msg) . '</p>';
        }
        return null;
    }

    /**
     * #2196 — Render a WooCommerce products table (id-based source). Cells are
     * trusted WooCommerce-generated HTML (product links, formatted prices,
     * add-to-cart), so they pass through the shared renderer with allow_html.
     */
    private function render_woocommerce_source_table(int $table_id, array $settings): string
    {
        if (!class_exists('TC_WooCommerce') || !TC_WooCommerce::is_woocommerce_active()) {
            return '<p class="gt-wc-source-error">' . esc_html__('WooCommerce is not active.', 'tc-data-tables') . '</p>';
        }

        $per_page = (isset($settings['per_page']) && (int) $settings['per_page'] > 0) ? (int) $settings['per_page'] : 100;
        // get_product_table_entries() returns ['entries' => [...products], 'total' => N].
        $result = TC_WooCommerce::get_product_table_entries(array('per_page' => $per_page));
        $rows   = (is_array($result) && isset($result['entries']) && is_array($result['entries'])) ? $result['entries'] : array();

        if (empty($rows)) {
            return '<p class="gt-wc-source-empty">' . esc_html__('No products found.', 'tc-data-tables') . '</p>';
        }

        // Column keys from the first row, honouring any builder column selection.
        $flat = array();
        foreach ($rows as $row) {
            $flat[] = (array) $row;
        }
        $column_keys = array_map('strval', array_keys($flat[0]));
        if (!empty($settings['selected_fields']) && is_array($settings['selected_fields'])) {
            $ordered = array();
            foreach (array_map('strval', $settings['selected_fields']) as $key) {
                if (in_array($key, $column_keys, true)) {
                    $ordered[] = $key;
                }
            }
            if (!empty($ordered)) {
                $column_keys = $ordered;
            }
        }

        return $this->render_external_source_table_html(
            $flat,
            $column_keys,
            'woocommerce',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d product.', '%d products.', count($flat), 'tc-data-tables'),
            array('allow_html' => true)
        );
    }

    /**
     * #2205 — Resolve a display header label for an external-source column.
     * Saved column_labels / field_labels always win; when none is set (or it
     * is blank) the raw data key is humanized so headers read "Product Name"
     * instead of "product_name". WooCommerce / Gravity Forms already provide
     * labels, so they never reach the humanize fallback.
     *
     * @param string               $key    Raw column/data key.
     * @param array<string,mixed>  $labels Saved label map (key => label).
     */
    public static function resolve_external_header_label(string $key, array $labels): string
    {
        if (isset($labels[$key]) && (string) $labels[$key] !== '') {
            return (string) $labels[$key];
        }
        return self::humanize_label($key);
    }

    /**
     * #2205 — Convert a raw data key into a human-readable Title Case label.
     * Handles snake_case, kebab-case, dotted json-flatten keys, and
     * camelCase/PascalCase; preserves ALL-CAPS acronyms (SKU, ID) and is
     * idempotent on already-humanized input ("Product Name").
     */
    public static function humanize_label(string $key): string
    {
        if ($key === '') {
            return '';
        }
        // Split camelCase / PascalCase boundaries.
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        // Split an acronym run followed by a TitleCase word ("HTTPStatus" -> "HTTP Status").
        $s = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', (string) $s);
        // Separators -> spaces, then collapse.
        $s = preg_replace('/[_\-.]+/', ' ', (string) $s);
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        if ($s === '') {
            return '';
        }
        $acronyms = self::header_acronyms();
        $words    = explode(' ', $s);
        foreach ($words as &$word) {
            // Preserve all-caps acronyms already spelled uppercase (SKU, ID).
            if (preg_match('/^[A-Z0-9]+$/', $word) && preg_match('/[A-Z]/', $word)) {
                continue;
            }
            $lower = strtolower($word);
            // Upper-case a known acronym that arrived lowercase (id -> ID).
            if (isset($acronyms[$lower])) {
                $word = strtoupper($word);
                continue;
            }
            $word = ucfirst($lower);
        }
        unset($word);
        return implode(' ', $words);
    }

    /**
     * #2205 — Lowercase-keyed set of known acronyms that should render
     * UPPER-CASE in a humanized header (so `order_id` -> "Order ID", not
     * "Order Id"). Extend or trim for site-specific acronyms via the
     * `tablecrafter_header_acronyms` filter (pass a flat array of tokens).
     *
     * @return array<string,bool>
     */
    private static function header_acronyms(): array
    {
        $acronyms = array(
            'id', 'url', 'uri', 'api', 'sku', 'http', 'https', 'ftp',
            'json', 'xml', 'html', 'css', 'csv', 'pdf', 'sql', 'db',
            'ip', 'gps', 'isbn', 'uuid', 'ssn', 'ein', 'vat', 'ai',
            'usd', 'eur', 'gbp', 'cpu', 'ram', 'faq', 'seo', 'utc',
        );
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('tablecrafter_header_acronyms', $acronyms);
            if (is_array($filtered)) {
                $acronyms = $filtered;
            }
        }
        $set = array();
        foreach ($acronyms as $a) {
            $set[strtolower((string) $a)] = true;
        }
        return $set;
    }

    /**
     * #1008 v4.180.0 — Shared HTML generation for the external data-source
     * render methods. Takes pre-flattened rows + column keys + a source-kind
     * slug ('json' / 'airtable' / 'notion') and emits the standard wrapper /
     * table / meta footer markup.
     *
     * Replaces ~150 lines of near-identical code that shipped in v4.170.0 /
     * v4.174.0 / v4.178.0.
     */
    private function render_external_source_table_html(
        array $rows,
        array $column_keys,
        string $source_kind,
        string $title,
        int $table_id,
        string $meta_n_format,
        array $opts = array()
    ): string {
        $kind = esc_attr($source_kind);
        $wrapper_id = 'gt-' . $kind . '-table-' . $table_id;

        // #1960 — map column keys to the builder-configured labels so headers
        // read e.g. "First Name" instead of the raw "first_name" data key, and
        // read per_page for client-side pagination.
        $labels   = array();
        $per_page = 25;
        $td = TC_Admin::get_instance()->get_table($table_id);
        if ($td && isset($td->settings)) {
            $s = json_decode($td->settings, true);
            if (is_array($s)) {
                if (isset($s['column_labels']) && is_array($s['column_labels'])) {
                    $labels = $s['column_labels'];
                } elseif (isset($s['field_labels']) && is_array($s['field_labels'])) {
                    $labels = $s['field_labels'];
                }
                if (isset($s['per_page']) && (int) $s['per_page'] > 0) {
                    $per_page = (int) $s['per_page'];
                }
            }
        }

        // #2142 — shortcode-level overrides (inline sources): per_page + the
        // search/export/filters toggles, restoring 3.5.x inline behavior.
        if (!empty($opts['per_page'])) {
            $per_page = (int) $opts['per_page'];
        }
        $show_search  = array_key_exists('search', $opts) ? (bool) $opts['search'] : true;
        $show_export  = !empty($opts['export']);
        $show_filters = !empty($opts['filters']);

        // #2143 — legacy inline auto-refresh opts (empty for id-based sources).
        $refresh      = isset($opts['refresh']) && is_array($opts['refresh']) ? $opts['refresh'] : array();
        $auto_refresh = !empty($refresh['auto']);

        // #1960 — progressive enhancement: click-to-sort, pagination, search.
        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script(
                'gt-external-interactive',
                TC_PLUGIN_URL . 'assets/js/gt-external-interactive.js',
                array(),
                TC_VERSION,
                true
            );
            // #2143 — the auto-refresh poller re-renders via admin-ajax; hand the
            // script the endpoint + a nonce. Localized only when needed.
            if ($auto_refresh && function_exists('wp_localize_script')) {
                wp_localize_script('gt-external-interactive', 'gtExtRefresh', array(
                    'ajaxurl' => function_exists('admin_url') ? admin_url('admin-ajax.php') : '/wp-admin/admin-ajax.php',
                    'nonce'   => function_exists('wp_create_nonce') ? wp_create_nonce('gt_inline_refresh') : '',
                ));
            }
        }

        // #2143 — emit refresh data-attributes + the source atts needed to
        // re-render. Only present when auto-refresh is enabled.
        $refresh_attr = '';
        if ($auto_refresh) {
            $refresh_atts_json = wp_json_encode(isset($opts['refresh_atts']) && is_array($opts['refresh_atts']) ? $opts['refresh_atts'] : array());
            $refresh_attr =
                  ' data-auto-refresh="true"'
                . ' data-refresh-interval="' . esc_attr((string) ($refresh['interval'] ?? 300000)) . '"'
                . ' data-refresh-indicator="' . (!empty($refresh['indicator']) ? 'true' : 'false') . '"'
                . ' data-refresh-countdown="' . (!empty($refresh['countdown']) ? 'true' : 'false') . '"'
                . ' data-refresh-last-updated="' . (!empty($refresh['last_updated']) ? 'true' : 'false') . '"'
                . " data-refresh-atts='" . esc_attr((string) $refresh_atts_json) . "'";
        }

        // #2147 — keep the 3.5.x wrapper class (`.tablecrafter-container`) so
        // existing theme CSS that targets inline tables still applies.
        $html  = '<div class="gt-' . $kind . '-source-wrapper tablecrafter-container" id="' . esc_attr($wrapper_id) . '"' . $refresh_attr . '>';
        if ($title !== '') {
            $html .= '<h3 class="gt-' . $kind . '-source-title">' . esc_html($title) . '</h3>';
        }
        $html .= '<table class="gt-table gt-' . $kind . '-source-table widefat"'
            . ' data-per-page="' . esc_attr((string) $per_page) . '"'
            . ' data-search="' . ($show_search ? 'true' : 'false') . '"'
            . ' data-export="' . ($show_export ? 'true' : 'false') . '"'
            . ' data-filters="' . ($show_filters ? 'true' : 'false') . '">';

        $html .= '<thead><tr>';
        foreach ($column_keys as $key) {
            $label = self::resolve_external_header_label((string) $key, $labels);
            $html .= '<th>' . esc_html($label) . '</th>';
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($column_keys as $key) {
                $val = $row[$key] ?? '';
                if (is_array($val) || is_object($val)) {
                    $val = wp_json_encode($val);
                }
                // #2196 — allow_html: trusted source-generated HTML cells
                // (e.g. WooCommerce product links / prices / add-to-cart) pass
                // through wp_kses_post instead of being auto-formatted/escaped.
                if (!empty($opts['allow_html'])) {
                    $html .= '<td>' . wp_kses_post((string) $val) . '</td>';
                // #2132 — auto-format dates / numbers / URLs into beautiful cells
                // by default (the engine escapes text internally). Falls back to
                // plain esc_html if the engine isn't loaded.
                } elseif (class_exists('TC_Auto_Format')) {
                    $html .= '<td>' . TC_Auto_Format::format_cell((string) $val, 'auto') . '</td>';
                } else {
                    $html .= '<td>' . esc_html((string) $val) . '</td>';
                }
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '<p class="gt-' . $kind . '-source-meta">'
            . esc_html(sprintf($meta_n_format, count($rows)))
            . '</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * #996 v4.174.0 — Render an Airtable-source table on the frontend.
     *
     * Phase D of #517. Closes the Airtable-as-data-source feature. Mirrors
     * render_json_source_table() architecturally — different cached-helper
     * call, same render shape so customer-visible output is consistent
     * across the two data sources.
     *
     * Out of scope (deferred, same as JSON render): filters, sorting,
     * pagination, frontend editing, exports.
     *
     * @param int   $table_id wp_gravity_tables row id.
     * @param array $settings Already-decoded settings array.
     * @return string Rendered HTML.
     */
    private function render_airtable_source_table(int $table_id, array $settings): string
    {
        $rows = TC_Airtable_Sync_Engine::get_cached_rows_for_table($table_id);

        $error_or_empty = $this->render_external_source_short_circuit($rows, 'airtable',
            __('Airtable source error:', 'tc-data-tables'),
            __('No rows in the Airtable source.', 'tc-data-tables')
        );
        if ($error_or_empty !== null) {
            return $error_or_empty;
        }

        // Cached helper already pre-flattens; union-of-columns to handle sparse rows.
        // @codeCoverageIgnoreStart
        $column_keys = $this->union_of_row_keys($rows);
        // @codeCoverageIgnoreEnd

        // @codeCoverageIgnoreStart
        return $this->render_external_source_table_html(
            $rows,
            $column_keys,
            'airtable',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d row from Airtable source.', '%d rows from Airtable source.', count($rows), 'tc-data-tables')
        );
        // @codeCoverageIgnoreEnd
    }

    /**
     * #1008 v4.180.0 — Compute the union of column keys across a row array.
     * Used by the Airtable + Notion renders so a sparse record doesn't drop
     * columns that exist in others.
     */
    private function union_of_row_keys(array $rows): array {
        $keys = array();
        foreach ($rows as $row) {
            foreach ((array) $row as $k => $_v) {
                if (!in_array($k, $keys, true)) {
                    $keys[] = $k;
                }
            }
        }
        return $keys;
    }

    /**
     * #1004 v4.178.0 — Render a Notion-source table on the frontend.
     *
     * Phase 4 of #592. CLOSES the Notion-as-data-source feature. Mirrors
     * render_airtable_source_table() architecturally — different cached
     * helper call, identical render shape so output is consistent across
     * all three external data sources (JSON, Airtable, Notion).
     *
     * Out of scope (deferred, same as JSON + Airtable): filters, sorting,
     * pagination, frontend editing, exports.
     *
     * @param int   $table_id wp_gravity_tables row id.
     * @param array $settings Already-decoded settings array.
     * @return string Rendered HTML.
     */
    private function render_notion_source_table(int $table_id, array $settings): string
    {
        // #2083 — engine ships only in premium (@fs_premium_only). Guard so a
        // grandfathered site on the stripped free build degrades gracefully.
        if (!class_exists('TC_Notion_Sync_Engine')) {
            return '<p>' . esc_html__('This data source is available in the Pro version.', 'tc-data-tables') . '</p>';
        }
        $rows = TC_Notion_Sync_Engine::get_cached_rows_for_table($table_id);

        $error_or_empty = $this->render_external_source_short_circuit($rows, 'notion',
            __('Notion source error:', 'tc-data-tables'),
            __('No rows in the Notion source.', 'tc-data-tables')
        );
        if ($error_or_empty !== null) {
            return $error_or_empty;
        }

        // @codeCoverageIgnoreStart
        return $this->render_external_source_table_html(
            $rows,
            $this->union_of_row_keys($rows),
            'notion',
            isset($settings['table_title']) ? (string) $settings['table_title'] : '',
            $table_id,
            _n('%d row from Notion source.', '%d rows from Notion source.', count($rows), 'tc-data-tables')
        );
        // @codeCoverageIgnoreEnd
    }

    /**
     * #607 slice 3 — Frontend password gate.
     *
     * Returns null when the visitor is allowed through (no password set
     * OR cookie verifies OR correct POST just succeeded). Returns the
     * password-form HTML when locked.
     *
     * Side effects (only on the unlocking POST path):
     *  - Sets the signed-token cookie via setcookie().
     *
     * Side effects (only on the forget-password GET path):
     *  - Clears the cookie.
     *
     * Defense in depth: when this method returns HTML, the caller MUST
     * short-circuit immediately. The table data must never enter the
     * output buffer.
     *
     * @param int    $table_id     The table id (for cookie naming).
     * @param string $stored_hash  The bcrypt hash from settings; '' = no password.
     * @return string|null         null = unlocked; string = locked, return this HTML.
     */
    private function apply_password_gate(int $table_id, string $stored_hash)
    {
        if ($stored_hash === '' || $table_id <= 0) {
            return null; // No password set on this table.
        }
        if (!class_exists('TC_Table_Password_Service')) {
            // @codeCoverageIgnoreStart
            return null; // Service missing — fail-open rather than break the page.
            // @codeCoverageIgnoreEnd
        }

        $cookie_name = TC_Table_Password_Service::cookie_name($table_id);
        $secret      = function_exists('wp_salt') ? wp_salt('auth') : 'gt-fallback-salt';
        $ttl         = TC_Table_Password_Service::default_ttl_seconds();
        $now         = time();

        // Forget-password path: GET ?gt_pw_forget=<table_id>.
        if (isset($_GET['gt_pw_forget']) && (int) $_GET['gt_pw_forget'] === $table_id) {
            if ($cookie_name !== '') {
                setcookie($cookie_name, '', $now - 3600, '/');
            }
            return $this->render_password_form($table_id, __('You have been signed out of this table.', 'tc-data-tables'));
        }

        // POST path: visitor submitted the password form.
        $posted_id    = isset($_POST['gt_pw_table_id']) ? (int) $_POST['gt_pw_table_id'] : 0;
        $posted_pass  = isset($_POST['gt_password']) ? (string) $_POST['gt_password'] : '';
        $nonce_field  = 'gt_pw_nonce_' . $table_id;
        $nonce_action = 'gt_pw_unlock_' . $table_id;
        if ($posted_id === $table_id && $posted_pass !== '') {
            $nonce_ok = function_exists('wp_verify_nonce')
                && isset($_POST[$nonce_field])
                && wp_verify_nonce($_POST[$nonce_field], $nonce_action);
            if (!$nonce_ok) {
                return $this->render_password_form($table_id, __('Session expired. Please try again.', 'tc-data-tables'));
            }
            if (TC_Table_Password_Service::verify($posted_pass, $stored_hash)) {
                $token = TC_Table_Password_Service::generate_unlock_token($table_id, $stored_hash, $now, $secret);
                if ($cookie_name !== '') {
                    setcookie($cookie_name, $token, $now + $ttl, '/');
                    // Echo into $_COOKIE so this same request renders unlocked.
                    $_COOKIE[$cookie_name] = $token;
                }
                // Fall through to the cookie check below — should pass now.
            } else {
                return $this->render_password_form($table_id, __('Incorrect password. Please try again.', 'tc-data-tables'));
            }
        }

        // Cookie path: visitor has a previously-issued unlock token.
        $cookie_value = '';
        if ($cookie_name !== '' && isset($_COOKIE[$cookie_name])) {
            $cookie_value = (string) $_COOKIE[$cookie_name];
        }
        if (TC_Table_Password_Service::is_unlocked($cookie_value, $table_id, $stored_hash, $now, $ttl, $secret)) {
            return null; // Unlocked — continue normal render.
        }

        // No valid cookie + no successful POST → render the password form.
        return $this->render_password_form($table_id, '');
    }

    /**
     * Render the visitor-facing password form for #607.
     *
     * @param int    $table_id      Used for the cookie naming + nonce field.
     * @param string $error_message Empty unless a previous attempt failed.
     */
    private function render_password_form(int $table_id, string $error_message): string
    {
        $nonce_field  = 'gt_pw_nonce_' . $table_id;
        $nonce_action = 'gt_pw_unlock_' . $table_id;
        $nonce        = function_exists('wp_create_nonce') ? wp_create_nonce($nonce_action) : '';
        $error_html   = $error_message !== ''
            ? '<p class="gt-password-error" role="alert" style="color:#b00;margin-bottom:8px;">' . esc_html($error_message) . '</p>'
            : '';

        // Self-posting form. The form posts back to the same page; the
        // shortcode picks up the POST in apply_password_gate() above.
        $action = esc_url(remove_query_arg('gt_pw_forget'));
        $tid    = (int) $table_id;
        $field  = esc_attr($nonce_field);
        $nonce  = esc_attr($nonce);

        return '<div class="gt-password-form-wrap">'
            . '<form class="gt-password-form" id="gt-password-form-' . $tid . '" method="post" action="' . $action . '">'
            . '<h3>' . esc_html__('This table is password-protected', 'tc-data-tables') . '</h3>'
            . $error_html
            . '<label for="gt-password-input-' . $tid . '" style="display:block;margin-bottom:4px;">'
            .   esc_html__('Password', 'tc-data-tables')
            . '</label>'
            . '<input type="password" name="gt_password" id="gt-password-input-' . $tid . '" autocomplete="current-password" required style="width:100%;max-width:280px;">'
            . '<input type="hidden" name="gt_pw_table_id" value="' . $tid . '">'
            . '<input type="hidden" name="' . $field . '" value="' . $nonce . '">'
            . '<p style="margin-top:8px;">'
            .   '<button type="submit" class="button button-primary">'
            .     esc_html__('Unlock', 'tc-data-tables')
            .   '</button>'
            . '</p>'
            . '</form>'
            . '</div>';
    }

    private function get_form_fields(int $form_id): array
    {
        if (!class_exists('GFAPI')) {
            return array();
        }

        $form = GFAPI::get_form($form_id);
        if (!$form || is_wp_error($form)) {
            return array();
        }

        return $form['fields'];
    }

    /**
     * Get system field configuration with proper label handling
     *
     * @param string $field_id System field ID
     * @param array $column_labels Custom column labels
     * @param array $filterable_fields Filterable fields array
     * @param array $filter_configurations Filter configurations
     * @param array $conditional_formatting Conditional formatting rules
     * @return array|null Field configuration or null if not a system field
     */
    private function get_system_field_config(string $field_id, array $column_labels, array $filterable_fields, array $filter_configurations, array $conditional_formatting): ?array
    {
        $system_fields = array(
            'entry_id' => array(
                'default_label' => __('Entry ID', 'tc-data-tables'),
                'type' => 'number',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => true
            ),
            'date_created' => array(
                'default_label' => __('Date Created', 'tc-data-tables'),
                'type' => 'date',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => false
            ),
            'date_updated' => array(
                'default_label' => __('Date Updated', 'tc-data-tables'),
                'type' => 'date',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => false
            ),
            'created_by' => array(
                'default_label' => __('User', 'tc-data-tables'),
                'type' => 'user',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => true,
                'is_lookup' => true,
                'lookup_type' => 'user'
            ),
            'ip' => array(
                'default_label' => __('IP Address', 'tc-data-tables'),
                'type' => 'text',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => true
            ),
            'source_url' => array(
                'default_label' => __('Source URL', 'tc-data-tables'),
                'type' => 'text',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => true
            ),
            'user_id' => array(
                'default_label' => __('User ID', 'tc-data-tables'),
                'type' => 'number',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => true
            ),
            'is_starred' => array(
                'default_label' => __('Starred', 'tc-data-tables'),
                'type' => 'boolean',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => true
            ),
            'is_read' => array(
                'default_label' => __('Read', 'tc-data-tables'),
                'type' => 'boolean',
                'editable' => false,
                'sortable' => true,
                'default_filterable' => true
            )
        );

        if (!isset($system_fields[$field_id])) {
            return null;
        }

        $field_def = $system_fields[$field_id];

        // Determine label: custom > default
        $label = isset($column_labels[$field_id]) ? $column_labels[$field_id] : $field_def['default_label'];

        // Determine if filterable: explicit config > default
        $is_filterable = $field_def['default_filterable'];
        if (!empty($filterable_fields)) {
            $is_filterable = in_array($field_id, $filterable_fields);
        }
        if (isset($filter_configurations[$field_id]) && !empty($filter_configurations[$field_id])) {
            $is_filterable = true;
        }

        $config_array = array(
            'label' => $label,
            'type' => $field_def['type'],
            'editable' => $field_def['editable'],
            'sortable' => $field_def['sortable'],
            'filterable' => $is_filterable,
            'conditional_formatting' => isset($conditional_formatting[$field_id]) ? $conditional_formatting[$field_id] : array(),
            'filter_config' => isset($filter_configurations[$field_id]) ? $filter_configurations[$field_id] : array()
        );

        // Add special properties for specific fields
        if (isset($field_def['is_lookup'])) {
            $config_array['is_lookup'] = $field_def['is_lookup'];
        }
        if (isset($field_def['lookup_type'])) {
            $config_array['lookup_type'] = $field_def['lookup_type'];
        }

        return $config_array;
    }

    private function build_column_config(array $form_fields, array $atts): array
    {
        $config = array();

        // Get columns from configuration
        // Check both 'selected_fields' (saved tables) and 'columns' (legacy/alternative format)
        $columns = array();
        if (isset($atts['selected_fields'])) {
            $columns = $atts['selected_fields'];
        } elseif (isset($atts['columns'])) {
            $columns = $atts['columns'];
        }

        // Get column labels - check both formats
        $column_labels = array();
        if (isset($atts['field_labels'])) {
            $column_labels = $atts['field_labels'];
        } elseif (isset($atts['column_labels'])) {
            $column_labels = $atts['column_labels'];
        }
        $editable_fields = isset($atts['editable_fields']) ? $atts['editable_fields'] : array();
        $sortable_fields = isset($atts['sortable_fields']) ? $atts['sortable_fields'] : array();
        $filterable_fields = isset($atts['filterable_fields']) ? $atts['filterable_fields'] : array();
        $conditional_formatting = isset($atts['conditional_formatting']) ? $atts['conditional_formatting'] : array();
        $filter_configurations = isset($atts['filter_configurations']) ? $atts['filter_configurations'] : array();
        $lookup_configurations = isset($atts['lookup_fields']) ? $atts['lookup_fields'] : array();
        $field_configurations = isset($atts['field_configurations']) ? $atts['field_configurations'] : array();

        // Debug: Log incoming field configurations
        if (!empty($field_configurations)) {
            // error_log("GT Shortcode: Received field configurations: " . print_r($field_configurations, true));
        } else {
            // error_log("GT Shortcode: No field configurations received in shortcode attributes");
        }

        // Debug: Log incoming filter configurations
        if (!empty($filter_configurations)) {
            // error_log("GT Shortcode: Received filter configurations: " . print_r($filter_configurations, true));
        } else {
            // error_log("GT Shortcode: No filter configurations received in shortcode attributes");
        }

        // Ensure arrays
        if (!is_array($columns))
            // @codeCoverageIgnoreStart
            $columns = array();
            // @codeCoverageIgnoreEnd
        if (!is_array($column_labels))
            $column_labels = array();
        if (!is_array($editable_fields))
            $editable_fields = array();
        if (!is_array($sortable_fields))
            $sortable_fields = array();
        if (!is_array($filterable_fields))
            $filterable_fields = array();
        if (!is_array($conditional_formatting))
            $conditional_formatting = array();
        if (!is_array($filter_configurations))
            $filter_configurations = array();
        if (!is_array($lookup_configurations))
            $lookup_configurations = array();

        // If no columns configured, show all form fields
        if (empty($columns)) {
            foreach ($form_fields as $field) {
                if (!in_array($field->type, array('html', 'section', 'page'))) {
                    $columns[] = $field->id;
                }
            }
        }

        // Build configuration for each column
        global $wpdb;
        foreach ($columns as $field_id) {
            $field_id = strval($field_id);
            if (empty($field_id))
                continue;

            // Check if this is a system field first
            $system_field_config = $this->get_system_field_config($field_id, $column_labels, $filterable_fields, $filter_configurations, $conditional_formatting);

            if ($system_field_config !== null) {
                // This is a system field - use centralized configuration
                $config[$field_id] = $system_field_config;
                continue;
            }

            // This is a regular form field - process normally
            $field = null;
            foreach ($form_fields as $form_field) {
                if ($form_field->id == $field_id) {
                    $field = $form_field;
                    break;
                }
            }

            // Use saved label or field label
            $label = '';
            if (isset($column_labels[$field_id])) {
                $label = $column_labels[$field_id];
            } elseif ($field) {
                $label = $field->label;
            } else {
                $label = 'Field ' . $field_id;
            }

            // SIMPLE EDITABLE LOGIC - Single source of truth
            $is_editable = true; // Default: all fields are editable

            // Check if field is auto-generated (never editable)
            $auto_generated_fields = array('entry_id', 'date_created', 'date_updated', 'is_starred', 'is_read', 'ip', 'source_url', 'user_id');
            if (in_array($field_id, $auto_generated_fields)) {
                // @codeCoverageIgnoreStart
                $is_editable = false;
                // @codeCoverageIgnoreEnd
            }
            // Check if field is explicitly disabled in field_configurations
            elseif (isset($field_configurations[$field_id]) && isset($field_configurations[$field_id]['editable'])) {
                $is_editable = $field_configurations[$field_id]['editable'];
            }
            // Check if field type should never be editable
            elseif ($field) {
                $non_editable_types = array('html', 'section', 'page', 'captcha', 'signature');
                if (in_array($field->type, $non_editable_types)) {
                    $is_editable = false;
                }
            }

            // Extract choices from field
            $choices = array();
            if ($field && isset($field->choices) && is_array($field->choices)) {
                foreach ($field->choices as $choice) {
                    if (is_array($choice)) {
                        $choices[] = array(
                            'text' => isset($choice['text']) ? $choice['text'] : $choice['value'],
                            'value' => isset($choice['value']) ? $choice['value'] : $choice['text']
                        );
                    } elseif (is_object($choice)) {
                        $choices[] = array(
                            'text' => isset($choice->text) ? $choice->text : $choice->value,
                            'value' => isset($choice->value) ? $choice->value : $choice->text
                        );
                    }
                }
            }

            // Automatically detect and configure lookups for specialized field types
            $is_automatic_lookup = false;
            $auto_lookup_config = array();

            if ($field) {
                if ($field->type === 'driver_selector') {
                    $is_automatic_lookup = true;
                    $auto_lookup_config = array(
                        'type' => 'user',
                        'user_field' => 'display_name'
                    );
                } elseif ($field->type === 'ajs_lookup') {
                    $is_automatic_lookup = true;
                    $lookup_type = isset($field->ajs_lookup_type) ? $field->ajs_lookup_type : 'clients';
                    $auto_lookup_config = array(
                        'type' => 'custom',
                        'table' => $wpdb->prefix . 'ajs_' . $lookup_type,
                        'id_column' => 'id',
                        'display_column' => 'name'
                    );
                }
            }

            $lookup_enabled = (isset($lookup_configurations[$field_id]) && !empty($lookup_configurations[$field_id])) || $is_automatic_lookup;
            
            // If automatically detected and not manually configured, add to lookup_configurations
            if ($is_automatic_lookup && (!isset($lookup_configurations[$field_id]) || empty($lookup_configurations[$field_id]))) {
                $lookup_configurations[$field_id] = $auto_lookup_config;
            }

            // Determine if field is filterable
            $is_filterable = true;
            if (isset($field_configurations[$field_id]) && isset($field_configurations[$field_id]['filterable'])) {
                $is_filterable = filter_var($field_configurations[$field_id]['filterable'], FILTER_VALIDATE_BOOLEAN);
            } elseif (!empty($filterable_fields)) {
                $is_filterable = in_array($field_id, $filterable_fields);
            }

            // render_shortcodes: pass cell value through do_shortcode() on render (#87)
            $render_shortcodes = false;
            if (isset($field_configurations[$field_id]['render_shortcodes'])) {
                $render_shortcodes = (bool) $field_configurations[$field_id]['render_shortcodes'];
            }

            $config[$field_id] = array(
                'label' => $label,
                'type' => $field ? $field->type : 'text',
                'editable' => $is_editable,
                'sortable' => !empty($sortable_fields) ? in_array($field_id, $sortable_fields) : true,
                'filterable' => $is_filterable,
                'conditional_formatting' => isset($conditional_formatting[$field_id]) ? $conditional_formatting[$field_id] : array(),
                'filter_config' => isset($filter_configurations[$field_id]) ? $filter_configurations[$field_id] : array(),
                'choices' => $choices,
                'lookup_enabled' => $lookup_enabled,
                'render_shortcodes' => $render_shortcodes,
            );

            // Debug logging for filter configurations
            if (isset($filter_configurations[$field_id]) && !empty($filter_configurations[$field_id])) {
                // error_log("GT Shortcode: Field $field_id has filter config: " . print_r($filter_configurations[$field_id], true));

                // Specifically check the multiple setting
                if (isset($filter_configurations[$field_id]['multiple'])) {
                    // error_log("GT Shortcode: Field $field_id multiple setting: " . var_export($filter_configurations[$field_id]['multiple'], true) . " (type: " . gettype($filter_configurations[$field_id]['multiple']) . ")");
                } else {
                    // error_log("GT Shortcode: Field $field_id does NOT have multiple setting in filter config");
                }
            } else {
                // error_log("GT Shortcode: Field $field_id has NO filter config");
            }

            // Debug logging for lookup configuration
            if (isset($lookup_configurations[$field_id]) && !empty($lookup_configurations[$field_id])) {
                // error_log('GT Shortcode: Field ' . $field_id . ' is configured as user-enabled lookup field');
            }
        }

        // #1598 — computed columns ride the same polymorphic config as
        // system columns. Read-only: no DB column exists to sort or
        // filter server-side; values are injected per row by
        // TC_Formula_Service::augment_rows() in the entries pipeline.
        if (!empty($atts['computed_columns']) && is_array($atts['computed_columns'])) {
            foreach ($atts['computed_columns'] as $cc_def) {
                if (!is_array($cc_def) || empty($cc_def['id']) || !is_string($cc_def['id'])) {
                    continue;
                }
                $config[$cc_def['id']] = array(
                    'label'      => isset($cc_def['label']) ? (string) $cc_def['label'] : $cc_def['id'],
                    'type'       => 'computed',
                    'editable'   => false,
                    'sortable'   => false,
                    'filterable' => false,
                );
            }
        }

        return $config;
    }

    /**
     * Check if current user has permission to access table
     *
     * @param int $form_id Form ID
     * @param array $atts Table attributes/settings
     * @return bool True if user can access table
     */
    private function checkTableAccessPermission(int $form_id, array $atts): bool
    {
        // By the time this is called, $atts already has all DB settings merged via
        // render_table()'s get_table() call — build TC_Table_Configuration directly
        // from $atts to avoid a redundant second DB query (#131)
        $table_config = new TC_Table_Configuration($atts);
        return $table_config->canCurrentUserViewTable();
    }

    /**
     * Enforce plan limits on table rendering - prevents bypassing via direct shortcode manipulation
     * 
     * @param array|string $atts Shortcode attributes
     * @return string|null Returns error message if limits violated, null if OK
     */
    private function enforce_plan_limits($atts)
    {
        if (gt_is_premium()) {
            return; // Premium users have no limits
        }

        // Parse attributes to check for premium features
        if (is_string($atts)) {
            $atts = shortcode_parse_atts($atts);
        }

        if (!is_array($atts)) {
            $atts = array();
        }

        // Check for premium-only attributes in shortcode
        $premium_features = array(
            'show_advanced_filters' => 'Advanced filters are a Pro feature',
            'show_bulk_actions' => 'Bulk actions are a Pro feature',
            'enable_frontend_editing' => 'Frontend editing is a Pro feature'
        );

        foreach ($premium_features as $feature => $message) {
            if (isset($atts[$feature]) && filter_var($atts[$feature], FILTER_VALIDATE_BOOLEAN)) {
                // Log the attempt for security monitoring
                error_log("GT Security: Free user attempted to use premium feature '{$feature}' via shortcode");

                // Return upgrade notice instead of table
                return '<div class="gt-upgrade-notice" style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 20px 0;">' .
                    '<strong>⚠️ Feature Restricted:</strong> ' . esc_html($message) . '. ' .
                    '<a href="' . esc_url(function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#') . '">Upgrade to Pro</a>' .
                    '</div>';
            }
        }

        // If table ID is provided, validate the stored configuration.
        // Use TC_Admin::get_table() so results come from the static/object cache
        // rather than firing a redundant DB query for every shortcode render (#437).
        if (isset($atts['id']) && $atts['id'] > 0) {
            $table_data = TC_Admin::get_instance()->get_table(intval($atts['id']));

            if ($table_data) {
                $stored_settings = json_decode($table_data->settings, true);
                if (is_array($stored_settings)) {
                    // Check stored column count
                    $columns = isset($stored_settings['columns']) ? $stored_settings['columns'] : array();
                    if (count($columns) > TC_FREE_MAX_COLUMNS) {
                        error_log("GT Security: Free user attempted to render table with " . count($columns) . " columns (limit: " . TC_FREE_MAX_COLUMNS . ")");

                        return '<div class="gt-upgrade-notice" style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 20px 0;">' .
                            '<strong>⚠️ Table Limit Exceeded:</strong> This table has ' . count($columns) . ' columns but free plan allows maximum ' . TC_FREE_MAX_COLUMNS . ' columns. ' .
                            '<a href="' . esc_url(function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#') . '">Upgrade to Pro</a>' .
                            '</div>';
                    }

                    // Check for premium features in stored settings
                    foreach ($premium_features as $feature => $message) {
                        if (isset($stored_settings[$feature]) && filter_var($stored_settings[$feature], FILTER_VALIDATE_BOOLEAN)) {
                            error_log("GT Security: Free user attempted to render table with premium feature '{$feature}'");

                            return '<div class="gt-upgrade-notice" style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 20px 0;">' .
                                '<strong>⚠️ Premium Feature Detected:</strong> ' . esc_html($message) . '. ' .
                                '<a href="' . esc_url(function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#') . '">Upgrade to Pro</a>' .
                                '</div>';
                        }
                    }
                }
            }
        }
    }
}