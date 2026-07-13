/**
 * TableCrafter - admin/field-list.js
 *
 * Seventh slice of #842 (filed as #957). Field-selection UI cluster:
 *
 *   - loadFormFields: AJAX fetch of GF form fields, populates formFields[]
 *   - renderAvailableFields: paint the left column (unselected fields)
 *   - renderSelectedFields: paint the right column (selected fields, with config gear)
 *   - addFieldToSelection: move a field from available -> selected
 *   - removeFieldFromSelection: move a field from selected -> available
 *
 * @since 4.155.0
 */
(function ($) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        loadFormFields: function (formId) {
            var self = this;

            // Show loading
            $('#available-fields-list').html('<p>Loading fields...</p>');

            // Get form fields via AJAX
            $.post(gtAdmin.ajax_url, {
                action: 'gt_get_form_fields',
                form_id: formId,
                nonce: gtAdmin.nonce
            }, function (response) {
                if (response.success) {
                    // Convert array to object with field ID as key
                    self.formFields = {};
                    $.each(response.data, function (index, field) {
                        self.formFields[field.id] = field;
                    });

                    // Apply saved settings if we're editing an existing table
                    if (self.savedTableData && self.savedTableData.settings) {
                        self.applySavedSettings(self.savedTableData.settings);
                    }

                    // Render fields and initialize drag and drop
                    self.renderAvailableFields();
                    self.renderSelectedFields();
                    self.initDragAndDrop();

                    // If we're editing an existing table and have selected fields, 
                    // make sure they're properly displayed
                    if (self.selectedFields.length > 0) {
                        self.renderSelectedFields();
                        self.renderAvailableFields();
                    }
                } else {
                    $('#available-fields-list').html('<p>Error loading fields: ' + (response.data || 'Unknown error') + '</p>');
                }
            }).fail(function (xhr, status, error) {
                var msg = error || 'Unknown error';
                $('#available-fields-list').html(
                    '<div class="gt-load-error">' +
                    '<p>' + gtAdmin.strings.error + ': ' + msg + '</p>' +
                    '<button type="button" class="button gt-retry-load-fields">' +
                    (typeof gtAdmin.strings.retry !== 'undefined' ? gtAdmin.strings.retry : 'Retry') +
                    '</button></div>'
                );
                // Surface the top-level error banner and wire up its retry button
                $('#gt-load-error').show();
                $('#gt-retry-load, .gt-retry-load-fields').on('click', function () {
                    /* c8 ignore next */
                    $('#gt-load-error').hide();
                    /* c8 ignore next */
                    self.loadFormFields(formId);
                });
            });
        },

        loadJsonColumns: function (columns) {
            var self = this;
            self.formFields = {};
            $.each(columns, function (i, col) {
                var id    = col.id   || col.name || String(i);
                var label = col.name || col.id   || id;
                self.formFields[id] = { id: id, label: label, type: 'text' };
            });
            if (self.savedTableData && self.savedTableData.settings) {
                self.applySavedSettings(self.savedTableData.settings);
            }
            self.renderAvailableFields();
            self.renderSelectedFields();
            self.initDragAndDrop();
            self.generatePreview();
        },

        renderAvailableFields: function () {
            var html = '';

            // Filter out already selected fields
            var availableFields = {};
            $.each(this.formFields, function (id, field) {
                if (this.selectedFields.indexOf(id) === -1) {
                    availableFields[id] = field;
                }
            }.bind(this));

            if (Object.keys(availableFields).length > 0) {
                // Check if column limit is reached
                var limitReached = gtAdmin.limits.max_columns !== -1 && this.selectedFields.length >= gtAdmin.limits.max_columns;

                $.each(availableFields, function (id, field) {
                    var disabledClass = limitReached ? ' gt-field-disabled' : '';
                    var disabledTitle = limitReached ? ' title="Column limit reached. Upgrade to Pro for unlimited columns."' : '';

                    html += '<div class="gt-field-item' + disabledClass + '" data-field-id="' + id + '" data-field-type="' + field.type + '"' + disabledTitle + '>';
                    html += '<div class="gt-field-info">';
                    html += '<div class="gt-field-label">' + field.label + '</div>';
                    html += '<div class="gt-field-type">' + field.type + '</div>';
                    html += '</div>';
                    if (limitReached) {
                        html += '<div class="gt-field-limit-overlay">🔒</div>';
                    }
                    html += '</div>';
                }.bind(this));
            } else {
                html = '<p>No available fields</p>';
            }

            $('#available-fields-list').html(html);
            // SortableJS observes child mutations natively; no refresh call needed.
        },

        renderSelectedFields: function () {
            var html = '';

            if (this.selectedFields.length > 0) {
                $.each(this.selectedFields, function (index, fieldId) {
                    var field = this.formFields[fieldId];
                    if (field) {
                        var label = field.custom_label || field.label;
                        html += '<div class="gt-selected-field" data-field-id="' + fieldId + '">';
                        html += '<div class="gt-field-info">';
                        html += '<div class="gt-field-label">' + label + '</div>';
                        html += '<div class="gt-field-type">' + field.type + '</div>';
                        html += '</div>';
                        html += '<div class="gt-field-actions">';

                        // Get current responsive settings for this field  
                        var responsiveSettings = field.responsive_settings || {};
                        var mobileVisible = responsiveSettings.mobile_visible !== false;
                        var tabletVisible = responsiveSettings.tablet_visible !== false;

                        // Mobile toggle button
                        html += '<button type="button" class="gt-field-mobile-toggle' + (mobileVisible ? ' active' : '') + '" data-field-id="' + fieldId + '" title="Toggle mobile visibility">';
                        html += '<span class="dashicons dashicons-smartphone"></span>';
                        html += '</button>';

                        // Tablet toggle button
                        html += '<button type="button" class="gt-field-tablet-toggle' + (tabletVisible ? ' active' : '') + '" data-field-id="' + fieldId + '" title="Toggle tablet visibility">';
                        html += '<span class="dashicons dashicons-tablet"></span>';
                        html += '</button>';

                        // Settings button
                        html += '<button type="button" class="gt-field-config" data-field-id="' + fieldId + '" title="Configure field">';
                        html += '<span class="dashicons dashicons-admin-generic"></span>';
                        html += '</button>';

                        // Remove button
                        html += '<button type="button" class="gt-field-remove" data-field-id="' + String(fieldId) + '" title="Remove field">';
                        html += '<span class="dashicons dashicons-no-alt"></span>';
                        html += '</button>';
                        html += '</div>';
                        html += '</div>';
                    }
                }.bind(this));
            } else {
                html = '<p class="gt-no-fields">No fields selected. Drag fields from the available fields list.</p>';
            }

            $('#selected-fields-list').html(html);
            // SortableJS observes child mutations natively; no refresh call needed.
        },

        addFieldToSelection: function (fieldId) {
            // Check if field is already selected
            if (this.selectedFields.indexOf(fieldId) === -1) {
                // Check column limit for free plan
                if (gtAdmin.limits.max_columns !== -1 && this.selectedFields.length >= gtAdmin.limits.max_columns) {
                    // Show upgrade notice
                    this.showUpgradeNotice('column_limit',
                        'Free plan allows maximum ' + gtAdmin.limits.max_columns + ' columns. Upgrade to Pro for unlimited columns.');
                    return;
                }

                this.selectedFields.push(fieldId);
                this.renderAvailableFields();
                this.renderSelectedFields();
            }
        },

        removeFieldFromSelection: function (fieldId) {
            //console.log('removeFieldFromSelection called with fieldId:', fieldId, typeof fieldId);
            //console.log('Current selectedFields:', this.selectedFields);

            // Guard: cannot delete last column - table must have at least one column
            if (this.selectedFields.length <= 1) {
                console.warn('GT Admin: Cannot delete last column. A table must have at least one column.');
                return;
            }

            // Convert fieldId to string to match what's stored in selectedFields
            fieldId = String(fieldId);

            var index = this.selectedFields.indexOf(fieldId);
            //console.log('Field index:', index);

            if (index !== -1) {
                this.selectedFields.splice(index, 1);
                //console.log('Field removed. New selectedFields:', this.selectedFields);
                this.renderAvailableFields();
                this.renderSelectedFields();
            } else {
                // Try finding by numeric comparison if string didn't work
                var numericIndex = -1;
                for (var i = 0; i < this.selectedFields.length; i++) {
                    if (String(this.selectedFields[i]) === fieldId) {
                        numericIndex = i;
                        break;
                    }
                }

                if (numericIndex !== -1) {
                    this.selectedFields.splice(numericIndex, 1);
                    //console.log('Field removed (numeric match). New selectedFields:', this.selectedFields);
                    this.renderAvailableFields();
                    this.renderSelectedFields();
                } else {
                    console.error('Field not found in selectedFields:', fieldId, 'Available fields:', this.selectedFields);
                }
            }
        }

    });

})(jQuery);
