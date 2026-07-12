/**
 * Gravity Tables -- admin/preview-and-shortcode.js
 *
 * Eighth slice of #842 (filed as #959). Live preview generation + shortcode helpers.
 *
 * @since 4.156.0
 */
(function ($) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        generatePreview: function () {
            var self = this;

            // Debounce: wait 400 ms after the last call before actually firing.
            // This prevents a new AJAX request on every individual keypress or
            // checkbox toggle when the user is making several changes quickly,
            // which is the primary cause of admin-editor unresponsiveness on
            // large tables (issue #187).
            clearTimeout(self._previewDebounceTimer);
            self._previewDebounceTimer = setTimeout(function () {
                self._generatePreviewNow();
            }, 400);
        },

        _generatePreviewNow: function () {
            //console.log('GT Admin DEBUG: === GENERATING PREVIEW ===');
            //console.log('GT Admin DEBUG: Current checkbox states when generating preview:');
            //console.log('  show_deleted_entries:', $('input[name="show_deleted_entries"]').is(':checked'));
            //console.log('  filter_user_entries:', $('input[name="filter_user_entries"]').is(':checked'));
            var self = this;

            var formId = $('#gravity-form').val();
            var dataSourceType = $('select[name="data_source_type"]').val() || 'gravity_forms';
            var isExternal = (dataSourceType === 'json' || dataSourceType === 'airtable' || dataSourceType === 'notion'
                || dataSourceType === 'csv' || dataSourceType === 'xml' || dataSourceType === 'google_sheets'
                || dataSourceType === 'external_db' || dataSourceType === 'woocommerce_products' || dataSourceType === 'xlsx');

            if (!isExternal && (!formId || this.selectedFields.length === 0)) {
                $('#table-preview-area').html('<p>Please select a form and add some fields to see preview</p>');
                $('#generated-shortcode').text('[tablecrafter]');
                return;
            }
            var jsonUrl = $('input[name="json_url"]').val() || '';
            if (dataSourceType === 'json' && Object.keys(this.formFields).length === 0 && !jsonUrl.trim()) {
                $('#table-preview-area').html('<p>Enter a JSON URL and click <strong>Test Connection</strong> to load columns.</p>');
                $('#generated-shortcode').text(this.generateShortcode());
                return;
            }
            if (isExternal && this.selectedFields.length === 0) {
                $('#table-preview-area').html('<p>Click <strong>Test connection</strong> to load columns, then drag columns into the Table Columns area to see a preview.</p>');
                $('#generated-shortcode').text('[tablecrafter]');
                return;
            }

            // Generate shortcode preview
            var shortcode = this.generateShortcode();
            $('#generated-shortcode').text(shortcode);

            // Collect field labels, lookup configuration, and conditional formatting
            var fieldLabels = {};
            var lookupFields = {};
            var conditionalFormatting = {};

            //console.log('GT Admin DEBUG: Collecting data from selected fields...');
            $.each(this.selectedFields, function (index, fieldId) {
                var field = self.formFields[fieldId];
                //console.log('GT Admin DEBUG: Processing field', fieldId, ':', field);
                if (field) {
                    if (field.custom_label) {
                        fieldLabels[fieldId] = field.custom_label;
                    }
                    if (field.lookup_enabled) {
                        lookupFields[fieldId] = {
                            type: field.lookup_type,
                            user_field: field.lookup_user_field,
                            user_roles: field.lookup_user_roles,
                            post_field: field.lookup_post_field,
                            table: field.lookup_table,
                            id_column: field.lookup_id_column,
                            display_column: field.lookup_display_column
                        };
                    }
                    if (field.conditional_formatting && field.conditional_formatting.length > 0) {
                        //console.log('GT Admin DEBUG: Adding conditional formatting for field', fieldId, ':', field.conditional_formatting);
                        conditionalFormatting[fieldId] = field.conditional_formatting;
                    }
                }
            });

            //console.log('GT Admin DEBUG: Final conditional formatting object for preview:', conditionalFormatting);

            // Collect filter configurations for preview
            var filterConfigurations = {};
            this.selectedFields.forEach(function (fieldId) {
                var field = this.formFields[fieldId];
                if (field && field.filter_config) {
                    filterConfigurations[fieldId] = field.filter_config;
                }
            }.bind(this));

            // Debug: Log filter configurations for preview
            //console.log('GT Admin: Filter configurations for preview:', filterConfigurations);

            // Collect field configurations and filterable fields for preview
            var fieldConfigurations = {};
            var filterableFields = [];
            this.selectedFields.forEach(function (fieldId) {
                var field = this.formFields[fieldId];
                if (field) {
                    fieldConfigurations[fieldId] = {
                        editable: field.editable,
                        sortable: field.sortable,
                        filterable: field.filterable,
                        custom_label: field.custom_label,
                        width: field.width
                    };
                    
                    if (field.filterable !== false) {
                        filterableFields.push(fieldId);
                    }
                }
            }.bind(this));

            // Generate table preview via AJAX
            var formData = {
                action: 'gt_preview_table',
                nonce: $('input[name="nonce"]').val() || (typeof gtAdmin !== 'undefined' ? gtAdmin.nonce : ''),
                form_id: formId,
                settings: {
                    columns: this.selectedFields,
                    selected_fields: this.selectedFields,
                    json_url:            $('input[name="json_url"]').val() || '',
                    json_dot_path:       $('input[name="json_dot_path"]').val() || '',
                    json_headers_raw:    $('textarea[name="json_headers_raw"]').val() || '',
                    csv_url:             $('input[name="csv_url"]').val() || '',
                    xlsx_url:            $('input[name="xlsx_url"]').val() || '',
                    xml_url:             $('input[name="xml_url"]').val() || '',
                    xml_row_path:        $('input[name="xml_row_path"]').val() || '',
                    google_sheets_url:   $('input[name="google_sheets_url"]').val() || '',
                    // #2240 — Airtable live preview config. A blank PAT means "use
                    // the saved token" — the server falls back via table_id.
                    airtable_pat:        $('input[name="airtable_pat"]').val() || '',
                    airtable_base_id:    $('input[name="airtable_base_id"]').val() || '',
                    airtable_table_id:   $('input[name="airtable_table_id"]').val() || '',
                    // #2241 — Notion live preview config (same blank-token → saved-
                    // token fallback via table_id).
                    notion_token:        $('input[name="notion_token"]').val() || '',
                    notion_database_id:  $('input[name="notion_database_id"]').val() || '',
                    // #2242 — External DB live preview config (saved connection
                    // index + read-only query).
                    external_db_connection: $('select[name="external_db_connection"]').val() || '',
                    external_db_query:      $('textarea[name="external_db_query"]').val() || '',
                    table_id:            $('input[name="table_id"]').val() || '',
                    data_source_type:    $('select[name="data_source_type"]').val() || 'gravity_forms',
                    column_labels: fieldLabels,
                    lookup_fields: lookupFields,
                    conditional_formatting: conditionalFormatting,
                    cell_vertical_alignments: this.cellVerticalAlignments || {}, // #549 slice 3
                    filter_configurations: filterConfigurations, // Add filter configurations to preview
                    field_configurations: fieldConfigurations,   // Add field configurations
                    filterable_fields: filterableFields,         // Add explicitly filterable fields
                    show_search: $('input[name="show_search"]').is(':checked') ? true : false,
                    show_pagination: $('input[name="show_pagination"]').is(':checked') ? true : false,
                    show_selection: $('input[name="show_selection"]').is(':checked') ? true : false,
                    show_bulk_actions: $('input[name="show_bulk_actions"]').is(':checked') ? true : false,
                    show_advanced_filters: $('input[name="show_advanced_filters"]').is(':checked') ? true : false,
                    show_entry_info: $('input[name="show_entry_info"]').is(':checked') ? true : false,
                    show_add_entry: $('input[name="show_add_entry"]').is(':checked') ? true : false,
                    enable_frontend_editing: $('input[name="enable_frontend_editing"]').is(':checked') ? true : false,
                    enable_delete: $('input[name="enable_delete"]').is(':checked') ? true : false,
                    persistent_filters: $('input[name="persistent_filters"]').is(':checked') ? true : false,
                    show_deleted_entries: $('input[name="show_deleted_entries"]').is(':checked') ? true : false,
                    filter_user_entries: $('input[name="filter_user_entries"]').is(':checked') ? true : false,
                    show_column_totals: $('input[name="show_column_totals"]').is(':checked') ? true : false,
                    // #521 slice 2 — toolbar visibility map (6 components).
                    toolbar_visibility: (function () {
                        var components = ['global_search', 'pagination', 'length_selector', 'info_label', 'column_filters', 'export_buttons'];
                        var out = {};
                        $.each(components, function (i, c) {
                            out[c] = $('input[name="toolbar_visibility[' + c + ']"]').is(':checked') ? true : false;
                        });
                        return out;
                    })(),
                    // Export toolbar buttons + processing/template/data-source
                    // (#654). These named UI controls existed in
                    // admin/views/table-builder.php but were never in this
                    // save payload — admins toggled them and the value
                    // silently reverted on save. The runtime layer
                    // (templates/table.php:456-459, server_side_entries
                    // AJAX handler, comparison-table renderer) is fully
                    // wired; only the save path was broken.
                    show_toolbar_copy:  $('input[name="show_toolbar_copy"]').is(':checked') ? true : false,
                    show_toolbar_csv:   $('input[name="show_toolbar_csv"]').is(':checked') ? true : false,
                    show_toolbar_excel: $('input[name="show_toolbar_excel"]').is(':checked') ? true : false,
                    show_pdf_export:    $('input[name="show_pdf_export"]').is(':checked') ? true : false,
                    processing_mode:    $('select[name="processing_mode"]').val() || 'client',
                    template_type:      $('select[name="template_type"]').val() || 'standard',
                    data_source_type:   $('select[name="data_source_type"]').val() || 'gravity_forms',
                    per_page: parseInt($('select[name="per_page"]').val()),
                    allowed_user_roles: $('select[name="allowed_user_roles[]"]').val() || [],
                    custom_css: $('textarea[name="custom_css"]').val() || '',
                    webhook_url: $('input[name="webhook_url"]').val() || '',
                    notify_emails: $('input[name="notify_emails"]').val() || '',
                    /* c8 ignore next */
                    notify_events: $('input[name="notify_events[]"]:checked').map(function () { return this.value; }).get(),
                    wc_mapping: {
                        title: $('select[name="wc_mapping[title]"]').val() || '',
                        price: $('select[name="wc_mapping[price]"]').val() || '',
                        sku: $('select[name="wc_mapping[sku]"]').val() || '',
                        description: $('select[name="wc_mapping[description]"]').val() || ''
                    },
                    // #2323 — arbitrary cell merges. Parse the JSON textarea so
                    // the server receives an array (not a JSON string); jQuery
                    // $.post serialises it into the PHP-array form automatically.
                    cell_merges: (function () {
                        try {
                            var raw = $('textarea[name="cell_merges"]').val() || '[]';
                            var parsed = JSON.parse(raw);
                            return Array.isArray(parsed) ? parsed : [];
                        } catch (e) {
                            return [];
                        }
                    })()
                }
            };

            // Show loading
            $('#table-preview-area').html('<div class="gt-loading">Generating preview...</div>');

            //console.log('GT Admin DEBUG: Sending preview AJAX request with data:', formData);
            //console.log('GT Admin DEBUG: Filter settings being sent:', {
            //    show_deleted_entries: formData.settings.show_deleted_entries,
            //    filter_user_entries: formData.settings.filter_user_entries
            //});

            // Abort any previous in-flight preview request before starting a new
            // one — prevents stacked responses arriving out of order and avoids
            // the browser queuing many simultaneous XHRs (issue #187).
            if (this._previewXHR && this._previewXHR.readyState !== 4) {
                this._previewXHR.abort();
            }

            // Get preview via AJAX
            this._previewXHR = $.post(gtAdmin.ajax_url, formData, function (response) {
                //console.log('GT Admin DEBUG: Preview AJAX response:', response);
                if (response.success) {
                    //console.log('GT Admin DEBUG: Preview HTML generated successfully');
                    $('#table-preview-area').html(response.data.html);
                } else {
                    console.error('GT Admin DEBUG: Preview generation failed:', response.data);
                    $('#table-preview-area').html('<div class="gt-error">Error generating preview: ' + (response.data || 'Unknown error') + '</div>');
                }
            }).fail(function (jqXHR) {
                // Ignore user-initiated aborts (readyState 0 = aborted).
                if (jqXHR.statusText === 'abort') { return; }
                console.error('GT Admin DEBUG: Preview AJAX request failed');
                $('#table-preview-area').html('<div class="gt-error">Error generating preview</div>');
            });
        },

        generateShortcode: function () {
            // Check if we have a saved table ID
            var tableId = $('input[name="table_id"]').val();

            if (tableId) {
                // For saved tables, use simple ID-based shortcode
                return '[tablecrafter id="' + tableId + '"]';
            } else {
                // For unsaved tables, show placeholder text
                return '[tablecrafter id="SAVE_TABLE_FIRST"]';
            }
        },

        copyShortcode: function () {
            var shortcode = $('#generated-shortcode').text();

            // Try modern Clipboard API first
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(shortcode).then(function () {
                    // Show success message
                    var button = $('#copy-shortcode');
                    var originalText = button.text();
                    button.text('Copied!');
                    setTimeout(function () {
                        /* c8 ignore next */
                        button.text(originalText);
                    }, 2000);
                }).catch(function (err) {
                    console.error('Clipboard API failed:', err);
                    // Fallback to old method
                    TC_TableBuilder.copyShortcodeFallback(shortcode);
                });
            } else {
                // Use fallback for older browsers
                this.copyShortcodeFallback(shortcode);
            }
        },

        copyShortcodeFallback: function (text) {
            // Create temporary textarea
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy');
                var button = $('#copy-shortcode');
                var originalText = button.text();
                button.text('Copied!');
                setTimeout(function () {
                    button.text(originalText);
                }, 2000);
            } catch (err) {
                console.error('Copy failed:', err);
                alert('Failed to copy shortcode. Please copy manually.');
            }

            document.body.removeChild(textarea);
        }

    });

})(jQuery);
