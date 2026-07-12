/**
 * TableCrafter — admin/load-table-data.js
 *
 * Sixth slice of #842 (filed as #955). The load-side AJAX flow —
 * loadTableData receives a saved table_config JSON from the server and
 * hydrates the builder UI; applySavedSettings restores per-field config
 * + per-column alignments + conditional formatting + responsive settings.
 *
 *   - loadTableData: top-level loader called from init() when editing an
 *     existing table.
 *   - applySavedSettings: comprehensive field-config restoration.
 *
 * Dead-code cleanup in this slice: the prior duplicate applySavedSettings
 * stub at admin.js:739 (2,719 chars, no column_alignments / conditional
 * formatting / vertical alignment / per-column visibility handling) was
 * shadowed by the comprehensive 16,952-char definition at admin.js:1409
 * due to JS object-literal duplicate-key semantics. Only the live version
 * is moved here; the dead stub is deleted.
 *
 * @since 4.154.0
 */
(function ($) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        loadTableData: function (tableData) {
            // Store table data for later use
            this.savedTableData = tableData;

            // Populate form with existing data
            $('#table-title').val(tableData.title);
            $('#gravity-form').val(tableData.form_id).trigger('change');

            // Load settings
            if (tableData.settings) {
                // Debug: Log the loaded settings for troubleshooting
                //console.log('Loading table settings:', tableData.settings);

                // Check for specific checkbox values
                //console.log('Individual setting values:');
                //console.log('show_search:', tableData.settings.show_search, typeof tableData.settings.show_search);
                //console.log('show_selection:', tableData.settings.show_selection, typeof tableData.settings.show_selection);
                //console.log('show_bulk_actions:', tableData.settings.show_bulk_actions, typeof tableData.settings.show_bulk_actions);
                //console.log('enable_frontend_editing:', tableData.settings.enable_frontend_editing, typeof tableData.settings.enable_frontend_editing);
                // Set all checkboxes based on saved settings with explicit default handling
                $('input[name="show_search"]').prop('checked', tableData.settings.show_search !== undefined ? tableData.settings.show_search : true);
                $('input[name="show_pagination"]').prop('checked', tableData.settings.show_pagination !== undefined ? tableData.settings.show_pagination : true);
                $('input[name="show_entry_info"]').prop('checked', tableData.settings.show_entry_info !== undefined ? tableData.settings.show_entry_info : true);
                $('input[name="show_add_entry"]').prop('checked', tableData.settings.show_add_entry !== undefined ? tableData.settings.show_add_entry : true);
                $('input[name="show_selection"]').prop('checked', tableData.settings.show_selection !== undefined ? tableData.settings.show_selection : true);
                $('input[name="show_bulk_actions"]').prop('checked', tableData.settings.show_bulk_actions !== undefined ? tableData.settings.show_bulk_actions : true);
                $('input[name="show_advanced_filters"]').prop('checked', tableData.settings.show_advanced_filters !== undefined ? tableData.settings.show_advanced_filters : true);
                $('input[name="enable_frontend_editing"]').prop('checked', tableData.settings.enable_frontend_editing !== undefined ? tableData.settings.enable_frontend_editing : false);
                $('input[name="enable_delete"]').prop('checked', tableData.settings.enable_delete !== undefined ? tableData.settings.enable_delete : false);
                // Debug: Log filter checkbox values from database
                //console.log('GT Admin DEBUG: Loading filter checkboxes from database:');
                //console.log('  show_deleted_entries in DB:', tableData.settings.show_deleted_entries, '(type:', typeof tableData.settings.show_deleted_entries, ')');
                //console.log('  filter_user_entries in DB:', tableData.settings.filter_user_entries, '(type:', typeof tableData.settings.filter_user_entries, ')');

                $('input[name="show_deleted_entries"]').prop('checked', tableData.settings.show_deleted_entries !== undefined ? tableData.settings.show_deleted_entries : false);
                $('input[name="filter_user_entries"]').prop('checked', tableData.settings.filter_user_entries !== undefined ? tableData.settings.filter_user_entries : false);
                $('input[name="show_column_totals"]').prop('checked', tableData.settings.show_column_totals !== undefined ? tableData.settings.show_column_totals : false);
                // Load export toolbar + processing-mode + template-type +
                // data-source-type (#654). Previously these were saved-but-
                // never-loaded (and never-saved either; v4.7.201 fixes both).
                $('input[name="show_toolbar_copy"]').prop('checked',  tableData.settings.show_toolbar_copy  !== undefined ? tableData.settings.show_toolbar_copy  : false);
                $('input[name="show_toolbar_csv"]').prop('checked',   tableData.settings.show_toolbar_csv   !== undefined ? tableData.settings.show_toolbar_csv   : false);
                $('input[name="show_toolbar_excel"]').prop('checked', tableData.settings.show_toolbar_excel !== undefined ? tableData.settings.show_toolbar_excel : false);
                $('input[name="show_pdf_export"]').prop('checked',    tableData.settings.show_pdf_export    !== undefined ? tableData.settings.show_pdf_export    : false);
                if (tableData.settings.processing_mode)  $('select[name="processing_mode"]').val(tableData.settings.processing_mode);
                if (tableData.settings.template_type)    $('select[name="template_type"]').val(tableData.settings.template_type);
                if (tableData.settings.data_source_type) $('select[name="data_source_type"]').val(tableData.settings.data_source_type);

                // Debug: Verify checkboxes were set correctly
                //console.log('GT Admin DEBUG: Checkboxes set to:');
                //console.log('  show_deleted_entries checkbox:', $('input[name="show_deleted_entries"]').is(':checked'));
                //console.log('  filter_user_entries checkbox:', $('input[name="filter_user_entries"]').is(':checked'));

                // Set dropdown values
                if (tableData.settings.per_page) {
                    $('select[name="per_page"]').val(tableData.settings.per_page);
                }
                // Load allowed user roles (backward compatibility with user_role_filter)
                var allowedRoles = tableData.settings.allowed_user_roles || [];
                if (allowedRoles.length === 0 && tableData.settings.user_role_filter) {
                    allowedRoles = [tableData.settings.user_role_filter];
                }
                if (allowedRoles.length > 0) {
                    $('select[name="allowed_user_roles[]"]').val(allowedRoles);
                }

                // Load top-N rows settings (#347 / #660). top_n_column is
                // a text input (not a select) so the field id matches the
                // markup at admin/views/table-builder.php.
                if (tableData.settings.top_n_count) {
                    $('input[name="top_n_count"]').val(tableData.settings.top_n_count);
                }
                if (tableData.settings.top_n_column) {
                    $('select[name="top_n_column"]').val(tableData.settings.top_n_column);
                }
                if (tableData.settings.top_n_direction) {
                    $('select[name="top_n_direction"]').val(tableData.settings.top_n_direction);
                }

                // #634 — load saved export filename pattern. Empty string
                // means "use legacy default" at runtime.
                if (typeof tableData.settings.export_filename_pattern !== 'undefined') {
                    $('input[name="export_filename_pattern"]').val(tableData.settings.export_filename_pattern || '');
                }

                // #547 — load schema.org JSON-LD type. settings.schema is
                // the normalized map produced by TC_Schema_Service::normalize.
                if (tableData.settings.schema && tableData.settings.schema.schema_type) {
                    $('select[name="schema[schema_type]"]').val(tableData.settings.schema.schema_type);
                }

                // TC_Pagination_Label_Service — load the five pagination
                // labels. Each is an independent text input; empty value
                // is preserved as "use plugin default at runtime".
                ['info_text', 'previous_label', 'next_label', 'no_results', 'loading'].forEach(function (key) {
                    if (typeof tableData.settings[key] !== 'undefined') {
                        $('input[name="' + key + '"]').val(tableData.settings[key] || '');
                    }
                });

                // #565 — load enable_multi_sort. Service default is ON; only
                // explicitly-saved false flips the checkbox off.
                if (typeof tableData.settings.enable_multi_sort !== 'undefined') {
                    $('input[name="enable_multi_sort"]').prop('checked', !!tableData.settings.enable_multi_sort);
                }

                // #531 — load per-table print settings. Service defaults
                // (enabled=false, paper=letter, repeat=true, striping=true,
                // excluded=[]) drive the form's initial state when the
                // table has no print_settings saved yet.
                if (tableData.settings.print_settings && typeof tableData.settings.print_settings === 'object') {
                    var ps = tableData.settings.print_settings;
                    $('input[name="print_settings[enabled]"]').prop('checked', !!ps.enabled);
                    if (ps.paper_size) {
                        $('select[name="print_settings[paper_size]"]').val(ps.paper_size);
                    }
                    if (typeof ps.repeat_header !== 'undefined') {
                        $('input[name="print_settings[repeat_header]"]').prop('checked', !!ps.repeat_header);
                    }
                    if (typeof ps.row_striping !== 'undefined') {
                        $('input[name="print_settings[row_striping]"]').prop('checked', !!ps.row_striping);
                    }
                    if (Array.isArray(ps.excluded_columns)) {
                        $('select[name="print_settings[excluded_columns][]"]').val(ps.excluded_columns);
                    }
                }

                // TC_Border_Service preset. Default classic.
                if (typeof tableData.settings.border_preset !== 'undefined') {
                    $('select[name="border_preset"]').val(tableData.settings.border_preset || 'classic');
                }

                // TC_URL_Filter_Service toggle. Off by default.
                if (typeof tableData.settings.allow_url_filters !== 'undefined') {
                    $('input[name="allow_url_filters"]').prop('checked', !!tableData.settings.allow_url_filters);
                }

                // #2254 — per-table external DB public render opt-in. Off by default.
                if (typeof tableData.settings.external_db_public_render !== 'undefined') {
                    $('input[name="external_db_public_render"]').prop('checked', !!tableData.settings.external_db_public_render);
                }

                // #2369 — shortcode expansion opt-in for manual-table cells. Off by default.
                if (typeof tableData.settings.manual_render_shortcodes !== 'undefined') {
                    $('input[name="manual_render_shortcodes"]').prop('checked', !!tableData.settings.manual_render_shortcodes);
                }

                // TC_Row_Height_Service — three flat string settings.
                ['row_height', 'header_height'].forEach(function (key) {
                    if (typeof tableData.settings[key] !== 'undefined') {
                        $('input[name="' + key + '"]').val(tableData.settings[key] || '');
                    }
                });
                if (typeof tableData.settings.row_overflow_mode !== 'undefined') {
                    $('select[name="row_overflow_mode"]').val(tableData.settings.row_overflow_mode || 'ellipsis');
                }

                // TC_Default_Sort_Service — column + direction.
                if (typeof tableData.settings.default_sort_column !== 'undefined') {
                    $('select[name="default_sort_column"]').val(tableData.settings.default_sort_column || '');
                }
                if (typeof tableData.settings.default_sort_direction !== 'undefined') {
                    $('select[name="default_sort_direction"]').val(tableData.settings.default_sort_direction || 'asc');
                }

                // Persistent filters via localStorage (off by default).
                if (typeof tableData.settings.persist_filters_localstorage !== 'undefined') {
                    $('input[name="persist_filters_localstorage"]').prop('checked', !!tableData.settings.persist_filters_localstorage);
                }

                // TC_Collapsible_Service — both flags off by default.
                if (typeof tableData.settings.collapsible_enabled !== 'undefined') {
                    $('input[name="collapsible_enabled"]').prop('checked', !!tableData.settings.collapsible_enabled);
                }
                if (typeof tableData.settings.collapsible_default_collapsed !== 'undefined') {
                    $('input[name="collapsible_default_collapsed"]').prop('checked', !!tableData.settings.collapsible_default_collapsed);
                }

                // Visitor-side length selector toggle + options.
                if (typeof tableData.settings.show_length_selector !== 'undefined') {
                    $('input[name="show_length_selector"]').prop('checked', !!tableData.settings.show_length_selector);
                }
                if (typeof tableData.settings.length_selector_options !== 'undefined') {
                    $('input[name="length_selector_options"]').val(tableData.settings.length_selector_options || '');
                }

                // Load responsive mode — valid values: 'disabled','basic','enhanced','flip' (#348)
                if (tableData.settings.responsive_mode) {
                    var validModes = ['disabled', 'basic', 'enhanced', 'flip'];
                    var mode = validModes.includes(tableData.settings.responsive_mode)
                        ? tableData.settings.responsive_mode : 'basic';
                    $('select[name="responsive_mode"]').val(mode);
                } else {
                    $('select[name="responsive_mode"]').val('disabled');
                }

                // Load flip breakpoint (#348)
                if (tableData.settings.flip_breakpoint) {
                    $('input[name="flip_breakpoint"]').val(tableData.settings.flip_breakpoint);
                }

                // Load bulk actions (these are stored as an array in bulk_actions)
                if (tableData.settings.bulk_actions && Array.isArray(tableData.settings.bulk_actions)) {
                    $('input[name="bulk_delete"]').prop('checked', tableData.settings.bulk_actions.includes('delete'));
                    $('input[name="bulk_export"]').prop('checked', tableData.settings.bulk_actions.includes('export'));
                    $('input[name="bulk_edit"]').prop('checked', tableData.settings.bulk_actions.includes('edit'));
                }

                // Load selected fields
                if (tableData.settings.columns && Array.isArray(tableData.settings.columns)) {
                    this.selectedFields = tableData.settings.columns;
                } else if (tableData.settings.selected_fields && Array.isArray(tableData.settings.selected_fields)) {
                    this.selectedFields = tableData.settings.selected_fields;
                }

                // Load field configurations will happen after form fields are loaded
            }

            // Always generate preview after loading table data
            setTimeout(function () {
                this.generatePreview();
            }.bind(this), 200);
        },

        applySavedSettings: function (settings) {
            // Load comprehensive field configurations first
            if (settings.field_configurations) {
                $.each(settings.field_configurations, function (fieldId, config) {
                    if (this.formFields[fieldId]) {
                        if (config.editable !== undefined) this.formFields[fieldId].editable = config.editable;
                        if (config.sortable !== undefined) this.formFields[fieldId].sortable = config.sortable;
                        if (config.filterable !== undefined) this.formFields[fieldId].filterable = config.filterable;
                        if (config.custom_label !== undefined) this.formFields[fieldId].custom_label = config.custom_label;
                        if (config.width !== undefined) this.formFields[fieldId].width = config.width;
                    }
                }.bind(this));
            }

            // Load per-column alignment from settings.column_alignments (#661).
            // Stored at the top level of the saved settings rather than inside
            // field_configurations because the server-side sanitizer at
            // class-tc-admin.php:661 takes a flat field_id => alignment map.
            if (settings.column_alignments) {
                $.each(settings.column_alignments, function (fieldId, alignment) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].alignment = alignment;
                    }
                }.bind(this));
            }

            // Load per-column wrap mode from settings.column_wrap_modes (#662).
            // Same shape as column_alignments — flat field_id => mode map.
            // TC_Wrap_Mode_Service::sanitize_map omits 'default' values, so
            // any field id not in this map should fall back to 'default' in
            // the modal load above.
            if (settings.column_wrap_modes) {
                $.each(settings.column_wrap_modes, function (fieldId, mode) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].wrap_mode = mode;
                    }
                }.bind(this));
            }

            // Load per-column vertical alignment from
            // settings.column_vertical_alignments (#663). Same flat shape —
            // sanitizer at class-tc-admin.php:735 whitelists top/middle/bottom
            // and coerces anything else to ''. Empty values fall back to the
            // browser default in the modal load above.
            if (settings.column_vertical_alignments) {
                $.each(settings.column_vertical_alignments, function (fieldId, va) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].vertical_alignment = va;
                    }
                }.bind(this));
            }

            // #549 slice 3: load per-cell vertical alignment overrides.
            if (settings.cell_vertical_alignments) {
                this.cellVerticalAlignments = settings.cell_vertical_alignments;
            } else {
                this.cellVerticalAlignments = {};
            }

            // Load per-column link settings from settings.column_link_settings
            // (#664). Nested object: {field_id => {link_target, link_color,
            // link_underline}}. Sanitizer at class-tc-admin.php:920 enforces
            // the whitelist for target and bool-validates underline.
            if (settings.column_link_settings) {
                $.each(settings.column_link_settings, function (fieldId, ls) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].link_settings = ls;
                    }
                }.bind(this));
            }

            // Load per-column cell types from settings.column_cell_types.
            // Flat field_id => cell_type string ('' or 'star_rating').
            if (settings.column_cell_types) {
                $.each(settings.column_cell_types, function (fieldId, cellType) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].cell_type = cellType;
                    }
                }.bind(this));
            }

            // Load per-column aggregations. Flat field_id => SUM/AVG/MIN/MAX/COUNT/COUNT_DISTINCT.
            if (settings.column_aggregations) {
                $.each(settings.column_aggregations, function (fieldId, agg) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].aggregation = agg;
                    }
                }.bind(this));
            }

            // Load per-column detail-only flags (#556).
            if (settings.column_detail_only) {
                $.each(settings.column_detail_only, function (fieldId, flag) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].detail_only = !!flag;
                    }
                }.bind(this));
            }

            // Load per-column auto-merge flags (#518 slice 2).
            if (settings.column_auto_merge) {
                $.each(settings.column_auto_merge, function (fieldId, flag) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].auto_merge_consecutive = !!flag;
                    }
                }.bind(this));
            }

            // #1731 — Data Bars (Pro). field_id => { enabled, color }.
            if (settings.column_data_bars) {
                $.each(settings.column_data_bars, function (fieldId, cfg) {
                    if (this.formFields[fieldId] && cfg) {
                        this.formFields[fieldId].data_bar_enabled = !!cfg.enabled;
                        this.formFields[fieldId].data_bar_color = cfg.color || '#3b82f6';
                    }
                }.bind(this));
            }

            // Load drilldown columns flat list, project onto per-field flags (#568 slice 2).
            if (settings.drilldown_columns && settings.drilldown_columns.length) {
                $.each(settings.drilldown_columns, function (i, fieldId) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].drilldown_enabled = true;
                    }
                }.bind(this));
            }

            // Load field configurations (legacy/redundant properties for backwards compatibility)
            if (settings.column_labels) {
                $.each(settings.column_labels, function (fieldId, label) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].custom_label = label;
                    }
                }.bind(this));
            }

            // Load other field properties
            if (settings.editable_fields && Array.isArray(settings.editable_fields)) {
                $.each(settings.editable_fields, function (index, fieldId) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].editable = true;
                    }
                }.bind(this));
            }

            if (settings.sortable_fields && Array.isArray(settings.sortable_fields)) {
                $.each(settings.sortable_fields, function (index, fieldId) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].sortable = true;
                    }
                }.bind(this));
            }

            if (settings.filterable_fields && Array.isArray(settings.filterable_fields)) {
                $.each(settings.filterable_fields, function (index, fieldId) {
                    if (this.formFields[fieldId]) {
                        this.formFields[fieldId].filterable = true;
                    }
                }.bind(this));
            }

            if (settings.lookup_fields) {
                try {
                    $.each(settings.lookup_fields, function (fieldId, lookupConfig) {
                        if (this.formFields[fieldId]) {
                            this.formFields[fieldId].lookup_enabled = true;
                            this.formFields[fieldId].lookup_type = lookupConfig.type || '';
                            this.formFields[fieldId].lookup_user_field = lookupConfig.user_field || 'display_name';
                            this.formFields[fieldId].lookup_user_roles = lookupConfig.user_roles || [];
                            this.formFields[fieldId].lookup_post_field = lookupConfig.post_field || 'post_title';
                            this.formFields[fieldId].lookup_table = lookupConfig.table || '';
                            this.formFields[fieldId].lookup_id_column = lookupConfig.id_column || '';
                            this.formFields[fieldId].lookup_display_column = lookupConfig.display_column || '';
                        }
                    }.bind(this));
                } catch (e) {
                    console.error('Error applying lookup fields:', e);
                }
            }

            // Load conditional formatting rules
            if (settings.conditional_formatting) {
                //console.log('GT Admin DEBUG: Applying saved conditional formatting settings:', settings.conditional_formatting);
                $.each(settings.conditional_formatting, function (fieldId, rules) {
                    if (this.formFields[fieldId]) {
                        // Check if field was recently updated (within last 5 seconds) to prevent overwriting
                        var recentlyUpdated = this.formFields[fieldId]._cf_last_updated &&
                            (Date.now() - this.formFields[fieldId]._cf_last_updated) < 5000;

                        if (recentlyUpdated) {
                            //console.log('GT Admin DEBUG: Skipping saved rules for field', fieldId, '- field was recently updated');
                        } else {
                            //console.log('GT Admin DEBUG: Loading saved rules for field', fieldId, ':', rules);
                            this.formFields[fieldId].conditional_formatting = rules;
                        }
                    }
                }.bind(this));
            }

            // Load filter configurations
            if (settings.filter_configurations) {
                //console.log('GT Admin DEBUG: Applying saved filter configurations:', settings.filter_configurations);
                $.each(settings.filter_configurations, function (fieldId, filterConfig) {
                    if (this.formFields[fieldId]) {
                        //console.log('GT Admin DEBUG: Loading filter config for field', fieldId, ':', filterConfig);
                        this.formFields[fieldId].filter_config = filterConfig;
                        //console.log('GT Admin DEBUG: Field', fieldId, 'now has filter_config:', !!this.formFields[fieldId].filter_config);
                    } else {
                        //console.log('GT Admin DEBUG: Field', fieldId, 'not found in formFields when trying to load filter config');
                    }
                }.bind(this));
            } else {
                //console.log('GT Admin DEBUG: No filter configurations found in settings to load');
            }

            // Load responsive settings
            if (settings.responsive_settings) {
                //console.log('GT Admin DEBUG: Applying saved responsive settings:', settings.responsive_settings);
                //console.log('GT Admin DEBUG: Available formFields:', Object.keys(this.formFields));
                $.each(settings.responsive_settings, function (fieldId, responsiveConfig) {
                    //console.log('GT Admin DEBUG: Processing responsive config for field', fieldId, 'type:', typeof fieldId);
                    if (this.formFields[fieldId]) {
                        //console.log('GT Admin DEBUG: Loading responsive config for field', fieldId, ':', responsiveConfig);
                        this.formFields[fieldId].responsive_settings = responsiveConfig;
                        //console.log('GT Admin DEBUG: Field', fieldId, 'now has responsive_settings:', this.formFields[fieldId].responsive_settings);
                    } else {
                        //console.log('GT Admin DEBUG: Field', fieldId, 'not found in formFields when trying to load responsive settings');
                        // Try converting field ID to string/number
                        var stringFieldId = String(fieldId);
                        var numberFieldId = parseInt(fieldId);
                        if (this.formFields[stringFieldId]) {
                            //console.log('GT Admin DEBUG: Found field with string ID:', stringFieldId);
                            /* c8 ignore next */
                            this.formFields[stringFieldId].responsive_settings = responsiveConfig;
                        } else if (this.formFields[numberFieldId]) {
                            //console.log('GT Admin DEBUG: Found field with number ID:', numberFieldId);
                            /* c8 ignore next */
                            this.formFields[numberFieldId].responsive_settings = responsiveConfig;
                        }
                    }
                }.bind(this));
            } else {
                //console.log('GT Admin DEBUG: No responsive settings found in settings to load');
            }

            // GLOBAL DEBUG FUNCTION - expose field state for debugging (available after settings load)
            window.gtDebugFieldState = function () {
                //console.log('=== GT FIELD OBJECT STATE DEBUG ===');
                //console.log('All formFields:', this.formFields);
                //console.log('Field 25 object:', this.formFields[25]);
                //console.log('Field 26 object:', this.formFields[26]);
                //console.log('Field 25 has filter_config:', !!this.formFields[25]?.filter_config);
                //console.log('Field 26 has filter_config:', !!this.formFields[26]?.filter_config);
                if (this.formFields[25]?.filter_config) {
                    //console.log('Field 25 filter_config:', this.formFields[25].filter_config);
                }
                if (this.formFields[26]?.filter_config) {
                    //console.log('Field 26 filter_config:', this.formFields[26].filter_config);
                }
                return {
                    field25: this.formFields[25],
                    field26: this.formFields[26],
                    allFields: this.formFields
                };
            }.bind(this);

            // Debug function for responsive settings inspection
            window.gtDebugResponsiveSettings = function (fieldId) {
                var admin = window.gravityTableBuilder || adminInstance;
                //console.log('=== GT Responsive Settings Debug ===');
                if (fieldId) {
                    //console.log('Field', fieldId, 'responsive_settings:', admin.formFields[fieldId]?.responsive_settings);
                } else {
                    //console.log('All fields with responsive settings:');
                    $.each(admin.formFields, function (id, field) {
                        if (field.responsive_settings) {
                            //console.log('Field', id, ':', field.responsive_settings);
                        }
                    });
                }
            };

            // Additional debug function for UI state inspection - store reference to this admin instance
            var adminInstance = this;
            window.gtDebugField26UI = function () {
                //console.log('=== GT Field 26 UI Debug ===');
                var admin = window.gravityTableBuilder || adminInstance;
                //console.log('Admin object available:', !!admin);
                //console.log('Admin formFields available:', !!(admin && admin.formFields));

                if (admin && admin.formFields && admin.formFields['26']) {
                    var field = admin.formFields['26'];
                    //console.log('Field 26 object:', field);
                    //console.log('Field 26 filterable:', field.filterable);
                    //console.log('Field 26 filter_config:', field.filter_config);
                    //console.log('Current config field ID:', admin.currentConfigFieldId);

                    // Check current UI state
                    //console.log('UI - Filterable checkbox exists:', $('#field-filterable').length > 0);
                    //console.log('UI - Filterable checkbox checked:', $('#field-filterable').is(':checked'));
                    //console.log('UI - Filter type field exists:', $('#field-filter-type').length > 0);
                    //console.log('UI - Filter type value:', $('#field-filter-type').val());
                    //console.log('UI - Filter placeholder value:', $('#field-filter-placeholder').val());

                    // Check if modal is for the right field
                    //console.log('Modal shows field ID:', admin.currentConfigFieldId);
                    //console.log('Modal shows field label:', $('#field-label').val());
                } else {
                    //console.log('Field 26 not found in formFields or admin not available');
                    //console.log('Available admin object keys:', admin ? Object.keys(admin) : 'No admin object');
                }
            };
        }

    });

})(jQuery);
