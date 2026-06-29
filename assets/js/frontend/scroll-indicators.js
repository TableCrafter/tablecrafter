/**
 * TableCrafter — frontend/scroll-indicators.js
 *
 * Horizontal scroll affordance overlay. Twelfth slice under #833.
 * One method, ~370 lines — second-largest single-method extraction
 * after #832 slice 20's responsive block.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - setupScrollIndicators() — install scroll-shadow gradients on
 *     the .gt-table-content wrapper, wire scroll listeners to
 *     toggle the .gt-scrollable-left / .gt-scrollable-right classes,
 *     re-evaluate after entries load via the gt-entries-loaded
 *     custom event.
 *
 * Called once from init().
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.setupScrollIndicators = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $tableContent = $wrapper.find('.gt-table-content');
        var $table = $wrapper.find('.gt-table');

        if ($tableContent.length === 0 || $table.length === 0) {
            return;
        }

        // Only show scroll indicators on mobile or when responsive_table is enabled
        // NOT for desktop tables in normal view
        var windowWidth = $(window).width();
        var isMobile = windowWidth <= 768;
        var shouldShowScrollIndicators = isMobile || this.config.responsive_table;

        if (!shouldShowScrollIndicators) {
            //console.log('GT Frontend: Skipping scroll indicators for desktop view');
            return;
        }

        function updateScrollIndicators() {
            var contentWidth = $tableContent.width();
            var tableWidth = $table.width();
            var scrollLeft = $tableContent.scrollLeft();
            var maxScroll = tableWidth - contentWidth;

            // Remove existing classes and hints
            $tableContent.removeClass('no-scroll has-scroll scroll-left scroll-right');
            $tableContent.find('.gt-scroll-hint').remove();

            if (maxScroll <= 0) {
                // Table fits completely, no scroll needed
                $tableContent.addClass('no-scroll');
            } else {
                // Table is wider than container - just add scroll hint, no shadows
                $tableContent.addClass('has-scroll');

                // Add scroll hint if user hasn't interacted yet (but not on mobile)
                if (!$tableContent.hasClass('scroll-interacted') && scrollLeft === 0 && window.innerWidth > 480) {
                    $tableContent.append('<div class="gt-scroll-hint">← Scroll to see more →</div>');
                }
            }
        }

        // Initial check
        updateScrollIndicators();

        // Update on scroll
        $tableContent.on('scroll', function () {
            // Mark as interacted with on first scroll
            if (!$tableContent.hasClass('scroll-interacted')) {
                $tableContent.addClass('scroll-interacted');
            }
            updateScrollIndicators();
        });

        // Update on window resize (namespaced so each table instance can be cleaned up independently)
        $(window).on('resize.gtScroll.' + self.wrapperId, function () {
            setTimeout(updateScrollIndicators, 100);
        });

        // Update after entries are loaded
        $(document).on('gt-entries-loaded-' + this.wrapperId, updateScrollIndicators);
    };


    /**
     * Extract and return the lowercase file extension from a URL or filename.
     * Returns empty string if no extension can be determined.
     *
     * @param  {string} url  File URL or filename.
     * @return {string}      Lowercase extension (e.g. 'pdf', 'jpg') or ''.
     */
    // getFileExtension removed (#832 slice 16) — dead code; the only
    // caller (renderFileUploadCell) moved to detail-popup.js in #837 with
    // its own inline extension parsing, leaving this method orphaned.

    /**
     * Normalise a raw field value to a boolean 0/1 for toggle columns (#325).
     * '1', 'true', 'yes' (case-insensitive) → 1; everything else → 0.
     */
    // normalizeToggleValue moved to assets/js/frontend/util.js (#841).

    /**
     * Render a fileupload field value as <img> (for images) or <a> links (for other files).
     * Handles single URLs, comma-separated URLs, and JSON-encoded arrays.
     */
    // renderFileUploadCell moved to assets/js/frontend/detail-popup.js (#837).

    // escapeRegex and escapeHtml moved to assets/js/frontend/util.js (#841).

    // applyRowExpiry (#501) + applyAutoMerge (#518) moved to
    // assets/js/frontend/post-render-gates.js (#832 slice 18).

    // Date formatting utilities
    // applyConditionalFormatting / getColumnIndex / getCellValue / evaluateCondition /
    // applyFormattingAction moved to assets/js/frontend/conditional-format.js (#838 —
    // third child of #830). Object.assign attachment keeps the public surface
    // unchanged so existing call sites (self.applyConditionalFormatting(),
    // self.evaluateCondition(...), etc.) work as before.

    // formatDate moved to assets/js/frontend/util.js (#841).

    // parseDateInput moved to assets/js/frontend/util.js (#832 slice 16) —
    // joins the existing date helper family.

    // Initialize add new entry functionality
    $(document).ready(function () {
        // Prevent duplicate event handlers
        if (window.gtAddNewInitialized) {
            //console.log('GT Add New: Already initialized, skipping');
            /* c8 ignore next */
            return;
        }
        window.gtAddNewInitialized = true;

        // Listen for successful form submission from iframe
        window.addEventListener('message', function(event) {
            if (event.data && event.data.gtEvent === 'form_submitted') {
                if (typeof tb_remove === 'function') {
                    tb_remove();
                }
                // Reload the page to show the new entry
                window.location.reload();
            }
        });

        // Handle Add New Entry button click
        $(document).on('click.gtAddNew', '.gt-add-new-btn', function (e) {
            e.preventDefault();
            var $button = $(this);
            var formId = $button.data('form-id');

            if (!formId) {
                console.error('GT Add New: No form ID found for add new entry');
                return;
            }

            // Get the correct AJAX URL
            var ajax_url = (window.gravityTables && window.gravityTables.ajax_url) || '/wp-admin/admin-ajax.php';

            // Calculate dimensions
            var width = 600;
            var height = 500;
            if (window.innerWidth) {
                width = Math.min(600, window.innerWidth - 40);
                height = Math.min(500, window.innerHeight - 40);
            }

            // Get nonce for AJAX request - try multiple sources
            var $tableWrapper = $button.closest('.gt-table-wrapper');
            var nonce = $tableWrapper.data('nonce') ||
                (window.gtTableData && window.gtTableData[$tableWrapper.attr('id')] && window.gtTableData[$tableWrapper.attr('id')].nonce) ||
                (window.gravityTables && window.gravityTables.nonce);

            // Get table_id for permission check
            var tableWrapperConfig = window.gtTableData && window.gtTableData[$tableWrapper.attr('id')];
            var tableDbId = (tableWrapperConfig && tableWrapperConfig.table_id) || 0;

            // Construct ThickBox URL - Let CSS handle dimensions
            var url = ajax_url + '?action=gt_get_form_html&form_id=' + formId + '&nonce=' + nonce + '&table_id=' + tableDbId + '&raw=1&TB_iframe=true';

            // Debug logging
            // console.log('GT Add New: Opening Thickbox with URL:', url);

            // Open ThickBox
            tb_show('Add New Entry', url);

            // Override Thickbox's inline styles with proper dimensions
            setTimeout(function () {
                var $window = $('#TB_window');
                var $iframe = $('#TB_iframeContent');

                if ($window.length) {
                    // Force modal sizing
                    $window.css({
                        'width': '80%',
                        'max-width': '900px',
                        'height': 'auto',
                        'top': '30px',
                        'bottom': '30px',
                        'left': '50%',
                        'margin-left': '0',
                        'margin-top': '0',
                        'transform': 'translateX(-50%)',
                        'overflow-y': 'auto'
                    });
                }

                if ($iframe.length) {
                    // Force iframe sizing
                    $iframe.css({
                        'width': '100%',
                        'height': 'calc(100vh - 100px)',
                        'min-height': '600px'
                    });
                }

                // console.log('GT Add New: Thickbox dimensions overridden');
            }, 100);

            // Verify iframe was created
            setTimeout(function () {
                var $iframe = $('#TB_iframeContent');
                // console.log('GT Add New: Iframe exists:', $iframe.length > 0);
                if ($iframe.length > 0) {
                    // console.log('GT Add New: Iframe src:', $iframe.attr('src'));
                    // console.log('GT Add New: Iframe display:', $iframe.css('display'));
                    // console.log('GT Add New: Iframe visibility:', $iframe.css('visibility'));
                    // console.log('GT Add New: Iframe height:', $iframe.css('height'));
                }
            }, 500);
        });

        // Obsolete custom modal functions removed (replaced by ThickBox integration)
        // Removed in #1560: orphan showNotification helper had no callers anywhere
        // in the codebase. The user-facing notification surface lives in
        // table-utils.js (showMessage) and edit-history.js (toast lifecycle).
    });

    // Export-close handler is now wrapper-scoped inside bindEvents() to avoid
    // capturing all document clicks (#435).

    // Initialize date filter functionality when DOM is ready
    $(document).ready(function () {
        // Handle date input synchronization (HTML5 date input with text display)
        $(document).on('change', '.gt-date-html5', function () {
            var $dateInput = $(this);
            var $textDisplay = $dateInput.siblings('.gt-date-display');
            var dateValue = $dateInput.val();

            if (dateValue) {
                // Parse the date string directly to avoid timezone issues
                // dateValue is in format "YYYY-MM-DD"
                var parts = dateValue.split('-');
                var year = parts[0];
                var month = parts[1];
                var day = parts[2];

                // Format as MM/DD/YYYY for display
                var formattedDate = month + '/' + day + '/' + year;
                $textDisplay.val(formattedDate);
            } else {
                $textDisplay.val('');
            }

            // Auto-apply filters when date changes
            var $table = $dateInput.closest('.gt-table-wrapper');
            if ($table.length && window.GravityTable) {
                var tableId = $table.attr('id');
                if (tableId && window.GravityTable.instances && window.GravityTable.instances[tableId]) {
                    window.GravityTable.instances[tableId].applyFilters();
                }
            }
        });

        // Handle text display clicks to show HTML5 date picker
        $(document).on('click focus', '.gt-date-display', function () {
            var $textDisplay = $(this);
            var $dateInput = $textDisplay.siblings('.gt-date-html5');
            $dateInput.focus();
            if ($dateInput[0].showPicker) {
                $dateInput[0].showPicker();
            } else {
                $dateInput.click();
            }
        });

        // Handle wrapper clicks to show date picker
        $(document).on('click.gtDateFilter', '.gt-date-input-wrapper', function (e) {
            var $wrapper = $(this);
            var $dateInput = $wrapper.find('.gt-date-html5');
            if (e.target === $wrapper[0] || $(e.target).hasClass('gt-date-display')) {
                e.preventDefault();
                $dateInput.focus();
                if ($dateInput[0].showPicker) {
                    $dateInput[0].showPicker();
                } else {
                    $dateInput.click();
                }
            }
        });

        // Handle date preset buttons
        $(document).on('click.gtDateFilter', '.gt-date-preset', function () {
            var $preset = $(this);
            var presetType = $preset.data('preset');
            var $dateFilter = $preset.closest('.gt-date-filter');
            var startDateStr, endDateStr;

            // Remove active class from other presets
            $dateFilter.find('.gt-date-preset').removeClass('active');
            $preset.addClass('active');

            // Get today's date in simple format without timezone conversion
            var now = new Date();
            var todayYear = now.getFullYear();
            var todayMonth = now.getMonth() + 1; // getMonth() returns 0-11
            var todayDay = now.getDate();

            // Calculate date ranges based on preset (no timezone conversion)
            switch (presetType) {
                case 'today':
                    startDateStr = endDateStr = todayYear + '-' +
                        todayMonth.toString().padStart(2, '0') + '-' +
                        todayDay.toString().padStart(2, '0');
                    break;
                case 'week':
                    // Get start of week (Sunday)
                    var dayOfWeek = now.getDay(); // 0 = Sunday, 1 = Monday, etc.
                    var startOfWeekDay = todayDay - dayOfWeek;
                    var startOfWeekDate = new Date(todayYear, todayMonth - 1, startOfWeekDay);

                    startDateStr = startOfWeekDate.getFullYear() + '-' +
                        (startOfWeekDate.getMonth() + 1).toString().padStart(2, '0') + '-' +
                        startOfWeekDate.getDate().toString().padStart(2, '0');
                    endDateStr = todayYear + '-' +
                        todayMonth.toString().padStart(2, '0') + '-' +
                        todayDay.toString().padStart(2, '0');
                    break;
                case 'month':
                    // Start of current month
                    startDateStr = todayYear + '-' +
                        todayMonth.toString().padStart(2, '0') + '-01';
                    endDateStr = todayYear + '-' +
                        todayMonth.toString().padStart(2, '0') + '-' +
                        todayDay.toString().padStart(2, '0');
                    break;
            }

            // Set the date inputs - handle both single and range dates
            if (startDateStr) {
                // Check if it's a range filter (has from/to) or single date
                var $fromInput = $dateFilter.find('.gt-date-from-html5');
                var $singleInput = $dateFilter.find('.gt-date-html5:not(.gt-date-from-html5):not(.gt-date-to-html5)');

                if ($fromInput.length > 0) {
                    // Range filter
                    $fromInput.val(startDateStr).trigger('change');
                } else if ($singleInput.length > 0) {
                    // Single date filter
                    $singleInput.val(startDateStr).trigger('change');
                }
            }

            if (endDateStr) {
                var $toInput = $dateFilter.find('.gt-date-to-html5');
                if ($toInput.length > 0) {
                    // Only set end date if we have a "to" field (range filter)
                    $toInput.val(endDateStr).trigger('change');
                }
            }
        });

        // Multi-select dropdowns are now handled natively by the browser
    });

})(window);
