(function ($) {
    'use strict';

    // Pure helpers (gtNaturalSort, gtParseCurrency, gtCurrencySort,
    // gtCheckVersionMismatch + TC_JS_VERSION) moved to assets/js/frontend/core.js
    // (#831). They are reachable as window.GTCore.naturalSort /
    // .parseCurrency / .currencySort / .checkVersionMismatch / .VERSION.
    // TC_JS_VERSION kept here as a local alias for the few existing call
    // sites in this file (init() still references it via the namespace).
    var TC_JS_VERSION = (window.GTCore && window.GTCore.VERSION) || '8.0.28';

    // Initialize all tables on the page
    $(document).ready(function () {
        // Skip initialization if we're in admin preview context
        if ($('#table-preview-area').length > 0) {
            //console.log('GT Frontend: Skipping initialization - admin preview context detected');
            /* c8 ignore next */
            return;
        }

        //console.log('GT Frontend: Document ready');
        //console.log('GT Frontend: Looking for .gt-table-wrapper elements');
        //console.log('GT Frontend: Found', $('.gt-table-wrapper').length, 'table wrappers');
        //console.log('GT Frontend: Available table data:', window.gtTableData);

        $('.gt-table-wrapper').each(function () {
            var tableId = $(this).attr('id');
            var tableData = window.gtTableData || {};
            var config = null;

            //console.log('GT Frontend: Processing table:', tableId);

            // Check if we have configuration for this specific table ID
            if (tableData[tableId]) {
                config = tableData[tableId];
                //console.log('GT Frontend: Found specific config for', tableId);
            } else if (tableData.table_id) {
                // Fallback for single table configuration (legacy)
                config = tableData;
                //console.log('GT Frontend: Using legacy config for', tableId);
            }

            if (config) {
                //console.log('GT Frontend: Initializing table:', tableId, config);
                //console.log('GT Frontend: Frontend editing enabled:', config.enable_frontend_editing);
                try {
                    new GravityTable(tableId, config).init();
                    //console.log('GT Frontend: Successfully initialized table:', tableId);
                } catch (error) {
                    console.error('GT Frontend: Error initializing table:', tableId, error);
                }
            } else {
                //console.log('GT Frontend: No config found for table:', tableId, 'Available data:', tableData);
            }
        });

        // Allow page-builders (Elementor, Bricks, SPA routers) to trigger a clean
        // re-init after they re-mount a table wrapper into the DOM.
        $(document).on('gt_reinit', function (e, wrapperId) {
            var tableData = window.gtTableData || {};
            var config = tableData[wrapperId] || (tableData.table_id ? tableData : null);
            var $wrapper = $('#' + wrapperId);
            if ($wrapper.length && config) {
                $wrapper.data('gt-initialized', false);
                new GravityTable(wrapperId, config).init();
            } else {
                $(document).trigger('gt_reinit_error', [wrapperId, $wrapper.length ? 'missing_config' : 'missing_wrapper']);
            }
        });
    });

    // GravityTable class - make it globally available.
    //
    // #832 slice 20 fix: Extension modules (util.js, a11y-keyboard.js, etc.)
    // create a stub `window.GravityTable = function () {}` on script load
    // and attach methods to its prototype, because in the WP enqueue order
    // those modules load BEFORE frontend.js. Without preserving the stub's
    // prototype here, replacing `window.GravityTable` would drop every
    // extension-attached method (prior latent bug; surfaced by browser test
    // after 18 module extractions had silently failed in production).
    (function () {
        var existingProto = window.GravityTable && window.GravityTable.prototype;
        var GT = function GravityTable(wrapperId, config) {
            this.wrapperId = wrapperId;
            this.config = config;
            this.currentPage = 1;
            this.sortField = 'date_created';
            this.sortOrder = 'desc';
            // #565 — multi-column sort stack (max 3). sortField/sortOrder kept
            // as the primary entry for backward compat with the single-sort
            // SQL path; the stack is sent to the server as additional state and
            // surfaced visually via numbered badges on the column headers.
            // TC_Multi_Sort_Service validates and caps the stack server-side.
            this.sortStack = [{ column_id: this.sortField, direction: this.sortOrder }];
            this.searchTerm = '';
            this.filters = {};
            // #568 slice 4 — Seed initial drilldown filters from config (URL-seeded in template).
            this.drilldownFilters = (config && config.drilldown_filters && Array.isArray(config.drilldown_filters)) ? config.drilldown_filters : [];
        };
        if (existingProto) {
            // Preserve any methods extension modules already attached.
            Object.assign(GT.prototype, existingProto);
        }
        window.GravityTable = GT;
    })();

    // init moved to assets/js/frontend/init.js (#833 slice 23 / #947 — FINAL slice).

    // initializeColumnResizing moved to assets/js/frontend/column-resizing.js (#833 slice 17 / #935).

    // Persistent filter state via browser localStorage. Storage key matches
    // the column-widths / column-order pattern: tied to this.config.table_id
    // so multiple tables on the same page persist independently. Try/catch
    // around every localStorage call — defensive for quota exceeded, private
    // browsing, or disabled localStorage. Sort state is intentionally NOT
    // persisted; per-table default sort (v4.9.11) handles initial sort.
    // Filter state localStorage persistence (_filterStateStorageKey,
    // persistFilterStateLocal, restoreFilterStateLocal, clearFilterStateLocal)
    // moved to assets/js/frontend/filter-state-persistence.js (#832 slice 14).


    // #568 slice 4 — Update the browser URL with the current drilldown filter state.
    // Serializes this.drilldownFilters into ?gt_df=col:val,col:val and uses
    // history.pushState to update the address bar without a reload.
    // updateDrilldownUrlState + applyUrlFilters moved to assets/js/frontend/url-state.js (#833 slice 20 / #941).

    // bindEvents moved to assets/js/frontend/bind-events.js (#833 slice 10 / #910).

    // setupDateInputs moved to assets/js/frontend/date-inputs.js (#833 slice 13 / #927).
    // loadEntries moved to assets/js/frontend/load-entries.js (#833 slice 21 / #943).

    /**
     * WooCommerce product table: POSTs to gt_get_wc_products and renders
     * the returned product rows (thumbnail, name, sku, price, stock, rating,
     * add-to-cart) into the table body.
     */
    // loadWooCommerceProducts moved to assets/js/frontend/row-actions.js (#833 slice 7 / #904).


    // loadEntriesServerSide + renderSSPEntries moved to assets/js/frontend/ssp.js (#832 slice 2).

    // renderEntries moved to assets/js/frontend/render-entries.js (#833 slice 22 / #945).

    // updateColumnTotals moved to assets/js/frontend/totals.js (#832 slice 1).

    // bindEntryEvents moved to assets/js/frontend/bind-entry-events.js (#833 slice 11 / #912).

    /* -----------------------------------------------------------------
     * Destroy / teardown (#75)
     * Removes all wrapper-scoped and document-scoped listeners so the
     * table can be cleanly re-initialized after a page-builder re-mount.
     * --------------------------------------------------------------- */

    // destroy moved to assets/js/frontend/table-utils.js (#833 slice 19 / #939).

    // initVisibilityObserver moved to assets/js/frontend/observers.js (#840 — second
    // child of #830). Module attaches to GravityTable.prototype via Object.assign so
    // the public surface (this.initVisibilityObserver(...)) stays unchanged.

    // adjustColumns moved to assets/js/frontend/table-utils.js (#833 slice 19 / #939).

    /* -----------------------------------------------------------------
     * Undo / Redo (#39)
     *
     * #833 slice 1 — nine helpers (initUndoRedo, pushHistoryEntry,
     * updateUndoRedoButtons, undoLastEdit, redoLastEdit,
     * replayHistoryEntry, getFieldLabel, shortValue, showUndoToast)
     * moved to assets/js/frontend/edit-history.js.
     * --------------------------------------------------------------- */

    // findNextEditCell + scheduleEditOnTarget moved to
    // assets/js/frontend/edit-keyboard-nav.js (#833 slice 3).

    // editField moved to assets/js/frontend/edit-field.js (#833 slice 9 / #908).

    // editAjsToolkitLookupField moved to
    // assets/js/frontend/edit-ajs-lookup.js (#833 slice 4).

    // populateLookupDropdown moved to assets/js/frontend/lookup-dropdown.js (#833 slice 8 / #906).

    // saveField moved to assets/js/frontend/edit-save.js (#833 slice 2 / #889).
    // performBulkAction + getSelectedEntryIds moved to assets/js/frontend/selection.js (#836).



    // Preset subsystem (initPresets, findPresetById, renderPresetOptions,
    // loadPresets, savePresetPrompt, deletePreset, applyPresetById,
    // applyPresetFilters) moved to assets/js/frontend/presets.js
    // (#833 slice 5 / #900).

    // viewEntryDetails + showDetailsPopup + closeDetailsPopup moved to
    // assets/js/frontend/detail-popup.js (#837).

    // createWooCommerceProduct + viewEntryHistory + triggerInlineEditForEntry
    // moved to assets/js/frontend/row-actions.js (#833 slice 7 / #904).

    // Print subsystem (initPrint, preparePrintHeader, summarizeActiveFilters)
    // moved to assets/js/frontend/print.js (#833 slice 6 / #902).

    // -----------------------------------------------------------------------
    // Toolbar export buttons: Copy, CSV, Excel (#243)
    // -----------------------------------------------------------------------

    // initToolbarExport + exportTable moved to assets/js/frontend/export.js (#833 slice 14 / #929).
    // deleteEntry moved to assets/js/frontend/delete-entry.js (#833 slice 18 / #937).

    // updatePagination + computeTotalPages + formatPaginationInfoText + bindPaginationEvents
    // moved to assets/js/frontend/pagination.js (#835).

    // initTextFilterTypeaheads moved to assets/js/frontend/typeahead.js (#834 slice 5).
    // applyFilters + clearFilters moved to assets/js/frontend/filter-apply.js (#834 slice 4).

    // showEditIndicator + showReadonlyIndicator moved to assets/js/frontend/edit-indicators.js (#833 slice 16 / #933).

    // editEntireRow + saveAllFields moved to assets/js/frontend/row-edit.js (#833 slice 15 / #931).

    // isResponsiveMode moved to assets/js/frontend/a11y-keyboard.js (#839).

    // setupScrollIndicators moved to assets/js/frontend/scroll-indicators.js (#833 slice 12 / #925).

    // Responsive view + card-view rendering (initializeResponsiveMode,
    // setupResponsiveBreakpoints, handleResponsiveResize, initFlipResponsive,
    // initCollapsibleRows, initModalRows, shouldShowCardsOnTablet,
    // showCardView, showTableView, generateCards, generateCardHtml,
    // updateCardsAfterDataChange, bindCardEvents) moved to
    // assets/js/frontend/responsive-card-view.js (#832 slice 20).

    // showMessage moved to assets/js/frontend/table-utils.js (#833 slice 19 / #939).

    // updateEntryCount moved to assets/js/frontend/table-utils.js (#833 slice 19 / #939).

})(jQuery);
