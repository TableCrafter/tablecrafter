/**
 * TableCrafter — frontend/detail-popup.js
 *
 * Entry-details popup overlay + file-upload cell renderer. Seventh
 * module under #830.
 *
 * Closes #837.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - viewEntryDetails(entryId)
 *       AJAX-fetches entry details from the gt_get_entry_details
 *       endpoint, builds the popup HTML (title, label/value grid,
 *       optional GF edit link, edit button when frontend editing is
 *       enabled), and hands the result to showDetailsPopup. Loading
 *       and error states are surfaced as inline content.
 *
 *   - showDetailsPopup(content, options)
 *       Creates the .gt-popup-overlay DOM lazily on first call and
 *       reuses it on subsequent calls. Wires close handlers (X button,
 *       backdrop click, Escape key, .gt-close-popup) and a focus trap
 *       (Tab/Shift+Tab cycling between first/last focusable). Edit
 *       button click closes the popup and triggers inline-edit for
 *       the captured entry id. options.titleId attaches
 *       aria-labelledby on the modal; omit it to remove the attribute.
 *
 *   - closeDetailsPopup()
 *       Fades the overlay out, removes the gt-modal-open body class,
 *       and restores focus to the element that had it before the
 *       popup opened.
 *
 *   - bindDetailViewEvents($wrapper)
 *       Wires the .gt-view-detail / .gt-view-action click handlers
 *       that delegate to viewEntryDetails. Previously inlined in
 *       bindEntryEvents; now called from there in one line.
 *
 *   - renderFileUploadCell(value)
 *       Renders a fileupload field value as <img> (for known image
 *       extensions: jpg, jpeg, png, gif, webp, bmp, svg) or as an
 *       <a> link otherwise. Handles single URLs, JSON-encoded
 *       multi-file arrays, and gracefully falls back to single-URL
 *       render for non-JSON strings. URL + filename are escaped via
 *       this.escapeHtml (provided by util.js) to prevent injection.
 *
 * Pre-requisites: util.js (escapeHtml) must load before this module.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery || window.$;

    var IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    Object.assign(window.GravityTable.prototype, {

        viewEntryDetails: function (entryId) {
            var self = this;

            self.showDetailsPopup('<p class="gt-details-loading">Loading details&hellip;</p>', { titleId: null });

            $.post(this.config.ajax_url, {
                action: 'gt_get_entry_details',
                nonce: this.config.nonce,
                entry_id: entryId
            }, function (response) {
                if (response.success) {
                    var data = response.data;
                    var titleId = 'gt-details-title-' + data.entry_id;
                    var canEdit = !!self.config.enable_frontend_editing;
                    var html = '<div class="gt-details-container">';
                    html += '<h3 id="' + titleId + '" class="gt-details-title">' + self.escapeHtml(data.form_title) + ' &mdash; Entry #' + self.escapeHtml(String(data.entry_id)) + '</h3>';
                    html += '<dl class="gt-details-grid">';
                    $.each(data.details, function (index, detail) {
                        html += '<div class="gt-details-row">';
                        html += '<dt class="gt-detail-label">' + self.escapeHtml(detail.label) + '</dt>';
                        html += '<dd class="gt-detail-value">' + (detail.value === '' || detail.value == null ? '<span class="gt-detail-empty">&mdash;</span>' : detail.value) + '</dd>';
                        html += '</div>';
                    });
                    html += '</dl>';
                    if (data.gf_entry_edit_url) {
                        html += '<p class="gt-details-gf-link"><a href="' + self.escapeHtml(data.gf_entry_edit_url) + '" target="_blank" rel="noopener noreferrer">Open in Gravity Forms</a></p>';
                    }
                    html += '</div>';
                    html += '<div class="gt-details-footer">';
                    if (canEdit) {
                        html += '<button type="button" class="gt-details-edit-btn" data-entry-id="' + self.escapeHtml(String(data.entry_id)) + '">Edit</button>';
                    }
                    html += '<button type="button" class="gt-close-popup">Close</button>';
                    html += '</div>';

                    self.showDetailsPopup(html, { titleId: titleId });
                } else {
                    self.showDetailsPopup('<p class="gt-details-error">Error: ' + self.escapeHtml(String(response.data || 'Unknown error')) + '</p>', { titleId: null });
                }
            }).fail(function () {
                self.showDetailsPopup('<p class="gt-details-error">Failed to load entry details.</p>', { titleId: null });
            });
        },

        showDetailsPopup: function (content, options) {
            var self = this;
            options = options || {};
            var $overlay = $('.gt-popup-overlay');

            if (!$overlay.length) {
                $overlay = $('<div class="gt-popup-overlay" aria-hidden="true">'
                    + '<div class="gt-popup-modal" role="dialog" aria-modal="true" tabindex="-1">'
                    + '<button type="button" class="gt-popup-close" aria-label="Close">&times;</button>'
                    + '<div class="gt-popup-body"></div>'
                    + '</div></div>');
                $('body').append($overlay);

                $overlay.on('click', function (e) {
                    if ($(e.target).hasClass('gt-popup-overlay') || $(e.target).hasClass('gt-popup-close')) {
                        self.closeDetailsPopup();
                    }
                });

                $overlay.on('click', '.gt-close-popup', function () {
                    self.closeDetailsPopup();
                });

                $overlay.on('click', '.gt-details-edit-btn', function () {
                    var entryId = $(this).data('entry-id');
                    self.closeDetailsPopup();
                    setTimeout(function () { self.triggerInlineEditForEntry(entryId); }, 0);
                });

                $overlay.on('keydown', function (e) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        self.closeDetailsPopup();
                        return;
                    }
                    if (e.key === 'Tab') {
                        var $focusables = $overlay.find('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible');
                        if (!$focusables.length) return;
                        var first = $focusables.first()[0];
                        var last = $focusables.last()[0];
                        if (e.shiftKey && document.activeElement === first) {
                            e.preventDefault();
                            last.focus();
                        } else if (!e.shiftKey && document.activeElement === last) {
                            e.preventDefault();
                            first.focus();
                        }
                    }
                });
            }

            var $modal = $overlay.find('.gt-popup-modal');
            if (options.titleId) {
                $modal.attr('aria-labelledby', options.titleId);
            } else {
                $modal.removeAttr('aria-labelledby');
            }

            $overlay.find('.gt-popup-body').html(content);
            $overlay.attr('aria-hidden', 'false');
            $overlay.fadeIn(200, function () {
                $modal.focus();
            });
            $('body').addClass('gt-modal-open');

            if (!self._gtPreviousFocus) {
                self._gtPreviousFocus = document.activeElement;
            }
        },

        closeDetailsPopup: function () {
            var self = this;
            var $overlay = $('.gt-popup-overlay');
            $overlay.attr('aria-hidden', 'true');
            $overlay.fadeOut(200);
            $('body').removeClass('gt-modal-open');
            if (self._gtPreviousFocus && typeof self._gtPreviousFocus.focus === 'function') {
                try { self._gtPreviousFocus.focus(); } catch (err) {}
            }
            self._gtPreviousFocus = null;
        },

        bindDetailViewEvents: function ($wrapper) {
            var self = this;
            $wrapper.on('click', '.gt-view-detail, .gt-view-action', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var entryId = $(this).data('entry-id');
                self.viewEntryDetails(entryId);
            });
        },

        renderFileUploadCell: function (value) {
            var self = this;
            var urls = [];
            if (!value) return '';

            // Try JSON array first (multi-file upload).
            try {
                var parsed = JSON.parse(value);
                if (Array.isArray(parsed)) {
                    urls = parsed;
                } else {
                    urls = [String(value)];
                }
            } catch (e) {
                urls = [String(value)];
            }

            var html = '';

            urls.forEach(function (url) {
                url = String(url).trim();
                if (!url) return;
                var ext = url.split('.').pop().toLowerCase().split('?')[0];
                var name = url.split('/').pop().split('?')[0];
                if (IMAGE_EXTENSIONS.indexOf(ext) !== -1) {
                    html += '<img src="' + self.escapeHtml(url) + '" alt="' + self.escapeHtml(name) + '" class="gt-file-image" loading="lazy" style="max-width:80px;max-height:60px;object-fit:contain;" />';
                } else {
                    html += '<a href="' + self.escapeHtml(url) + '" class="gt-file-link" target="_blank" rel="noopener noreferrer">' + self.escapeHtml(name) + '</a> ';
                }
            });

            return html;
        }

    });

})(window);
