/**
 * TableCrafter — admin/core.js
 *
 * First slice of #842 (admin.js monolith split). Page-bootstrap chunk:
 *   - init: prevents re-entry, calls bindEvents + showAllSections, loads table data
 *   - showAllSections: shows all builder panels, triggers section/drag/viewport init
 *   - toggleSection: collapse/expand on header click
 *   - updateAdvancedFiltersInPreview: keep filters open/closed in preview area
 *   - initCollapsibleSections: wires collapsible click handlers
 *   - initFloatingSaveButton: scroll-aware floating save fade
 *
 * Split pattern for object-literal namespaces (different from frontend.js's
 * GravityTable.prototype.X pattern): admin.js still defines the base
 * `window.TC_TableBuilder = {...}` literal; this module loads AFTER admin.js
 * and extends it via Object.assign. The guard `|| {}` preserves load-order
 * safety in case enqueue order ever flips.
 *
 * NOT in this slice — intentionally deferred to later #842 sub-issues:
 *   - bindEvents (~465 lines of event delegation, will be subdivided per feature)
 *   - initDragAndDrop, initRowReorder, updateFieldOrder, saveRowOrder (#844 table-builder.js)
 *   - initViewportToggles (deferred — references an undefined `self` outside its scope; bug to address separately)
 *
 * @since 4.149.0
 */
(function ($) {
    'use strict';

    // Stub-preserve guard: if this file loads before admin.js for any reason
    // (script-loader reorders, defer/async edge cases), seed the namespace
    // rather than crash.
    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        init: function () {
            // Prevent multiple initializations
            if (this.initialized) {
                return;
            }
            this.initialized = true;

            this.bindEvents();
            this.showAllSections();

            // Load existing table data if editing
            if (typeof window.gtTableData !== 'undefined') {
                this.loadTableData(window.gtTableData);

                // For a saved remote JSON source the field metadata (formFields)
                // isn't known until the columns are fetched, so the field picker +
                // Table Columns would render empty. Auto-fetch them on load — the
                // existing Test connection handler calls loadJsonColumns(), which
                // builds formFields, re-applies saved settings, and renders the
                // available fields, the selected Table Columns, and the preview.
                // (No manual "Test connection" click needed.)
                var srcType = $('#gt-data-source-type').val();
                var jsonUrl = $('input[name="json_url"]').val() || '';
                if (srcType === 'json' && jsonUrl) {
                    setTimeout(function () {
                        $('#gt-json-test-connection').trigger('click');
                    }, 150);
                } else if (srcType === 'csv' || srcType === 'xml' || srcType === 'google_sheets') {
                    // CSV / XML / Google Sheets use the generic remote-source preview
                    // button; auto-fire it (when its URL is set) so the field picker +
                    // Table Columns populate on load, same as JSON.
                    var $remoteBtn = $('.gt-remote-source-preview[data-source-type="' + srcType + '"]');
                    var urlField   = $remoteBtn.data('url-field');
                    if ($remoteBtn.length && urlField && ($('input[name="' + urlField + '"]').val() || '')) {
                        setTimeout(function () {
                            $remoteBtn.trigger('click');
                        }, 150);
                    } else if (this.selectedFields.length > 0) {
                        setTimeout(function () {
                            this.renderSelectedFields();
                            this.renderAvailableFields();
                            this.generatePreview();
                        }.bind(this), 100);
                    }
                } else if (srcType === 'woocommerce_products') {
                    // #2200 — WooCommerce has no URL; auto-load product columns into
                    // the picker on edit so saved/selectable columns + preview appear.
                    setTimeout(function () {
                        $('.gt-wc-load-columns').trigger('click');
                    }, 150);
                } else if (this.selectedFields.length > 0) {
                    // GF / already-known sources: render the saved selection + preview
                    // after a short delay to ensure the UI is ready.
                    setTimeout(function () {
                        this.renderSelectedFields();
                        this.renderAvailableFields();
                        this.generatePreview();
                    }.bind(this), 100);
                }
            }
        },

        toggleSection: function ($header) {
            var targetId = $header.data('target');
            var $content = $('#' + targetId);

            if ($content.length === 0) {
                console.error('GT Admin: Target content element not found:', targetId);
                return;
            }

            if ($content.hasClass('collapsed')) {
                $content.removeClass('collapsed');
                $header.removeClass('collapsed');
            } else {
                $content.addClass('collapsed');
                $header.addClass('collapsed');
            }
        },

        updateAdvancedFiltersInPreview: function (keepFiltersOpen) {
            var $previewArea = $('#table-preview-area');
            var $advancedFilters = $previewArea.find('.gt-advanced-filters, .gt-filters-container');

            if ($advancedFilters.length > 0) {
                if (keepFiltersOpen) {
                    $advancedFilters.removeClass('gt-filters-collapsed').addClass('gt-filters-expanded');
                    $advancedFilters.find('.gt-filters-toggle').removeClass('collapsed');
                } else {
                    $advancedFilters.removeClass('gt-filters-expanded').addClass('gt-filters-collapsed');
                    $advancedFilters.find('.gt-filters-toggle').addClass('collapsed');
                }
            }
        },

        showAllSections: function () {
            $('.gt-builder-section').show();

            this.initCollapsibleSections();
            this.initDragAndDrop();
            this.initRowReorder();
            this.initViewportToggles();
        },

        initCollapsibleSections: function () {
            var self = this;

            // Wait for DOM to be ready
            setTimeout(function () {
                // Open all sections first, then collapse 2 and 3 — only 1 and 4 open by default.
                $('.gt-section-content').removeClass('collapsed');
                $('.gt-collapsible').removeClass('collapsed');

                ['field-selection-content', 'table-features-content'].forEach(function (id) {
                    $('#' + id).addClass('collapsed');
                    $('.gt-collapsible[data-target="' + id + '"]').addClass('collapsed');
                });

                $('.gt-collapsible').off('click.collapsible');

                $('.gt-collapsible').on('click.collapsible', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    self.toggleSection($(this));
                });
            }, 100);
        },

        initFloatingSaveButton: function () {
            var $floatingButton = $('#gt-floating-save');
            if ($floatingButton.length === 0) return;

            var $window = $(window);
            var $finalSaveSection = $('.gt-final-save-section');
            var threshold = 200;

            function toggleFloatingButton() {
                var scrollTop = $window.scrollTop();
                var windowHeight = $window.height();
                var documentHeight = $(document).height();

                var finalSectionOffset = $finalSaveSection.length ? $finalSaveSection.offset().top : documentHeight;
                var showButton = scrollTop > threshold && (scrollTop + windowHeight) < (finalSectionOffset - 100);

                if (showButton) {
                    /* c8 ignore next */
                    $floatingButton.fadeIn(200);
                } else {
                    $floatingButton.fadeOut(200);
                }
            }

            var scrollTimeout;
            $window.on('scroll.floatingSave', function () {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(toggleFloatingButton, 50);
            });

            toggleFloatingButton();
        }
    });

})(jQuery);
