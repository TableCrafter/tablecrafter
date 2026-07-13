/**
 * TableCrafter - admin/field-config-modal.js
 *
 * Third slice of #842 (admin.js monolith split). Per-field configuration
 * modal lifecycle:
 *
 *   - openFieldConfig: populate modal with field-specific settings
 *   - loadFilterConfiguration / getIntelligentFilterDefaults: filter UI
 *   - loadResponsiveSettings / collectResponsiveSettings / cacheResponsiveSettings:
 *     responsive viewport settings (mobile/tablet/desktop visibility)
 *   - handleFilterTypeChange / updateFilteringOptionsForLookupField:
 *     dynamic filter-type switching
 *   - saveFieldConfig: persist modal state back to selectedFields config
 *   - closeFieldConfig: cleanup + close
 *
 * @since 4.151.0
 */
(function ($) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        openFieldConfig: function (fieldId) {
            //console.log('GT Admin DEBUG: === OPENING FIELD CONFIG ===');
            //console.log('GT Admin DEBUG: Field ID:', fieldId);
            //console.log('GT Admin DEBUG: Modal element exists:', $('#field-config-modal').length > 0);

            // Reset modal flags when opening
            this.modalClosing = false;
            this.modalOpening = true;
            //console.log('GT Admin: Set modalOpening to true, modalClosing to false');

            var field = this.formFields[fieldId];
            if (!field) {
                console.error('GT Admin DEBUG: Field not found:', fieldId);
                return;
            }

            //console.log('GT Admin DEBUG: Field object:', field);
            //console.log('GT Admin DEBUG: Field conditional formatting:', field.conditional_formatting);

            // Store current field ID for saving
            this.currentConfigFieldId = fieldId;

            // Display the actual field name
            var fieldName = field.id === 'date_created' ? 'date_created' : field.label + ' (ID: ' + field.id + ')';
            $('#field-name-display').text(fieldName);

            // Populate modal with field data
            $('#field-label').val(field.custom_label || field.label);
            // Default to sortable unless explicitly set to false
            $('#field-sortable').prop('checked', field.sortable !== false);
            // Default editable to true unless it's auto-generated fields (entry_id, date_created) or explicitly set to false
            var defaultEditable = field.id !== 'date_created' && field.id !== 'entry_id';
            // Flip the logic: field.disabled = !field.editable
            var isDisabled = field.editable !== undefined ? !field.editable : !defaultEditable;
            $('#field-disabled').prop('checked', isDisabled);
            // Default filterable to true unless explicitly set to false
            $('#field-filterable').prop('checked', field.filterable !== false);
            $('#field-width').val(field.width || '');
            $('#field-alignment').val(field.alignment || ''); // #661 - column alignment
            $('#field-wrap-mode').val(field.wrap_mode || 'default'); // #662 - column wrap mode
            $('#field-vertical-alignment').val(field.vertical_alignment || ''); // #663 - column vertical alignment
            $('#field-cell-type').val(field.cell_type || ''); // TC_Star_Rating_Service / TC_Badge_Service per-column cell type
            // #1741 badge map: populate textarea + show/hide the row
            var badgeMap = field.badge_map || {};
            $('#field-badge-map').val(Object.keys(badgeMap).length ? JSON.stringify(badgeMap, null, 2) : '');
            $('.gt-badge-map-row').toggle(field.cell_type === 'badge');
            // #1742 - per-column inline-edit validation rules (Pro)
            var vr = field.validation_rules || {};
            $('#field-val-required').prop('checked', !!vr.required);
            $('#field-val-min-length').val(vr.min_length || '');
            $('#field-val-max-length').val(vr.max_length || '');
            $('#field-val-min-value').val(vr.min_value !== undefined && vr.min_value !== null ? vr.min_value : '');
            $('#field-val-max-value').val(vr.max_value !== undefined && vr.max_value !== null ? vr.max_value : '');
            $('#field-val-regex').val(vr.regex || '');
            $('#field-val-regex-message').val(vr.regex_message || '');
            // #1746 - per-column role visibility (Pro)
            var allowedRoles = field.allowed_roles || [];
            $('.field-role-visibility-cb').each(function () {
                var role = $(this).data('role');
                $(this).prop('checked', allowedRoles.indexOf(role) !== -1);
            });
            $('#field-aggregation').val(field.aggregation || ''); // TC_Formula_Service per-column totals-row aggregation
            $('#field-detail-only').prop('checked', !!field.detail_only); // TC_Detail_Rows_Service per-column detail-only flag
            $('#field-auto-merge-consecutive').prop('checked', !!field.auto_merge_consecutive); // TC_Rowspan_Merge_Service per-column auto-merge flag (#518 slice 2)
            $('#field-drilldown-enabled').prop('checked', !!field.drilldown_enabled); // TC_Drilldown_Filter_Service per-column click-to-filter flag (#568 slice 2)
            $('#field-data-bar-enabled').prop('checked', !!field.data_bar_enabled); // TC_Data_Bars_Service per-column value-bar (#1731, Pro)
            $('#field-data-bar-color').val(field.data_bar_color || '#3b82f6'); // #1731 - data-bar color
            // #664 - column link settings (nested object: link_target / link_color / link_underline)
            var ls = field.link_settings || {};
            $('#field-link-target').val(ls.link_target || '');
            $('#field-link-color').val(ls.link_color || '');
            $('#field-link-underline').prop('checked', ls.link_underline !== false);

            // Load filter configuration
            this.loadFilterConfiguration(field);

            // Load responsive settings
            this.loadResponsiveSettings(field);

            // Populate lookup configuration
            var lookupEnabled = field.lookup_enabled || false;
            $('#field-lookup-enabled').prop('checked', lookupEnabled);

            if (lookupEnabled) {
                $('#lookup-config').show();
                $('#field-lookup-type').val(field.lookup_type || '').trigger('change');

                if (field.lookup_type === 'user') {
                    $('#field-lookup-user-field').val(field.lookup_user_field || 'display_name');
                    // Populate selected user roles
                    if (field.lookup_user_roles && Array.isArray(field.lookup_user_roles)) {
                        $('#field-lookup-user-roles').val(field.lookup_user_roles);
                    }
                } else if (field.lookup_type === 'post') {
                    $('#field-lookup-post-field').val(field.lookup_post_field || 'post_title');
                } else if (field.lookup_type === 'custom') {
                    $('#field-lookup-table').val(field.lookup_table || '');
                    $('#field-lookup-id-column').val(field.lookup_id_column || '');
                    $('#field-lookup-display-column').val(field.lookup_display_column || '');
                }
            } else {
                $('#lookup-config').hide();
            }

            // Load conditional formatting rules
            //console.log('GT Admin DEBUG: About to load conditional formatting for field:', fieldId);
            //console.log('GT Admin DEBUG: Field data at modal open:', JSON.stringify(this.formFields[fieldId], null, 2));
            this.loadConditionalFormattingRules(fieldId);

            // Initialize tabs - set General tab as active by default
            $('.gt-field-modal-tab').removeClass('active');
            $('.gt-field-modal-tab-content').removeClass('active');
            $('.gt-field-modal-tab[data-tab="gt-general-tab"]').addClass('active');
            $('#gt-general-tab').addClass('active');

            // Initialize filter options visibility based on checkbox state
            if ($('#field-filterable').is(':checked')) {
                $('#filter-options-group').show();
            } else {
                $('#filter-options-group').hide();
            }

            // Update filtering options for lookup fields
            this.updateFilteringOptionsForLookupField();

            // Show modal
            //console.log('GT Admin DEBUG: Showing modal');
            $('#field-config-modal').show();
            //console.log('GT Admin DEBUG: Modal display style:', $('#field-config-modal').css('display'));

            // Reset modalOpening flag after modal is shown
            setTimeout(() => {
                this.modalOpening = false;
                //console.log('GT Admin: Reset modalOpening flag to false after modal shown');
            }, 100);
        },

        loadFilterConfiguration: function (field) {
            //console.log('GT Admin: Loading filter config for field', field.id, field.filter_config);

            // Get filter configuration or set intelligent defaults
            var filterConfig = field.filter_config || this.getIntelligentFilterDefaults(field);

            // Populate filter settings
            $('#field-filter-type').val(filterConfig.type || 'text');
            $('#field-filter-placeholder').val(filterConfig.placeholder || '');

            // Text filter options
            $('#field-filter-case-sensitive').prop('checked', filterConfig.case_sensitive || false);
            $('#field-filter-exact-match').prop('checked', filterConfig.exact_match || false);

            // Dropdown filter options
            $('#field-filter-multiple').prop('checked', filterConfig.multiple || false);
            $('#field-filter-sort-options').val(filterConfig.sort_options || 'alphabetical');

            // Date filter options
            $('#field-filter-date-range').val(filterConfig.date_range || 'single');
            $('#field-filter-show-presets').prop('checked', filterConfig.show_presets || false);

            // Range filter options
            $('#field-filter-range-step').val(filterConfig.range_step || 1);
            $('#field-filter-range-format').val(filterConfig.range_format || 'number');

            // Checkboxes filter options
            $('#field-filter-checkboxes-logic').val(filterConfig.checkboxes_logic || 'or');
            $('#field-filter-show-select-all').prop('checked', filterConfig.show_select_all || false);

            // Trigger filter type change to show appropriate options
            this.handleFilterTypeChange(filterConfig.type || 'text');

            //console.log('GT Admin: Applied filter config:', filterConfig);
        },

        getIntelligentFilterDefaults: function (field) {
            //console.log('GT Admin: Getting intelligent defaults for field type:', field.type);

            var defaults = {
                type: 'text',
                placeholder: '',
                case_sensitive: false,
                multiple: false,
                exact_match: false
            };

            // Set intelligent defaults based on field type
            switch (field.type) {
                case 'number':
                case 'phone':
                    defaults.type = 'range';
                    defaults.placeholder = 'Enter range...';
                    break;

                case 'date':
                    defaults.type = 'date';
                    defaults.placeholder = 'Select date...';
                    break;

                case 'select':
                case 'radio':
                    defaults.type = 'dropdown';
                    defaults.placeholder = 'Choose option...';
                    break;

                case 'multiselect':
                case 'checkbox':
                    defaults.type = 'checkboxes';
                    defaults.multiple = true;
                    defaults.placeholder = 'Select options...';
                    break;

                case 'hidden':
                    // Hidden fields default to text but can be overridden to dropdown or other types
                    defaults.type = 'text';
                    defaults.placeholder = 'Filter by ' + (field.label || 'value') + '...';
                    break;

                case 'email':
                case 'website':
                case 'text':
                case 'textarea':
                default:
                    defaults.type = 'text';
                    defaults.placeholder = 'Filter by ' + (field.label || 'value') + '...';
                    break;
            }

            //console.log('GT Admin: Intelligent defaults for', field.type, ':', defaults);
            return defaults;
        },

        loadResponsiveSettings: function (field) {
            //console.log('GT Admin: Loading responsive settings for field', field.id, '- stored settings:', field.responsive_settings);

            // Get responsive settings or set defaults
            var responsiveSettings = field.responsive_settings || {
                mobile_visible: true,
                tablet_visible: true,
                mobile_label: ''
            };

            //console.log('GT Admin: Using responsive settings:', responsiveSettings);

            // Populate responsive settings
            $('#field-mobile-visible').prop('checked', responsiveSettings.mobile_visible !== false);
            $('#field-tablet-visible').prop('checked', responsiveSettings.tablet_visible !== false);
            $('#field-mobile-label').val(responsiveSettings.mobile_label || '');

            // Debug: Verify UI was set correctly
            //console.log('GT Admin: Set mobile visible checkbox to:', $('#field-mobile-visible').is(':checked'));
            //console.log('GT Admin: Set tablet visible checkbox to:', $('#field-tablet-visible').is(':checked'));
            //console.log('GT Admin: Set mobile label to:', $('#field-mobile-label').val());
        },

        handleFilterTypeChange: function (filterType) {
            //console.log('GT Admin: Filter type changed to:', filterType);

            // Hide all type-specific options first
            $('.gt-filter-type-options').hide();

            // Show options based on selected type
            switch (filterType) {
                case 'text':
                    $('.gt-filter-text-options').show();
                    break;
                case 'dropdown':
                    $('.gt-filter-dropdown-options').show();
                    break;
                case 'date':
                    $('.gt-filter-date-options').show();
                    break;
                case 'range':
                    $('.gt-filter-range-options').show();
                    break;
                case 'checkboxes':
                    $('.gt-filter-checkboxes-options').show();
                    break;
                default:
                    $('.gt-filter-text-options').show(); // Default to text options
                    break;
            }

            // Update placeholder text based on type
            var placeholderInput = $('#field-filter-placeholder');
            var currentField = this.formFields[this.currentConfigFieldId];
            var fieldLabel = currentField ? (currentField.label || 'value') : 'value';

            switch (filterType) {
                case 'text':
                    placeholderInput.attr('placeholder', 'Filter by ' + fieldLabel + '...');
                    break;
                case 'dropdown':
                    placeholderInput.attr('placeholder', 'Choose ' + fieldLabel + '...');
                    break;
                case 'date':
                    placeholderInput.attr('placeholder', 'Select date...');
                    break;
                case 'range':
                    placeholderInput.attr('placeholder', 'Enter range...');
                    break;
                case 'checkboxes':
                    placeholderInput.attr('placeholder', 'Select ' + fieldLabel + '...');
                    break;
            }

            //console.log('GT Admin: Updated filter UI for type:', filterType);
        },

        updateFilteringOptionsForLookupField: function () {
            var isLookupEnabled = $('#field-lookup-enabled').is(':checked');
            var isFilteringEnabled = $('#field-filterable').is(':checked');

            //console.log('GT Admin: Updating filtering options for lookup field. Lookup enabled:', isLookupEnabled, 'Filtering enabled:', isFilteringEnabled);

            // Remove any existing lookup notices
            $('.gt-lookup-filter-notice').remove();

            if (isLookupEnabled && isFilteringEnabled) {
                // Show notice and disable filter type options
                var notice = $('<div class="gt-lookup-filter-notice" style="background: #e7f3ff; border: 1px solid #0073aa; border-radius: 3px; padding: 10px; margin: 10px 0;">' +
                    '<strong>Notice:</strong> This field is configured as a lookup field in the Advanced tab, so it will automatically use dropdown filtering.' +
                    '</div>');

                $('#filter-options-group h4').after(notice);

                // Force dropdown selection and disable the dropdown
                $('#field-filter-type').val('dropdown').prop('disabled', true);

                // Gray out/disable other filter options
                $('.gt-filter-type-options').not('.gt-filter-dropdown-options').css({
                    'opacity': '0.5',
                    'pointer-events': 'none'
                });

                // Show only dropdown options
                this.handleFilterTypeChange('dropdown');

            } else {
                // Re-enable all options
                $('#field-filter-type').prop('disabled', false);
                $('.gt-filter-type-options').css({
                    'opacity': '1',
                    'pointer-events': 'auto'
                });
            }
        },

        saveFieldConfig: function () {
            //console.log('GT Admin DEBUG: saveFieldConfig called');
            var fieldId = this.currentConfigFieldId;
            if (!fieldId || !this.formFields[fieldId]) return;

            // Debug: Check checkbox states
            var filterableCheckbox = $('#field-filterable');
            var isFilterableChecked = filterableCheckbox.is(':checked');
            //console.log('GT Admin DEBUG: Saving field config for field', fieldId);
            //console.log('GT Admin DEBUG: Filterable checkbox exists:', filterableCheckbox.length > 0);
            //console.log('GT Admin DEBUG: Filterable checkbox checked:', isFilterableChecked);

            // Save field configuration
            this.formFields[fieldId].custom_label = $('#field-label').val();
            this.formFields[fieldId].sortable = $('#field-sortable').is(':checked');
            // Flip the logic: editable = !disabled
            this.formFields[fieldId].editable = !$('#field-disabled').is(':checked');
            this.formFields[fieldId].filterable = isFilterableChecked;
            this.formFields[fieldId].width = $('#field-width').val();
            this.formFields[fieldId].alignment = $('#field-alignment').val(); // #661 - column alignment
            this.formFields[fieldId].wrap_mode = $('#field-wrap-mode').val(); // #662 - column wrap mode
            this.formFields[fieldId].vertical_alignment = $('#field-vertical-alignment').val(); // #663 - column vertical alignment
            this.formFields[fieldId].cell_type = $('#field-cell-type').val(); // TC_Star_Rating_Service / TC_Badge_Service per-column cell type
            // #1741 - badge map: parse JSON from textarea (silently ignore invalid JSON)
            try {
                var rawBadge = $('#field-badge-map').val() || '';
                this.formFields[fieldId].badge_map = rawBadge.trim() ? JSON.parse(rawBadge) : {};
            } catch (e) {
                this.formFields[fieldId].badge_map = {};
            }
            // #1746 - per-column role visibility (Pro)
            var checkedRoles = [];
            $('.field-role-visibility-cb:checked').each(function () {
                checkedRoles.push($(this).data('role'));
            });
            this.formFields[fieldId].allowed_roles = checkedRoles;
            this.formFields[fieldId].aggregation = $('#field-aggregation').val(); // TC_Formula_Service per-column totals-row aggregation
            this.formFields[fieldId].detail_only = $('#field-detail-only').is(':checked'); // TC_Detail_Rows_Service
            this.formFields[fieldId].auto_merge_consecutive = $('#field-auto-merge-consecutive').is(':checked'); // TC_Rowspan_Merge_Service (#518 slice 2)
            this.formFields[fieldId].drilldown_enabled = $('#field-drilldown-enabled').is(':checked'); // TC_Drilldown_Filter_Service (#568 slice 2)
            this.formFields[fieldId].data_bar_enabled = $('#field-data-bar-enabled').is(':checked'); // TC_Data_Bars_Service (#1731, Pro)
            this.formFields[fieldId].data_bar_color = $('#field-data-bar-color').val() || '#3b82f6'; // #1731 - data-bar color
            // #1742 - per-column inline-edit validation rules (Pro)
            var valRules = {};
            if ($('#field-val-required').is(':checked')) valRules.required = true;
            var minLen = parseInt($('#field-val-min-length').val(), 10);
            if (!isNaN(minLen) && minLen > 0) valRules.min_length = minLen;
            var maxLen = parseInt($('#field-val-max-length').val(), 10);
            if (!isNaN(maxLen) && maxLen > 0) valRules.max_length = maxLen;
            var minVal = parseFloat($('#field-val-min-value').val());
            if (!isNaN(minVal)) valRules.min_value = minVal;
            var maxVal = parseFloat($('#field-val-max-value').val());
            if (!isNaN(maxVal)) valRules.max_value = maxVal;
            var regex = $('#field-val-regex').val().trim();
            if (regex) { valRules.regex = regex; valRules.regex_message = $('#field-val-regex-message').val().trim(); }
            this.formFields[fieldId].validation_rules = Object.keys(valRules).length ? valRules : null;
            // #664 - column link settings (target / color / underline)
            this.formFields[fieldId].link_settings = {
                link_target:    $('#field-link-target').val() || '',
                link_color:     $('#field-link-color').val() || '',
                link_underline: $('#field-link-underline').is(':checked')
            };

            //console.log('GT Admin DEBUG: Set field.filterable to:', this.formFields[fieldId].filterable);

            // Save responsive settings
            this.formFields[fieldId].responsive_settings = {
                mobile_visible: $('#field-mobile-visible').is(':checked'),
                tablet_visible: $('#field-tablet-visible').is(':checked'),
                mobile_label: $('#field-mobile-label').val()
            };

            //console.log('GT Admin DEBUG: Saved responsive settings for field', fieldId, ':', this.formFields[fieldId].responsive_settings);
            //console.log('GT Admin DEBUG: Mobile visible checkbox exists:', $('#field-mobile-visible').length > 0);
            //console.log('GT Admin DEBUG: Mobile visible checked:', $('#field-mobile-visible').is(':checked'));

            // Save lookup configuration
            this.formFields[fieldId].lookup_enabled = $('#field-lookup-enabled').is(':checked');

            if (this.formFields[fieldId].lookup_enabled) {
                this.formFields[fieldId].lookup_type = $('#field-lookup-type').val();

                if (this.formFields[fieldId].lookup_type === 'user') {
                    this.formFields[fieldId].lookup_user_field = $('#field-lookup-user-field').val();
                    // Save selected user roles for filtering
                    var selectedRoles = [];
                    $('#field-lookup-user-roles option:selected').each(function () {
                        selectedRoles.push($(this).val());
                    });
                    this.formFields[fieldId].lookup_user_roles = selectedRoles;
                } else if (this.formFields[fieldId].lookup_type === 'post') {
                    this.formFields[fieldId].lookup_post_field = $('#field-lookup-post-field').val();
                } else if (this.formFields[fieldId].lookup_type === 'custom') {
                    this.formFields[fieldId].lookup_table = $('#field-lookup-table').val();
                    this.formFields[fieldId].lookup_id_column = $('#field-lookup-id-column').val();
                    this.formFields[fieldId].lookup_display_column = $('#field-lookup-display-column').val();
                }
            }

            // Save filter configuration
            //console.log('GT Admin DEBUG: Checking if field is filterable:', this.formFields[fieldId].filterable);
            if (this.formFields[fieldId].filterable) {
                var filterConfig = {
                    type: $('#field-filter-type').val() || 'text',
                    placeholder: $('#field-filter-placeholder').val() || '',
                    // Text filter options
                    case_sensitive: $('#field-filter-case-sensitive').is(':checked'),
                    exact_match: $('#field-filter-exact-match').is(':checked'),
                    // Dropdown filter options
                    multiple: (function () {
                        var checkbox = $('#field-filter-multiple');
                        var isChecked = checkbox.is(':checked');
                        //console.log('GT Admin DEBUG: Multiple checkbox found:', checkbox.length > 0);
                        //console.log('GT Admin DEBUG: Multiple checkbox checked:', isChecked);
                        return isChecked;
                    })(),
                    sort_options: $('#field-filter-sort-options').val() || 'alphabetical',
                    // Date filter options
                    date_range: $('#field-filter-date-range').val() || 'single',
                    show_presets: $('#field-filter-show-presets').is(':checked'),
                    // Range filter options
                    range_step: parseFloat($('#field-filter-range-step').val()) || 1,
                    range_format: $('#field-filter-range-format').val() || 'number',
                    // Checkboxes filter options
                    checkboxes_logic: $('#field-filter-checkboxes-logic').val() || 'or',
                    show_select_all: $('#field-filter-show-select-all').is(':checked')
                };

                // Store filter configuration
                this.formFields[fieldId].filter_config = filterConfig;

                //console.log('GT Admin: Saved filter config for field', fieldId, ':', filterConfig);
            } else {
                // Clear filter config if filtering is disabled
                //console.log('GT Admin DEBUG: Field', fieldId, 'is not filterable, clearing filter_config');
                delete this.formFields[fieldId].filter_config;
            }

            // Save conditional formatting rules
            var compiledRules = this.compileConditionalFormattingRules();
            // If validation flagged incomplete rules, prevent save and guide the user
            if (this._lastRuleValidation && this._lastRuleValidation.invalidCount > 0) {
                try {
                    var $first = this._lastRuleValidation.firstInvalidEl;
                    if ($first && $first.length) {
                        $first.focus();
                        // Ensure it is in view
                        $first[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                } catch (e) { }
                alert('Please complete all required fields for conditional formatting rules before saving.');
                return; // Abort save
            }
            //console.log('GT Admin DEBUG: Compiling conditional formatting rules - found', $('#gt-conditional-formatting-rules-container .gt-conditional-formatting-rule').length, 'rule elements in UI');
            //console.log('GT Admin DEBUG: Saving', compiledRules.length, 'rules for field', fieldId);
            //console.log('GT Admin DEBUG: Compiled rules:', compiledRules);
            //console.log('GT Admin DEBUG: Field BEFORE setting conditional_formatting:', JSON.stringify(this.formFields[fieldId].conditional_formatting));
            this.formFields[fieldId].conditional_formatting = compiledRules;

            // Mark that this field has recently updated conditional formatting to prevent overwrite
            this.formFields[fieldId]._cf_last_updated = Date.now();

            //console.log('GT Admin DEBUG: Field AFTER setting conditional_formatting:', JSON.stringify(this.formFields[fieldId].conditional_formatting));

            // Update the display
            //console.log('GT Admin DEBUG: Before renderSelectedFields - field data:', this.formFields[fieldId]);
            this.renderSelectedFields();
            //console.log('GT Admin DEBUG: After renderSelectedFields - field data:', this.formFields[fieldId]);
            this.generatePreview();
            //console.log('GT Admin DEBUG: After generatePreview - field data:', this.formFields[fieldId]);

            this.closeFieldConfig();
        },

        closeFieldConfig: function () {
            //console.log('GT Admin: closeFieldConfig called');

            // Check if modal exists
            var $modal = $('#field-config-modal');
            //console.log('GT Admin: Modal element found:', $modal.length > 0);
            //console.log('GT Admin: Modal currently visible:', $modal.is(':visible'));

            // Clear conditional formatting container to prevent cross-field contamination
            $('#gt-conditional-formatting-rules-container').empty();

            // Clear any stored field reference
            this.currentConfigFieldId = null;

            // Reset the modal flags
            this.modalClosing = false;
            this.modalOpening = false;

            // Hide modal using multiple methods to ensure it works
            //console.log('GT Admin: Hiding modal...');

            // Try multiple hiding methods
            $modal.hide();
            $modal.css('display', 'none');
            $modal.removeClass('show');

            //console.log('GT Admin: Modal hidden. Now visible:', $modal.is(':visible'));
            //console.log('GT Admin: Modal display style:', $modal.css('display'));
        },

        collectResponsiveSettings: function () {
            var responsiveSettings = {};

            // Collect responsive settings from all fields that have them
            // Note: We always collect them for preservation, even if responsive_mode isn't enhanced
            var self = this;
            $.each(this.formFields, function (fieldId, field) {
                if (field.responsive_settings) {
                    responsiveSettings[fieldId] = field.responsive_settings;
                }
            });

            //console.log('GT Admin DEBUG: Collected responsive settings for', Object.keys(responsiveSettings).length, 'fields:', responsiveSettings);
            return responsiveSettings;
        },

        cacheResponsiveSettings: function (fieldId, settings) {
            this.responsiveSettingsCache = this.responsiveSettingsCache || {};
            this.responsiveSettingsCache[fieldId] = settings;
        }
    });

})(jQuery);
