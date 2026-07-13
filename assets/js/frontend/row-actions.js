/**
 * TableCrafter - frontend/row-actions.js
 *
 * Handlers behind the per-row action buttons. Seventh slice under
 * #833. Pairs with actions-cell.js (#832 slice 7) which renders the
 * buttons themselves.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - loadWooCommerceProducts() - populate the WC product list via
 *     gt_get_wc_products AJAX. Used when the table is configured as
 *     a WC product browser (config.is_wc_products_table).
 *   - createWooCommerceProduct(entryId, $btn) - POST to
 *     gt_create_wc_product to spin up a draft WC product from a row.
 *     Confirms first, swaps button to a spinner icon, opens the
 *     edit URL in a new tab on success, alerts on failure.
 *   - viewEntryHistory(entryId) - AJAX gt_get_entry_history and
 *     render the change-log in the detail popup (showDetailsPopup
 *     lives in detail-popup.js, #837).
 *   - triggerInlineEditForEntry(entryId) - find a row by entryId in
 *     the DOM, scroll to it, mark it gt-row-selected, and call
 *     showEditIndicator. Used by deep-link URLs and the inline-edit
 *     icon.
 *
 * Callees that remain in frontend.js or other modules:
 *   - this.escapeHtml (frontend.js)
 *   - this.showDetailsPopup (detail-popup.js, #837)
 *   - this.showEditIndicator (frontend.js)
 *   - this.showUndoToast (edit-history.js, #833 slice 1; defensive
 *     `typeof` check preserved)
 *   - this.renderSSPEntries (ssp.js, #832 slice 2)
 *   - this.updatePagination (pagination.js)
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.loadWooCommerceProducts = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $tbody = $wrapper.find('tbody');

        $wrapper.addClass('gt-table-loading');
        $tbody.html('<tr class="gt-loading-row"><td colspan="' + ($wrapper.find('thead th').length) + '"><div class="gt-loading">Loading products...</div></td></tr>');

        var data = {
            action:     'gt_get_wc_products',
            nonce:      this.config.nonce,
            table_id:   this.config.table_id,
            page:       this.currentPage,
            per_page:   this.config.per_page,
            search:     this.searchTerm || '',
            orderby:    this.sortField   || 'date',
            order:      (this.sortOrder  || 'desc').toUpperCase()
        };

        $.extend(data, this.filters);

        $.post(this.config.ajax_url, data, function (response) {
            $wrapper.removeClass('gt-table-loading');
            if (response.success && response.data) {
                self.totalEntries = parseInt(response.data.total, 10) || 0;
                self.renderSSPEntries(
                    (response.data.entries || []).map(function (p) {
                        return [p.thumbnail, p.name, p.sku, p.price, p.stock_status, p.rating, p.add_to_cart];
                    })
                );
                self.updatePagination(self.totalEntries);
            } else {
                /* c8 ignore next */
                $tbody.html('<tr><td colspan="' + ($wrapper.find('thead th').length) + '"><div class="gt-error">Error loading products</div></td></tr>');
            }
        }).fail(function () {
            /* c8 ignore next */
            $wrapper.removeClass('gt-table-loading');
            /* c8 ignore next */
            $tbody.html('<tr><td colspan="' + ($wrapper.find('thead th').length) + '"><div class="gt-error">Error loading products</div></td></tr>');
        });
    };

    GravityTable.prototype.createWooCommerceProduct = function (entryId, $btn) {
        var self = this;
        if (!entryId) return;
        if (!self.config.woocommerce || !self.config.woocommerce.active) {
            alert('WooCommerce is not active on this site.');
            return;
        }
        if (!confirm('Create a draft WooCommerce product from this entry?')) return;

        var origIcon = $btn.text();
        $btn.text('⏳').prop('disabled', true);

        $.post(this.config.ajax_url, {
            action: 'gt_create_wc_product',
            nonce: this.config.nonce,
            entry_id: entryId,
            table_id: this.config.table_id || 0
        }, function (response) {
            $btn.text(origIcon).prop('disabled', false);
            if (response && response.success) {
                var d = response.data || {};
                var msg = 'Created product #' + d.product_id + ' (' + (d.product_title || 'untitled') + ')';
                if (typeof self.showUndoToast === 'function') {
                    self.showUndoToast(msg);
                } else {
                    alert(msg);
                }
                if (d.edit_url) {
                    window.open(d.edit_url, '_blank', 'noopener');
                }
            } else {
                var err = (response && response.data && response.data.message) || (response && response.data) || 'Unknown error';
                alert('Could not create product: ' + err);
            }
        }).fail(function () {
            $btn.text(origIcon).prop('disabled', false);
            alert('Request failed. Please try again.');
        });
    };

    GravityTable.prototype.viewEntryHistory = function (entryId) {
        var self = this;
        this.showDetailsPopup('<div class="gt-history-loading">Loading edit history…</div>');

        $.post(this.config.ajax_url, {
            action: 'gt_get_entry_history',
            nonce: this.config.nonce,
            entry_id: entryId
        }, function (response) {
            if (!response.success) {
                self.showDetailsPopup('<div class="gt-history-error">Error: ' + self.escapeHtml(String(response.data || 'Unknown error')) + '</div>');
                return;
            }
            var data = response.data;
            var html = '<div class="gt-history-container">';
            html += '<h3>Edit History &mdash; Entry #' + self.escapeHtml(String(data.entry_id)) + '</h3>';
            if (!data.records || data.records.length === 0) {
                html += '<p class="gt-history-empty">No edits recorded for this entry yet.</p>';
            } else {
                html += '<ol class="gt-history-list">';
                data.records.forEach(function (rec) {
                    html += '<li class="gt-history-item">';
                    html += '<div class="gt-history-meta">';
                    html += '<span class="gt-history-when">' + self.escapeHtml(rec.created_at_display) + '</span>';
                    html += ' · <span class="gt-history-who">' + self.escapeHtml(rec.user_name) + '</span>';
                    html += '</div>';
                    html += '<div class="gt-history-field"><strong>' + self.escapeHtml(rec.field_label) + '</strong></div>';
                    html += '<div class="gt-history-diff">';
                    html += '<span class="gt-history-old">' + (rec.old_value === null || rec.old_value === '' ? '<em>(empty)</em>' : self.escapeHtml(String(rec.old_value))) + '</span>';
                    html += ' <span class="gt-history-arrow">&rarr;</span> ';
                    html += '<span class="gt-history-new">' + (rec.new_value === null || rec.new_value === '' ? '<em>(empty)</em>' : self.escapeHtml(String(rec.new_value))) + '</span>';
                    html += '</div>';
                    html += '</li>';
                });
                html += '</ol>';
            }
            html += '<div class="gt-details-footer"><button type="button" class="gt-close-popup">Close</button></div>';
            html += '</div>';
            self.showDetailsPopup(html);
        }).fail(function () {
            self.showDetailsPopup('<div class="gt-history-error">Failed to load history.</div>');
        });
    };

    GravityTable.prototype.triggerInlineEditForEntry = function (entryId) {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $row = $wrapper.find('tbody tr').filter(function () {
            return $(this).find('[data-entry-id="' + entryId + '"]').length > 0;
        }).first();
        if (!$row.length) return;
        $wrapper.find('.gt-edit-indicator').remove();
        $wrapper.find('tbody tr').removeClass('gt-row-selected');
        $row.addClass('gt-row-selected');
        if (typeof self.showEditIndicator === 'function') {
            self.showEditIndicator($row, entryId);
        }
        var rowTop = $row.offset() ? $row.offset().top : 0;
        $('html, body').animate({ scrollTop: Math.max(0, rowTop - 100) }, 200);
    };

})(window);
