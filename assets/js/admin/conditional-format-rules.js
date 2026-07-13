/**
 * TableCrafter - admin/conditional-format-rules.js
 *
 * Fourth slice of #842 (admin.js monolith split). Conditional formatting
 * rule builder + color picker helpers.
 *
 *   - addConditionalFormattingRule / handleActionChange / handleOperatorChange:
 *     rule row UI (add, action-type pivot, operator-type pivot)
 *   - initColorPicker / positionColorPicker / removeColorPicker /
 *     updateRGBAFromPicker: jQuery-based RGBA color picker lifecycle
 *   - parseColorToRGBA / formatRGBA / hexToRGBA / hexToRGB / rgbToHex /
 *     isValidColor: color format conversion helpers
 *   - loadConditionalFormattingRules: read saved rules into UI on edit
 *   - compileConditionalFormattingRules: serialize UI rules back to config
 *
 * @since 4.152.0
 */
(function ($) {
    'use strict';

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        addConditionalFormattingRule: function () {
            //console.log('GT Admin DEBUG: === ADDING CONDITIONAL FORMATTING RULE ===');

            // Prevent rapid multiple calls
            if (this.isAddingRule) {
                //console.log('GT Admin DEBUG: Already adding rule, skipping...');
                return;
            }

            this.isAddingRule = true;

            var template = $('#gt-conditional-formatting-template').html();
            //console.log('GT Admin DEBUG: Template found:', template ? 'YES' : 'NO');
            //console.log('GT Admin DEBUG: Template HTML:', template ? template.substring(0, 100) + '...' : 'NONE');

            if (template) {
                var $rule = $(template);
                var $container = $('#gt-conditional-formatting-rules-container');
                //console.log('GT Admin DEBUG: Container before append:', $container.find('.gt-conditional-formatting-rule').length, 'rules');
                $container.append($rule);
                //console.log('GT Admin DEBUG: Container after append:', $container.find('.gt-conditional-formatting-rule').length, 'rules');

                // Initialize color picker only if color action is selected
                var $actionSelect = $rule.find('.gt-formatting-rule-action');
                var $setValueInput = $rule.find('.gt-formatting-rule-set-value');
                var $operatorSelect = $rule.find('.gt-formatting-rule-if-clause');

                if (['setCellColor', 'setRowColor'].includes($actionSelect.val())) {
                    /* c8 ignore next */
                    this.initColorPicker($setValueInput);
                }

                // Initialize operator handling for cell value input
                this.handleOperatorChange($operatorSelect);
            }

            // Reset flag after a short delay
            var self = this;
            setTimeout(function () {
                self.isAddingRule = false;
            }, 100);
        },

        handleActionChange: function ($select) {
            var action = $select.val();
            var $setValueInput = $select.closest('.gt-conditional-formatting-rule').find('.gt-formatting-rule-set-value');

            if (['setCellColor', 'setRowColor'].includes(action)) {
                this.initColorPicker($setValueInput);
                $setValueInput.attr('placeholder', 'Select color with transparency');
            } else {
                this.removeColorPicker($setValueInput);
                if (action === 'setCellContent') {
                    $setValueInput.attr('placeholder', 'Enter content');
                } else if (['setCellClass', 'setRowClass'].includes(action)) {
                    $setValueInput.attr('placeholder', 'Enter CSS class');
                } else if (action === '') {
                    $setValueInput.attr('placeholder', 'Choose action first');
                } else {
                    $setValueInput.attr('placeholder', 'Enter value');
                }
            }
        },

        handleOperatorChange: function ($select) {
            var operator = $select.val();
            var $cellValueInput = $select.closest('.gt-conditional-formatting-rule').find('.gt-formatting-rule-cell-value');

            if (operator === 'empty' || operator === 'not_empty') {
                // Disable and clear cell value input for empty/not empty operators
                $cellValueInput.prop('disabled', true).val('').attr('placeholder', 'Not required for this operator');
            } else {
                // Enable cell value input for all other operators
                $cellValueInput.prop('disabled', false).attr('placeholder', 'Enter comparison value');
            }
        },

        initColorPicker: function ($input) {
            if ($input.hasClass('gt-color-initialized')) return;

            $input.addClass('gt-color-initialized gt-color-input');

            // Create RGBA color picker interface
            var currentValue = $input.val() || '#ff0000';
            var rgba = this.parseColorToRGBA(currentValue);

            // Create wrapper for color controls
            var $wrapper = $('<div class="gt-rgba-picker-wrapper"></div>');
            var $colorInput = $('<input type="color" class="gt-color-base" />');
            var $alphaSlider = $('<input type="range" class="gt-alpha-slider" min="0" max="100" step="1" />');
            var $alphaLabel = $('<label class="gt-alpha-label">Opacity: <span class="gt-alpha-value">100</span>%</label>');
            var $preview = $('<div class="gt-color-preview"></div>');
            var $textInput = $('<input type="text" class="gt-rgba-text" placeholder="rgba(255,0,0,1)" />');

            // Set initial values
            $colorInput.val(rgba.hex);
            $alphaSlider.val(rgba.alpha * 100);
            $alphaLabel.find('.gt-alpha-value').text(Math.round(rgba.alpha * 100));
            $textInput.val(this.formatRGBA(rgba));
            $preview.css('background-color', this.formatRGBA(rgba));

            // Build the interface
            $wrapper.append($colorInput);
            $wrapper.append($alphaSlider);
            $wrapper.append($alphaLabel);
            $wrapper.append($preview);
            $wrapper.append($textInput);

            // Attach wrapper to body as a fixed popup, then position it
            $input.hide();
            $('body').append($wrapper);
            // #1562: pair the wrapper to the input via .data() so loaders
            // (loadConditionalFormattingRules) can find it after the fact.
            // $input.next() would never work because the wrapper lives on
            // body, not as a sibling.
            $input.data('gtColorPickerWrapper', $wrapper);

            var self = this;
            self.positionColorPicker($input, $wrapper);

            // Bind events
            $colorInput.on('change', function () {
                self.updateRGBAFromPicker($input, $wrapper);
            });

            $alphaSlider.on('input', function () {
                var alpha = $(this).val();
                $alphaLabel.find('.gt-alpha-value').text(alpha);
                self.updateRGBAFromPicker($input, $wrapper);
            });

            $textInput.on('change', function () {
                var value = $(this).val();
                if (self.isValidColor(value)) {
                    var rgba = self.parseColorToRGBA(value);
                    $colorInput.val(rgba.hex);
                    $alphaSlider.val(rgba.alpha * 100);
                    $alphaLabel.find('.gt-alpha-value').text(Math.round(rgba.alpha * 100));
                    $input.val(value).trigger('change');
                    $preview.css('background-color', value);
                }
            });

            // Enter key must also apply the typed color (blur/change alone is not enough).
            $textInput.on('keydown', function (e) {
                if (e.which === 13 || e.key === 'Enter') {
                    e.preventDefault();
                    $(this).trigger('change');
                }
            });

            // Reposition on window resize while the picker is open
            $(window).on('resize.gtColorPicker', function () {
                if ($wrapper.is(':visible')) {
                    /* c8 ignore next */
                    self.positionColorPicker($input, $wrapper);
                }
            });
        },

        positionColorPicker: function ($input, $wrapper) {
            var rect = $input[0].getBoundingClientRect();
            var pickerHeight = $wrapper.outerHeight(true) || 220;
            var pickerWidth  = $wrapper.outerWidth(true)  || 240;
            var viewportHeight = window.innerHeight;
            var viewportWidth  = window.innerWidth;

            var spaceBelow = viewportHeight - rect.bottom;
            var top, left;

            if (spaceBelow < pickerHeight && rect.top > pickerHeight) {
                // Not enough space below but enough above - flip upward
                top = rect.top - pickerHeight;
            } else {
                top = rect.bottom + 4;
            }

            left = rect.left;
            if (left + pickerWidth > viewportWidth - 8) {
                left = viewportWidth - pickerWidth - 8;
            }
            if (left < 8) { left = 8; }

            $wrapper.css({ top: top, left: left });
        },

        removeColorPicker: function ($input) {
            $input.removeClass('gt-color-initialized gt-color-input');
            $input.attr('type', 'text');
            $input.show();
            $(window).off('resize.gtColorPicker');
            $('body').find('.gt-rgba-picker-wrapper').remove();
        },

        updateRGBAFromPicker: function ($input, $wrapper) {
            var color = $wrapper.find('.gt-color-base').val();
            var alpha = $wrapper.find('.gt-alpha-slider').val() / 100;
            var rgba = this.hexToRGBA(color, alpha);
            var rgbaString = this.formatRGBA(rgba);

            $input.val(rgbaString);
            $wrapper.find('.gt-rgba-text').val(rgbaString);
            $wrapper.find('.gt-color-preview').css('background-color', rgbaString);
        },

        parseColorToRGBA: function (color) {
            // Default values
            var result = { r: 255, g: 0, b: 0, alpha: 1, hex: '#ff0000' };

            if (!color) return result;

            // Handle hex colors
            if (color.match(/^#([a-f0-9]{3}|[a-f0-9]{6})$/i)) {
                var hex = color;
                var rgb = this.hexToRGB(hex);
                return {
                    r: rgb.r,
                    g: rgb.g,
                    b: rgb.b,
                    alpha: 1,
                    hex: hex
                };
            }

            // Handle rgba colors
            var rgbaMatch = color.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\s*\)/);
            if (rgbaMatch) {
                var r = parseInt(rgbaMatch[1]);
                var g = parseInt(rgbaMatch[2]);
                var b = parseInt(rgbaMatch[3]);
                var alpha = rgbaMatch[4] ? parseFloat(rgbaMatch[4]) : 1;

                return {
                    r: r,
                    g: g,
                    b: b,
                    alpha: alpha,
                    hex: this.rgbToHex(r, g, b)
                };
            }

            return result;
        },

        formatRGBA: function (rgba) {
            if (rgba.alpha === 1) {
                return 'rgb(' + rgba.r + ',' + rgba.g + ',' + rgba.b + ')';
            }
            return 'rgba(' + rgba.r + ',' + rgba.g + ',' + rgba.b + ',' + rgba.alpha + ')';
        },

        hexToRGBA: function (hex, alpha) {
            var rgb = this.hexToRGB(hex);
            return {
                r: rgb.r,
                g: rgb.g,
                b: rgb.b,
                alpha: alpha || 1
            };
        },

        hexToRGB: function (hex) {
            hex = hex.replace(/^#/, '');
            // Expand 3-digit shorthand (#f53 → ff5533) before parsing.
            if (hex.length === 3) {
                hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            }
            var result = /^([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? {
                r: parseInt(result[1], 16),
                g: parseInt(result[2], 16),
                b: parseInt(result[3], 16)
            } : { r: 255, g: 0, b: 0 };
        },

        rgbToHex: function (r, g, b) {
            return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
        },

        isValidColor: function (color) {
            // Check for hex colors
            if (color.match(/^#([a-f0-9]{3}|[a-f0-9]{6})$/i)) {
                return true;
            }

            // Check for rgb/rgba colors
            if (color.match(/rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+)?\s*\)/)) {
                return true;
            }

            // Check for named colors
            var namedColors = ['red', 'blue', 'green', 'yellow', 'orange', 'purple', 'black', 'white', 'gray', 'grey'];
            if (namedColors.includes(color.toLowerCase())) {
                return true;
            }

            return false;
        },

        loadConditionalFormattingRules: function (fieldId) {
            var self = this;
            var field = this.formFields[fieldId];
            var $container = $('#gt-conditional-formatting-rules-container');

            if (!field || !field.conditional_formatting || !Array.isArray(field.conditional_formatting)) {
                //console.log('GT Admin DEBUG: No conditional formatting rules found for field', fieldId);
                return;
            }

            // Always clear the container first to prevent cross-field contamination
            $container.empty();

            //console.log('GT Admin DEBUG: Loading rules for field', fieldId);
            //console.log('GT Admin DEBUG: Field data:', field);
            //console.log('GT Admin DEBUG: Available rules:', field ? field.conditional_formatting : 'No field');

            // Process rules sequentially to ensure proper creation of UI elements
            field.conditional_formatting.forEach(function (rule, index) {
                //console.log('GT Admin DEBUG: Processing rule', index, 'for field', fieldId, ':', rule);

                // Reset the throttling flag to allow multiple rules during loading
                self.isAddingRule = false;

                // Add rule immediately without setTimeout to avoid timing issues
                self.addConditionalFormattingRule();

                // Use a more reliable way to get the rule we just created
                var $allRules = $container.find('.gt-conditional-formatting-rule');
                var $currentRule = $allRules.eq($allRules.length - 1); // Get the last (newest) rule

                //console.log('GT Admin DEBUG: Total rules in container after adding:', $allRules.length);

                $currentRule.find('.gt-formatting-rule-if-clause').val(rule.ifClause).trigger('change');
                $currentRule.find('.gt-formatting-rule-cell-value').val(rule.cellVal);
                $currentRule.find('.gt-formatting-rule-action').val(rule.action).trigger('change');
                $currentRule.find('.gt-formatting-rule-set-value').val(rule.setVal);

                //console.log('GT Admin DEBUG: Loaded rule', index, 'for field', fieldId, '- action:', rule.action, 'cellVal:', rule.cellVal, 'setVal:', rule.setVal);

                // Specifically initialize color picker for loaded color rules if needed
                if (['setCellColor', 'setRowColor'].includes(rule.action)) {
                    var $colorInput = $currentRule.find('.gt-formatting-rule-set-value');
                    self.initColorPicker($colorInput);
                    // Update the color picker with the value after initialization
                    var rgba = self.parseColorToRGBA(rule.setVal);
                    $colorInput.val(rule.setVal);
                    // #1562: read the wrapper reference stored on the input
                    // by initColorPicker. Previously this used .next() which
                    // never matched because the wrapper lives on body.
                    var $pickerWrapper = $colorInput.data('gtColorPickerWrapper');
                    if ($pickerWrapper && $pickerWrapper.length) {
                        $pickerWrapper.find('.gt-color-base').val(rgba.hex);
                        $pickerWrapper.find('.gt-alpha-slider').val(rgba.alpha * 100);
                        $pickerWrapper.find('.gt-alpha-value').text(Math.round(rgba.alpha * 100));
                        $pickerWrapper.find('.gt-color-preview').css('background-color', rule.setVal);
                        $pickerWrapper.find('.gt-rgba-text').val(rule.setVal);
                    }
                }
            });
        },

        compileConditionalFormattingRules: function () {
            var rules = [];
            var invalidCount = 0;
            var firstInvalidEl = null;
            var $container = $('#gt-conditional-formatting-rules-container');

            //console.log('GT Admin DEBUG: === COMPILING CONDITIONAL FORMATTING RULES ===');
            //console.log('GT Admin DEBUG: Container exists:', $container.length > 0);
            //console.log('GT Admin DEBUG: Container HTML:', $container.html().substring(0, 200) + '...');
            //console.log('GT Admin DEBUG: Found rule elements:', $container.find('.gt-conditional-formatting-rule').length);

            $container.find('.gt-conditional-formatting-rule').each(function (index) {
                var $rule = $(this);
                var rule = {
                    ifClause: $rule.find('.gt-formatting-rule-if-clause').val(),
                    cellVal: $rule.find('.gt-formatting-rule-cell-value').val(),
                    action: $rule.find('.gt-formatting-rule-action').val(),
                    setVal: $rule.find('.gt-formatting-rule-set-value').val()
                };

                //console.log('GT Admin DEBUG: Rule', index, 'raw values:', rule);

                // Only add complete rules (action must be selected and have values)
                // Using more precise checks that don't fail on falsy values like "0", false, etc.
                // Also allow values like "0", "false", " ", etc. as these can be valid for conditional formatting
                var hasAction = rule.action && rule.action !== '';
                var operator = rule.ifClause;
                var hasCellValue = (operator === 'empty' || operator === 'not_empty')
                    ? true
                    : (rule.cellVal !== null && rule.cellVal !== undefined && (typeof rule.cellVal === 'string' ? rule.cellVal.trim() !== '' : true));

                // For setVal, only require it for actions that actually need a value
                var needsSetValue = ['setCellColor', 'setRowColor', 'setTextColor', 'setFontWeight', 'setFontStyle', 'setTextDecoration'].includes(rule.action);
                var hasSetValue = !needsSetValue || (rule.setVal !== null && rule.setVal !== undefined && (typeof rule.setVal === 'string' ? rule.setVal.trim() !== '' : true));

                if (hasAction && hasCellValue && hasSetValue) {
                    rules.push(rule);
                    //console.log('GT Admin DEBUG: Rule', index, 'accepted - action:', rule.action, 'cellVal:', rule.cellVal, 'setVal:', rule.setVal);
                } else {
                    //console.log('GT Admin DEBUG: Rule', index, 'rejected - incomplete');
                    //console.log('GT Admin DEBUG: hasAction:', hasAction, 'hasCellValue:', hasCellValue, 'hasSetValue:', hasSetValue, 'needsSetValue:', needsSetValue);
                    //console.log('GT Admin DEBUG: raw values - action:', rule.action, 'cellVal:', rule.cellVal, 'setVal:', rule.setVal);
                    invalidCount++;
                    // Highlight the specific missing input(s)
                    var $actionEl = $rule.find('.gt-formatting-rule-action');
                    var $cellValEl = $rule.find('.gt-formatting-rule-cell-value');
                    var $setValEl = $rule.find('.gt-formatting-rule-set-value');
                    if (!hasAction) {
                        $actionEl.addClass('gt-input-error');
                        firstInvalidEl = firstInvalidEl || $actionEl;
                    }
                    if (!hasCellValue) {
                        $cellValEl.addClass('gt-input-error');
                        firstInvalidEl = firstInvalidEl || $cellValEl;
                    }
                    if (!hasSetValue && needsSetValue) {
                        $setValEl.addClass('gt-input-error');
                        firstInvalidEl = firstInvalidEl || $setValEl;
                    }
                }
            });

            //console.log('GT Admin DEBUG: Final rules array:', rules);
            // Store last validation result for the save handler to act upon
            this._lastRuleValidation = { invalidCount: invalidCount, firstInvalidEl: firstInvalidEl };
            return rules;
        }

    });

})(jQuery);
