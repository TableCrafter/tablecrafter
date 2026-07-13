/**
 * TableCrafter - frontend/init.js
 *
 * GravityTable instance lifecycle entry point. **Final** slice under
 * #833 (slice 23). One method, ~125 lines.
 *
 *   - init() - full bootstrap: validate wrapperId+config, dedup guard,
 *     destroy stray DataTables instance (#155), set sticky-header class,
 *     dispatch to bindEvents / setupDateInputs / bindEntryEvents /
 *     setupResponsiveCardView / initFlipResponsive / initCollapsibleRows /
 *     initModalRows / initializeColumnResizing / initVisibilityObserver /
 *     initPresets / initPrint / initToolbarExport / setupScrollIndicators /
 *     initColumnReorder / initUndoRedo / loadEntries.
 *
 * After this slice, frontend.js holds only the bootstrap IIFE: the
 * DOM-ready scanner that finds .gt-table-wrapper elements and the
 * GravityTable constructor that the modules attach onto.
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.init = function () {
        try {
            // Add error checking for wrapperId and config
            if (!this.wrapperId) {
                console.error('GT Frontend: No wrapper ID provided');
                return;
            }

            if (!this.config) {
                console.error('GT Frontend: No config provided for table:', this.wrapperId);
                return;
            }

            if (window.GTCore && window.GTCore.checkVersionMismatch) {
                // #960 v4.155.1 - was a bare reference to a var inside core.js's IIFE,
                // undefined here in init.js's own IIFE; threw ReferenceError and killed
                // the frontend render. Use the namespace export instead.
                window.GTCore.checkVersionMismatch(this.config, window.GTCore.VERSION);
            }

            // Prevent multiple initializations
            var $wrapper = $('#' + this.wrapperId);
            if ($wrapper.data('gt-initialized')) {
                //console.log('GT Frontend: Table already initialized, skipping:', this.wrapperId);
                return;
            }

            if ($wrapper.length === 0) {
                console.error('GT Frontend: Table wrapper not found:', this.wrapperId);
                return;
            }

            // Mark as initialized only after confirming the wrapper exists
            $wrapper.data('gt-initialized', true);

            // Guard against external DataTables having auto-initialized on our container (#155).
            // Some plugins initialize DataTables on all <table> elements; destroy any stray
            // instance so it doesn't conflict with our own rendering.
            var $table = $wrapper.find('.gt-table');
            if ($table.length && typeof $.fn.DataTable !== 'undefined' && $.fn.DataTable.isDataTable($table[0])) {
                $table.DataTable().destroy();
            }

            if (!this.config.enable_frontend_editing) {
                $wrapper.attr('data-editing-disabled', 'true');
            }

            // Apply sticky header class if enabled. The v4.7.71 CSS rewrite
            // makes this purely class-driven - `position: sticky; top: 0` on
            // <th> handles freezing natively, no JS scroll watcher required.
            if (this.config.sticky_header && !$wrapper.hasClass('sticky-header')) {
                $wrapper.addClass('sticky-header');
            }

            // #544 slice 2: the JS scroll-watcher path is gone - CSS
            // `position: sticky; top: 0` on the <th> elements (rule
            // lives in frontend.css since the v4.7.71 rewrite) keeps
            // the thead in the table flow, so the browser handles
            // header alignment without any JS width-syncing - including
            // in the multi-row frozen-header case, since each th stays
            // in its column's flow and inherits the body cell's
            // effective width. No JS to restore here.

            // Apply freeze-first-column class if enabled (#193)
            if (this.config.freeze_first_column && !$wrapper.hasClass('gt-freeze-first-col')) {
                $wrapper.addClass('gt-freeze-first-col');
            }

            // Apply responsive table class if enabled
            if (this.config.responsive_table) {
                $wrapper.addClass('responsive-table');
                //console.log('GT Frontend: Responsive table enabled');
            }

            // Initialize responsive mode functionality
            if (this.config.responsive_mode) {
                //console.log('GT Frontend: Responsive mode configured as:', this.config.responsive_mode);
                this.initializeResponsiveMode($wrapper);
            }

            // Debug: Log date format configuration
            if (this.config.date_format) {
                //console.log('GT Frontend: Date format configured as:', this.config.date_format);
                //console.log('GT Frontend: JavaScript date format:', this.config.date_format_js);
            }

            this.bindEvents();
            this.setupDateInputs();
            // TC_Default_Sort_Service - per-table initial sort override.
            // Override the hardcoded date_created/desc default before the
            // first AJAX so the table arrives sorted as configured. The
            // existing click-to-sort handler will continue to mutate
            // this.sortField / this.sortOrder when the visitor re-sorts.
            if (this.config && this.config.default_sort_column) {
                this.sortField = String(this.config.default_sort_column);
                this.sortOrder = this.config.default_sort_direction === 'desc' ? 'desc' : 'asc';
                this.sortStack = [{ column_id: this.sortField, direction: this.sortOrder }];
            }
            // TC_URL_Filter_Service - apply URL pre-filters before the
            // first AJAX. Server-side parse_filters has already validated +
            // sanitized, so config.url_filters is a safe column_id => value
            // map. We populate the visible filter inputs (so customers see
            // what's filtered) and seed this.filters in the shape the AJAX
            // backend expects (filter_<id> = {type:'text', value:<v>}).
            this.applyUrlFilters();
            // Persistent filter restore (v4.9.12). URL filters above take
            // precedence - restoreFilterStateLocal merges, doesn't replace.
            this.restoreFilterStateLocal();
            this.loadEntries();
            this.setupScrollIndicators();
            this.initializeColumnResizing($wrapper);
            this.initPrint();
            this.initPresets();
            // #1601 - summary line (no-op unless show_table_summary).
            if (typeof this.initTableSummary === 'function') {
                this.initTableSummary();
            }
            this.initColumnReorder();
            this.initUndoRedo();
            this.initVisibilityObserver($wrapper);
            this.initToolbarExport();
            // #1743 - auto-refresh on interval. startAutoRefresh is a no-op when
            // config.auto_refresh_interval is 0 or absent so we call it unconditionally.
            if (typeof this.startAutoRefresh === 'function') {
                this.startAutoRefresh();
            }
            // #1744 - column visibility picker. initColumnPicker is a no-op when
            // config.show_column_picker is falsy.
            if (typeof this.initColumnPicker === 'function') {
                this.initColumnPicker();
            }
            // #1745 - bulk column fill is triggered from bulk-action toolbar, no init needed.
            // #1746 - column-level role visibility (Pro). Apply once after render.
            if (typeof this.applyColumnRoleVisibility === 'function') {
                this.applyColumnRoleVisibility();
            }

        } catch (error) {
            console.error('GT Frontend: Error in init function:', error);
            console.error('GT Frontend: Error details:', {
                message: error.message,
                stack: error.stack,
                wrapperId: this.wrapperId,
                hasConfig: !!this.config
            });
        }
    };

})(window);
