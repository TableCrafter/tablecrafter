(function ($) {
    'use strict';

    // Boot table config from PHP (see gtAdmin.boot_table in class-tc-admin.php).
    if (typeof window.gtTableData === 'undefined' && typeof gtAdmin !== 'undefined' && gtAdmin.boot_table) {
        window.gtTableData = gtAdmin.boot_table;
    }

    // Gravity Tables - Admin JavaScript
    // By default, all fields are sortable unless explicitly disabled in the field configuration

    window.TC_TableBuilder = {
        formFields: {},
        selectedFields: [],
        tableConfig: {},
        cellVerticalAlignments: {}, // #549 slice 3: per-cell vertical alignment overrides

        // #843 v4.149.0 - moved to assets/js/admin/core.js

        // #964 v4.157.0 -- moved to assets/js/admin/bind-events.js

        // #843 v4.149.0 - moved to assets/js/admin/core.js

        // #843 v4.149.0 - moved to assets/js/admin/core.js

        // #843 v4.149.0 - moved to assets/js/admin/core.js

        // #843 v4.149.0 - moved to assets/js/admin/core.js

        // #843 v4.149.0 - moved to assets/js/admin/core.js

        // #844 v4.150.0 - moved to assets/js/admin/table-builder.js

        // #844 v4.150.0 - moved to assets/js/admin/table-builder.js

        // (#440) Initialise SortableJS on the row preview container so users can
        // drag rows to a new position. Persists the order via gt_save_row_order.
        // #844 v4.150.0 - moved to assets/js/admin/table-builder.js

        // (#440) Read the current row order from the DOM and persist via AJAX.
        // #844 v4.150.0 - moved to assets/js/admin/table-builder.js

        // (#441) Bind viewport toggle buttons so users can preview the table at
        // desktop, tablet (768px) and mobile (375px) widths without reloading.
        // #966 v4.158.0 moved to admin/utilities.js

        // #966 v4.158.0 moved to admin/utilities.js

        // #957 v4.155.0 - moved to assets/js/admin/field-list.js

        // #957 v4.155.0 - moved to assets/js/admin/field-list.js

        // #957 v4.155.0 - moved to assets/js/admin/field-list.js

        // #955 v4.154.0 - dead-code DELETED (was shadowed by duplicate at original line 1409)

        // #957 v4.155.0 - moved to assets/js/admin/field-list.js

        // #957 v4.155.0 - moved to assets/js/admin/field-list.js

        // #966 v4.158.0 moved to admin/utilities.js

        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        // #966 v4.158.0 moved to admin/utilities.js

        // Debounce timer and in-flight XHR reference for preview AJAX.
        // Shared across calls so rapid settings changes only fire one request.
        _previewDebounceTimer: null,
        _previewXHR: null,

        // #959 v4.156.0 -- moved to assets/js/admin/preview-and-shortcode.js

        // #959 v4.156.0 -- moved to assets/js/admin/preview-and-shortcode.js

        // #959 v4.156.0 -- moved to assets/js/admin/preview-and-shortcode.js

        // #959 v4.156.0 -- moved to assets/js/admin/preview-and-shortcode.js

        // #959 v4.156.0 -- moved to assets/js/admin/preview-and-shortcode.js

        // #954 v4.153.0 - moved to assets/js/admin/save-table.js

        // #955 v4.154.0 - moved to assets/js/admin/load-table-data.js

        // #955 v4.154.0 - moved to assets/js/admin/load-table-data.js (was live duplicate)

        // Conditional formatting methods
        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        // #846 v4.152.0 - moved to assets/js/admin/conditional-format-rules.js

        /**
         * Collect responsive settings for all fields
         * @returns {Object} Responsive settings by field ID
         */
        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        /**
         * Cache responsive settings from field modal
         * @param {string} fieldId Field ID
         * @param {Object} settings Responsive settings
         */
        // #845 v4.151.0 - moved to assets/js/admin/field-config-modal.js

        /**
         * Show upgrade notice when hitting plan limits
         * @param {string} type Limit type (column_limit, table_limit, etc.)
         * @param {string} message The message to display
         */
        // #966 v4.158.0 moved to admin/utilities.js
    };

    // Initialize when document is ready
    $(document).ready(function () {
        // Add a small delay to ensure all scripts are loaded
        setTimeout(function () {
            if (typeof TC_TableBuilder !== 'undefined') {
                TC_TableBuilder.init();
            } else {
                /* c8 ignore next */
                console.error('TC_TableBuilder is not defined');
            }
        }, 100);
    });

})(jQuery);