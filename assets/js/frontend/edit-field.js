/**
 * TableCrafter — frontend/edit-field.js
 *
 * Inline-editor dispatcher. Ninth slice under #833. One method,
 * ~280 lines.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - editField($field)
 *
 * Inspects the cell's data-field-type and renders the appropriate
 * inline editor:
 *
 *   - 'autoToolkitLookup' → delegates to editAjsToolkitLookupField
 *     (slice 4, edit-ajs-lookup.js).
 *   - 'lookup' → delegates to populateLookupDropdown (slice 8,
 *     lookup-dropdown.js).
 *   - 'select' / 'radio' / 'checkbox' / 'date' / 'time' / 'datetime' /
 *     'textarea' / default → renders the editor inline with this.saveField
 *     (slice 2, edit-save.js) on Enter / blur / change.
 *
 * Pairs with every edit-* sibling. Routes through this/self so every
 * inter-module call survives extraction.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.editField = function ($field) {
        var self = this;

        // Check if frontend editing is enabled
        //console.log('GT Frontend: Checking frontend editing config:', this.config.enable_frontend_editing);
        if (!this.config.enable_frontend_editing) {
            //console.log('GT Frontend: Frontend editing is not enabled for this table');
            //console.log('GT Frontend: Full config:', this.config);
            return;
        }

        //console.log('GT Frontend: Frontend editing is ENABLED - proceeding with edit');

        // Handle both cell-based and span-based editing
        var currentValue = $field.text();

        // Handle empty fields that display as &nbsp;
        if (currentValue === '\u00A0' || currentValue.trim() === '') {
            currentValue = '';
        }

        // For cell-based editing, we need to handle the styling differently
        var isCell = $field.hasClass('gt-editable-cell');
        if (isCell) {
            // Store original cell styling
            $field.data('original-padding', $field.css('padding'));
            $field.css('padding', '0'); // Remove padding so input fits perfectly
        }

        var fieldId = $field.data('field-id');
        var entryId = $field.data('entry-id');
        var colCfg = (this.config.column_config && this.config.column_config[fieldId]) || {};
        var fieldType = colCfg.type || 'text';

        // Store original value for restoration
        $field.data('original-value', currentValue);

        // Debug logging for field editing
        //console.log('GT Edit: Editing field', fieldId, 'Current value:', currentValue);
        //console.log('GT Edit: Available lookup fields:', this.config.lookup_fields);
        //console.log('GT Edit: Full config object keys:', Object.keys(this.config));
        //console.log('GT Edit: Field type:', fieldType);

        // Check if this is a USER-CONFIGURED lookup field (not auto-configured like driver_selector)
        var isUserLookupField = this.config.column_config && this.config.column_config[fieldId] && this.config.column_config[fieldId].lookup_enabled === true;
        // Keep backward compatibility for existing lookup fields - check for either source_form OR type (user/post lookup)
        var isLookupField = this.config.lookup_fields && this.config.lookup_fields[fieldId] && typeof this.config.lookup_fields[fieldId] === 'object' && (this.config.lookup_fields[fieldId].source_form || this.config.lookup_fields[fieldId].type);
        //console.log('GT Edit: Is user lookup field?', isUserLookupField);
        //console.log('GT Edit: Is backward compat lookup field?', isLookupField);
        //console.log('GT Edit: Lookup config for field:', this.config.lookup_fields ? this.config.lookup_fields[fieldId] : 'No lookup fields config');

        // Check if this field has predefined choices (dropdown/select fields from form configuration)
        var hasChoices = this.config.column_config && this.config.column_config[fieldId] && this.config.column_config[fieldId].choices && Array.isArray(this.config.column_config[fieldId].choices) && this.config.column_config[fieldId].choices.length > 0;
        //console.log('GT Edit: Has predefined choices?', hasChoices);
        //console.log('GT Edit: Choices:', this.config.column_config[fieldId] ? this.config.column_config[fieldId].choices : 'No column config');

        // Create input based on field type and configuration
        var inputHtml = '';
        var isMobile = window.innerWidth <= 768;

        if (hasChoices) {
            // Create dropdown from predefined choices - this takes priority over lookup fields
            //console.log('GT Edit: Creating predefined choices dropdown with', this.config.column_config[fieldId].choices.length, 'options');
            inputHtml = '<select class="gt-edit-input gt-field-select">';
            inputHtml += '<option value="">Select...</option>';

            var choices = this.config.column_config[fieldId].choices;
            choices.forEach(function (choice) {
                var value = choice.value || choice.text || choice;
                var text = choice.text || choice.label || choice;
                var selected = (value === currentValue) ? ' selected' : '';
                //console.log('GT Edit: Adding choice option:', {value: value, text: text, selected: selected});
                inputHtml += '<option value="' + self.escapeHtml(value) + '"' + selected + '>' + self.escapeHtml(text) + '</option>';
            });

            inputHtml += '</select>';
            //console.log('GT Edit: Predefined choices dropdown HTML:', inputHtml);
        } else if (fieldType === 'ajs_lookup' && self.config.ajs_toolkit && self.config.ajs_toolkit.active) {
            self.editAjsToolkitLookupField($field, fieldId, currentValue, entryId);
            return;
        } else if (isUserLookupField) {
            // User-configured lookup fields use the AJAX lookup system
            //console.log('GT Edit: Field is user-configured lookup field, using AJAX system');
            inputHtml = '<select class="gt-edit-input gt-lookup-select">';
            inputHtml += '<option value="">Select...</option>';

            this.populateLookupDropdown($field, fieldId, currentValue);
            return; // Exit early, the AJAX callback will handle the rest
        } else if (isLookupField) {
            // For backward compatibility lookup fields, create a dropdown with available options
            //console.log('GT Edit: Field is backward compatibility lookup field, using AJAX system');
            inputHtml = '<select class="gt-edit-input gt-lookup-select">';
            inputHtml += '<option value="">Select...</option>';

            // We need to get the lookup options - this requires an AJAX call
            // For now, show a loading state and populate via AJAX
            this.populateLookupDropdown($field, fieldId, currentValue);
            return; // Exit early, the AJAX callback will handle the rest
        } else if (fieldType === 'driver_selector' || fieldType === 'ajs_lookup') {
            // Specialized toolkit lookup fields (dropdown fallback if toolkit script inactive)
            inputHtml = '<select class="gt-edit-input gt-lookup-select">';
            inputHtml += '<option value="">Select...</option>';

            this.populateLookupDropdown($field, fieldId, currentValue);
            return; // Exit early, the AJAX callback will handle the rest
        } else {
            switch (fieldType) {
                case 'textarea':
                    inputHtml = '<textarea class="gt-edit-input" rows="' + (isMobile ? '3' : '2') + '">' + this.escapeHtml(currentValue) + '</textarea>';
                    break;
                case 'date':
                    // Use text input with user's preferred date format instead of HTML5 date picker
                    // This ensures consistency with the configured date format
                    var dateFormat = this.config.date_format || 'm/d/Y';
                    var placeholder = dateFormat.toUpperCase().replace(/[A-Z]/g, function (match) {
                        switch (match) {
                            case 'M': return 'MM';
                            case 'D': return 'DD';
                            case 'Y': return 'YYYY';
                            /* c8 ignore next */
                            default: return match;
                        }
                    });

                    inputHtml = '<input type="text" class="gt-edit-input gt-date-input" value="' + this.escapeHtml(currentValue) + '" placeholder="' + placeholder + '" data-date-format="' + this.escapeHtml(dateFormat) + '">';
                    break;
                case 'email':
                    inputHtml = '<input type="email" class="gt-edit-input" value="' + this.escapeHtml(currentValue) + '">';
                    break;
                case 'number':
                    inputHtml = '<input type="number" class="gt-edit-input" value="' + this.escapeHtml(currentValue) + '">';
                    break;
                default:
                    inputHtml = '<input type="text" class="gt-edit-input" value="' + this.escapeHtml(currentValue) + '">';
            }
        }

        $field.html(inputHtml);
        $field.addClass('gt-editing');

        var $input = $field.find('.gt-edit-input');

        // Special handling for predefined choices select dropdowns
        if ($input.hasClass('gt-field-select')) {
            //console.log('GT Edit: Setting up predefined choices select handlers');

            // Auto-save on change for dropdowns
            $input.on('change', function () {
                var newValue = $(this).val();
                //console.log('GT Edit: Dropdown changed to:', newValue);
                if (newValue !== currentValue) {
                    var vd = self.validateCell ? self.validateCell(fieldId, newValue) : { valid: true };
                    if (!vd.valid) {
                        if (self.showValidationError) self.showValidationError($field, $input, vd.message);
                        return;
                    }
                    self.saveField(entryId, fieldId, newValue, $field);
                }
            });

            // Handle Escape key to cancel
            $input.on('keydown', function (e) {
                if (e.key === 'Escape') { // Escape key
                    e.preventDefault();
                    //console.log('GT Edit: Escape pressed, restoring original value');
                    $field.html(self.escapeHtml(currentValue));

                    // Restore original padding if it was a cell-based edit
                    if ($field.hasClass('gt-editable-cell') && $field.data('original-padding')) {
                        $field.css('padding', $field.data('original-padding'));
                        $field.removeData('original-padding');
                    }
                }
            });

            // Override blur handler for select dropdowns - don't auto-save, just restore if unchanged
            $input.off('blur');
            $input.on('blur', function () {
                setTimeout(function () {
                    if ($field.find('.gt-edit-input').length > 0) {
                        var newValue = $input.val();
                        if (newValue === '' || newValue === currentValue) {
                            // No selection made or same value, restore original
                            //console.log('GT Edit: Select blur with no change, restoring original');
                            $field.html(self.escapeHtml(currentValue));

                            // Restore original padding if it was a cell-based edit
                            if ($field.hasClass('gt-editable-cell') && $field.data('original-padding')) {
                                $field.css('padding', $field.data('original-padding'));
                                $field.removeData('original-padding');
                            }
                        }
                        // If a valid selection was made, the change handler already saved it
                    }
                }, 100);
            });

            // Focus the select
            setTimeout(function () {
                $input.focus();
            }, 100);

            // Don't continue with the normal input setup for selects
            return;
        }

        // Focus and select text for better experience
        setTimeout(function () {
            $input.focus();

            // #1568: setSelectionRange is not supported on all input types
            // (number, email in jsdom, color, etc.) and throws InvalidStateError
            // when the input is not part of the document anymore. Wrap in a
            // try-catch so the focus path still completes cleanly even on
            // detached or unsupported inputs.
            //
            // Special handling for different input types
            try {
                if ($input.hasClass('gt-date-input')) {
                    // For date inputs with custom format, just select all text
                    if ($input[0].setSelectionRange) {
                        $input[0].setSelectionRange(0, $input.val().length);
                    }
                } else if ($input.attr('type') === 'date') {
                    // For HTML5 date inputs, try to show the picker
                    /* c8 ignore next */
                    if ($input[0].showPicker) {
                        /* c8 ignore next */
                        $input[0].showPicker();
                    } else {
                        // Fallback: click to trigger picker on some browsers
                        /* c8 ignore next */
                        $input.trigger('click');
                    }
                } else if ($input[0].setSelectionRange && $input.attr('type') !== 'number' && $input.attr('type') !== 'email') {
                    // Don't use setSelectionRange on number or email inputs --
                    // number is not supported anywhere; email throws under
                    // jsdom and is unreliable across browsers per the HTML spec.
                    $input[0].setSelectionRange(0, $input.val().length);
                }
            } catch (e) {
                /* c8 ignore next */
                // Swallow — focus is the important side-effect; selection is
                // an extra convenience that can be skipped on input types
                // that reject it.
            }
        }, 100);

        // Enhanced keyboard handling
        $input.on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { // Enter key (allow Shift+Enter for textarea)
                if (fieldType !== 'textarea' || !e.shiftKey) {
                    e.preventDefault();
                    var newValue = $input.val();
                    var vEnter = self.validateCell ? self.validateCell(fieldId, newValue) : { valid: true };
                    if (!vEnter.valid) {
                        if (self.showValidationError) self.showValidationError($field, $input, vEnter.message);
                        return;
                    }
                    var nextTarget = self.findNextEditCell($field, 'down');
                    self.saveField(entryId, fieldId, newValue, $field);
                    if (nextTarget) self.scheduleEditOnTarget(nextTarget);
                }
            } else if (e.key === 'Tab') { // Tab key (with or without shift)
                e.preventDefault();
                var newValueT = $input.val();
                var vTab = self.validateCell ? self.validateCell(fieldId, newValueT) : { valid: true };
                if (!vTab.valid) {
                    if (self.showValidationError) self.showValidationError($field, $input, vTab.message);
                    return;
                }
                var direction = e.shiftKey ? 'prev' : 'next';
                var target = self.findNextEditCell($field, direction);
                self.saveField(entryId, fieldId, newValueT, $field);
                if (target) self.scheduleEditOnTarget(target);
            } else if (e.key === 'Escape') { // Escape key
                e.preventDefault();
                $field.html(self.escapeHtml(currentValue));

                // Restore original padding if it was a cell-based edit
                if ($field.hasClass('gt-editable-cell') && $field.data('original-padding')) {
                    $field.css('padding', $field.data('original-padding'));
                    $field.removeData('original-padding');
                }
            }
        });

        // Handle blur event - save changes automatically but with validation
        $input.on('blur', function () {
            setTimeout(function () {
                if ($field.find('.gt-edit-input').length > 0) {
                    var newValue = $input.val();
                    var originalValue = $field.data('original-value') || '';

                    // Only save if value actually changed and is not empty (unless original was empty)
                    if (newValue !== originalValue && (newValue !== '' || originalValue !== '')) {
                        var vBlur = self.validateCell ? self.validateCell(fieldId, newValue) : { valid: true };
                        if (!vBlur.valid) {
                            if (self.showValidationError) self.showValidationError($field, $input, vBlur.message);
                            return;
                        }
                        //console.log('GT Edit: Blur save - New:', newValue, 'Original:', originalValue);
                        self.saveField(entryId, fieldId, newValue, $field);
                    } else {
                        //console.log('GT Edit: Blur cancelled - no meaningful change detected');
                        // Restore original value if no meaningful change
                        $field.html(self.escapeHtml(originalValue));

                        // Restore original padding if it was a cell-based edit
                        if ($field.hasClass('gt-editable-cell') && $field.data('original-padding')) {
                            $field.css('padding', $field.data('original-padding'));
                            $field.removeData('original-padding');
                        }
                    }
                }
            }, 100);
        });
    };

})(window);
