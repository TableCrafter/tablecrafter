/**
 * TableCrafter - frontend/lookup-dropdown.js
 *
 * Generic (non-AJS-toolkit) lookup-field dropdown populator. Eighth
 * slice under #833. One method, ~275 lines - the largest single
 * extraction yet.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - populateLookupDropdown($field, fieldId, currentValue)
 *
 * The populator:
 *   1. Validates this.config.lookup_fields[fieldId] is present and
 *      has either source_form (form-based) or type (user/post lookup).
 *      Falls back to a plain text editor on validation failure.
 *   2. Shows "Loading options..." placeholder.
 *   3. Arms a 10-second timeout that falls back to a plain text
 *      editor if AJAX hangs.
 *   4. POSTs to gt_get_lookup_options with the lookup_config payload.
 *   5. On success: renders a <select class="gt-lookup-select"> with
 *      reverse-lookup of current display value → stored option id,
 *      then wires change/keydown/click handlers to save through
 *      this.saveField.
 *   6. On AJAX failure or success=false: shows a brief error
 *      message, then falls back to a plain text editor after 3
 *      seconds with the same keyboard / blur save lifecycle.
 *
 * Pairs with edit-save.js (slice 2) - every save path routes through
 * this.saveField on the prototype.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.populateLookupDropdown = function ($field, fieldId, currentValue) {
        //console.log('GT Lookup: populateLookupDropdown called for field', fieldId, 'with current value:', currentValue);
        var self = this;
        var lookupConfig = this.config.lookup_fields && this.config.lookup_fields[fieldId];

        //console.log('GT Lookup: this.config.lookup_fields:', this.config.lookup_fields);
        //console.log('GT Lookup: Raw lookupConfig for field ' + fieldId + ':', lookupConfig);

        // Check if lookup configuration exists and is valid (either has source_form for gravity form lookups OR type for user/post lookups)
        if (!lookupConfig || typeof lookupConfig !== 'object' || (!lookupConfig.source_form && !lookupConfig.type)) {
            console.error('GT Lookup: No valid lookup configuration found for field', fieldId);
            //console.log('GT Lookup: Available lookup fields', this.config.lookup_fields);
            //console.log('GT Lookup: Provided config:', lookupConfig);
            //console.log('GT Lookup: Validation failed - lookupConfig exists?', !!lookupConfig);
            //console.log('GT Lookup: Validation failed - is object?', typeof lookupConfig === 'object');
            //console.log('GT Lookup: Validation failed - has source_form?', !!(lookupConfig && lookupConfig.source_form));
            //console.log('GT Lookup: Validation failed - has type?', !!(lookupConfig && lookupConfig.type));
            $field.html('<input type="text" class="gt-edit-input" value="' + self.escapeHtml(currentValue) + '">');

            // Focus and add keyboard handling for fallback text input
            setTimeout(function () {
                var $input = $field.find('.gt-edit-input');
                $input.focus();

                // Add Enter/Esc handling
                $input.on('keydown', function (e) {
                    if (e.key === 'Enter') { // Enter
                        e.preventDefault();
                        var newValue = $input.val();
                        self.saveField($field.data('entry-id'), fieldId, newValue, $field);
                    } else if (e.key === 'Escape') { // Escape
                        e.preventDefault();
                        $field.html(self.escapeHtml(currentValue));
                    }
                });

                // Auto-save on blur
                $input.on('blur', function () {
                    setTimeout(function () {
                        if ($field.find('.gt-edit-input').length > 0) {
                            var newValue = $input.val();
                            self.saveField($field.data('entry-id'), fieldId, newValue, $field);
                        }
                    }, 100);
                });
            }, 10);

            return;
        }

        // Show loading state
        //console.log('GT Lookup: Setting loading state for field', fieldId);
        $field.html('Loading options...');

        // Debug logging
        //console.log('GT Lookup: Starting lookup for field', fieldId);
        //console.log('GT Lookup: Config', lookupConfig);
        //console.log('GT Lookup: AJAX URL', this.config.ajax_url);
        //console.log('GT Lookup: Form ID', this.config.form_id);
        //console.log('GT Lookup: Nonce', this.config.nonce);
        //console.log('GT Lookup: Entire config object:', this.config);

        // Add timeout for stuck loading states
        //console.log('GT Lookup: Setting up 10-second timeout for field', fieldId);
        var timeoutId = setTimeout(function () {
            console.warn('GT Lookup: AJAX request timed out after 10 seconds for field', fieldId, ', falling back to text input');
            //console.log('GT Lookup: Timeout triggered - replacing loading with text input');
            $field.html('<input type="text" class="gt-edit-input" value="' + self.escapeHtml(currentValue) + '">');

            // Setup the text input with proper event handling
            var $input = $field.find('.gt-edit-input');
            setTimeout(function () {
                //console.log('GT Lookup: Focusing fallback text input after timeout');
                $input.focus();

                // Add Enter/Esc handling
                $input.on('keydown', function (e) {
                    if (e.key === 'Enter') { // Enter
                        e.preventDefault();
                        var newValue = $input.val();
                        self.saveField($field.data('entry-id'), fieldId, newValue, $field);
                    } else if (e.key === 'Escape') { // Escape
                        e.preventDefault();
                        $field.html(self.escapeHtml(currentValue));
                    }
                });

                // Auto-save on blur
                $input.on('blur', function () {
                    setTimeout(function () {
                        if ($field.find('.gt-edit-input').length > 0) {
                            var newValue = $input.val();
                            self.saveField($field.data('entry-id'), fieldId, newValue, $field);
                        }
                    }, 100);
                });
            }, 10);
        }, 10000); // 10 second timeout

        // Make AJAX call to get lookup options.
        // #914: previously emitted a `console.log('GT Lookup: Making AJAX
        // request...')` on every customer edit - removed to keep the console
        // clean. Re-enable behind a debug flag if diagnostics needed.

        $.post(this.config.ajax_url, {
            action: 'gt_get_lookup_options',
            nonce: this.config.nonce,
            field_id: fieldId,
            lookup_config: lookupConfig,
            form_id: this.config.form_id
        }, function (response) {
            clearTimeout(timeoutId); // Clear timeout since we got a response
            if (typeof GTDebugFrontend !== 'undefined') {
                GTDebugFrontend.log('lookup', 'AJAX response received', {
                    response: response,
                    responseType: typeof response,
                    success: response.success,
                    data: response.data
                });
            }

            if (response.success && response.data) {
                var inputHtml = '<select class="gt-edit-input gt-lookup-select">';
                inputHtml += '<option value="">Select...</option>';

                // Find current value ID (reverse lookup from display value to stored ID)
                var currentValueId = '';
                $.each(response.data, function (index, option) {
                    if (option.label === currentValue) {
                        currentValueId = option.value;
                    }
                });

                // Add all options
                $.each(response.data, function (index, option) {
                    var selected = (option.value == currentValueId) ? ' selected' : '';
                    inputHtml += '<option value="' + self.escapeHtml(option.value) + '"' + selected + '>' + self.escapeHtml(option.label) + '</option>';
                });

                inputHtml += '</select>';

                $field.html(inputHtml);
                $field.addClass('gt-editing');

                var $select = $field.find('.gt-lookup-select');
                var originalValue = $field.data('original-value');
                var entryId = $field.data('entry-id');

                // Focus the select and ensure it's clickable
                setTimeout(function () {
                    $select.focus();

                    // Force click handling for select dropdown
                    $select.on('mousedown', function (e) {
                        //console.log('GT Lookup: Select mousedown event triggered');
                        // Don't prevent default - allow normal dropdown behavior
                    });

                    $select.on('click', function (e) {
                        //console.log('GT Lookup: Select click event triggered');
                        e.stopPropagation(); // Prevent cell click handlers from interfering
                        // Don't prevent default - allow normal dropdown behavior
                    });
                }, 100);

                // Auto-save on change
                $select.on('change', function () {
                    //console.log('GT Lookup: Select value changed');
                    var newValue = $select.val();
                    var newLabel = $select.find('option:selected').text();
                    self.saveField(entryId, fieldId, newValue, $field, newLabel);
                });

                // Enhanced keyboard handling
                $select.on('keydown', function (e) {
                    if (e.key === 'Enter') { // Enter key
                        e.preventDefault();
                        var newValue = $select.val();
                        var newLabel = $select.find('option:selected').text();
                        self.saveField(entryId, fieldId, newValue, $field, newLabel);
                    } else if (e.key === 'Escape') { // Escape key
                        e.preventDefault();
                        $field.html(self.escapeHtml(originalValue));
                    }
                });

            } else {
                console.error('GT Lookup: AJAX failed or no data', response);
                console.error('GT Lookup: Response success status:', response ? response.success : 'No response');
                console.error('GT Lookup: Response data:', response ? response.data : 'No response');

                // Show error message temporarily
                var errorMsg = 'No data received';
                if (response && !response.success) {
                    errorMsg = response.data || 'Unknown server error';
                }
                $field.html('<div style="color: red; font-size: 12px;">Server Error: ' + errorMsg + '<br>Check console for details</div>');

                // Fallback to text input after 3 seconds
                setTimeout(function () {
                    $field.html('<input type="text" class="gt-edit-input" value="' + self.escapeHtml(currentValue) + '">');

                    var $input = $field.find('.gt-edit-input');
                    setTimeout(function () {
                        $input.focus();

                        $input.on('keydown', function (e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                var newValue = $input.val();
                                self.saveField($field.data('entry-id'), fieldId, newValue, $field);
                            } else if (e.key === 'Escape') {
                                e.preventDefault();
                                $field.html(self.escapeHtml(currentValue));
                            }
                        });

                        $input.on('blur', function () {
                            setTimeout(function () {
                                if ($field.find('.gt-edit-input').length > 0) {
                                    var newValue = $input.val();
                                    self.saveField($field.data('entry-id'), fieldId, newValue, $field);
                                }
                            }, 100);
                        });
                    }, 10);
                }, 3000); // 3 second delay before showing fallback
            }
        }).fail(function (xhr, status, error) {
            clearTimeout(timeoutId); // Clear timeout since we got a response (even if failed)
            console.error('GT Lookup: AJAX request failed');
            console.error('GT Lookup: XHR object:', xhr);
            console.error('GT Lookup: Status:', status);
            console.error('GT Lookup: Error:', error);
            console.error('GT Lookup: Response text:', xhr.responseText);
            console.error('GT Lookup: Status code:', xhr.status);

            // Show error message temporarily
            $field.html('<div style="color: red; font-size: 12px;">AJAX Error: ' + status + ' - ' + error + '<br>Check console for details</div>');

            // Fallback to text input after 3 seconds
            setTimeout(function () {
                $field.html('<input type="text" class="gt-edit-input" value="' + self.escapeHtml(currentValue) + '">');
                var $input = $field.find('.gt-edit-input');
                setTimeout(function () {
                    $input.focus();

                    $input.on('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            var newValue = $input.val();
                            self.saveField($field.data('entry-id'), fieldId, newValue, $field);
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            $field.html(self.escapeHtml(currentValue));
                        }
                    });

                    $input.on('blur', function () {
                        setTimeout(function () {
                            if ($field.find('.gt-edit-input').length > 0) {
                                var newValue = $input.val();
                                self.saveField($field.data('entry-id'), fieldId, newValue, $field);
                            }
                        }, 100);
                    });
                }, 10);
            }, 3000); // 3 second delay before showing fallback
        });
    };

})(window);
