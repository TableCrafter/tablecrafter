/**
 * TableCrafter — frontend/export.js
 *
 * Toolbar export pipeline. Fourteenth slice under #833. Two methods,
 * ~150 lines.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - initToolbarExport() — wire the export dropdown click handlers
 *     (Copy / CSV / Excel / PDF). Pairs with the pure formatters in
 *     toolbar-export.js (#832 slice 13).
 *   - exportTable(format)  — orchestrate the actual export: fetch all
 *     entries, build the payload via the pure formatters, trigger
 *     download (Blob + anchor + revoke).
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.initToolbarExport = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);

        $wrapper.on('click', '.gt-toolbar-copy-btn', function (e) {
            e.preventDefault();
            self.toolbarCopyToClipboard();
        });

        $wrapper.on('click', '.gt-toolbar-csv-btn', function (e) {
            e.preventDefault();
            self.toolbarDownloadCSV();
        });

        $wrapper.on('click', '.gt-toolbar-excel-btn', function (e) {
            e.preventDefault();
            self.toolbarDownloadExcel();
        });

        $wrapper.on('click', '.gt-pdf-btn', function (e) {
            e.preventDefault();
            self.toolbarDownloadPDF();
        });
    };

    // Toolbar export helpers moved to assets/js/frontend/toolbar-export.js
    // (#832 slice 13 + getVisibleTableData moved in slice 17).

    // Column order localStorage persistence (_columnOrderKey,
    // readStoredColumnOrder, saveStoredColumnOrder, clearStoredColumnOrder)
    // moved to assets/js/frontend/column-order-persistence.js (#832 slice 15).
    //
    // Column reorder DnD UI (getReorderableHeaders, fieldIdFromTh,
    // applyStoredColumnOrderToHead, applyStoredColumnOrderToBody,
    // bindColumnDragEvents, reorderColumn, initColumnReorder,
    // updateColumnReorderResetButton) moved to
    // assets/js/frontend/column-reorder-dnd.js (#832 slice 19).

    GravityTable.prototype.exportTable = function (format) {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);

        // Show loading state
        var $exportBtn = $wrapper.find('.gt-export-btn');
        var originalText = $exportBtn.html();
        $exportBtn.html('<span class="gt-export-icon">⏳</span> Exporting...');
        $exportBtn.prop('disabled', true);

        // Collect current filters (same logic as applyFilters)
        var filters = {};
        var searchTerm = $wrapper.find('.gt-search-input').val() || '';
        var sortField = this.sortField || 'date_created';
        var sortOrder = this.sortOrder || 'desc';

        // Get all current filters
        $wrapper.find('.gt-filter-field').each(function () {
            var $field = $(this);
            var fieldId = $field.data('field-id');
            var fieldType = $field.data('field-type');

            // Process filters same as applyFilters method
            switch (fieldType) {
                case 'date':
                    var $singleHtml5 = $field.find('.gt-date-html5:not(.gt-date-from-html5):not(.gt-date-to-html5)');
                    var $fromHtml5 = $field.find('.gt-date-from-html5');
                    var $toHtml5 = $field.find('.gt-date-to-html5');

                    var fromValue = '';
                    var toValue = '';
                    var dateFormat = (self.config && self.config.date_format) ? self.config.date_format : 'm/d/Y';

                    if ($singleHtml5.length > 0 && $singleHtml5.val()) {
                        fromValue = self.convertHtml5DateToFormat($singleHtml5.val(), dateFormat);
                        toValue = fromValue;
                    } else {
                        if ($fromHtml5.val()) {
                            fromValue = self.convertHtml5DateToFormat($fromHtml5.val(), dateFormat);
                        }
                        if ($toHtml5.val()) {
                            toValue = self.convertHtml5DateToFormat($toHtml5.val(), dateFormat);
                        }
                    }

                    if (fromValue || toValue) {
                        filters['filter_' + fieldId] = {
                            type: 'date_range',
                            from: fromValue || '',
                            to: toValue || ''
                        };
                    }
                    break;

                default:
                    var filterValue = $field.find('input, select').val();
                    if (filterValue && filterValue.length > 0) {
                        filters['filter_' + fieldId] = {
                            type: 'text',
                            value: filterValue
                        };
                    }
                    break;
            }
        });

        // Prepare export data
        var exportData = {
            action: 'gt_export_table',
            nonce: this.config.nonce,
            table_id: this.config.table_id || 0,
            form_id: this.config.form_id,
            format: format,
            search: searchTerm,
            sort_field: sortField,
            sort_order: sortOrder,
            filters: filters,
            columns: this.config.columns
        };

        // Create form and submit for download
        var $form = $('<form>', {
            method: 'POST',
            action: this.config.ajax_url,
            style: 'display: none;'
        });

        // Add all data as hidden inputs
        $.each(exportData, function (key, value) {
            if (typeof value === 'object') {
                $form.append($('<input>', {
                    type: 'hidden',
                    name: key,
                    value: JSON.stringify(value)
                }));
            } else {
                $form.append($('<input>', {
                    type: 'hidden',
                    name: key,
                    value: value
                }));
            }
        });

        $('body').append($form);
        $form.submit();
        $form.remove();

        // Reset button state
        setTimeout(function () {
            $exportBtn.html(originalText);
            $exportBtn.prop('disabled', false);
        }, 1000);
    };


})(window);
