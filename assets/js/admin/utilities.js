/**
 * Gravity Tables -- admin/utilities.js
 *
 * Tenth and final slice of #842. Misc utility methods + initViewportToggles
 * bug fix (was missing var self = this).
 *
 * @since 4.158.0
 */
(function ($) {
    "use strict";

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        initViewportToggles: function () {
            var self = this;
            $(document).off('click.gtViewport').on('click.gtViewport', '[data-viewport]', function () {
                var viewport = $(this).data('viewport');
                var $preview = $('.gt-responsive-preview');

                $('.gt-viewport-toggle').removeClass('active');
                $(this).addClass('active');

                if (viewport === 'tablet') {
                    $preview.css({ 'max-width': '768px', 'width': '768px', 'margin': '0 auto' });
                } else if (viewport === 'mobile') {
                    $preview.css({ 'max-width': '375px', 'width': '375px', 'margin': '0 auto' });
                } else {
                    $preview.css({ 'max-width': '', 'width': '', 'margin': '' });
                }
            });
            // #549 slice 3: Per-cell vertical alignment toolbar in preview
            $(document).on('click', '#table-preview-area td', function(e) {
                var $td = $(this);
                var fieldId = $td.data('field-id');
                var $tr = $td.closest('tr');
                var entryId = $tr.data('entry-id');

                if (!fieldId || !entryId) return;

                self.showCellToolbar($td, entryId, fieldId);
            });

            // Close cell toolbar on outside click
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.gt-cell-toolbar, #table-preview-area td').length) {
                    $('.gt-cell-toolbar').remove();
                }
            });
        },

        showCellToolbar: function($td, entryId, fieldId) {
            var self = this;
            $('.gt-cell-toolbar').remove();

            var currentVal = (this.cellVerticalAlignments[entryId] && this.cellVerticalAlignments[entryId][fieldId]) || '';
            
            var $toolbar = $('<div class="gt-cell-toolbar">' +
                '<button type="button" data-val="top" title="Align Top" class="' + (currentVal === 'top' ? 'active' : '') + '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
                '<button type="button" data-val="middle" title="Align Middle" class="' + (currentVal === 'middle' ? 'active' : '') + '"><span class="dashicons dashicons-minus"></span></button>' +
                '<button type="button" data-val="bottom" title="Align Bottom" class="' + (currentVal === 'bottom' ? 'active' : '') + '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
                '<button type="button" data-val="" title="Default (Column)" class="' + (currentVal === '' ? 'active' : '') + '"><span class="dashicons dashicons-undo"></span></button>' +
            '</div>');

            $('body').append($toolbar);

            var offset = $td.offset();
            $toolbar.css({
                top: offset.top - $toolbar.outerHeight() - 10,
                left: offset.left + ($td.outerWidth() / 2) - ($toolbar.outerWidth() / 2)
            });

            $toolbar.find('button').on('click', function() {
                var val = $(this).data('val');
                
                if (!self.cellVerticalAlignments[entryId]) {
                    self.cellVerticalAlignments[entryId] = {};
                }
                
                if (val === '') {
                    delete self.cellVerticalAlignments[entryId][fieldId];
                    if ($.isEmptyObject(self.cellVerticalAlignments[entryId])) {
                        delete self.cellVerticalAlignments[entryId];
                    }
                } else {
                    self.cellVerticalAlignments[entryId][fieldId] = val;
                }

                // Update cell style in DOM for immediate feedback
                if (val === '') {
                    // Fall back to column-level align
                    var colVal = (self.formFields[fieldId] && self.formFields[fieldId].vertical_alignment) || 'middle';
                    $td.css('vertical-align', colVal);
                } else {
                    $td.css('vertical-align', val);
                }

                $toolbar.remove();
            });
        },

        deleteTable: function (tableId, nonce) {
            var self = this;

            // Show loading state
            var $deleteButton = $('.gt-delete-table[data-table-id="' + tableId + '"]');
            var originalText = $deleteButton.text();
            $deleteButton.text('Deleting...');

            // Send AJAX request
            $.post(gtAdmin.ajax_url, {
                action: 'gt_delete_table',
                table_id: tableId,
                nonce: nonce
            }, function (response) {
                if (response.success) {
                    // Remove the table row from the list
                    $('tr').each(function () {
                        if ($(this).find('.gt-delete-table').data('table-id') == tableId) {
                            $(this).fadeOut(300, function () {
                                $(this).remove();

                                // If no tables left, show the "no tables" message
                                if ($('.wp-list-table tbody tr').length === 0) {
                                    $('#gt-tables-list').html('<div class="gt-no-tables"><p>No tables have been created yet.</p><a href="admin.php?page=gravity-tables-new" class="button button-primary">Create Your First Table</a></div>');
                                }
                            });
                        }
                    });
                } else {
                    alert('Error deleting table: ' + (response.data || 'Unknown error'));
                    $deleteButton.text(originalText);
                }
            }).fail(function () {
                alert('Error deleting table');
                $deleteButton.text(originalText);
            });
        },

        duplicateTable: function (tableId, nonce) {
            var $link = $('.gt-duplicate-table[data-table-id="' + tableId + '"]');
            var originalText = $link.text();
            $link.text('Duplicating...');

            $.post(gtAdmin.ajax_url, {
                action: 'gt_duplicate_table',
                table_id: tableId,
                nonce: nonce
            }, function (response) {
                if (response.success && response.data && response.data.redirect_url) {
                    window.location.href = response.data.redirect_url;
                } else {
                    alert('Error duplicating table: ' + (response.data || 'Unknown error'));
                    $link.text(originalText);
                }
            }).fail(function () {
                alert('Error duplicating table');
                $link.text(originalText);
            });
        },

        updateBulkActions: function () {
            var bulkActions = [];

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

            // Update hidden field
            $('input[name="bulk_actions"]').val(bulkActions.join(','));
        },

        showUpgradeNotice: function (type, message) {
            // Remove any existing notices
            $('.gt-upgrade-notice').remove();

            // Create upgrade notice
            var notice = $('<div class="gt-upgrade-notice" style="' +
                'background: linear-gradient(135deg, #ff6b6b 0%, #ff8e8e 100%); ' +
                'color: white; ' +
                'padding: 15px 20px; ' +
                'margin: 15px 0; ' +
                'border-radius: 8px; ' +
                'border-left: 4px solid #ff4757; ' +
                'box-shadow: 0 4px 12px rgba(255, 107, 107, 0.3); ' +
                'display: flex; ' +
                'align-items: center; ' +
                'justify-content: space-between; ' +
                'animation: slideInDown 0.3s ease-out;' +
                '">' +
                '<div>' +
                '<strong style="font-size: 16px; margin-right: 8px;">⚠️ Plan Limit Reached</strong>' +
                '<div style="margin-top: 5px; opacity: 0.9;">' + message + '</div>' +
                '</div>' +
                '<div>' +
                '<a href="' + (gtAdmin.upgrade_url || '#') + '" class="button" style="' +
                'background: rgba(255,255,255,0.2); ' +
                'border: 1px solid rgba(255,255,255,0.3); ' +
                'color: white; ' +
                'text-decoration: none; ' +
                'padding: 8px 16px; ' +
                'border-radius: 4px; ' +
                'font-weight: 500; ' +
                'margin-left: 15px; ' +
                'transition: all 0.3s ease;' +
                '">Upgrade to Pro</a>' +
                '</div>' +
                '</div>');

            // Add CSS animation
            if (!$('#gt-upgrade-notice-styles').length) {
                $('<style id="gt-upgrade-notice-styles">' +
                    '@keyframes slideInDown {' +
                    'from { transform: translateY(-20px); opacity: 0; }' +
                    'to { transform: translateY(0); opacity: 1; }' +
                    '}' +
                    '.gt-upgrade-notice a:hover {' +
                    'background: rgba(255,255,255,0.3) !important;' +
                    'transform: translateY(-1px);' +
                    '}' +
                    '</style>').appendTo('head');
            }

            // Insert notice at the top of the builder
            $('.gt-table-builder-container').prepend(notice);

            // Auto-remove after 8 seconds
            setTimeout(function () {
                notice.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 8000);
        }

    });

})(jQuery);
