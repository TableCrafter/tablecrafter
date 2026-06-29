/**
 * TableCrafter — frontend/responsive-card-view.js
 *
 * Responsive view + card-view rendering. #832 slice 20 of N.
 *
 * Thirteen helpers attached to GravityTable.prototype directly (kept as
 * `GravityTable.prototype.X = function...` assignments rather than the
 * Object.assign property-shorthand pattern used by other modules — the
 * block is large enough that re-folding into Object.assign would risk
 * subtle method-body changes during the move).
 *
 *   - initializeResponsiveMode($wrapper)
 *   - setupResponsiveBreakpoints($wrapper)
 *   - handleResponsiveResize($wrapper)
 *   - initFlipResponsive($wrapper)
 *   - initCollapsibleRows($wrapper)
 *   - initModalRows($wrapper)
 *   - shouldShowCardsOnTablet()
 *   - showCardView($wrapper)
 *   - showTableView($wrapper)
 *   - generateCards($wrapper)
 *   - generateCardHtml($row, entryId, isTabletView)
 *   - updateCardsAfterDataChange($wrapper)
 *   - bindCardEvents($wrapper)
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    // Local alias so the block below (kept verbatim from frontend.js)
    // reads `GravityTable.prototype.X = function...` without
    // window-qualified names.
    var GravityTable = window.GravityTable;
    var $ = window.jQuery;

    GravityTable.prototype.initializeResponsiveMode = function ($wrapper) {
        var self = this;

        //console.log('GT Frontend: Initializing responsive mode:', this.config.responsive_mode);

        // Set up responsive mode handling based on screen size
        this.setupResponsiveBreakpoints($wrapper);

        // Handle window resize to toggle between table and card views (with throttling)
        var resizeTimeout;
        $(window).on('resize.gravityTable.' + this.wrapperId, function () {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function () {
                self.handleResponsiveResize($wrapper);
            }, 100);
        });

        // ResizeObserver catches browser zoom and container-width changes that may not
        // fire a window resize event in all browsers (#156)
        if (typeof ResizeObserver !== 'undefined' && $wrapper.length) {
            /* c8 ignore next */
            var ro = new ResizeObserver(function () {
                /* c8 ignore next */
                clearTimeout(resizeTimeout);
                /* c8 ignore next */
                resizeTimeout = setTimeout(function () {
                    /* c8 ignore next */
                    self.handleResponsiveResize($wrapper);
                }, 100);
            });
            /* c8 ignore next */
            ro.observe($wrapper[0]);
        }

        // Initial responsive check
        this.handleResponsiveResize($wrapper);
    };

    GravityTable.prototype.setupResponsiveBreakpoints = function ($wrapper) {
        this.breakpoints = {
            mobile: 480,
            tablet: 768
        };

        //console.log('GT Frontend: Responsive breakpoints set:', this.breakpoints);
    };

    GravityTable.prototype.handleResponsiveResize = function ($wrapper) {
        //console.log('GT Frontend: handleResponsiveResize called');
        //console.log('GT Frontend: responsive_mode config:', this.config.responsive_mode);

        if (this.config.responsive_mode === 'collapse') {
            this.initCollapsibleRows($wrapper);
            return;
        }

        if (this.config.responsive_mode === 'modal') {
            this.initModalRows($wrapper);
            return;
        }

        // Flip responsive mode: first column becomes row labels on narrow viewports (#348).
        if (this.config.responsive_mode === 'flip') {
            this.initFlipResponsive($wrapper);
            return;
        }

        if (this.config.responsive_mode !== 'enhanced') {
            //console.log('GT Frontend: Responsive mode not enhanced, staying in table view');
            return;
        }

        var windowWidth = $(window).width();
        var shouldShowCards = false;

        //console.log('GT Frontend: Window width:', windowWidth);
        //console.log('GT Frontend: Mobile breakpoint:', this.breakpoints.mobile);
        //console.log('GT Frontend: Tablet breakpoint:', this.breakpoints.tablet);

        // Determine if we should show cards based on breakpoints and settings
        if (windowWidth <= this.breakpoints.mobile) {
            shouldShowCards = true;
            $wrapper.addClass('mobile-view').removeClass('tablet-view');
            //console.log('GT Frontend: Mobile view detected');
        } else if (windowWidth <= this.breakpoints.tablet) {
            shouldShowCards = this.shouldShowCardsOnTablet();
            $wrapper.addClass('tablet-view').removeClass('mobile-view');
            //console.log('GT Frontend: Tablet view detected, shouldShowCards:', shouldShowCards);
        } else {
            shouldShowCards = false;
            $wrapper.removeClass('mobile-view tablet-view');
            //console.log('GT Frontend: Desktop view detected');
        }

        //console.log('GT Frontend: Final decision - shouldShowCards:', shouldShowCards);

        if (shouldShowCards) {
            this.showCardView($wrapper);
        } else {
            this.showTableView($wrapper);
        }
    };

    /**
     * Flip responsive mode: labels each cell with its column header and makes the first
     * column cell a row-label (role=rowheader). Applies when viewport <= flip_breakpoint (#348).
     */
    GravityTable.prototype.initFlipResponsive = function ($wrapper) {
        if ($wrapper.data('gt-flip-init')) {
            return;
        }
        $wrapper.data('gt-flip-init', true);

        var $table = $wrapper.find('.gt-table');
        var flipBreakpoint = parseInt($wrapper.data('flip-breakpoint')) || 768;
        var headers = [];
        $table.find('thead th').each(function () {
            headers.push($(this).text().trim());
        });

        $table.find('tbody tr').each(function () {
            $(this).find('td').each(function (index) {
                var label = headers[index] || '';
                $(this).attr('data-label', label);
                if (index === 0) {
                    $(this).attr('scope', 'row').attr('role', 'rowheader');
                }
            });
        });

        var applyFlip = function () {
            if ($(window).width() <= flipBreakpoint) {
                $wrapper.addClass('gt-flip-responsive');
            } else {
                /* c8 ignore next */
                $wrapper.removeClass('gt-flip-responsive');
            }
        };
        applyFlip();
        $(window).on('resize.gt-flip.' + $wrapper.attr('id'), applyFlip);
    };

    /**
     * Collapse mode: adds a per-row expand toggle that reveals a sub-row with
     * the values of any columns that overflow on narrow viewports.
     */
    GravityTable.prototype.initCollapsibleRows = function ($wrapper) {
        var self = this;
        var $table = $wrapper.find('.gt-table');
        var $headers = $table.find('thead th');

        $table.find('tbody tr').not('.gt-collapsed-sub-row').each(function () {
            var $row = $(this);
            if ($row.data('gt-collapse-init')) {
                return;
            }
            $row.data('gt-collapse-init', true);

            // Build sub-row data from all cells except the first.
            var $cells = $row.find('td');
            if ($cells.length < 2) {
                return;
            }

            // Create expand toggle in first cell.
            var $toggle = $('<button type="button" class="gt-row-expand-btn" aria-expanded="false" aria-label="' + (self.config.expand_label || 'Show details') + '">&#9658;</button>');
            $cells.first().prepend($toggle);

            // Build a definition list of hidden column data.
            var dlHtml = '<dl class="gt-sub-row-dl">';
            $cells.each(function (idx) {
                if (idx === 0) {
                    return;
                }
                var label = $headers.eq(idx).text().trim();
                var value = $(this).html();
                dlHtml += '<dt>' + label + '</dt><dd>' + value + '</dd>';
            });
            dlHtml += '</dl>';

            var colCount = $cells.length;
            var $subRow = $('<tr class="gt-collapsed-sub-row"><td colspan="' + colCount + '">' + dlHtml + '</td></tr>');
            $row.after($subRow);

            $toggle.on('click', function () {
                var expanded = $toggle.attr('aria-expanded') === 'true';
                $toggle.attr('aria-expanded', expanded ? 'false' : 'true');
                $subRow.toggleClass('gt-sub-row-open', !expanded);
            });
        });
    };

    /**
     * Modal mode: adds a per-row detail button that opens an overlay/drawer
     * showing the full row data as a labelled list.
     */
    GravityTable.prototype.initModalRows = function ($wrapper) {
        var self = this;
        var $table = $wrapper.find('.gt-table');
        var $headers = $table.find('thead th');

        $table.find('tbody tr').each(function () {
            var $row = $(this);
            if ($row.data('gt-modal-init')) {
                return;
            }
            $row.data('gt-modal-init', true);

            var $cells = $row.find('td');
            var $btn = $('<button type="button" class="gt-row-detail-btn" aria-label="' + (self.config.detail_label || 'View row details') + '">&#9432;</button>');
            $cells.last().append($btn);

            $btn.on('click', function () {
                var dlHtml = '<dl class="gt-sub-row-dl">';
                $cells.each(function (idx) {
                    var label = $headers.eq(idx).text().trim();
                    var value = $(this).clone().find('.gt-row-detail-btn').remove().end().html();
                    dlHtml += '<dt>' + label + '</dt><dd>' + value + '</dd>';
                });
                dlHtml += '</dl>';

                var $overlay = $('<div class="gt-row-detail-overlay" role="dialog" aria-modal="true" tabindex="-1">' +
                    '<div class="gt-row-detail-panel">' +
                    '<button type="button" class="gt-row-detail-close" aria-label="Close">&times;</button>' +
                    dlHtml +
                    '</div></div>');

                $overlay.on('click', '.gt-row-detail-close', function () {
                    $overlay.remove();
                });
                $overlay.on('click', function (e) {
                    if ($(e.target).is($overlay)) {
                        $overlay.remove();
                    }
                });

                $('body').append($overlay);
                $overlay.find('.gt-row-detail-close').focus();
            });
        });
    };

    GravityTable.prototype.shouldShowCardsOnTablet = function () {
        // Check if any fields are configured to be hidden on tablet
        if (this.config.responsive_settings) {
            for (var fieldId in this.config.responsive_settings) {
                if (this.config.responsive_settings[fieldId].tablet_visible === false) {
                    return true; // If any field is hidden on tablet, use card view
                }
            }
        }
        return false;
    };

    GravityTable.prototype.showCardView = function ($wrapper) {
        var $table = $wrapper.find('.gt-table');
        var $cardsContainer = $wrapper.find('.gt-cards-container');

        //console.log('GT Frontend: Switching to card view');
        //console.log('GT Frontend: Table element found:', $table.length);
        //console.log('GT Frontend: Cards container found:', $cardsContainer.length);
        //console.log('GT Frontend: Table currently visible:', $table.is(':visible'));
        //console.log('GT Frontend: Cards currently visible:', $cardsContainer.is(':visible'));

        // Hide table, show cards
        $table.hide();
        $cardsContainer.show();

        //console.log('GT Frontend: After toggle - Table visible:', $table.is(':visible'));
        //console.log('GT Frontend: After toggle - Cards visible:', $cardsContainer.is(':visible'));

        // Generate cards from current table data
        this.generateCards($wrapper);
    };

    GravityTable.prototype.showTableView = function ($wrapper) {
        var $table = $wrapper.find('.gt-table');
        var $cardsContainer = $wrapper.find('.gt-cards-container');

        //console.log('GT Frontend: Switching to table view');
        //console.log('GT Frontend: Table element found:', $table.length);
        //console.log('GT Frontend: Cards container found:', $cardsContainer.length);

        // Show table, hide cards
        $table.show();
        $cardsContainer.hide();

        //console.log('GT Frontend: After toggle - Table visible:', $table.is(':visible'));
        //console.log('GT Frontend: After toggle - Cards visible:', $cardsContainer.is(':visible'));
    };

    GravityTable.prototype.generateCards = function ($wrapper) {
        var self = this;
        var $cardsContainer = $wrapper.find('.gt-cards-container');
        var $table = $wrapper.find('.gt-table');
        var $tbody = $table.find('tbody');
        var isTabletView = $wrapper.hasClass('tablet-view');

        //console.log('GT Frontend: Generating cards from table data');
        //console.log('GT Frontend: Responsive settings:', this.config.responsive_settings);
        //console.log('GT Frontend: Is tablet view:', isTabletView);

        // Clear existing cards
        $cardsContainer.empty();

        // Check if table has data
        if ($tbody.find('tr').length === 0 || $tbody.find('.gt-loading-row').length > 0) {
            $cardsContainer.html('<div class="gt-cards-loading">Loading cards...</div>');
            return;
        }

        // Check if table has no entries
        if ($tbody.find('.gt-no-entries').length > 0) {
            $cardsContainer.html(
                '<div class="gt-cards-empty">' +
                '<div class="gt-empty-icon">📄</div>' +
                '<div class="gt-empty-message">No entries found</div>' +
                '<div class="gt-empty-submessage">Try adjusting your search or filter criteria</div>' +
                '</div>'
            );
            return;
        }

        // Generate cards from table rows
        $tbody.find('tr:not(.gt-loading-row)').each(function (index) {
            var $row = $(this);
            var entryId = $row.data('entry-id') || (index + 1);

            var cardHtml = self.generateCardHtml($row, entryId, isTabletView);
            $cardsContainer.append(cardHtml);
        });

        // Bind card events
        this.bindCardEvents($wrapper);

        //console.log('GT Frontend: Generated', $cardsContainer.find('.gt-entry-card').length, 'cards');
    };

    GravityTable.prototype.generateCardHtml = function ($row, entryId, isTabletView) {
        var self = this;
        var cardHtml = '<div class="gt-entry-card" data-entry-id="' + entryId + '">';

        // Process each table cell to create card fields
        var $cells = $row.find('td:not(.gt-checkbox-cell):not(.gt-actions-column)');
        var $headers = $row.closest('table').find('thead th:not(.gt-selection-header):not(.gt-actions-header)');

        // Find date created value for the header - prioritize date_created field or first date type field
        var dateCreatedValue = '';
        var dateFieldFound = false;

        // Debug logging
        //console.log('GT Mobile Debug: Processing entry', entryId, 'with', $cells.length, 'cells and', $headers.length, 'headers');

        $cells.each(function (index) {
            if (dateFieldFound) return; // Skip if we already found a date

            var $header = $($headers[index]);
            var fieldId = $header.data('field-id') || $header.attr('data-field-id') || $header.attr('data-field') || $header.attr('data-sort-field');
            var cellValue = $(this).text().trim();
            var headerText = $header.find('.gt-header-label').text() || $header.text();

            // Debug each field (#920: removed verbose console.log spam).
            // console.log('GT Mobile Debug: Field', index, {
            //     fieldId: fieldId,
            //     cellValue: cellValue,
            //     headerText: headerText,
            //     columnConfig: self.config.column_config ? self.config.column_config[fieldId] : 'no config'
            // });

            if (!fieldId) {
                //console.log('GT Mobile: No field ID found for header index', index);
                return; // Continue to next iteration
            }

            // Check if this is a date field using column config or field ID
            var isDateField = false;

            // Method 1: Check column config for date type
            if (self.config.column_config && self.config.column_config[fieldId]) {
                if (self.config.column_config[fieldId].type === 'date' || fieldId === 'date_created') {
                    isDateField = true;
                    //console.log('GT Mobile Debug: Found date field via column config:', fieldId);
                }
            }

            // Method 2: Check for known date field IDs
            if (!isDateField && (fieldId === 'date_created' || fieldId == '1')) {
                isDateField = true;
                //console.log('GT Mobile Debug: Found date field via field ID:', fieldId);
            }

            // Method 3: Check header text as fallback
            if (!isDateField) {
                if (headerText.toLowerCase().includes('date') ||
                    headerText.toLowerCase() === 'load date') {
                    isDateField = true;
                    //console.log('GT Mobile Debug: Found date field via header text:', headerText);
                }
            }

            // If this is a date field, get its value
            if (isDateField) {
                //console.log('GT Mobile Debug: Processing date field:', fieldId, 'with value:', cellValue);
                if (cellValue && cellValue !== '' && cellValue !== '&nbsp;' && cellValue !== 'N/A') {
                    dateCreatedValue = cellValue;
                    dateFieldFound = true;
                    //console.log('GT Mobile Debug: Using date value:', dateCreatedValue);
                    return false; // Break loop
                } else {
                    //console.log('GT Mobile Debug: Date field found but value is empty/invalid:', cellValue);
                }
            }
        });

        //console.log('GT Mobile Debug: Final date value for entry', entryId, ':', dateCreatedValue || 'No Date');

        // Fallback: If no date found through proper methods, try to get the first non-empty cell value
        if (!dateCreatedValue && $cells.length > 0) {
            //console.log('GT Mobile Debug: No date found, trying fallback with first cells');
            $cells.each(function (index) {
                if (!dateCreatedValue) {
                    var fallbackValue = $(this).text().trim();
                    //console.log('GT Mobile Debug: Fallback cell', index, 'value:', fallbackValue);
                    // Check if this looks like a date (contains numbers and slashes/dashes)
                    if (fallbackValue && fallbackValue.match(/\d+[\/\-]\d+[\/\-]\d+/) && fallbackValue !== '&nbsp;') {
                        dateCreatedValue = fallbackValue;
                        //console.log('GT Mobile Debug: Using fallback date value:', dateCreatedValue);
                        return false;
                    }
                }
            });
        }

        // Final fallback - use entry ID as identifier if still no date
        var displayDate = dateCreatedValue || ('Entry #' + entryId);
        //console.log('GT Mobile Debug: Final display date for entry', entryId, ':', displayDate);

        // Card header with date as primary info and collapse toggle
        cardHtml += '<div class="gt-card-header">';
        cardHtml += '<div class="gt-card-primary-info">';
        cardHtml += '<div class="gt-card-date">' + displayDate + '</div>';
        cardHtml += '<div class="gt-card-entry-id">Entry #' + entryId + '</div>';
        cardHtml += '</div>';
        cardHtml += '<div class="gt-card-actions">';

        // Add checkbox if selection is enabled
        var $checkbox = $row.find('.gt-entry-checkbox');
        if ($checkbox.length > 0) {
            cardHtml += '<input type="checkbox" class="gt-entry-checkbox" value="' + entryId + '"';
            if ($checkbox.is(':checked')) {
                cardHtml += ' checked';
            }
            cardHtml += '>';
        }

        // Add main collapse toggle for entire card
        cardHtml += '<button class="gt-card-main-toggle" title="Expand/Collapse Card">+</button>';
        cardHtml += '</div></div>';

        // Card content (collapsed by default)
        cardHtml += '<div class="gt-card-main-content collapsed">';

        var visibleFields = '';
        var hiddenFields = '';
        var hasHiddenFields = false;

        $cells.each(function (index) {
            var $cell = $(this);
            var $header = $($headers[index]);
            var fieldId = $header.data('field-id') || $header.attr('data-field') || index;
            var fieldLabel = $header.text().trim().replace(/[▲▼]/g, '').trim() || 'Field ' + (index + 1);
            var fieldValue = $cell.html().trim() || '';
            var fieldType = $header.data('field-type') || 'text';

            // Get responsive settings for this field
            var isVisibleOnDevice = self.isFieldVisibleOnCurrentDevice(fieldId, isTabletView);
            var mobileLabel = self.getMobileLabel(fieldId, fieldLabel);

            //console.log('GT Frontend: Field', fieldId, '(' + fieldLabel + ') - visible on device:', isVisibleOnDevice);

            // Create field HTML
            var fieldHtml = '<div class="gt-card-field" data-field-id="' + fieldId + '" data-field-type="' + fieldType + '">';
            fieldHtml += '<div class="gt-card-field-label">' + mobileLabel + '</div>';
            fieldHtml += '<div class="gt-card-field-value';

            // Preserve editing classes
            if ($cell.hasClass('gt-editable-cell')) {
                fieldHtml += ' gt-editable-cell';
                // Add gt-empty-field class for empty editable cells
                if ($cell.hasClass('gt-empty-field')) {
                    fieldHtml += ' gt-empty-field';
                }
            }
            if ($cell.hasClass('gt-readonly-cell')) {
                fieldHtml += ' gt-readonly-cell';
            }

            fieldHtml += '">' + fieldValue + '</div>';
            fieldHtml += '</div>';

            // Separate into visible and hidden sections based on admin settings
            // isVisibleOnDevice = true means field is CHECKED (mobile_visible: true) in admin → show immediately
            // isVisibleOnDevice = false means field is UNCHECKED (mobile_visible: false) in admin → show only when expanded
            if (isVisibleOnDevice) {
                visibleFields += fieldHtml;
                //console.log('GT Frontend: Adding', fieldLabel, 'to visible fields (checked in admin)');
            } else {
                hiddenFields += fieldHtml;
                hasHiddenFields = true;
                //console.log('GT Frontend: Adding', fieldLabel, 'to hidden fields (unchecked in admin)');
            }
        });

        // Add visible fields section (always shown)
        if (visibleFields) {
            cardHtml += '<div class="gt-card-visible-fields">' + visibleFields + '</div>';
        }

        // Add expand/collapse toggle if there are hidden fields
        if (hasHiddenFields) {
            cardHtml += '<div class="gt-card-toggle-section">';
            cardHtml += '<button class="gt-card-toggle" title="Show/Hide Additional Fields">';
            cardHtml += '<span class="gt-toggle-text">Show More</span>';
            cardHtml += '<span class="gt-toggle-icon">▼</span>';
            cardHtml += '</button>';
            cardHtml += '</div>';

            // Add hidden fields section (collapsed by default)
            cardHtml += '<div class="gt-card-hidden-fields collapsed">' + hiddenFields + '</div>';
        }

        // Close main content div
        cardHtml += '</div>';

        // Close main card div
        cardHtml += '</div>';

        return cardHtml;
    };

    // Responsive-visibility helpers (isFieldVisibleInCards, isFieldVisibleOnMobile,
    // isFieldVisibleOnTablet, getMobileLabel, isFieldVisibleOnCurrentDevice)
    // moved to assets/js/frontend/responsive-visibility.js (#832 slice 12).

    GravityTable.prototype.updateCardsAfterDataChange = function ($wrapper) {
        var $cardsContainer = $wrapper.find('.gt-cards-container');

        // Only update cards if they're currently visible
        if ($cardsContainer.is(':visible')) {
            //console.log('GT Frontend: Updating cards after data change');
            this.generateCards($wrapper);
        }
    };

    GravityTable.prototype.bindCardEvents = function ($wrapper) {
        var self = this;

        // Handle main card toggle (expand/collapse entire card)
        $wrapper.off('click.cardMainToggle').on('click.cardMainToggle', '.gt-card-main-toggle', function () {
            var $toggle = $(this);
            var $card = $toggle.closest('.gt-entry-card');
            var $mainContent = $card.find('.gt-card-main-content');

            if ($mainContent.hasClass('collapsed')) {
                // Expand entire card
                $mainContent.removeClass('collapsed');
                $toggle.text('-').addClass('expanded');
            } else {
                // Collapse entire card
                $mainContent.addClass('collapsed');
                $toggle.text('+').removeClass('expanded');
            }
        });

        // Handle "Show More" toggle (expand/collapse hidden fields within visible card)
        $wrapper.off('click.cardToggle').on('click.cardToggle', '.gt-card-toggle', function () {
            var $toggle = $(this);
            var $card = $toggle.closest('.gt-entry-card');
            var $hiddenFields = $card.find('.gt-card-hidden-fields');
            var $toggleText = $toggle.find('.gt-toggle-text');
            var $toggleIcon = $toggle.find('.gt-toggle-icon');

            if ($hiddenFields.hasClass('collapsed')) {
                // Expand to show hidden fields
                $hiddenFields.removeClass('collapsed');
                $toggleText.text('Show Less');
                $toggleIcon.html('▲');
                $toggle.addClass('expanded');
            } else {
                // Collapse to hide fields
                $hiddenFields.addClass('collapsed');
                $toggleText.text('Show More');
                $toggleIcon.html('▼');
                $toggle.removeClass('expanded');
            }
        });

        // Handle card checkbox changes (sync with table)
        $wrapper.off('change.cardCheckbox').on('change.cardCheckbox', '.gt-entry-card .gt-entry-checkbox', function () {
            var $checkbox = $(this);
            var entryId = $checkbox.closest('.gt-entry-card').data('entry-id');
            var $tableCheckbox = $wrapper.find('.gt-table tbody tr[data-entry-id="' + entryId + '"] .gt-entry-checkbox');

            if ($tableCheckbox.length > 0) {
                $tableCheckbox.prop('checked', $checkbox.is(':checked'));
            }

            // Trigger table checkbox change event for bulk actions
            $tableCheckbox.trigger('change');
        });

        // Handle editable field clicks in cards (delegate to table functionality)
        $wrapper.off('click.cardEdit').on('click.cardEdit', '.gt-card-field-value.gt-editable-cell', function () {
            var $fieldValue = $(this);
            var $card = $fieldValue.closest('.gt-entry-card');
            var entryId = $card.data('entry-id');
            var fieldId = $fieldValue.closest('.gt-card-field').data('field-id');

            // Find corresponding table cell and trigger click
            var $tableCell = $wrapper.find('.gt-table tbody tr[data-entry-id="' + entryId + '"] td[data-field-id="' + fieldId + '"]');
            if ($tableCell.length > 0) {
                $tableCell.trigger('click');
            }
        });
    };

})(window);
