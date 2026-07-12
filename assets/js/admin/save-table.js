/**
 * TableCrafter — admin/save-table.js
 *
 * Fifth slice of #842 (filed as #954). The saveTable AJAX flow — the
 * single biggest remaining method in admin.js after the slice 1-4 cleanup.
 *
 *   - saveTable: assemble the full table-config payload, validate, post
 *     to the gt_save_table AJAX endpoint, handle success/error feedback.
 *
 * @since 4.153.0
 */
(function ($) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        saveTable: function ($clickedButton) {
            var self = this;
            $clickedButton = $clickedButton || $('#save-table');

            // Validate required fields
            var title = $('#table-title').val().trim();
            var formId = $('#gravity-form').val();

            if (!title) {
                alert('Please enter a table title');
                return;
            }

            var dataSourceType = $('select[name="data_source_type"]').val() || 'gravity_forms';
            if (!formId && dataSourceType === 'gravity_forms') {
                alert('Please select a Gravity Form');
                return;
            }

            if (dataSourceType === 'gravity_forms' && this.selectedFields.length === 0) {
                alert('Please select at least one field');
                return;
            }

            // Collect field configuration data
            var fieldLabels = {};
            var editableFields = [];
            var sortableFields = [];
            var filterableFields = [];
            var lookupFields = {};
            var conditionalFormatting = {};
            var fieldConfigurations = {}; // Individual field configurations
            var columnAlignments = {}; // #661 — per-column text alignment
            var columnWrapModes = {}; // #662 — per-column word-wrap mode
            var columnVerticalAlignments = {}; // #663 — per-column vertical alignment
            var columnLinkSettings = {}; // #664 — per-column link target / color / underline
            var columnCellTypes = {}; // TC_Star_Rating_Service per-column cell type ('' or 'star_rating')
            var columnAggregations = {}; // TC_Formula_Service per-column totals-row aggregation
            var columnDetailOnly = {};   // TC_Detail_Rows_Service per-column detail-only flag
            var columnAutoMerge = {};    // TC_Rowspan_Merge_Service per-column auto-merge flag (#518 slice 2)
            var columnDataBars = {};     // TC_Data_Bars_Service per-column value-bar config (#1731, Pro)
            var columnBadgeMaps = {};   // TC_Badge_Service per-column badge map (#1741, Free)
            var columnValidations = {}; // TC_Validation_Service per-column inline-edit rules (#1742, Pro)
            var columnRoleVisibility = {}; // #1746 per-column role visibility (Pro)
            var drilldownColumns = [];   // TC_Drilldown_Filter_Service flat list of field_ids with click-to-filter on (#568 slice 2)

            $.each(this.selectedFields, function (index, fieldId) {
                var field = self.formFields[fieldId];
                if (field) {
                    if (field.custom_label && field.custom_label !== field.label) {
                        fieldLabels[fieldId] = field.custom_label;
                    }
                    if (field.alignment) {
                        // Whitelist enforced server-side at class-tc-admin.php:662
                        // (left / center / right / justify); anything else is
                        // coerced to 'left'. Empty string omitted so the saved
                        // settings stay clean.
                        columnAlignments[fieldId] = field.alignment;
                    }
                    if (field.wrap_mode && field.wrap_mode !== 'default') {
                        // Whitelist enforced server-side via
                        // TC_Wrap_Mode_Service::sanitize_map (default,
                        // break-word, hyphenate, nowrap). The service skips
                        // 'default' in its output to keep saved settings
                        // minimal — we mirror that here.
                        columnWrapModes[fieldId] = field.wrap_mode;
                    }
                    if (field.vertical_alignment) {
                        // Whitelist enforced server-side at
                        // class-tc-admin.php:735 (top / middle / bottom);
                        // anything else is coerced to '' which the runtime
                        // treats as the browser default. Empty values omitted
                        // here so saved settings stay minimal.
                        columnVerticalAlignments[fieldId] = field.vertical_alignment;
                    }
                    if (field.link_settings) {
                        // Per-column hyperlink target / color / underline
                        // (#664). sanitize_column_link_settings at
                        // class-tc-admin.php:920 whitelists target values,
                        // sanitizes color (sanitize_hex_color), bool-validates
                        // underline. Skip a field's entry entirely when all
                        // sub-keys are empty/default — no need to bloat the
                        // saved JSON with every column's link defaults.
                        var hasNonDefaultLink =
                            (field.link_settings.link_target && field.link_settings.link_target !== '') ||
                            (field.link_settings.link_color && field.link_settings.link_color !== '') ||
                            field.link_settings.link_underline === false; // false is non-default
                        if (hasNonDefaultLink) {
                            columnLinkSettings[fieldId] = field.link_settings;
                        }
                    }
                    if (field.cell_type && field.cell_type !== '') {
                        // TC_Star_Rating_Service: server-side sanitizer
                        // whitelists '' (plain text default) and 'star_rating'
                        // — anything else is coerced back to ''. Empty values
                        // omitted here so saved settings stay minimal.
                        columnCellTypes[fieldId] = field.cell_type;
                    }
                    if (field.aggregation && field.aggregation !== '') {
                        // TC_Formula_Service::SUPPORTED_AGGREGATIONS — server-side
                        // sanitizer whitelists. Empty = "auto" (legacy SUM-for-numeric).
                        columnAggregations[fieldId] = field.aggregation;
                    }
                    if (field.badge_map && field.cell_type === 'badge' && typeof field.badge_map === 'object' && Object.keys(field.badge_map).length) {
                        columnBadgeMaps[fieldId] = field.badge_map;
                    }
                    if (field.validation_rules && typeof field.validation_rules === 'object' && Object.keys(field.validation_rules).length) {
                        columnValidations[fieldId] = field.validation_rules;
                    }
                    if (field.data_bar_enabled) {
                        // #1731 — Data Bars (Pro). The server-side sanitizer
                        // (TC_Data_Bars_Service::sanitize) strips this whole
                        // block on the free tier, so a free user can never
                        // persist it even via a hand-edited payload.
                        columnDataBars[fieldId] = {
                            enabled: true,
                            color: field.data_bar_color || '#3b82f6'
                        };
                    }
                    if (field.allowed_roles && field.allowed_roles.length) {
                        // #1746 — per-column role visibility (Pro). Only persist when non-empty.
                        columnRoleVisibility[fieldId] = field.allowed_roles;
                    }
                    if (field.detail_only) {
                        // TC_Detail_Rows_Service — flat field_id => true map.
                        // Sanitizer at class-tc-admin.php normalizes via service.
                        columnDetailOnly[fieldId] = true;
                    }
                    if (field.auto_merge_consecutive) {
                        // TC_Rowspan_Merge_Service per-column flag (#518 slice 2).
                        // Sanitizer at class-tc-admin.php:729 boolean-validates.
                        // Slice 3 will wire templates/table.php to consume this.
                        columnAutoMerge[fieldId] = true;
                    }
                    if (field.drilldown_enabled) {
                        // TC_Drilldown_Filter_Service flat list (#568 slice 2).
                        // Sanitizer delegates to service::normalize_settings.
                        // Slice 3 will wire frontend.js for cell click delegation.
                        drilldownColumns.push(fieldId);
                    }
                    if (field.editable) {
                        editableFields.push(fieldId);
                    }
                    // Make all fields sortable by default unless explicitly disabled
                    if (field.sortable !== false) {
                        sortableFields.push(fieldId);
                    }
                    // Make all fields filterable by default unless explicitly disabled
                    if (field.filterable !== false) {
                        filterableFields.push(fieldId);
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

                    // Save individual field configuration
                    fieldConfigurations[fieldId] = {
                        editable: field.editable,
                        sortable: field.sortable,
                        filterable: field.filterable,
                        custom_label: field.custom_label,
                        width: field.width
                    };
                }
            });

            //console.log('GT Admin DEBUG: Final conditional formatting object:', conditionalFormatting);
            //console.log('GT Admin DEBUG: Final field configurations object:', fieldConfigurations);

            // Compile filter configurations
            var filterConfigurations = {};
            $.each(this.formFields, function (fieldId, field) {
                //console.log('GT Admin DEBUG: Checking field', fieldId, '- filterable:', field.filterable, 'has filter_config:', !!field.filter_config);
                // Include any field that has a filter_config, regardless of filterable flag
                // This fixes the issue where filterable is undefined but filter_config exists
                if (field.filter_config) {
                    //console.log('GT Admin DEBUG: Adding filter config for field', fieldId, ':', field.filter_config);
                    filterConfigurations[fieldId] = field.filter_config;
                } else if (field.filterable) {
                    //console.log('GT Admin DEBUG: Field', fieldId, 'is filterable but has no filter_config');
                }
            });

            //console.log('GT Admin DEBUG: Final filter configurations object:', filterConfigurations);

            // Collect bulk actions.
            //
            // The legacy bulk_delete / bulk_export / bulk_edit checkboxes were
            // removed from the table builder in v4.7.178 (#635) — the section
            // is now a paragraph pointing at the global Settings → Bulk
            // Actions toggle. The selectors below no longer match anything,
            // so reading them produces an empty array, which used to bypass
            // the server-side default and silently break the toolbar (#651).
            //
            // We now default to all three documented actions and let the
            // legacy selector loop only ADD to that set if the deprecated
            // markup is somehow still present (in the wild during a partial
            // migration). Per-table opt-in returns when #635 is wired up.
            var bulkActions = ['delete', 'edit', 'export'];
            // Legacy checkbox path — currently a no-op; preserved so a
            // partial-migration page state can still narrow the action set.
            if ($('input[name="bulk_delete"]').length || $('input[name="bulk_export"]').length || $('input[name="bulk_edit"]').length) {
                bulkActions = [];
                if ($('input[name="bulk_delete"]').is(':checked')) {
                    bulkActions.push('delete');
                }
                if ($('input[name="bulk_export"]').is(':checked')) {
                    /* c8 ignore next */
                    bulkActions.push('export');
                }
                if ($('input[name="bulk_edit"]').is(':checked')) {
                    bulkActions.push('edit');
                }
            }

            // Collect form data
            var formData = {
                table_id: $('input[name="table_id"]').val(),
                title: $('#table-title').val(),
                form_id: $('#gravity-form').val(),
                selected_fields: this.selectedFields,
                field_labels: fieldLabels,
                editable_fields: editableFields,
                sortable_fields: sortableFields,
                filterable_fields: filterableFields,
                lookup_fields: lookupFields,
                conditional_formatting: conditionalFormatting,
                filter_configurations: filterConfigurations,
                field_configurations: fieldConfigurations,
                column_alignments: columnAlignments, // #661
                column_wrap_modes: columnWrapModes, // #662
                column_vertical_alignments: columnVerticalAlignments, // #663
                cell_vertical_alignments: this.cellVerticalAlignments || {}, // #549 slice 3
                column_link_settings: columnLinkSettings, // #664
                column_cell_types: columnCellTypes, // TC_Star_Rating_Service / TC_Badge_Service per-column cell type
                column_badge_maps: columnBadgeMaps, // TC_Badge_Service per-column badge map (#1741)
                column_validations: columnValidations, // TC_Validation_Service per-column inline-edit rules (#1742, Pro)
                column_role_visibility: columnRoleVisibility, // #1746 per-column role visibility (Pro)
                column_aggregations: columnAggregations, // TC_Formula_Service per-column totals-row aggregation
                column_data_bars: columnDataBars, // TC_Data_Bars_Service per-column value-bar config (#1731, Pro)
                column_detail_only: columnDetailOnly, // TC_Detail_Rows_Service per-column detail-only flag
                column_auto_merge: columnAutoMerge, // TC_Rowspan_Merge_Service per-column auto-merge flag (#518 slice 2)
                drilldown_columns: drilldownColumns, // TC_Drilldown_Filter_Service flat list (#568 slice 2)
                // #2323 — arbitrary cell merges. JSON string from the builder
                // textarea; server sanitizer validates via TC_Cell_Merge_Service.
                cell_merges: $('textarea[name="cell_merges"]').val() || '[]',
                // #501 slice 2 — row-expiry admin opt-in. Posted at top-level
                // of formData so they land in $_POST where save_table's
                // sanitizer block (class-tc-admin.php:709-726) reads them
                // straight from $data. Slice 1 (v4.7.30) shipped TC_Row_Expiry_
                // Service; the frontend gate in assets/js/frontend.js
                // (applyRowExpiry) already consumes these four keys via the
                // table config emitted by templates/table.php.
                expiry_field_id:   $('select[name="expiry_field_id"]').val() || '',
                expiry_behavior:   $('select[name="expiry_behavior"]').val() || 'hide',
                expiry_grace_days: parseInt($('input[name="expiry_grace_days"]').val(), 10) || 0,
                expiry_inverse:    $('input[name="expiry_inverse"]').is(':checked') ? true : false,
                // #1598 — computed columns repeater. Collected by
                // admin/computed-columns.js; sanitized server-side via
                // TC_Formula_Service::sanitize_computed_columns().
                computed_columns: JSON.stringify(
                    (window.TC_TableBuilder && typeof window.TC_TableBuilder.collectComputedColumns === 'function')
                        ? window.TC_TableBuilder.collectComputedColumns()
                        : []
                ),
                // #519 slice 3 — scheduled export admin opt-in. Posted at
                // top-level so class-tc-admin.php sanitizers (line ~760)
                // can read them from $data and reconcile the WP-Cron
                // queue via TC_Scheduled_Export_Service.
                // #2338 — row grouping. Same top-level contract: the
                // class-tc-admin.php sanitizers read these from $data.
                group_by_column:          $('select[name="group_by_column"]').val() || '',
                group_default_collapsed:  $('input[name="group_default_collapsed"]').is(':checked') ? true : false,
                group_label_prefix:       $('input[name="group_label_prefix"]').val() || '',
                scheduled_export_enabled:           $('input[name="scheduled_export_enabled"]').is(':checked') ? true : false,
                scheduled_export_recurrence:        $('select[name="scheduled_export_recurrence"]').val() || 'daily',
                scheduled_export_format:            $('select[name="scheduled_export_format"]').val() || 'csv',
                scheduled_export_filename_pattern:  $('input[name="scheduled_export_filename_pattern"]').val() || '',
                scheduled_export_email_recipients:  $('input[name="scheduled_export_email_recipients"]').val() || '',
                scheduled_export_honor_filters:     $('input[name="scheduled_export_honor_filters"]').is(':checked') ? true : false,
                // #526 slice 2/3 — fallback image URL picked via GT Media
                // Folder adapter (supports folder-aware media browsers).
                default_image_fallback_url:         $('input[name="default_image_fallback_url"]').val() || '',
                // #560 slice 2 — server-side pagination admin opt-in.
                server_side_pagination:             $('input[name="server_side_pagination"]').is(':checked') ? true : false,
                default_page_size:                  parseInt($('input[name="default_page_size"]').val(), 10) || 50,
                // #562 slice 2 — pivot view admin opt-in (single-aggregate UI).
                pivot_mode:          $('select[name="pivot_mode"]').val() || 'raw',
                pivot_group_by:      $('select[name="pivot_group_by"]').val() || '',
                pivot_aggregate_col: $('select[name="pivot_aggregate_col"]').val() || '', // legacy pre-#1617 builders
                pivot_aggregate_op:  $('select[name="pivot_aggregate_op"]').val() || 'sum', // legacy pre-#1617 builders
                // #1617 — multi-aggregate repeater. Collected by
                // admin/pivot-aggregates.js; TC_Admin composes via
                // TC_Pivot_Service::parse_aggregates_input + normalize.
                pivot_aggregates: JSON.stringify(
                    (window.TC_TableBuilder && typeof window.TC_TableBuilder.collectPivotAggregates === 'function')
                        ? window.TC_TableBuilder.collectPivotAggregates()
                        : []
                ),
                table_password: $('#gt-table-password').val() || '', // #607 slice 2 — empty = keep existing; '__GT_CLEAR__' = remove
                send_email_recipient_field: $('#gt-send-email-recipient-field').val() || '', // #618 slice 5
                per_row_webhook_url: $('#gt-per-row-webhook-url').val() || '', // #618 slice 5
                cascading_filter_parent_field: $('#gt-cascading-filter-parent').val() || '', // #599 slice 2
                cascading_filter_child_field: $('#gt-cascading-filter-child').val() || '', // #599 slice 2
                responsive_settings: this.collectResponsiveSettings(),
                settings: {
                    show_search: $('input[name="show_search"]').is(':checked') ? true : false,
                    show_pagination: $('input[name="show_pagination"]').is(':checked') ? true : false,
                    show_selection: $('input[name="show_selection"]').is(':checked') ? true : false,
                    show_bulk_actions: $('input[name="show_bulk_actions"]').is(':checked') ? true : false,
                    show_advanced_filters: $('input[name="show_advanced_filters"]').is(':checked') ? true : false,
                    show_entry_info: $('input[name="show_entry_info"]').is(':checked') ? true : false,
                    show_add_entry: $('input[name="show_add_entry"]').is(':checked') ? true : false,
                    enable_frontend_editing: $('input[name="enable_frontend_editing"]').is(':checked') ? true : false,
                    enable_delete: $('input[name="enable_delete"]').is(':checked') ? true : false,
                    sticky_header: $('input[name="sticky_header"]').is(':checked') ? true : false,
                frozen_top_rows: parseInt($('input[name="frozen_top_rows"]').val(), 10) || 1, // TC_Sticky_Rows_Service (#544 slice 2; clamped server-side to 1..10)
                sync_direction: $('select[name="sync_direction"]').val() || 'pull_only', // #517 slice 4b — Airtable sync direction (pull_only / push_only / two_way; server whitelists)
                airtable_record_id_field: $('input[name="airtable_record_id_field"]').val() || '', // #517 slice 4c — GF field id that holds the Airtable record id (server sanitizes alphanumeric + dot)
                    freeze_first_column: $('input[name="freeze_first_column"]').is(':checked') ? true : false,
                    responsive_table: $('input[name="responsive_table"]').is(':checked') ? true : false,
                    responsive_mode: $('select[name="responsive_mode"]').val() || 'basic',
                    // flip_breakpoint (#653) — no admin UI today; reading from
                    // the non-existent input selector saved a hard-coded 768
                    // on every re-save and clobbered any value set via the
                    // gravity_tables_table_config filter or direct DB edit.
                    // Same dead-selector pattern as #651 (bulk_actions) and
                    // #652 (top_n_*). Server-side array_merge preserves
                    // existing values when the key is omitted from the
                    // payload. Restore once a real input lands in the view;
                    // template default remains 768 in the meantime.
                    persistent_filters: $('input[name="persistent_filters"]').is(':checked') ? true : false,
                    show_deleted_entries: $('input[name="show_deleted_entries"]').is(':checked') ? true : false,
                    filter_user_entries: $('input[name="filter_user_entries"]').is(':checked') ? true : false,
                    show_column_totals: $('input[name="show_column_totals"]').is(':checked') ? true : false,
                    // #2340 — index column: 1..n display-order counter.
                    show_index_column: $('input[name="show_index_column"]').is(':checked') ? true : false,
                    index_column_label: $('input[name="index_column_label"]').val() || '#',
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
                    // #2369 — opt-in shortcode expansion in manual-table cells.
                    manual_render_shortcodes: $('#gt-manual-render-shortcodes').is(':checked'),
                    // #2002 — Google Sheets data source URL.
                    google_sheets_url:  $('input[name="google_sheets_url"]').val() || '',
                    // #2004 — XML data source URL + repeating-element path.
                    xml_url:            $('input[name="xml_url"]').val() || '',
                    xml_row_path:       $('input[name="xml_row_path"]').val() || '',
                    // #2010 — live CSV data source URL.
                    csv_url:            $('input[name="csv_url"]').val() || '',
                    xlsx_url:           $('input[name="xlsx_url"]').val() || '',
                    // #2003 — External database connection index + read-only query.
                    external_db_connection:     $('select[name="external_db_connection"]').val() || '',
                    external_db_query:          $('textarea[name="external_db_query"]').val() || '',
                    // #2254 — per-table public render opt-in for external DB tables.
                    external_db_public_render:  $('input[name="external_db_public_render"]').is(':checked') ? true : false,
                    // #985 v4.168.0 — JSON data source payload (slice 3b-2 of #512).
                    // Convert the textarea "Key: Value" lines back to an associative
                    // array for the server; the server-side sanitizer also handles
                    // the parse, but we send the structured form so the round-trip
                    // is identical to the test-connection preview.
                    json_url:           $('input[name="json_url"]').val() || '',
                    json_headers:       (function () {
                        var raw = $('textarea[name="json_headers_raw"]').val() || '';
                        var headers = {};
                        var lines = raw.split(/\r\n|\r|\n/);
                        for (var i = 0; i < lines.length; i++) {
                            var line = lines[i].trim();
                            if (!line) { continue; }
                            var pos = line.indexOf(':');
                            if (pos <= 0) { continue; }
                            var key = line.substring(0, pos).trim();
                            var val = line.substring(pos + 1).trim();
                            if (key) { headers[key] = val; }
                        }
                        return headers;
                    })(),
                    json_dot_path:      $('input[name="json_dot_path"]').val() || '',
                    json_refresh_minutes: parseInt($('input[name="json_refresh_minutes"]').val(), 10) || 30,
                    // #992 v4.172.0 — Airtable wizard payload (phase B of #517).
                    // PAT only sent when the user has typed something; the server
                    // treats empty as "keep existing" so existing creds aren't
                    // wiped by an unrelated edit.
                    airtable_pat:      $('input[name="airtable_pat"]').val() || '',
                    airtable_base_id:  $('input[name="airtable_base_id"]').val() || '',
                    airtable_table_id: $('input[name="airtable_table_id"]').val() || '',
                    airtable_view:     $('input[name="airtable_view"]').val() || '',
                    // #998 v4.175.0 — Notion data source payload (phase 1 of #592).
                    notion_token:       $('input[name="notion_token"]').val() || '',
                    notion_database_id: $('input[name="notion_database_id"]').val() || '',
                    // #2366 — manual data source: initial dimension hints + column defs.
                    // manual_columns is owned by the server on first save (provisioned
                    // via TC_Manual_Rows_Service::provision_empty_grid); subsequent saves
                    // post the persisted value back so it isn't clobbered.
                    manual_initial_rows: parseInt($('input[name="manual_initial_rows"]').val(), 10) || 5,
                    manual_initial_cols: parseInt($('input[name="manual_initial_cols"]').val(), 10) || 3,
                    // #2367 — grid editor: collect updated column labels so header edits
                    // are included in the main save payload. The grid editor may also
                    // call gt_save_manual_rows independently (for row data); this key
                    // only carries label changes that came through the column-header inputs.
                    manual_columns_labels: (function () {
                        var $editor = $('#gt-manual-grid-editor');
                        if (!$editor.length || !$editor.data('gtGridEditor')) { return {}; }
                        var editor = $editor.data('gtGridEditor');
                        return editor.model.getColumnLabelsMap();
                    })(),
                    // #1010 v4.181.0 — sync_direction (phase 1 of #613 two-way sync).
                    sync_direction:     $('select[name="sync_direction"]').val() || 'pull',
                    bulk_actions: bulkActions,
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
                    // Top-N (#347 / #660) — UI restored in v4.8.8.
                    // Read directly when the inputs exist on the page; the
                    // selectors are now real (admin/views/table-builder.php
                    // section 3 → Display Options → Top-N rows). Pre-v4.8.8
                    // tables that had developer-hook-set values continue
                    // to work since the server-side save merges incoming
                    // with existing on overlap.
                    top_n_count:     parseInt($('input[name="top_n_count"]').val(), 10) || 0,
                    top_n_column:    $('select[name="top_n_column"]').val() || '',
                    top_n_direction: $('select[name="top_n_direction"]').val() || 'desc',
                    // #634 — token-driven export filename pattern. Server-side
                    // sanitizer at class-tc-admin.php strips path separators
                    // and parent-traversal. Empty string falls back to the
                    // legacy hardcoded pattern at runtime.
                    export_filename_pattern: $('input[name="export_filename_pattern"]').val() || '',
                    // #547 — schema.org JSON-LD. Server-side sanitizer
                    // delegates to TC_Schema_Service::normalize which
                    // whitelists the schema_type. Off = no JSON-LD emitted.
                    schema: {
                        schema_type: $('select[name="schema[schema_type]"]').val() || 'off'
                    },
                    // TC_Pagination_Label_Service wire-up — five customizable
                    // pagination/info labels. Empty string per field means
                    // "use the plugin default at runtime" (the service's
                    // get_labels() merges over defaults, so empty keys do
                    // not override). Server-side sanitizer applies
                    // sanitize_text_field to each.
                    info_text:      $('input[name="info_text"]').val() || '',
                    previous_label: $('input[name="previous_label"]').val() || '',
                    next_label:     $('input[name="next_label"]').val() || '',
                    no_results:     $('input[name="no_results"]').val() || '',
                    loading:        $('input[name="loading"]').val() || '',
                    // #565 — multi-column sort toggle. Service default is ON.
                    enable_multi_sort: $('input[name="enable_multi_sort"]').is(':checked'),
                    // #531 — per-table print settings. Server-side sanitizer
                    // delegates to TC_Print_Settings_Service::normalize so the
                    // paper_size whitelist + bool coercion + excluded_columns
                    // string-array filter live in one place. Disabled = no
                    // inline style block emitted at runtime.
                    print_settings: {
                        enabled:          $('input[name="print_settings[enabled]"]').is(':checked'),
                        paper_size:       $('select[name="print_settings[paper_size]"]').val() || 'letter',
                        repeat_header:    $('input[name="print_settings[repeat_header]"]').is(':checked'),
                        row_striping:     $('input[name="print_settings[row_striping]"]').is(':checked'),
                        excluded_columns: $('select[name="print_settings[excluded_columns][]"]').val() || []
                    },
                    // TC_Border_Service preset (classic / rows_only / none / outer_only).
                    border_preset: $('select[name="border_preset"]').val() || 'classic',
                    // TC_URL_Filter_Service master toggle (off by default).
                    allow_url_filters: $('input[name="allow_url_filters"]').is(':checked'),
                    // TC_Row_Height_Service per-table row height + overflow mode.
                    row_height:        $('input[name="row_height"]').val() || '',
                    header_height:     $('input[name="header_height"]').val() || '',
                    row_overflow_mode: $('select[name="row_overflow_mode"]').val() || 'ellipsis',
                    // TC_Default_Sort_Service per-table default sort column + direction.
                    default_sort_column:    $('select[name="default_sort_column"]').val() || '',
                    default_sort_direction: $('select[name="default_sort_direction"]').val() || 'asc',
                    // Persistent filters via localStorage (off by default).
                    persist_filters_localstorage: $('input[name="persist_filters_localstorage"]').is(':checked'),
                    // TC_Collapsible_Service — whole-table collapse toggle.
                    collapsible_enabled:           $('input[name="collapsible_enabled"]').is(':checked'),
                    collapsible_default_collapsed: $('input[name="collapsible_default_collapsed"]').is(':checked'),
                    // Visitor-side length selector ("Show N entries" dropdown).
                    show_length_selector:    $('input[name="show_length_selector"]').is(':checked'),
                    length_selector_options: $('input[name="length_selector_options"]').val() || '',
                    // #1743 — auto-refresh on interval (Free).
                    auto_refresh_interval: parseInt($('input[name="auto_refresh_interval"]').val(), 10) || 0,
                    // #1744 — column visibility picker (Free).
                    show_column_picker: $('input[name="show_column_picker"]').is(':checked'),
                    // #1747 — one-click entry duplicate (Pro).
                    enable_duplicate: $('input[name="enable_duplicate"]').is(':checked'),
                    // #1748 — email alert rules (Pro).
                    email_alert_rules: (function() {
                        var rules = [];
                        $('#gt-alert-rules-container .gt-alert-rule').each(function() {
                            var $r = $(this);
                            var fieldId   = $r.find('[name="alert_field_id"]').val()   || '';
                            var operator  = $r.find('[name="alert_operator"]').val()   || '';
                            var threshold = $r.find('[name="alert_threshold"]').val()  || '';
                            var recipient = $r.find('[name="alert_recipient"]').val()  || '';
                            if (fieldId && operator && threshold && recipient) {
                                rules.push({ field_id: fieldId, operator: operator, threshold: threshold, recipient: recipient });
                            }
                        });
                        return rules;
                    })()
                },
                action: 'gt_save_table',
                nonce: $('input[name="nonce"]').val() || (typeof gtAdmin !== 'undefined' ? gtAdmin.nonce : '')
            };

            // Debug: Log what we're saving
            //console.log('Saving table settings:', formData.settings);
            //console.log('GT Admin DEBUG: Responsive settings being saved:', formData.responsive_settings);
            //console.log('GT Admin DEBUG: Responsive mode being saved:', formData.settings.responsive_mode);

            // Warn if the serialized payload is very large (> 6 MB).
            // PHP's default post_max_size is 8 MB; serialization overhead can push
            // a 6 MB raw payload over the limit after URL encoding.
            var payloadStr = $.param(formData);
            var payloadBytes = payloadStr.length; // rough byte estimate
            var $sizeWarning = $('#gt-save-size-warning');
            if (!$sizeWarning.length) {
                $sizeWarning = $('<div id="gt-save-size-warning" class="notice notice-warning gt-save-size-warning" style="display:none"><p></p></div>').insertBefore($clickedButton.closest('p, .gt-save-row').first());
            }
            if (payloadBytes > 6 * 1024 * 1024) {
                $sizeWarning.find('p').text('Warning: this table is very large (' + Math.round(payloadBytes / 1024) + ' KB). If saving fails, ask your host to increase post_max_size or reduce the number of columns.');
                $sizeWarning.show();
            } else {
                $sizeWarning.hide();
            }

            // Show saving message - get original text first
            var originalText = $clickedButton.text().trim();
            $clickedButton.prop('disabled', true).text(gtAdmin.strings.saving || 'Saving...');

            // Send AJAX request — 30-second timeout prevents an indefinite spinner (#434)
            $.ajax({
                url: gtAdmin.ajax_url,
                method: 'POST',
                data: formData,
                timeout: 30000,
                success: function (response) {
                    if (response.success) {
                        // Update table ID if this was a new table
                        if (response.data.table_id) {
                            $('input[name="table_id"]').val(response.data.table_id);
                            // Update the shortcode to show the correct ID
                            self.generatePreview();
                        }

                        // #2367 — if a manual grid editor is active and dirty, save
                        // its rows via gt_save_manual_rows. This is a fire-and-forget
                        // secondary POST; failures surface a console warning but don't
                        // block the primary save success UX.
                        var $editor = $('#gt-manual-grid-editor');
                        var gridEditor = $editor.length ? $editor.data('gtGridEditor') : null;
                        if (gridEditor && gridEditor.model && gridEditor.model.isDirty()) {
                            var resolvedTableId = $('input[name="table_id"]').val() || '';
                            if (resolvedTableId) {
                                $.ajax({
                                    url: gtAdmin.ajax_url,
                                    method: 'POST',
                                    data: {
                                        action: 'gt_save_manual_rows',
                                        nonce:  gtAdmin.nonce,
                                        table_id: resolvedTableId,
                                        rows:   JSON.stringify(gridEditor.model.getRows()),
                                        manual_columns: JSON.stringify(
                                            gridEditor.model.getColumns().map(function (c) {
                                                return { key: c.key, label: c.label, hidden: c.hidden || false };
                                            })
                                        )
                                    },
                                    success: function (rowsResp) {
                                        if (rowsResp.success) {
                                            gridEditor.model.resetDirty();
                                            $editor.attr('data-dirty', '0');
                                            $editor.data('gtGridEditor', gridEditor);
                                        } else {
                                            console.warn('[TC] gt_save_manual_rows failed:', rowsResp);
                                        }
                                    },
                                    error: function () {
                                        console.warn('[TC] gt_save_manual_rows network error');
                                    }
                                });
                            }
                        }

                        // Show success message and re-enable button
                        $clickedButton.prop('disabled', false).text(gtAdmin.strings.saved || 'Saved!');

                        // Reset button text after short delay instead of redirecting
                        setTimeout(function () {
                            $clickedButton.text(originalText);
                        }, 2000);
                    } else {
                        // Show error in status banner, not alert()
                        var errMsg = (response.data && response.data.message) || 'Error saving table';
                        $('#gt-save-status').text(errMsg);
                        $clickedButton.prop('disabled', false).text(originalText);
                    }
                },
                error: function (xhr, status) {
                    var errMsg = status === 'timeout'
                        ? 'Save timed out — server did not respond. Please try again.'
                        : 'Could not reach the server. Please check your connection and try again.';
                    $('#gt-save-status').text(errMsg);
                    $clickedButton.prop('disabled', false).text(originalText);
                }
            });
        }

    });

})(jQuery);
